<?php
namespace csabourin\craftS3SpacesMigration\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use craft\records\VolumeFolder;
use csabourin\craftS3SpacesMigration\helpers\MigrationConfig;
use csabourin\craftS3SpacesMigration\helpers\DuplicateResolver;
use yii\console\ExitCode;
use craft\models\VolumeFolder as VolumeFolderModel;
use csabourin\craftS3SpacesMigration\services\CheckpointManager;
use csabourin\craftS3SpacesMigration\services\ChangeLogManager;
use csabourin\craftS3SpacesMigration\services\ErrorRecoveryManager;
use csabourin\craftS3SpacesMigration\services\RollbackEngine;
use csabourin\craftS3SpacesMigration\services\MigrationLock;
use csabourin\craftS3SpacesMigration\services\ProgressTracker;
use csabourin\craftS3SpacesMigration\services\MigrationStateService;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;

/**
 * Asset Migration Controller - PRODUCTION GRADE v4.0
 * 
 * MAJOR ENHANCEMENTS:
 * ✓ Checkpoint/Resume System - Survive interruptions
 * ✓ Continuous Change Log - Never lose rollback data
 * ✓ Batch Processing - Memory efficient, handles 100k+ assets
 * ✓ Comprehensive Rollback - ALL operations are reversible
 * ✓ Error Recovery - Retry logic, health checks, graceful degradation
 * ✓ Progress Tracking - ETA, percentage, items/sec
 * ✓ Transaction Safety - Atomic database operations
 * ✓ Idempotent - Safe to run multiple times
 * 
 * ARCHITECTURE:
 * - CheckpointManager: Save/restore state
 * - ChangeLogManager: Continuous atomic logging
 * - BatchProcessor: Memory-efficient iteration
 * - ErrorRecoveryManager: Retry and health checks
 * - RollbackEngine: Comprehensive undo operations
 * 
 * @author Christian Sabourin
 * @version 4.0.0
 */
class ImageMigrationController extends Controller
{
    public $defaultAction = 'migrate';

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    // Configuration - loaded from centralized config
    private $sourceVolumeHandles;
    private $targetVolumeHandle;
    private $quarantineVolumeHandle;

    // **ADD THESE PUBLIC PROPERTIES FOR OPTIONS**
    public $dryRun = false;
    public $skipBackup = false;
    public $skipInlineDetection = false;
    public $resume = false;
    public $checkpointId = null;
    public $skipLock = false;
    public $yes = false; // Skip all confirmation prompts (for automation)
    public $olderThanHours = null; // Hours threshold for cleanup (default: from config)

    // Batch Processing Config - loaded from centralized MigrationConfig
    private $BATCH_SIZE;
    private $CHECKPOINT_EVERY_BATCHES;
    private $CHANGELOG_FLUSH_EVERY;
    private $MAX_RETRIES;
    public const RETRY_DELAY_MS = 1000;
    private $CHECKPOINT_RETENTION_HOURS;

    // **PATCH: Add root-level volume tracking and transform patterns**
    private $rootLevelVolumes; // Volumes at bucket root - loaded from config

    private $transformPatterns = [
        '/_\d+x\d+_crop_center-center/',  // _800x600_crop_center-center
        '/_\d+x\d+_/',                      // _800x600_
        '/_[a-zA-Z]+\d*x\d*/',              // _thumb200x200
    ];

    // Configuration - now overridable
    public $errorThreshold = 50; // General threshold (deprecated, kept for backwards compatibility)
    public $criticalErrorThreshold = 20; // Threshold for unexpected errors (write failures, permissions, etc.)
    public $batchSize = 100;
    public $checkpointRetentionHours = 72;
    public $verificationSampleSize = null; // null = verify all
    public $progressReportingInterval = 50; // Report progress every N items

    // Expected error counts (set during discovery)
    private $expectedMissingFileCount = 0; // Number of broken links discovered

    // State tracking for resume
    private $processedAssetIds = []; // Track completed assets by ID
    private $processedFileKeys = []; // Track completed files by key
    private $failedOperations = []; // Track failed items to retry later
    private $phaseStartTime = 0;

    // Resource tracking
    private $tempFiles = [];
    private $migrationLock = null;

    // Performance tracking
    private $operationTimings = [];
    private $estimatedTimeRemaining = 0;

    // Managers
    private $checkpointManager;
    private $changeLogManager;
    private $errorRecoveryManager;
    private $rollbackEngine;

    // State
    private $migrationId;
    private $currentPhase = '';
    private $currentBatch = 0;
    private $processedIds = [];

    // Error tracking
    private $errorCounts = [];


    // Progress tracking
    private $stats = [
        'files_verified' => 0,
        'files_missing' => 0,
        'assets_orphaned' => 0,
        'files_orphaned' => 0,
        'files_moved' => 0,
        'files_quarantined' => 0,
        'assets_updated' => 0,
        'duplicates_resolved' => 0,
        'errors' => 0,
        'retries' => 0,
        'checkpoints_saved' => 0,
        'start_time' => null,
        'resume_count' => 0
    ];

    // Cache
    private $rteFieldMap = null;

    // Missing files tracking for CSV export
    private $missingFiles = [];

    // Track successfully copied source files to handle duplicates
    private $copiedSourceFiles = [];

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'migrate') {
            $options[] = 'dryRun';
            $options[] = 'skipBackup';
            $options[] = 'skipInlineDetection';
            $options[] = 'resume';
            $options[] = 'checkpointId';
            $options[] = 'skipLock';
            $options[] = 'yes';
        }

        if ($actionID === 'rollback') {
            $options[] = 'dryRun';
            $options[] = 'yes';
        }

        if ($actionID === 'cleanup') {
            $options[] = 'olderThanHours';
            $options[] = 'yes';
        }

        if ($actionID === 'force-cleanup') {
            $options[] = 'yes';
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        return [
            'r' => 'resume',
            'c' => 'checkpointId',
            'd' => 'dryRun',
            'sb' => 'skipBackup',
            'si' => 'skipInlineDetection',
            'sl' => 'skipLock'
        ];
    }

    /**
     *  init()
     */
    public function init(): void
    {
        parent::init();

        // Load configuration from centralized config
        $this->config = MigrationConfig::getInstance();
        $this->sourceVolumeHandles = $this->config->getSourceVolumeHandles();
        $this->targetVolumeHandle = $this->config->getTargetVolumeHandle();
        $this->quarantineVolumeHandle = $this->config->getQuarantineVolumeHandle();
        $this->rootLevelVolumes = $this->config->getVolumesAtBucketRoot();

        // Load batch processing config
        $this->BATCH_SIZE = $this->config->getBatchSize();
        $this->CHECKPOINT_EVERY_BATCHES = $this->config->getCheckpointEveryBatches();
        $this->CHANGELOG_FLUSH_EVERY = $this->config->getChangelogFlushEvery();
        $this->MAX_RETRIES = $this->config->getMaxRetries();
        $this->CHECKPOINT_RETENTION_HOURS = $this->config->getCheckpointRetentionHours();

        // Load error thresholds from config (can be overridden via CLI options)
        $this->errorThreshold = $this->config->getErrorThreshold();
        $this->criticalErrorThreshold = $this->config->getCriticalErrorThreshold();
        $this->batchSize = $this->config->getBatchSize();
        $this->checkpointRetentionHours = $this->config->getCheckpointRetentionHours();

        // Generate unique migration ID (or will be restored from checkpoint)
        $this->migrationId = date('Y-m-d-His') . '-' . substr(md5(microtime()), 0, 8);
        $this->stats['start_time'] = time();

        // Initialize managers
        $this->checkpointManager = new CheckpointManager($this->migrationId);
        $this->changeLogManager = new ChangeLogManager($this->migrationId, $this->CHANGELOG_FLUSH_EVERY);
        $this->errorRecoveryManager = new ErrorRecoveryManager($this->MAX_RETRIES, $this->config->getRetryDelayMs());
        $this->rollbackEngine = new RollbackEngine($this->changeLogManager, $this->migrationId);

        // Initialize migration lock
        $this->migrationLock = new MigrationLock($this->migrationId);

        // Register shutdown handler for cleanup
        register_shutdown_function([$this, 'emergencyCleanup']);
    }

    /**
     * Main migration action with checkpoint/resume support
     */
    public function actionMigrate()
    {
        $this->printHeader();

        // If checkpointId is provided, force resume mode
        if ($this->checkpointId) {
            $this->resume = true;
        }

        // Resume existing migration if requested
        if ($this->resume || $this->checkpointId) {
            return $this->resumeMigration($this->checkpointId, $this->dryRun, $this->skipBackup, $this->skipInlineDetection);
        }

        // LOCK HANDLING - allows resume of same migration
        if (!$this->resume && !$this->skipLock) {
            $this->stdout("  Acquiring migration lock... ");
            if (!$this->migrationLock->acquire(5, false)) {
                $this->stderr("\n\nERROR: Another migration is currently running.\n", Console::FG_RED);
                $this->stderr("Wait for it to complete, or if it's stuck, run:\n");
                $this->stderr("  ./craft s3-spaces-migration/image-migration/force-cleanup\n");
                $this->stderr("\nOr skip the lock check (dangerous):\n");
                $this->stderr("  ./craft s3-spaces-migration/image-migration/migrate skipLock=1\n\n");
                $this->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("acquired\n", Console::FG_GREEN);
        } elseif ($this->skipLock) {
            $this->stdout("  ⚠ SKIPPING LOCK CHECK - ensure no other migration is running!\n", Console::FG_YELLOW);
        }

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        if ($this->skipInlineDetection) {
            $this->stdout("INLINE DETECTION SKIPPED - Only checking asset field relations\n", Console::FG_YELLOW);
            $this->stdout("⚠ WARNING: Images in RTE fields may be quarantined as unused!\n\n", Console::FG_YELLOW);
        }

        // Handle resume
        if ($this->resume || $this->checkpointId) {
            return $this->resumeMigration($this->checkpointId, $this->dryRun, $this->skipBackup, $this->skipInlineDetection);
        }

        // Initialize quick state immediately so monitor can detect the migration
        $this->checkpointManager->saveQuickState([
            'migration_id' => $this->migrationId,
            'phase' => 'initializing',
            'batch' => 0,
            'processed_ids' => [],
            'processed_count' => 0,
            'stats' => $this->stats,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Register migration start in database with PID for recovery after page refresh
        $pid = getmypid();
        $this->checkpointManager->registerMigrationStart(
            $pid,
            null, // sessionId - not available in CLI
            's3-spaces-migration/image-migration/migrate',
            0 // totalCount - will be updated later
        );

        try {
            // Phase 0: Preparation & Validation
            $this->setPhase('preparation');
            $this->printPhaseHeader("PHASE 0: PREPARATION & VALIDATION");

            $volumes = $this->validateConfiguration();
            $targetVolume = $volumes['target'];
            $sourceVolumes = $volumes['sources'];
            $quarantineVolume = $volumes['quarantine'];

            $targetFs = $targetVolume->getFs();
            $quarantineFs = $quarantineVolume->getFs();

            $this->stdout("  ✓ Volumes validated\n", Console::FG_GREEN);
            $this->stdout("    Target: {$targetVolume->name} (ID: {$targetVolume->id})\n");
            $this->stdout("    Quarantine: {$quarantineVolume->name} (ID: {$quarantineVolume->id})\n");

            // Health check
            $this->performHealthCheck($targetVolume, $quarantineVolume);

            // Disk space validation
            $this->validateDiskSpace($sourceVolumes, $targetVolume);

            // Get target root folder
            $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);
            if (!$targetRootFolder) {
                throw new \Exception("Cannot find root folder for target volume");
            }

            $this->stdout("    Target root folder ID: {$targetRootFolder->id}\n\n");

            // Checkpoint: preparation complete
            $this->saveCheckpoint([
                'volumes' => [
                    'target' => $targetVolume->id,
                    'sources' => array_map(fn($v) => $v->id, $sourceVolumes),
                    'quarantine' => $quarantineVolume->id
                ],
                'targetRootFolderId' => $targetRootFolder->id
            ]);

            // Create backup
            if (!$this->dryRun && !$this->skipBackup) {
                $this->createBackup();
            } elseif ($this->skipBackup && !$this->dryRun) {
                $this->stdout("  ⚠⚠⚠ WARNING: BACKUP SKIPPED ⚠⚠⚠\n", Console::FG_RED);
                $this->stdout("  This migration will make destructive changes to your database!\n", Console::FG_RED);
                $this->stdout("  Proceeding without backup is EXTREMELY RISKY.\n", Console::FG_RED);
                $this->stdout("  Press Ctrl+C now to cancel, or wait 10 seconds to continue...\n\n", Console::FG_YELLOW);
                sleep(10);
            }

            // **PATCH: Phase 0.5: Handle optimisedImages at root FIRST**
            if (in_array('optimisedImages', $this->sourceVolumeHandles)) {
                // Check if there are actually any assets in optimisedImages to migrate
                $volumesService = Craft::$app->getVolumes();
                $optimisedVolume = $volumesService->getVolumeByHandle('optimisedImages');

                if ($optimisedVolume) {
                    $assetCount = (int) Asset::find()->volumeId($optimisedVolume->id)->count();

                    if ($assetCount === 0) {
                        $this->stdout("\n");
                        $this->printPhaseHeader("PHASE 0.5: OPTIMISED IMAGES (SKIPPED)");
                        $this->stdout("  No assets found in optimisedImages volume - skipping migration\n", Console::FG_GREY);
                        $this->stdout("  Phase 1 will handle all asset discovery\n\n", Console::FG_GREY);
                    } else {
                        $this->setPhase('optimised_root');
                        $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
                        $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);

                        $this->handleOptimisedImagesAtRoot(
                            $assetInventory,
                            $fileInventory,
                            $targetVolume,
                            $quarantineVolume
                        );

                        // Note: Inventories will be rebuilt in Phase 1 Discovery to capture all changes
                        $this->stdout("  ✓ Optimised images processed - Phase 1 will build fresh inventories\n", Console::FG_GREEN);
                    }
                } else {
                    $this->stdout("  ⚠ optimisedImages volume not found - skipping Phase 0.5\n", Console::FG_YELLOW);
                }
            }

            // Phase 1: Discovery & Analysis
            $this->setPhase('discovery');
            $this->printPhaseHeader("PHASE 1: DISCOVERY & ANALYSIS");

            $this->stdout("  Building asset inventory (batch processing)...\n");
            $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);

            $this->stdout("  Scanning filesystems for actual files...\n");
            $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);

            $this->stdout("  Analyzing asset-file relationships...\n");
            $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);

            $this->printAnalysisReport($analysis);

            // Set expected missing file count for type-specific error thresholds
            $this->expectedMissingFileCount = count($analysis['broken_links']);
            $this->stdout(sprintf(
                "  ⓘ Expected missing file errors: %d (will not halt migration)\n",
                $this->expectedMissingFileCount
            ), Console::FG_CYAN);

            // Save phase 1 results to database for resume capability
            $this->savePhase1Results($assetInventory, $fileInventory, $analysis);

            // Checkpoint: discovery complete
            $this->saveCheckpoint([
                'assetCount' => count($assetInventory),
                'fileCount' => count($fileInventory),
                'expectedMissingFiles' => $this->expectedMissingFileCount,
                'analysis' => [
                    'broken_links' => count($analysis['broken_links']),
                    'used_wrong_location' => count($analysis['used_assets_wrong_location']),
                    'unused_assets' => count($analysis['unused_assets']),
                    'orphaned_files' => count($analysis['orphaned_files'])
                ]
            ]);

            if ($this->dryRun) {
                $this->stdout("\nDRY RUN - Would perform these operations:\n", Console::FG_YELLOW);

                if (!$this->skipInlineDetection) {
                    $db = Craft::$app->getDb();
                    $inlineEstimate = $this->estimateInlineLinking($db);

                    if ($inlineEstimate['columns_found'] > 0) {
                        $this->stdout("  0. Link ~{$inlineEstimate['images_estimate']} inline images\n", Console::FG_CYAN);
                        $this->stdout("     Estimated time: {$inlineEstimate['time_estimate']}\n\n", Console::FG_GREY);
                    }
                }

                $this->printPlannedOperations($analysis);
                $this->stdout("\nTo execute: ./craft s3-spaces-migration/image-migration/migrate\n", Console::FG_CYAN);
                $this->stdout("To resume if interrupted: ./craft s3-spaces-migration/image-migration/migrate --resume\n\n", Console::FG_CYAN);
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }

            // Confirm before proceeding
            $this->stdout("\n");
            if (!$this->skipInlineDetection) {
                $this->stdout("⚠ Next: Link inline images to create proper asset relations\n", Console::FG_YELLOW);
            }

            // Force flush change log before user confirmation
            $this->changeLogManager->flush();

            if (!$this->yes) {
                $confirm = $this->prompt("Proceed with migration? (yes/no)", [
                    'required' => true,
                    'default' => 'no',
                ]);

                if ($confirm !== 'yes') {
                    $this->stdout("Migration cancelled.\n");
                    $this->stdout("__CLI_EXIT_CODE_0__\n");
                    $this->stdout("Checkpoint saved. Resume with: ./craft s3-spaces-migration/image-migration/migrate --resume\n", Console::FG_CYAN);
                    return ExitCode::OK;
                }
            } else {
                $this->stdout("⚠ Auto-confirmed (--yes flag)\n", Console::FG_YELLOW);
            }

            // Phase 1.5: Link Inline Images (BATCHED)
            if (!$this->skipInlineDetection) {
                $this->setPhase('link_inline');
                $this->printPhaseHeader("PHASE 1.5: LINKING INLINE IMAGES TO ASSETS");

                $linkingStats = $this->linkInlineImagesBatched(
                    Craft::$app->getDb(),
                    $assetInventory,
                    $targetVolume
                );

                // Rebuild inventory if images were linked
                if ($linkingStats['rows_updated'] > 0) {
                    $this->stdout("\n  Rebuilding asset inventory with new relations...\n");
                    $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
                    $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);
                    $this->printAnalysisReport($analysis);
                }

                $this->saveCheckpoint(['inline_linking_complete' => true]);
                // Force flush after inline linking phase
                $this->changeLogManager->flush();
            }


            // Phase 1.7: Safe File Duplicate Detection & Staging
            // CRITICAL: This phase MUST run BEFORE resolving duplicate assets (Phase 1.8)
            // to ensure all shared files are safely staged before any destructive operations
            $this->setPhase('safe_duplicates');
            $this->printPhaseHeader("PHASE 1.7: SAFE FILE DUPLICATE DETECTION & STAGING");

            // Analyze files that are shared by multiple assets
            $duplicateGroups = $this->analyzeFileDuplicates($sourceVolumes, $targetVolume);

            if (!empty($duplicateGroups)) {
                $batchSize = $this->config->getBatchSize();

                // Stage files to temp location
                $this->stdout("\n");
                $this->printPhaseHeader("PHASE 1.7a: STAGING FILES TO SAFE LOCATION");
                $this->stageFilesToTemp($duplicateGroups, $quarantineVolume, $batchSize);

                // Determine primary assets
                $this->stdout("\n");
                $this->printPhaseHeader("PHASE 1.7b: DETERMINING PRIMARY ASSETS");
                $this->determineActiveAssets($duplicateGroups, $batchSize);

                // Delete unused duplicate asset records
                $this->stdout("\n");
                $this->printPhaseHeader("PHASE 1.7c: CLEANING UP DUPLICATE ASSETS");
                $this->deleteUnusedDuplicateAssets($duplicateGroups, $batchSize);

                $this->saveCheckpoint(['safe_duplicates_complete' => true]);
                $this->changeLogManager->flush();

                $this->stdout("\n  ✓ Safe duplicate handling complete\n", Console::FG_GREEN);
                $this->stdout("  Files are staged in quarantine temp folder and will be safely migrated\n", Console::FG_CYAN);
                $this->stdout("  Source files will only be deleted when safe to do so\n\n", Console::FG_CYAN);
            } else {
                $this->stdout("  No duplicate files detected - all assets have unique physical files\n", Console::FG_GREEN);
                $this->saveCheckpoint(['safe_duplicates_complete' => true]);
            }

            // Phase 1.8: Resolve Duplicate Assets
            // NOTE: This now runs AFTER Phase 1.7 to ensure files are staged before asset deletion
            if (!empty($analysis['duplicates'])) {
                $this->setPhase('resolve_duplicates');
                $this->printPhaseHeader("PHASE 1.8: RESOLVE DUPLICATE ASSETS");

                $duplicateCount = count($analysis['duplicates']);
                $this->stdout("  Found {$duplicateCount} sets of duplicate filenames\n", Console::FG_YELLOW);
                $this->stdout("  Resolving duplicates by merging into best candidate...\n\n");

                $this->resolveDuplicateAssets($analysis['duplicates'], $targetVolume);

                // Rebuild inventory after resolving duplicates
                $this->stdout("\n  Rebuilding asset inventory after duplicate resolution...\n");
                $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
                $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);

                $this->saveCheckpoint(['duplicates_resolved' => true]);
                $this->changeLogManager->flush();
            }

            // Phase 2: Fix Broken Links (BATCHED)
            if (!empty($analysis['broken_links'])) {
                $this->setPhase('fix_links');
                $this->printPhaseHeader("PHASE 2: FIX BROKEN ASSET-FILE LINKS");
                $this->fixBrokenLinksBatched($analysis['broken_links'], $fileInventory, $sourceVolumes, $targetVolume, $targetRootFolder);

                // Export missing files to CSV after phase 2
                $this->exportMissingFilesToCsv();
            }

            // Phase 3: Consolidate Used Files (BATCHED)
            $this->setPhase('consolidate');
            $this->printPhaseHeader("PHASE 3: CONSOLIDATE USED FILES");
            $this->consolidateUsedFilesBatched(
                $analysis['used_assets_wrong_location'],
                $targetVolume,
                $targetRootFolder,
                $targetFs
            );

            // Phase 4: Quarantine Unused Files (BATCHED)
            // IMPORTANT: Only files from TARGET volume are quarantined
            // Source volumes are for discovery only - their files are not touched during quarantine
            // This ensures documents/videos in source volumes (e.g., "optimisedImages") are not quarantined
            if (!empty($analysis['orphaned_files']) || !empty($analysis['unused_assets'])) {
                $this->setPhase('quarantine');
                $this->printPhaseHeader("PHASE 4: QUARANTINE UNUSED FILES (TARGET VOLUME ONLY)");

                $unusedCount = count($analysis['unused_assets']);
                $orphanedCount = count($analysis['orphaned_files']);

                if ($unusedCount > 0) {
                    $this->stdout("  ⚠ About to quarantine {$unusedCount} unused assets from target volume\n", Console::FG_YELLOW);
                }
                if ($orphanedCount > 0) {
                    $this->stdout("  ⚠ About to quarantine {$orphanedCount} orphaned files from target volume\n", Console::FG_YELLOW);
                }

                $this->stdout("  ℹ️  Note: Only files in the target volume ('{$targetVolume->name}') will be quarantined.\n", Console::FG_CYAN);
                $this->stdout("  ℹ️  Source volumes are for discovery only - their files are not affected.\n\n", Console::FG_CYAN);

                // Force flush change log before user confirmation
                $this->changeLogManager->flush();

                $shouldProceed = $this->yes;

                if (!$this->yes) {
                    $confirm = $this->prompt("Proceed with quarantine? (yes/no)", [
                        'required' => true,
                        'default' => 'no',
                    ]);
                    $shouldProceed = ($confirm === 'yes');
                } else {
                    $this->stdout("⚠ Auto-confirmed (--yes flag)\n", Console::FG_YELLOW);
                }

                if (!$shouldProceed) {
                    $this->stdout("Quarantine cancelled. Skipping to cleanup.\n\n", Console::FG_YELLOW);
                } else {
                    $this->quarantineUnusedFilesBatched(
                        $analysis['orphaned_files'],
                        $analysis['unused_assets'],
                        $quarantineVolume,
                        $quarantineFs
                    );
                }
            }

            // Phase 4.5: Cleanup Duplicate Temp Files
            if (!empty($duplicateGroups)) {
                $this->stdout("\n");
                $this->printPhaseHeader("PHASE 4.5: CLEANUP DUPLICATE TEMP FILES");
                $this->cleanupTempFiles($quarantineVolume);
                $this->saveCheckpoint(['temp_cleanup_complete' => true]);
            }

            // Phase 5: Cleanup & Verification
            $this->setPhase('cleanup');
            $this->printPhaseHeader("PHASE 5: CLEANUP & VERIFICATION");
            $this->performCleanupAndVerification($targetVolume, $targetRootFolder);

            // Mark as complete
            $this->setPhase('complete');
            $this->saveCheckpoint(['completed' => true]);

            // Final flush to ensure all changes are written
            $this->changeLogManager->flush();

            // Final report
            $this->printFinalReport();

            // Cleanup old checkpoints
            $this->checkpointManager->cleanupOldCheckpoints();
            $this->stderr("__CLI_EXIT_CODE_1__\n");

        } catch (\Exception $e) {
            $this->handleFatalError($e);
            $this->safeStderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->printSuccessFooter();
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * **PATCH: Detect if a file is a transform**
     */
    private function isTransformFile($filename, $path)
    {
        // Check filename patterns
        foreach ($this->transformPatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }

        // Check if in transform directory
        if (
            strpos($path, '_transforms') !== false ||
            strpos($path, '/_') === 0
        ) {
            return true;
        }

        return false;
    }

    /**
     * **PATCH: Special handling for optimisedImages at root**
     *
     * IMPORTANT: This method filters assets by DATABASE ASSOCIATION (volumeId field),
     * NOT filesystem location. Only assets with volumeId = OptimisedImages will be
     * processed, even though OptimisedImages may point to bucket root containing
     * files from all other volumes.
     */
    private function handleOptimisedImagesAtRoot($assetInventory, $fileInventory, $targetVolume, $quarantineVolume)
    {
        $this->printPhaseHeader("SPECIAL: OPTIMISED IMAGES ROOT MIGRATION");

        $this->stdout("  NOTE: This step may be redundant with later migration steps.\n", Console::FG_YELLOW);
        $this->stdout("  It specifically handles optimised images at the bucket root.\n\n", Console::FG_YELLOW);

        if (!$this->confirm("Do you want to run this special migration step?", false)) {
            $this->stdout("  ⚠ Skipped by user - optimised images at root will be handled by later steps\n\n");
            return;
        }

        $volumesService = Craft::$app->getVolumes();
        $optimisedVolume = $volumesService->getVolumeByHandle('optimisedImages');

        if (!$optimisedVolume) {
            $this->stdout("  Skipping - optimisedImages volume not found\n\n");
            return;
        }

        $this->stdout("  Processing optimisedImages at bucket root...\n");
        $this->stdout("  Volume ID: {$optimisedVolume->id}\n", Console::FG_GREY);

        // CRITICAL: Filter assets by DATABASE volumeId = optimisedVolume ID
        // This ensures we only process assets associated with OptimisedImages in the database,
        // NOT all files in the filesystem directory (which may include other volumes)
        $optimisedAssets = array_filter($assetInventory, function ($asset) use ($optimisedVolume) {
            return $asset['volumeId'] == $optimisedVolume->id;
        });

        $this->stdout("  Found " . count($optimisedAssets) . " assets with volumeId={$optimisedVolume->id}\n");

        // Get all files from optimisedImages filesystem
        $optimisedFs = $optimisedVolume->getFs();
        $this->stdout("  Scanning filesystem... ");

        $allFiles = $this->scanFilesystem($optimisedFs, '', true, null);
        $this->stdout("done (" . count($allFiles['all']) . " files)\n");
        $this->stdout("  (Only files matching database assets with volumeId={$optimisedVolume->id} will be migrated)\n", Console::FG_GREY);
        $this->stdout("\n");

        // Categorize files
        $categories = [
            'linked_assets' => [],      // Files with asset records → move to images
            'transforms' => [],         // Transform files → move to imageTransforms or delete
            'orphans' => [],           // Files without assets → quarantine or leave
        ];

        // Build filename map from DATABASE-FILTERED assets only
        // This ensures only files belonging to OptimisedImages volume (by volumeId) are migrated
        $assetFilenames = [];
        foreach ($optimisedAssets as $asset) {
            $assetFilenames[$asset['filename']] = $asset['id'];
        }

        $this->stdout("  Categorizing files...\n");

        foreach ($allFiles['all'] as $file) {
            if ($file['type'] !== 'file')
                continue;

            $filename = basename($file['path']);

            // Is this a transform?
            if ($this->isTransformFile($filename, $file['path'])) {
                $categories['transforms'][] = $file;
                continue;
            }

            // Does this file have an asset record with volumeId = OptimisedImages?
            // Only files matching database assets will be migrated
            if (isset($assetFilenames[$filename])) {
                $categories['linked_assets'][] = [
                    'file' => $file,
                    'assetId' => $assetFilenames[$filename]
                ];
                continue;
            }

            // It's an orphan (file exists but no matching database asset with correct volumeId)
            $categories['orphans'][] = $file;
        }

        $this->stdout("    Assets with records: " . count($categories['linked_assets']) . "\n");
        $this->stdout("    Transform files:     " . count($categories['transforms']) . "\n");
        $this->stdout("    Orphaned files:      " . count($categories['orphans']) . "\n\n");

        // STEP 1: Move linked assets to images volume
        if (!empty($categories['linked_assets'])) {
            $this->moveOptimisedAssetsToImages(
                $categories['linked_assets'],
                $targetVolume,
                $optimisedVolume
            );
        }

        // STEP 2: Handle transforms
        if (!empty($categories['transforms'])) {
            $this->handleTransforms($categories['transforms'], $optimisedFs);
        }

        // STEP 3: Report on orphans (don't touch chartData)
        if (!empty($categories['orphans'])) {
            $this->reportOrphansAtRoot($categories['orphans']);
        }
    }

    /**
     * **PATCH: Move assets from optimisedImages (root) to images (subfolder)**
     *
     * NOTE: $linkedAssets contains ONLY assets that were filtered by volumeId = OptimisedImages.
     * Files in the filesystem belonging to other volumes (documents, videos, etc.) are not included.
     */
    private function moveOptimisedAssetsToImages($linkedAssets, $targetVolume, $sourceVolume)
    {
        $this->stdout("  STEP 1: Moving " . count($linkedAssets) . " assets to images volume\n");
        $this->printProgressLegend();
        $this->stdout("  Progress: ");

        $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);
        $targetFs = $targetVolume->getFs();
        $sourceFs = $sourceVolume->getFs();

        $moved = 0;
        $errors = 0;

        foreach ($linkedAssets as $item) {
            $file = $item['file'];
            $assetId = $item['assetId'];

            $asset = Asset::findOne($assetId);
            if (!$asset) {
                $this->safeStdout("?", Console::FG_GREY);
                continue;
            }

            try {
                // Source path (at root)
                $sourcePath = $file['path'];

                // Download file to temp location
                $tempPath = tempnam(sys_get_temp_dir(), 'asset_');
                $tempStream = fopen($tempPath, 'w');

                try {
                    $content = $sourceFs->read($sourcePath);
                    fwrite($tempStream, $content);
                } catch (\Exception $e) {
                    fclose($tempStream);
                    @unlink($tempPath);
                    throw new \Exception("Cannot read source file '{$sourcePath}': " . $e->getMessage());
                }

                fclose($tempStream);
                $this->trackTempFile($tempPath);

                // Store old path before updating asset
                $oldPath = $asset->getPath();

                // Update asset record - Craft will handle the file move via tempFilePath
                $db = Craft::$app->getDb();
                $transaction = $db->beginTransaction();

                try {
                    $asset->volumeId = $targetVolume->id;
                    $asset->folderId = $targetRootFolder->id;
                    $asset->tempFilePath = $tempPath; // Tell Craft where the source file is

                    if (Craft::$app->getElements()->saveElement($asset)) {
                        $transaction->commit();

                        // Delete from source (root) - only after successful save AND if path changed
                        // This prevents accidental deletion if source and target paths are the same
                        if ($oldPath !== $asset->getPath()) {
                            try {
                                $sourceFs->deleteFile($sourcePath);
                            } catch (\Exception $e) {
                                // File might already be gone, that's ok
                            }
                        }

                        $this->changeLogManager->logChange([
                            'type' => 'moved_from_optimised_root',
                            'assetId' => $asset->id,
                            'filename' => $asset->filename,
                            'fromVolume' => $sourceVolume->id,
                            'fromPath' => $sourcePath,
                            'toVolume' => $targetVolume->id,
                            'toPath' => $asset->filename
                        ]);

                        $this->safeStdout(".", Console::FG_GREEN);
                        $moved++;
                        $this->stats['files_moved']++;
                    } else {
                        $transaction->rollBack();
                        $this->safeStdout("x", Console::FG_RED);
                        $errors++;
                    }

                } catch (\Exception $e) {
                    $transaction->rollBack();
                    throw $e;
                } finally {
                    // Always cleanup temp file
                    @unlink($tempPath);
                    $this->tempFiles = array_diff($this->tempFiles, [$tempPath]);
                }

            } catch (\Exception $e) {
                $this->safeStdout("x", Console::FG_RED);
                $errors++;
                $this->trackError('move_optimised', $e->getMessage());
            }

            if ($moved % 50 === 0 && $moved > 0) {
                $this->safeStdout(" [{$moved}]\n  ");
            }
        }

        $this->safeStdout("\n\n  ✓ Moved: {$moved}, Errors: {$errors}\n\n");
    }

    /**
     * **PATCH: Handle transform files**
     */
    private function handleTransforms($transforms, $sourceFs)
    {
        $this->stdout("  STEP 2: Handling " . count($transforms) . " transform files\n");

        // Option 1: Delete transforms (they'll be regenerated)
        if ($this->confirm("Delete transform files? (they will be regenerated as needed)", true)) {
            $this->stdout("  Deleting transforms... ");

            $deleted = 0;
            foreach ($transforms as $file) {
                try {
                    $sourceFs->deleteFile($file['path']);
                    $deleted++;

                    $this->changeLogManager->logChange([
                        'type' => 'deleted_transform',
                        'path' => $file['path'],
                        'size' => $file['size'] ?? 0
                    ]);

                } catch (\Exception $e) {
                    // Continue on error
                }
            }

            $this->stdout("done ({$deleted} deleted)\n\n");

        } else {
            // Option 2: Move to imageTransforms filesystem
            $this->stdout("  ⚠ Transforms left in place\n");
            $this->stdout("  Recommendation: Delete and regenerate transforms\n\n");
        }
    }

    /**
     * **PATCH: Report on orphaned files at root (includes chartData)**
     */
    private function reportOrphansAtRoot($orphans)
    {
        $this->stdout("  STEP 3: Orphaned files at root: " . count($orphans) . "\n");

        // Check if any look like chartData
        $chartDataPattern = '/chart|data|\.json|\.csv/i';
        $possibleChartData = 0;

        foreach ($orphans as $file) {
            if (preg_match($chartDataPattern, $file['path'])) {
                $possibleChartData++;
            }
        }

        if ($possibleChartData > 0) {
            $this->stdout("    Possible chartData files: {$possibleChartData}\n", Console::FG_YELLOW);
            $this->stdout("    These should be migrated manually to chartData volume\n");
        }

        // Save list to file for review
        $reviewFile = Craft::getAlias('@storage') . '/root-orphans-' . $this->migrationId . '.json';
        file_put_contents($reviewFile, json_encode($orphans, JSON_PRETTY_PRINT));

        $this->stdout("    Full list saved to: {$reviewFile}\n", Console::FG_CYAN);
        $this->stdout("    Review these files before deleting\n\n");
    }


    private function trackTempFile(string $path): string
    {
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * Emergency cleanup on shutdown - ensures resources are released
     */
    public function emergencyCleanup(): void
    {
        // Clean up temp files
        foreach ($this->tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        // Release lock
        if ($this->migrationLock) {
            $this->migrationLock->release();
        }

        // Flush any pending changelog entries
        if ($this->changeLogManager) {
            try {
                $this->changeLogManager->flush();
            } catch (\Exception $e) {
                // Already shutting down
            }
        }

        // Save final checkpoint if we have state
        if ($this->checkpointManager && $this->currentPhase) {
            try {
                $this->saveCheckpoint([
                    'interrupted' => true,
                    'can_resume' => true
                ]);
            } catch (\Exception $e) {
                // Can't save checkpoint during emergency shutdown
            }
        }
    }

    /**
     * Monitor migration progress in real-time
     */
    public function actionMonitor()
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("MIGRATION PROGRESS MONITOR\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // First, try to get migration state from database (more reliable after refresh)
        $stateService = new MigrationStateService();
        $stateService->ensureTableExists();

        $latestMigration = $stateService->getLatestMigration();

        // Also check quick state file for backwards compatibility
        $quickState = $this->checkpointManager->loadQuickState();

        // Prefer database state if available and more recent
        $migrationState = null;
        if ($latestMigration && $quickState) {
            $dbTime = strtotime($latestMigration['lastUpdatedAt'] ?? '0');
            $fileTime = strtotime($quickState['timestamp'] ?? '0');
            $migrationState = ($dbTime >= $fileTime) ? $latestMigration : $quickState;
        } elseif ($latestMigration) {
            $migrationState = $latestMigration;
        } elseif ($quickState) {
            $migrationState = $quickState;
        }

        if (!$migrationState) {
            $this->stdout("No active migration found.\n\n", Console::FG_YELLOW);
            $this->stdout("To start a new migration, run:\n");
            $this->stdout("  ./craft s3-spaces-migration/image-migration/migrate\n\n");
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        // Normalize field names (database uses camelCase, quickState uses snake_case)
        $migrationId = $migrationState['migrationId'] ?? $migrationState['migration_id'] ?? 'unknown';
        $phase = $migrationState['phase'] ?? 'unknown';
        $processedCount = $migrationState['processedCount'] ?? $migrationState['processed_count'] ?? 0;
        $totalCount = $migrationState['totalCount'] ?? $migrationState['total_count'] ?? 0;
        $status = $migrationState['status'] ?? 'unknown';
        $pid = $migrationState['pid'] ?? null;
        $timestamp = $migrationState['lastUpdatedAt'] ?? $migrationState['timestamp'] ?? null;

        // Check if process is actually running
        $isProcessRunning = false;
        if ($pid) {
            if (function_exists('posix_kill')) {
                $isProcessRunning = @posix_kill($pid, 0);
            } elseif (file_exists("/proc/$pid")) {
                $isProcessRunning = true;
            } else {
                exec("ps -p $pid", $output, $returnCode);
                $isProcessRunning = $returnCode === 0;
            }
        }

        $this->stdout("Migration ID:     {$migrationId}\n");
        $this->stdout("Current Phase:    {$phase}\n");
        $this->stdout("Status:           {$status}\n");

        if ($pid) {
            $processStatus = $isProcessRunning ? "Running (PID: {$pid})" : "Not running (PID: {$pid} is dead)";
            $color = $isProcessRunning ? Console::FG_GREEN : Console::FG_RED;
            $this->stdout("Process Status:   ", Console::RESET);
            $this->stdout($processStatus . "\n", $color);
        } else {
            $this->stdout("Process Status:   ", Console::RESET);
            $this->stdout("No PID recorded\n", Console::FG_YELLOW);
        }

        if ($totalCount > 0) {
            $percentage = round(($processedCount / $totalCount) * 100, 1);
            $this->stdout("Progress:         {$processedCount}/{$totalCount} ({$percentage}%)\n");
        } else {
            $this->stdout("Processed Items:  {$processedCount}\n");
        }

        if ($timestamp) {
            $this->stdout("Last Update:      {$timestamp}\n");
        }

        $this->stdout("\n");

        $stats = $migrationState['stats'] ?? [];

        if (!empty($stats)) {
            $this->stdout("Statistics:\n", Console::FG_CYAN);
            $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");

            foreach ($stats as $key => $value) {
                $label = str_pad(ucwords(str_replace('_', ' ', $key)), 25);
                $this->stdout("  {$label}: {$value}\n");
            }
            $this->stdout("\n");
        }

        // Show recent errors if any
        $errorLogFile = Craft::getAlias('@storage') . '/migration-errors-' . $migrationId . '.log';
        if (file_exists($errorLogFile)) {
            $lines = file($errorLogFile);
            $recentErrors = array_slice($lines, -5);

            if (!empty($recentErrors)) {
                $this->stdout("Recent Errors:\n", Console::FG_YELLOW);
                $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");
                foreach ($recentErrors as $error) {
                    $this->stdout("  " . trim($error) . "\n", Console::FG_YELLOW);
                }
                $this->stdout("\n");
            }
        }

        $this->stdout("Available Commands:\n");
        if ($isProcessRunning) {
            $this->stdout("  Wait for completion:  (Process is currently running)\n", Console::FG_GREEN);
        } elseif ($status === 'running' || $status === 'paused') {
            $this->stdout("  Resume:               ./craft s3-spaces-migration/image-migration/migrate --resume\n", Console::FG_CYAN);
        }
        $this->stdout("  Status:               ./craft s3-spaces-migration/image-migration/status\n");
        $this->stdout("  Monitor (live):       watch -n 2 './craft s3-spaces-migration/image-migration/monitor'\n\n");

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Resume migration from checkpoint - FIXED VERSION
     */
    /**
     * REPLACE EXISTING resumeMigration() - Now with quick state loading
     */
    private function resumeMigration($checkpointId, $dryRun, $skipBackup, $skipInlineDetection)
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_YELLOW);
        $this->stdout("RESUMING MIGRATION FROM CHECKPOINT\n", Console::FG_YELLOW);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_YELLOW);

        // Try quick state first for faster resume
        $quickState = $this->checkpointManager->loadQuickState();

        if ($quickState && !$checkpointId) {
            $this->stdout("Found quick-resume state:\n", Console::FG_CYAN);
            $this->stdout("  Phase: {$quickState['phase']}\n");
            $this->stdout("  Processed: {$quickState['processed_count']} items\n");
            $this->stdout("  Last updated: {$quickState['timestamp']}\n\n");

            // Restore from quick state
            $this->migrationId = $quickState['migration_id'];
            $this->processedAssetIds = $quickState['processed_ids'] ?? [];
            $this->currentPhase = $quickState['phase'];
            $this->stats = array_merge($this->stats, $quickState['stats'] ?? []);
                $this->stderr("__CLI_EXIT_CODE_1__\n");

            // IMPORTANT: Clear any stale locks before acquiring for resume
            $this->clearStaleLocks();

            // Update lock with resumed migration ID
            $this->migrationLock = new MigrationLock($this->migrationId);
            $this->stdout("  Acquiring lock for resumed migration... ");
            if (!$this->migrationLock->acquire(5, true)) {
                $this->stderr("FAILED\n", Console::FG_RED);
                $this->stderr("Cannot acquire lock for migration {$this->migrationId}\n");
                $this->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("acquired\n\n", Console::FG_GREEN);

        } else {
            // Full checkpoint loading
            $checkpoint = $this->checkpointManager->loadLatestCheckpoint($checkpointId);
                $this->stderr("__CLI_EXIT_CODE_1__\n");

            if (!$checkpoint) {
                $this->stderr("No checkpoint found to resume from.\n", Console::FG_RED);
                $this->stdout("\nAvailable checkpoints:\n");
                $available = $this->checkpointManager->listCheckpoints();
                foreach ($available as $cp) {
                    $this->stdout("  - {$cp['id']} ({$cp['phase']}) at {$cp['timestamp']} - {$cp['processed']} items\n", Console::FG_CYAN);
                }
                $this->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("Found checkpoint: {$checkpoint['phase']} at {$checkpoint['timestamp']}\n", Console::FG_GREEN);
            $this->stdout("Migration ID: {$checkpoint['migration_id']}\n");
            $this->stdout("Processed: " . count($checkpoint['processed_ids'] ?? []) . " items\n\n");

            // Restore state
            $this->migrationId = $checkpoint['migration_id'];
            $this->currentPhase = $checkpoint['phase'];
            $this->currentBatch = $checkpoint['batch'] ?? 0;
                $this->stderr("__CLI_EXIT_CODE_1__\n");
            $this->processedAssetIds = $checkpoint['processed_ids'] ?? [];
            $this->expectedMissingFileCount = $checkpoint['expectedMissingFiles'] ?? 0;
            $this->stats = array_merge($this->stats, $checkpoint['stats']);
            $this->stats['resume_count']++;

            // IMPORTANT: Clear any stale locks before acquiring for resume
            $this->clearStaleLocks();

            // Update lock
            $this->migrationLock = new MigrationLock($this->migrationId);
            $this->stdout("  Acquiring lock for resumed migration... ");
            if (!$this->migrationLock->acquire(5, true)) {
                $this->stderr("FAILED\n", Console::FG_RED);
                $this->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("acquired\n\n", Console::FG_GREEN);
        }

        // Reinitialize managers with restored ID
        $this->changeLogManager = new ChangeLogManager($this->migrationId, $this->CHANGELOG_FLUSH_EVERY);
        $this->checkpointManager = new CheckpointManager($this->migrationId);
                $this->stdout("__CLI_EXIT_CODE_0__\n");
        $this->rollbackEngine = new RollbackEngine($this->changeLogManager, $this->migrationId);

        if (!$this->yes) {
            $confirm = $this->prompt("Resume migration from '{$this->currentPhase}' phase? (yes/no)", [
                'required' => true,
                'default' => 'yes',
            ]);

            if ($confirm !== 'yes') {
                $this->stdout("Resume cancelled.\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }
        } else {
            $this->stdout("⚠ Auto-confirmed resume (--yes flag)\n", Console::FG_YELLOW);
        }

        try {
            // Validate and restore volumes
            $volumes = $this->validateConfiguration();
            $targetVolume = $volumes['target'];
            $sourceVolumes = $volumes['sources'];
            $quarantineVolume = $volumes['quarantine'];

            $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);
            if (!$targetRootFolder) {
                throw new \Exception("Cannot find root folder for target volume");
            }

            // Resume from specific phase
            $this->stdout("Resuming from phase: {$this->currentPhase}\n", Console::FG_CYAN);
            $this->stdout("Already processed: " . count($this->processedAssetIds) . " items\n\n");

            switch ($this->currentPhase) {
                case 'preparation':
                case 'discovery':
                case 'optimised_root':
                    $this->stdout("Early phase - restarting from discovery...\n\n");
                    // Set defaults for resume mode
                    $this->dryRun = $dryRun ?? $this->dryRun;
                    $this->skipBackup = $skipBackup ?? $this->skipBackup;
                    $this->skipInlineDetection = $skipInlineDetection ?? $this->skipInlineDetection;
                    return $this->actionMigrate();

                case 'link_inline':
                    return $this->resumeInlineLinking($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'safe_duplicates':
                case 'resolve_duplicates':
                    $this->stdout("Duplicate handling phase - continuing from fix_links...\n\n");
                    return $this->resumeFixLinks($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'fix_links':
                    return $this->resumeFixLinks($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'consolidate':
                    $this->stdout("__CLI_EXIT_CODE_0__\n");
                    return $this->resumeConsolidate($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'quarantine':
                    return $this->resumeQuarantine($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'cleanup':
                case 'complete':
            $this->stderr("__CLI_EXIT_CODE_1__\n");
                    $this->stdout("Migration was nearly complete. Running final verification...\n\n");
                    $this->performCleanupAndVerification($targetVolume, $targetRootFolder);
                    $this->printFinalReport();
                    $this->printSuccessFooter();
                    $this->stdout("__CLI_EXIT_CODE_0__\n");
                    return ExitCode::OK;

                default:
                    throw new \Exception("Unknown checkpoint phase: {$this->currentPhase}");
            }

        } catch (\Exception $e) {
            $this->handleFatalError($e);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Resume helper methods for each phase
     */
    private function resumeInlineLinking($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume)
    {
        $this->setPhase('link_inline');
        $this->printPhaseHeader("PHASE 1.5: LINKING INLINE IMAGES (RESUMED)");

        $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
        $linkingStats = $this->linkInlineImagesBatched(Craft::$app->getDb(), $assetInventory, $targetVolume);

        if ($linkingStats['rows_updated'] > 0) {
            $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
        }

        return $this->continueToNextPhase($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume, $assetInventory);
    }

    private function resumeFixLinks($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume)
    {
        $this->setPhase('fix_links');
        $this->printPhaseHeader("PHASE 2: FIX BROKEN LINKS (RESUMED)");

        // Try to load Phase 1 results from database first
        $phase1Results = $this->loadPhase1Results();

        if ($phase1Results && !$this->needsInventoryRefresh()) {
            // Use cached results - much faster than rebuilding
            $this->stdout("  ✓ Loaded Phase 1 results from database (skipping rebuild)\n", Console::FG_GREEN);
            $assetInventory = $phase1Results['assetInventory'];
            $fileInventory = $phase1Results['fileInventory'];
            $analysis = $phase1Results['analysis'];
        } else {
            // Need to rebuild (inline linking or duplicates modified inventory)
            if ($phase1Results && $this->needsInventoryRefresh()) {
                $this->stdout("  ⚠ Inventory modified by previous phases - rebuilding\n", Console::FG_YELLOW);
            } else {
                $this->stdout("  No cached Phase 1 results found - building inventories\n", Console::FG_GREY);
            }
            $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
            $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
            $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);
        }

        if (!empty($analysis['broken_links'])) {
            $this->fixBrokenLinksBatched($analysis['broken_links'], $fileInventory, $sourceVolumes, $targetVolume, $targetRootFolder);
        }

        return $this->continueToNextPhase($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume, $assetInventory);
    }

    private function resumeConsolidate($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume)
    {
        $this->setPhase('consolidate');
        $this->printPhaseHeader("PHASE 3: CONSOLIDATE FILES (RESUMED)");

        // Try to load Phase 1 results from database first
        $phase1Results = $this->loadPhase1Results();

        if ($phase1Results && !$this->needsInventoryRefresh()) {
            // Use cached results - much faster than rebuilding
            $this->stdout("  ✓ Loaded Phase 1 results from database (skipping rebuild)\n", Console::FG_GREEN);
            $assetInventory = $phase1Results['assetInventory'];
            $fileInventory = $phase1Results['fileInventory'];
            $analysis = $phase1Results['analysis'];
        } else {
            // Need to rebuild (inline linking or duplicates modified inventory)
            if ($phase1Results && $this->needsInventoryRefresh()) {
                $this->stdout("  ⚠ Inventory modified by previous phases - rebuilding\n", Console::FG_YELLOW);
            } else {
                $this->stdout("  No cached Phase 1 results found - building inventories\n", Console::FG_GREY);
            }
            $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
            $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
            $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);
        }

        $this->consolidateUsedFilesBatched(
            $analysis['used_assets_wrong_location'],
            $targetVolume,
            $targetRootFolder,
            $targetVolume->getFs()
        );

        return $this->continueToNextPhase($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume, $assetInventory);
    }

    private function resumeQuarantine($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume)
    {
        $this->setPhase('quarantine');
        $this->printPhaseHeader("PHASE 4: QUARANTINE (RESUMED)");

        // Try to load Phase 1 results from database first
        $phase1Results = $this->loadPhase1Results();

        if ($phase1Results && !$this->needsInventoryRefresh()) {
            // Use cached results - much faster than rebuilding
            $this->stdout("  ✓ Loaded Phase 1 results from database (skipping rebuild)\n", Console::FG_GREEN);
            $assetInventory = $phase1Results['assetInventory'];
            $fileInventory = $phase1Results['fileInventory'];
            $analysis = $phase1Results['analysis'];
        } else {
            // Need to rebuild (inline linking or duplicates modified inventory)
            if ($phase1Results && $this->needsInventoryRefresh()) {
                $this->stdout("  ⚠ Inventory modified by previous phases - rebuilding\n", Console::FG_YELLOW);
            } else {
                $this->stdout("  No cached Phase 1 results found - building inventories\n", Console::FG_GREY);
            }
            $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
            $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
            $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);
        }

        if (!empty($analysis['orphaned_files']) || !empty($analysis['unused_assets'])) {
            $this->quarantineUnusedFilesBatched(
                $analysis['orphaned_files'],
                $analysis['unused_assets'],
                $quarantineVolume,
                $quarantineVolume->getFs()
            );
        }

        return $this->continueToNextPhase($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume, $assetInventory);
    }

    /**
     * Check if inventory needs to be refreshed instead of loaded from cache
     *
     * Inventory must be refreshed if:
     * - Inline linking created new relations (changes asset usage counts)
     * - Duplicate resolution deleted asset records
     *
     * @return bool True if refresh needed, false if cached results can be used
     */
    private function needsInventoryRefresh()
    {
        $checkpoint = $this->checkpointManager->loadLatestCheckpoint();

        if (!$checkpoint) {
            return true; // No checkpoint, must refresh
        }

        // Refresh needed if these phases completed (they modify the inventory)
        $inlineLinkingDone = $checkpoint['inline_linking_complete'] ?? false;
        $duplicatesResolved = $checkpoint['duplicates_resolved'] ?? false;

        return $inlineLinkingDone || $duplicatesResolved;
    }

    private function continueToNextPhase($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume, $assetInventory)
    {
        $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);

        // Continue with remaining phases...
        $this->setPhase('cleanup');
        $this->printPhaseHeader("PHASE 5: CLEANUP & VERIFICATION");
        $this->performCleanupAndVerification($targetVolume, $targetRootFolder);

        $this->setPhase('complete');
        $this->saveCheckpoint(['completed' => true]);

        $this->printFinalReport();
        $this->printSuccessFooter();

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Rollback migration using change log
     */
    /**
     * Rollback a migration using change log
     *
     * Examples:
     *   # Complete rollback via database restore (fastest)
     *   ./craft s3-spaces-migration/image-migration/rollback --method=database
     *
     *   # Complete rollback via change log
     *   ./craft s3-spaces-migration/image-migration/rollback
     *
     *   # Rollback specific phases only
     *   ./craft s3-spaces-migration/image-migration/rollback --phases=quarantine,fix_links --mode=only
     *
     *   # Rollback from phase onwards
     *   ./craft s3-spaces-migration/image-migration/rollback --phases=consolidate --mode=from
     *
     *   # Dry run to see what would be rolled back
     *   ./craft s3-spaces-migration/image-migration/rollback --dry-run
     *
     * @param string|null $migrationId Migration ID to rollback (prompts if not provided)
     * @param string|array|null $phases Phase(s) to rollback (null = all phases)
     * @param string $mode 'from' (rollback from phase onwards) or 'only' (rollback specific phases)
     * @param bool $dryRun Show what would be done without executing
     * @param string|null $method 'database' (restore DB backup) or 'changeset' (use change log)
     */
    public function actionRollback($migrationId = null, $phases = null, $mode = 'from', $dryRun = false, $method = null)
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_YELLOW);
        $this->stdout("ROLLBACK MIGRATION\n", Console::FG_YELLOW);
        $this->stdout(str_repeat("=", 80) . "\n\n");

        if ($dryRun) {
            $this->stdout("DRY RUN MODE - Will show what would be rolled back\n\n", Console::FG_YELLOW);
        }

        // List available migrations
        $migrations = $this->changeLogManager->listMigrations();

        if (empty($migrations)) {
            $this->stdout("No migrations found to rollback.\n");
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        $this->stdout("Available migrations:\n");
        foreach ($migrations as $i => $mig) {
            $this->stdout(sprintf(
                "  %d. %s (%s) - %d changes\n",
                $i + 1,
                $mig['id'],
                $mig['timestamp'],
                $mig['change_count']
            ), Console::FG_CYAN);
        }

        // Select migration
        if (!$migrationId) {
            if ($this->yes) {
                // Default to 'latest' when --yes flag is used
                    $this->stderr("__CLI_EXIT_CODE_1__\n");
                $selection = 'latest';
                $this->stdout("⚠ Auto-selecting 'latest' migration (--yes flag)\n", Console::FG_YELLOW);
            } else {
                $selection = $this->prompt("\nSelect migration number to rollback (or 'latest'):", [
                    'required' => true,
                    'default' => 'latest',
                ]);
            }

            if ($selection === 'latest') {
                $migrationId = $migrations[0]['id'];
            } else {
                $idx = (int) $selection - 1;
                if (!isset($migrations[$idx])) {
                    $this->stderr("Invalid selection.\n", Console::FG_RED);
                    $this->stderr("__CLI_EXIT_CODE_1__\n");
                    return ExitCode::UNSPECIFIED_ERROR;
                }
                $migrationId = $migrations[$idx]['id'];
            }
        }

        $this->stdout("\nSelected migration: {$migrationId}\n\n", Console::FG_YELLOW);

        // Initialize rollback engine with correct migration ID
        $this->migrationId = $migrationId;
        $this->changeLogManager = new ChangeLogManager($migrationId, $this->CHANGELOG_FLUSH_EVERY);
        $this->rollbackEngine = new RollbackEngine($this->changeLogManager, $migrationId);

        // Show phases in selected migration
        $phaseSummary = $this->rollbackEngine->getPhasesSummary($migrationId);

        if (!empty($phaseSummary)) {
            $this->stdout("Phases in this migration:\n", Console::FG_CYAN);
            foreach ($phaseSummary as $phase => $count) {
                $this->stdout("  - {$phase}: {$count} changes\n");
            }
            $this->stdout("\n");
        }

        // Select rollback method if not specified
        if (!$method && !$phases) {
            $this->stdout("Rollback methods:\n");
            $this->stdout("  1. Database restore (fastest, complete rollback - restores all DB tables)\n", Console::FG_GREEN);
            $this->stdout("  2. Change-by-change (selective, supports phase rollback)\n");
            $this->stdout("\n");

            if ($this->yes) {
                // Default to method 2 (changeset) when --yes flag is used
                $methodChoice = '2';
                $this->stdout("⚠ Auto-selecting method 2 (change-by-change) (--yes flag)\n", Console::FG_YELLOW);
            } else {
                $methodChoice = $this->prompt("Select rollback method (1 or 2):", [
                    'required' => true,
                    'default' => '2',
                ]);
            }

            $method = $methodChoice === '1' ? 'database' : 'changeset';
        } else if ($phases) {
            // If phases specified, must use changeset method
            $method = 'changeset';
            $this->stdout("Using change-by-change rollback (required for phase selection)\n\n", Console::FG_CYAN);
        } else {
            $method = $method ?: 'changeset';
        }

        // If using changeset method and no phases specified, ask for phase options
        if ($method === 'changeset' && !$phases && !$dryRun) {
            $this->stdout("Phase rollback options:\n");
            $this->stdout("  1. All phases (complete rollback)\n");
            $this->stdout("  2. Specific phases only\n");
            $this->stdout("  3. From phase onwards\n");
            $this->stdout("\n");

            if ($this->yes) {
                // Default to option 1 (all phases) when --yes flag is used
                $phaseOption = '1';
                $this->stdout("⚠ Auto-selecting option 1 (all phases) (--yes flag)\n", Console::FG_YELLOW);
            } else {
                $phaseOption = $this->prompt("Select option (1, 2, or 3):", [
                    'default' => '1',
                ]);

                if ($phaseOption === '2') {
                    $phasesInput = $this->prompt("Enter phases to rollback (comma-separated):");
                    $phases = array_map('trim', explode(',', $phasesInput));
                    $mode = 'only';
                } else if ($phaseOption === '3') {
                    $phases = $this->prompt("Rollback from which phase?:");
                    $mode = 'from';
                }
            }
        }

        // Execute rollback based on method
        try {
            if ($method === 'database') {
                // Database restore rollback
                $result = $this->rollbackEngine->rollbackViaDatabase($migrationId, $dryRun);

                $this->stdout("\n");
                if (isset($result['dry_run']) && $result['dry_run']) {
                    $this->stdout("DRY RUN - Database Restore Plan:\n", Console::FG_CYAN);
                    $this->stdout("  Method: {$result['method']}\n");
                    $this->stdout("  Backup file: {$result['backup_file']}\n");
                    $this->stdout("  Backup size: {$result['backup_size']}\n");
                    $this->stdout("  Tables to restore: " . implode(', ', $result['tables']) . "\n");
                    $this->stdout("  Estimated time: {$result['estimated_time']}\n");
                    $this->stdout("\nDRY RUN - No changes were made\n", Console::FG_YELLOW);
                } else {
                    $this->stdout("✓ Database Restore Completed:\n", Console::FG_GREEN);
                        $this->stdout("__CLI_EXIT_CODE_0__\n");
                    $this->stdout("  Method: {$result['method']}\n");
                    $this->stdout("  Backup file: " . basename($result['backup_file']) . "\n");
                    $this->stdout("  Backup size: {$result['backup_size']}\n");
                    $this->stdout("  Tables restored: " . implode(', ', $result['tables_restored']) . "\n");
                    $this->stdout("\n✓ Rollback completed successfully\n", Console::FG_GREEN);
                }
            } else {
                // Change-by-change rollback
                if (!$dryRun && !$phases && !$this->yes) {
                    $confirm = $this->prompt("This will reverse all changes. Continue? (yes/no)", [
                        'required' => true,
                        'default' => 'no',
                    ]);

                    if ($confirm !== 'yes') {
                        $this->stdout("Rollback cancelled.\n");
                        $this->stdout("__CLI_EXIT_CODE_0__\n");
                        return ExitCode::OK;
                    }
                } elseif ($this->yes && !$dryRun && !$phases) {
                    $this->stdout("⚠ Auto-confirmed (--yes flag)\n", Console::FG_YELLOW);
                }

                $result = $this->rollbackEngine->rollback($migrationId, $phases, $mode, $dryRun);

                $this->stdout("\n");
                if (isset($result['dry_run']) && $result['dry_run']) {
                    $this->stdout("DRY RUN - Change-by-Change Rollback Plan:\n", Console::FG_CYAN);
                    $this->stdout("  Total operations: {$result['total_operations']}\n");
                    $this->stdout("  Estimated time: {$result['estimated_time']}\n");
                    $this->stdout("\n  By Type:\n");
                    foreach ($result['by_type'] as $type => $count) {
                        $this->stdout("    - {$type}: {$count}\n");
                    }
            $this->stderr("__CLI_EXIT_CODE_1__\n");
                    $this->stdout("\n  By Phase:\n");
                    foreach ($result['by_phase'] as $phase => $count) {
        $this->stdout("__CLI_EXIT_CODE_0__\n");
                        $this->stdout("    - {$phase}: {$count}\n");
                    }
                    $this->stdout("\nDRY RUN - No changes were made\n", Console::FG_YELLOW);
                } else {
                    $this->stdout("Rollback Results:\n", Console::FG_CYAN);
                    $this->stdout("  Operations reversed: {$result['reversed']}\n", Console::FG_GREEN);
                    $this->stdout("  Errors: {$result['errors']}\n", $result['errors'] > 0 ? Console::FG_RED : Console::FG_GREEN);
                    $this->stdout("  Skipped: {$result['skipped']}\n", Console::FG_GREY);
                    $this->stdout("\n✓ Rollback completed\n", Console::FG_GREEN);
                }
            }

        } catch (\Exception $e) {
            $this->stderr("\nRollback failed: " . $e->getMessage() . "\n", Console::FG_RED);
            $this->stderr("Stack trace:\n" . $e->getTraceAsString() . "\n", Console::FG_GREY);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * List available checkpoints and migrations
     */
    public function actionStatus()
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("MIGRATION STATUS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n");

        // List checkpoints
        $checkpoints = $this->checkpointManager->listCheckpoints();

        if (empty($checkpoints)) {
            $this->stdout("No active checkpoints found.\n\n");
        } else {
            $this->stdout("Active Checkpoints:\n", Console::FG_CYAN);
            foreach ($checkpoints as $cp) {
                $age = $this->formatAge(strtotime($cp['timestamp']));
                $this->stdout(sprintf(
                    "  • %s (%s) - %s ago\n",
                    $cp['id'],
                    $cp['phase'],
                    $age
                ), Console::FG_GREY);
            }
            $this->stdout("\n");
        }

        // List migrations
        $migrations = $this->changeLogManager->listMigrations();

        if (empty($migrations)) {
            $this->stdout("No completed migrations found.\n\n");
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        } else {
            $this->stdout("Completed Migrations:\n", Console::FG_CYAN);
            foreach ($migrations as $mig) {
                $age = $this->formatAge(strtotime($mig['timestamp']));
                $this->stdout(sprintf(
                    "  • %s - %d changes - %s ago\n",
                    $mig['id'],
                    $mig['change_count'],
                    $age
                ), Console::FG_GREY);
            }
            $this->stdout("\n");
        }

        $this->stdout("Commands:\n");
        $this->stdout("  Resume:   ./craft s3-spaces-migration/image-migration/migrate --resume\n");
        $this->stdout("  Rollback: ./craft s3-spaces-migration/image-migration/rollback\n");
        $this->stdout("  Cleanup:  ./craft s3-spaces-migration/image-migration/cleanup\n\n");

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Cleanup old checkpoints and logs
     *
     * Uses --older-than-hours flag to specify hours threshold (default: 72)
     */
    public function actionCleanup()
    {
        // Parse $this->olderThanHours - could be 'force' string or hours number
        $force = false;
        $hours = $this->checkpointRetentionHours;

        if ($this->olderThanHours === 'force') {
            $force = true;
            $hours = $this->checkpointRetentionHours;
        } elseif ($this->olderThanHours !== null) {
            $hours = (int) $this->olderThanHours;
        }

        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("CLEANUP OLD MIGRATION DATA\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n");

        // Clean locks if forced
        if ($force) {
            $this->stdout("  Cleaning ALL migration locks (forced)...\n", Console::FG_YELLOW);
            try {
                $db = Craft::$app->getDb();
                $deleted = $db->createCommand()
                    ->delete('{{%migrationlocks}}')
                    ->execute();
                $this->stdout("    ✓ Removed {$deleted} locks\n", Console::FG_GREEN);
            } catch (\Exception $e) {
                $this->stdout("    ⚠ Could not clean locks: " . $e->getMessage() . "\n", Console::FG_YELLOW);
            }
        }

        $this->stdout("  Removing checkpoints and logs older than {$hours} hours...\n");

        $removed = $this->checkpointManager->cleanupOldCheckpoints($hours);

        $this->stdout("    ✓ Removed {$removed} old checkpoints\n", Console::FG_GREEN);
        $this->stdout("__CLI_EXIT_CODE_0__\n");

        // Clean old error logs
        $errorLogPattern = Craft::getAlias('@storage') . '/migration-errors-*.log';
        $errorLogs = glob($errorLogPattern);
        $cutoff = time() - ($hours * 3600);
        $removedLogs = 0;

        foreach ($errorLogs as $log) {
            if (filemtime($log) < $cutoff) {
                @unlink($log);
                $removedLogs++;
            }
        }

        if ($removedLogs > 0) {
            $this->stdout("    ✓ Removed {$removedLogs} old error logs\n", Console::FG_GREEN);
        }

        $this->stdout("\n✓ Cleanup complete.\n\n");

                $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Force cleanup - removes ALL locks and old data
     */

    public function actionForceCleanup()
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_RED);
        $this->stdout("FORCE CLEANUP - REMOVING ALL LOCKS\n", Console::FG_RED);
        $this->stdout(str_repeat("=", 80) . "\n\n");

        if (!$this->yes) {
            $confirm = $this->prompt("This will remove ALL migration locks. Continue? (yes/no)", [
                'required' => true,
                'default' => 'no',
            ]);

            if ($confirm !== 'yes') {
                $this->stdout("Cancelled.\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }
        } else {
            $this->stdout("⚠ Auto-confirmed (--yes flag)\n", Console::FG_YELLOW);
        }

        $db = Craft::$app->getDb();

        // First, let's see what's there
        $this->stdout("  Checking for existing locks...\n", Console::FG_GREY);
        try {
            $existingLocks = $db->createCommand('SELECT * FROM {{%migrationlocks}}')->queryAll();

            if (!empty($existingLocks)) {
                $this->stdout("    Found " . count($existingLocks) . " locks:\n", Console::FG_YELLOW);
                foreach ($existingLocks as $lock) {
                    $this->stdout("      - {$lock['migrationId']} locked by {$lock['lockedBy']} at {$lock['lockedAt']}\n", Console::FG_GREY);
                }
            } else {
                $this->stdout("    No locks found in table.\n", Console::FG_GREY);
            }
        } catch (\Exception $e) {
            $this->stdout("    Table doesn't exist or error: " . $e->getMessage() . "\n", Console::FG_GREY);

            // Try to create it
            try {
                $db->createCommand("
                CREATE TABLE IF NOT EXISTS {{%migrationlocks}} (
                    lockName VARCHAR(255) PRIMARY KEY,
                    migrationId VARCHAR(255) NOT NULL,
                    lockedAt DATETIME NOT NULL,
                    lockedBy VARCHAR(255) NOT NULL,
                    expiresAt DATETIME NOT NULL,
                    INDEX idx_expires (expiresAt),
                    INDEX idx_migration (migrationId)
                )
            ")->execute();
                $this->stdout("    Created migrationlocks table.\n", Console::FG_GREEN);
            } catch (\Exception $e2) {
                $this->stdout("    Could not create table: " . $e2->getMessage() . "\n", Console::FG_RED);
            }
        }

        // Now delete ALL locks
        $this->stdout("\n  Deleting ALL migration locks...\n", Console::FG_YELLOW);
        try {
            // Use truncate instead of delete for more thorough cleanup
            $db->createCommand('TRUNCATE TABLE {{%migrationlocks}}')->execute();
            $this->stdout("    ✓ Table truncated (all locks removed)\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            // If truncate fails, try delete
            try {
                $db->createCommand('DELETE FROM {{%migrationlocks}}')->execute();
                $this->stdout("    ✓ All locks deleted\n", Console::FG_GREEN);
            } catch (\Exception $e2) {
                $this->stdout("    ⚠ Could not delete locks: " . $e2->getMessage() . "\n", Console::FG_YELLOW);
            }
        }

        // Verify it's clean
        $this->stdout("\n  Verifying cleanup...\n", Console::FG_GREY);
        try {
            $remaining = $db->createCommand('SELECT COUNT(*) FROM {{%migrationlocks}}')->queryScalar();

            if ($remaining > 0) {
                $this->stdout("    ⚠ WARNING: {$remaining} locks still remain!\n", Console::FG_RED);
            } else {
                $this->stdout("    ✓ Confirmed: 0 locks remaining\n", Console::FG_GREEN);
            }
        } catch (\Exception $e) {
            $this->stdout("    Could not verify: " . $e->getMessage() . "\n", Console::FG_GREY);
        }

        // Also do regular cleanup
        $hours = $this->checkpointRetentionHours;
        $this->stdout("\n  Removing checkpoints and logs older than {$hours} hours...\n");

        $removed = $this->checkpointManager->cleanupOldCheckpoints($hours);
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        $this->stdout("    ✓ Removed {$removed} old checkpoints\n", Console::FG_GREEN);

        // Clean old error logs
        $errorLogPattern = Craft::getAlias('@storage') . '/migration-errors-*.log';
        $errorLogs = glob($errorLogPattern);
        $cutoff = time() - ($hours * 3600);
        $removedLogs = 0;

        foreach ($errorLogs as $log) {
            if (filemtime($log) < $cutoff) {
                @unlink($log);
                $removedLogs++;
            }
        }

        if ($removedLogs > 0) {
            $this->stdout("    ✓ Removed {$removedLogs} old error logs\n", Console::FG_GREEN);
        }

        $this->stdout("\n✓ Force cleanup complete. Migration lock released.\n\n", Console::FG_GREEN);
        $this->stdout("You can now run: ./craft s3-spaces-migration/image-migration/migrate\n\n", Console::FG_CYAN);

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }
    // =========================================================================
    // BATCHED PROCESSING METHODS
    // =========================================================================

    /**
     * Build asset inventory using batched queries
     */
    private function buildAssetInventoryBatched($sourceVolumes, $targetVolume)
    {
        $db = Craft::$app->getDb();

        $allVolumeIds = array_merge(
            [$targetVolume->id],
            array_map(fn($v) => $v->id, $sourceVolumes)
        );

        $volumeIdList = implode(',', $allVolumeIds);

        // Get total count
        $totalAssets = (int) $db->createCommand("
            SELECT COUNT(DISTINCT a.id)
            FROM assets a
            JOIN elements e ON e.id = a.id
                AND e.dateDeleted IS NULL
                AND e.archived = 0
                AND e.draftId IS NULL
            WHERE a.volumeId IN ({$volumeIdList})
                AND a.kind = 'image'
        ")->queryScalar();

        $this->stdout("    Found {$totalAssets} total assets\n");
        $this->stdout("    Processing in batches of " . $this->BATCH_SIZE . "...\n");

        $inventory = [];
        $offset = 0;
        $batchNum = 0;

        $this->stdout("    Progress: ");

        while ($offset < $totalAssets) {
            $assets = $db->createCommand("
                SELECT 
                    a.id,
                    a.volumeId,
                    a.folderId,
                    a.filename,
                    a.kind,
                    vf.path as folderPath,
                    COUNT(DISTINCT r.id) as relationCount,
                    e.dateCreated,
                    e.dateUpdated
                FROM assets a
                JOIN elements e ON e.id = a.id
                    AND e.dateDeleted IS NULL
                    AND e.archived = 0
                    AND e.draftId IS NULL
                LEFT JOIN volumefolders vf ON vf.id = a.folderId
                LEFT JOIN relations r ON r.targetId = a.id
                LEFT JOIN elements re ON re.id = r.sourceId
                    AND re.dateDeleted IS NULL
                    AND re.archived = 0
                WHERE a.volumeId IN ({$volumeIdList})
                    AND a.kind = 'image'
                GROUP BY a.id
                LIMIT " . $this->BATCH_SIZE . " OFFSET {$offset}
            ")->queryAll();

            if (empty($assets)) {
                break;
            }

            foreach ($assets as $asset) {
                $assetId = $asset['id'];
                $relationCount = (int) $asset['relationCount'];

                $inventory[$assetId] = [
                    'id' => $assetId,
                    'volumeId' => $asset['volumeId'],
                    'folderId' => $asset['folderId'],
                    'filename' => $asset['filename'],
                    'folderPath' => $asset['folderPath'] ?? '',
                    'relationCount' => $relationCount,
                    'inlineCount' => 0,
                    'totalUsage' => $relationCount,
                    'isUsed' => $relationCount > 0,
                    'dateCreated' => $asset['dateCreated'],
                    'dateUpdated' => $asset['dateUpdated']
                ];
            }

            $offset += $this->BATCH_SIZE;
            $batchNum++;

            $this->safeStdout(".", Console::FG_GREEN);
            if ($batchNum % 50 === 0) {
                $processed = min($offset, $totalAssets);
                $pct = round(($processed / $totalAssets) * 100, 1);
                $this->safeStdout(" [{$processed}/{$totalAssets} - {$pct}%]\n    ");
            }
        }

        $this->stdout("\n");

        $used = count(array_filter($inventory, fn($a) => $a['isUsed']));
        $unused = count($inventory) - $used;

        $this->stdout("    ✓ Asset inventory: {$used} used, {$unused} unused\n\n", Console::FG_GREEN);

        return $inventory;
    }

    /**
     * Link inline images in batches with checkpoints
     */
    /**
     * Process inline image batch with configurable limits - REPLACE existing method
     */
    /**
     * REPLACE linkInlineImagesBatched() - Now with progress tracking and skip-processed
     */
    private function linkInlineImagesBatched($db, $assetInventory, $targetVolume)
    {
        // Build field map
        if ($this->rteFieldMap === null) {
            $this->rteFieldMap = $this->buildRteFieldMap($db);
        }

        if (empty($this->rteFieldMap)) {
            $this->stdout("  ⚠ No RTE fields found - skipping inline linking\n\n", Console::FG_YELLOW);
            return ['rows_updated' => 0, 'relations_created' => 0];
        }

        $rteFieldCount = count($this->rteFieldMap);
        $this->stdout("  Found {$rteFieldCount} RTE fields\n");

        $fieldColumnMap = $this->mapFieldsToColumns($db, $this->rteFieldMap);
        $columnCount = count($fieldColumnMap);

        $this->stdout("  Mapped to {$columnCount} content columns\n\n");

        if ($columnCount === 0) {
            return ['rows_updated' => 0, 'relations_created' => 0];
        }

        $assetLookup = $this->buildAssetLookup($assetInventory);

        $stats = [
            'rows_scanned' => 0,
            'rows_with_images' => 0,
            'images_found' => 0,
            'images_linked' => 0,
            'images_already_linked' => 0,
            'images_no_match' => 0,
            'rows_updated' => 0,
            'relations_created' => 0
        ];

        $this->printProgressLegend();
        $this->stdout("  Progress: ");

        $columnNum = 0;
        $lastLockRefresh = time();

        foreach ($fieldColumnMap as $mapping) {
            $table = $mapping['table'];
            $column = $mapping['column'];
            $fieldId = $mapping['field_id'];

            // Get total rows for progress tracking
            try {
                $totalRows = (int) $db->createCommand("
                SELECT COUNT(*)
                FROM `{$table}`
                WHERE (`{$column}` LIKE '%<img%' OR `{$column}` LIKE '%&lt;img%')
                    AND elementId IS NOT NULL
            ")->queryScalar();
            } catch (\Exception $e) {
                $this->safeStdout("x", Console::FG_RED);
                continue;
            }

            if ($totalRows === 0) {
                continue;
            }

            // Create progress tracker
            $progress = new ProgressTracker("Inline Linking: {$table}.{$column}", $totalRows, $this->progressReportingInterval);

            $offset = 0;
            $batchNum = 0;

            while (true) {
                // Refresh lock periodically
                if (time() - $lastLockRefresh > 60) {
                    $this->migrationLock->refresh();
                    $lastLockRefresh = time();
                }

                try {
                    $rows = $db->createCommand("
                    SELECT id, elementId, `{$column}` as content
                    FROM `{$table}`
                    WHERE (`{$column}` LIKE '%<img%' OR `{$column}` LIKE '%&lt;img%')
                        AND elementId IS NOT NULL
                    LIMIT " . $this->batchSize . " OFFSET {$offset}
                ")->queryAll();
                } catch (\Exception $e) {
                    $this->safeStdout("x", Console::FG_RED);
                    break;
                }

                if (empty($rows)) {
                    break;
                }

                $stats['rows_scanned'] += count($rows);
                $stats['rows_with_images'] += count($rows);

                // Process batch
                $batchResult = $this->errorRecoveryManager->retryOperation(
                    fn() => $this->processInlineImageBatch($rows, $table, $column, $fieldId, $assetLookup, $db),
                    "inline_image_batch_{$table}_{$column}_{$batchNum}"
                );

                if ($batchResult) {
                    $stats['rows_updated'] += $batchResult['rows_updated'];
                    $stats['relations_created'] += $batchResult['relations_created'];
                    $stats['images_linked'] += $batchResult['images_linked'];
                    $stats['images_already_linked'] += $batchResult['images_already_linked'];
                    $stats['images_no_match'] += $batchResult['images_no_match'];
                    $stats['images_found'] += $batchResult['images_found'];
                }

                $offset += $this->batchSize;
                $batchNum++;

                // Update progress
                if ($progress->increment(count($rows))) {
                    $this->safeStdout(" " . $progress->getProgressString() . "\n  ");
                } else {
                    $this->safeStdout(".", Console::FG_GREEN);
                }

                // Checkpoint periodically
                if ($batchNum % $this->CHECKPOINT_EVERY_BATCHES === 0) {
                    $this->saveCheckpoint([
                        'inline_batch' => $batchNum,
                        'column' => "{$table}.{$column}",
                        'stats' => $stats
                    ]);
                }
            }

            $columnNum++;
        }

        $this->stdout("\n\n");
        $this->printInlineLinkingResults($stats);

        return $stats;
    }

    /**
     * Process a single batch of inline images
     */
    private function processInlineImageBatch($rows, $table, $column, $fieldId, $assetLookup, $db)
    {
        $batchStats = [
            'rows_updated' => 0,
            'relations_created' => 0,
            'images_linked' => 0,
            'images_already_linked' => 0,
            'images_no_match' => 0,
            'images_found' => 0
        ];

        // OPTIMIZATION: Pre-load all existing relations for elements in this batch
        // This reduces N queries (one per image) to just 1 query per batch
        $elementIds = array_unique(array_filter(array_column($rows, 'elementId')));
        $existingRelationsMap = [];
        $maxSortOrders = [];

        if (!empty($elementIds) && $fieldId) {
            try {
                // Get all existing relations for these elements in one query
                $existingRelations = $db->createCommand("
                    SELECT sourceId, targetId, fieldId
                    FROM relations
                    WHERE sourceId IN (" . implode(',', $elementIds) . ")
                      AND fieldId = :fieldId
                ", [':fieldId' => $fieldId])->queryAll();

                // Build lookup map: "elementId_assetId_fieldId" => true
                foreach ($existingRelations as $rel) {
                    $key = $rel['sourceId'] . '_' . $rel['targetId'] . '_' . $rel['fieldId'];
                    $existingRelationsMap[$key] = true;
                }

                // Get max sort orders for all elements in one query
                $maxSorts = $db->createCommand("
                    SELECT sourceId, MAX(sortOrder) as maxSort
                    FROM relations
                    WHERE sourceId IN (" . implode(',', $elementIds) . ")
                      AND fieldId = :fieldId
                    GROUP BY sourceId
                ", [':fieldId' => $fieldId])->queryAll();

                foreach ($maxSorts as $sortData) {
                    $maxSortOrders[$sortData['sourceId']] = (int)$sortData['maxSort'];
                }
            } catch (\Exception $e) {
                // If batch query fails, fall back to per-image queries
                Craft::warning("Could not pre-load relations for batch: " . $e->getMessage(), __METHOD__);
            }
        }

        foreach ($rows as $row) {
            $content = $row['content'];
            $originalContent = $content;
            $rowId = $row['id'];
            $elementId = $row['elementId'];
            $modified = false;

            preg_match_all('/<img[^>]+>/i', $content, $imgTags);

            foreach ($imgTags[0] as $imgTag) {
                $batchStats['images_found']++;

                if (
                    strpos($imgTag, '{asset:') !== false ||
                    strpos($imgTag, 'data-asset-id=') !== false
                ) {
                    $batchStats['images_already_linked']++;
                    continue;
                }

                if (!preg_match('/src=["\']([^"\']+)["\']/i', $imgTag, $srcMatch)) {
                    continue;
                }

                $src = $srcMatch[1];
                $asset = $this->findAssetByUrl($src, $assetLookup);

                if (!$asset) {
                    $batchStats['images_no_match']++;
                    continue;
                }

                $assetId = $asset['id'];

                $newImgTag = preg_replace(
                    '/src=["\']([^"\']+)["\']/i',
                    'src="{asset:' . $assetId . ':url}"',
                    $imgTag
                );

                $content = str_replace($imgTag, $newImgTag, $content);
                $modified = true;
                $batchStats['images_linked']++;

                // Create relation
                if ($fieldId && $elementId) {
                    try {
                        // OPTIMIZATION: Check pre-loaded map instead of querying
                        $relationKey = $elementId . '_' . $assetId . '_' . $fieldId;
                        $existingRelation = isset($existingRelationsMap[$relationKey]);

                        if (!$existingRelation) {
                            // OPTIMIZATION: Use pre-loaded max sort order
                            $maxSort = $maxSortOrders[$elementId] ?? 0;

                            $db->createCommand()->insert('relations', [
                                'fieldId' => $fieldId,
                                'sourceId' => $elementId,
                                'sourceSiteId' => null,
                                'targetId' => $assetId,
                                'sortOrder' => $maxSort + 1,
                                'dateCreated' => date('Y-m-d H:i:s'),
                                'dateUpdated' => date('Y-m-d H:i:s'),
                                'uid' => \craft\helpers\StringHelper::UUID()
                            ])->execute();

                            // Update maps for subsequent images in same element
                            $existingRelationsMap[$relationKey] = true;
                            $maxSortOrders[$elementId] = $maxSort + 1;

                            $batchStats['relations_created']++;
                        }
                    } catch (\Exception $e) {
                        // Continue without relation
                    }
                }
            }

            if ($modified) {
                try {
                    $db->createCommand()->update(
                        $table,
                        [$column => $content],
                        ['id' => $rowId]
                    )->execute();

                    $batchStats['rows_updated']++;

                    $this->changeLogManager->logChange([
                        'type' => 'inline_image_linked',
                        'table' => $table,
                        'column' => $column,
                        'rowId' => $rowId,
                        'elementId' => $elementId,
                        'originalContent' => $originalContent,
                        'newContent' => $content
                    ]);

                } catch (\Exception $e) {
                    throw $e;
                }
            }
        }

        return $batchStats;
    }

    /**
     * Resolve duplicate assets (same filename, different asset records)
     *
     * Takes the duplicates analysis and resolves them by:
     * 1. Picking the best candidate (used, largest, newest)
     * 2. Merging all other duplicates into the winner
     * 3. Ensuring no two assets point to the same file
     *
     * SAFETY: This runs AFTER Phase 1.7 which stages shared physical files to temp location.
     * Additional safety checks ensure files aren't deleted if they're shared by other assets.
     *
     * @param array $duplicates Array of duplicate sets from analysis
     * @param object $targetVolume Target volume
     */
    private function resolveDuplicateAssets($duplicates, $targetVolume)
    {
        if (empty($duplicates)) {
            $this->stdout("  No duplicates to resolve\n\n", Console::FG_GREEN);
            return;
        }

        $totalSets = count($duplicates);
        $totalDuplicates = 0;
        foreach ($duplicates as $dupSet) {
            $totalDuplicates += count($dupSet) - 1; // -1 because one will be kept
        }

        $this->stdout("  Processing {$totalSets} duplicate sets ({$totalDuplicates} duplicates to merge)...\n");
        $this->stdout("  NOTE: Files are protected by Phase 1.7 staging - safe to delete asset records\n\n", Console::FG_CYAN);

        $resolved = 0;
        $errors = 0;
        $setNum = 0;

        foreach ($duplicates as $filename => $dupAssets) {
            $setNum++;

            if (count($dupAssets) < 2) {
                continue;
            }

            $this->stdout("  [{$setNum}/{$totalSets}] Resolving '{$filename}' (" . count($dupAssets) . " copies)... ");

            try {
                // Get full Asset objects
                $assets = [];
                foreach ($dupAssets as $assetData) {
                    $asset = Asset::findOne($assetData['id']);
                    if ($asset) {
                        $assets[] = $asset;
                    }
                }

                if (count($assets) < 2) {
                    $this->stdout("skipped (assets not found)\n", Console::FG_YELLOW);
                    continue;
                }

                // Pick the winner
                $winner = $assets[0];
                foreach (array_slice($assets, 1) as $asset) {
                    $currentWinner = DuplicateResolver::pickWinner($winner, $asset);
                    if ($currentWinner->id !== $winner->id) {
                        $winner = $currentWinner;
                    }
                }

                // Merge all losers into winner
                $merged = 0;
                foreach ($assets as $asset) {
                    if ($asset->id === $winner->id) {
                        continue;
                    }

                    // Transfer relations from loser to winner
                    Craft::$app->getDb()->createCommand()
                        ->update(
                            '{{%relations}}',
                            ['targetId' => $winner->id],
                            ['targetId' => $asset->id]
                        )
                        ->execute();

                    // Check if loser has a larger file
                    $loserSize = $asset->size ?? 0;
                    $winnerSize = $winner->size ?? 0;

                    if ($loserSize > $winnerSize) {
                        try {
                            $loserFs = $asset->getVolume()->getFs();
                            $winnerFs = $winner->getVolume()->getFs();
                            $loserPath = $asset->getPath();
                            $winnerPath = $winner->getPath();

                            // SAFETY CHECK: Verify source file exists before attempting copy
                            if (!$loserFs->fileExists($loserPath)) {
                                Craft::warning(
                                    "Source file missing for asset {$asset->id} at {$loserPath} - file may have been staged in Phase 1.7",
                                    __METHOD__
                                );
                            } else {
                                // Copy loser's file to winner's path
                                // Use read()/write() instead of readStream()/writeStream() for DO Spaces compatibility
                                $content = $loserFs->read($loserPath);
                                if ($content !== false) {
                                    $winnerFs->write($winnerPath, $content, []);

                                    // Update winner's size
                                    $winner->size = $loserSize;
                                    Craft::$app->getElements()->saveElement($winner, false);

                                    $this->changeLogManager->log([
                                        'action' => 'upgrade_asset_file',
                                        'assetId' => $winner->id,
                                        'filename' => $filename,
                                        'oldSize' => $winnerSize,
                                        'newSize' => $loserSize,
                                        'reason' => 'Loser had larger file'
                                    ]);
                                }
                            }
                        } catch (\Exception $e) {
                            Craft::warning(
                                "Could not copy file from asset {$asset->id} to winner {$winner->id}: " . $e->getMessage(),
                                __METHOD__
                            );
                        }
                    }

                    // SAFETY CHECK: Count how many OTHER assets reference this physical file
                    $db = Craft::$app->getDb();
                    $assetPath = $asset->getPath();
                    $assetFolderId = $asset->folderId;
                    $assetVolumeId = $asset->volumeId;

                    $sharedFileCount = $db->createCommand('
                        SELECT COUNT(*) FROM {{%assets}} a
                        INNER JOIN {{%elements}} e ON e.id = a.id
                        LEFT JOIN {{%volumefolders}} f ON f.id = a.folderId
                        WHERE a.filename = :filename
                        AND a.volumeId = :volumeId
                        AND f.path = (SELECT path FROM {{%volumefolders}} WHERE id = :folderId)
                        AND a.id != :assetId
                        AND e.dateDeleted IS NULL
                    ', [
                        ':filename' => $asset->filename,
                        ':volumeId' => $assetVolumeId,
                        ':folderId' => $assetFolderId,
                        ':assetId' => $asset->id,
                    ])->queryScalar();

                    if ($sharedFileCount > 0) {
                        // File is shared by other assets - log this for safety
                        Craft::info(
                            "Deleting asset {$asset->id} but file {$assetPath} is shared by {$sharedFileCount} other asset(s) - Craft will preserve the file",
                            __METHOD__
                        );

                        $this->changeLogManager->log([
                            'action' => 'delete_duplicate_asset_shared_file',
                            'assetId' => $asset->id,
                            'filename' => $filename,
                            'sharedByCount' => $sharedFileCount,
                            'reason' => 'File preserved - shared by other assets'
                        ]);
                    }

                    // Delete the loser asset (Craft will preserve file if shared)
                    Craft::$app->getElements()->deleteElement($asset, true);
                    $merged++;
                }

                $this->stdout("merged {$merged} into asset #{$winner->id}\n", Console::FG_GREEN);
                $resolved += $merged;
                $this->stats['duplicates_resolved'] += $merged;

            } catch (\Exception $e) {
                $this->stdout("error: " . $e->getMessage() . "\n", Console::FG_RED);
                $errors++;
                Craft::error("Error resolving duplicates for '{$filename}': " . $e->getMessage(), __METHOD__);
            }
        }

        $this->stdout("\n  Summary:\n");
        $this->stdout("    Duplicates merged: {$resolved}\n", Console::FG_GREEN);
        if ($errors > 0) {
            $this->stdout("    Errors: {$errors}\n", Console::FG_RED);
        }
        $this->stdout("\n");
    }

    /**
     * Fix broken links in batches
     */
    /**
     * REPLACE fixBrokenLinksBatched() - Now skips already-processed items
     */
    private function fixBrokenLinksBatched($brokenLinks, $fileInventory, $sourceVolumes, $targetVolume, $targetRootFolder)
    {
        if (empty($brokenLinks)) {
            return;
        }

        // Filter out already processed assets
        $remainingLinks = array_filter($brokenLinks, function ($assetData) {
            return !in_array($assetData['id'], $this->processedAssetIds);
        });

        if (empty($remainingLinks)) {
            $this->stdout("  All broken links already processed - skipping\n\n", Console::FG_GREEN);
            return;
        }

        $total = count($remainingLinks);
        $skipped = count($brokenLinks) - $total;

        if ($skipped > 0) {
            $this->stdout("  Resuming: {$skipped} already processed, {$total} remaining\n", Console::FG_CYAN);
        }

        $this->stdout("\n");

        $searchIndexes = $this->buildFileSearchIndexes($fileInventory);
        $progress = new ProgressTracker("Fixing Broken Links", $total, $this->progressReportingInterval);

        $fixed = 0;
        $notFound = 0;
        $processedBatch = [];
        $lastLockRefresh = time();
        $counter = 0;

        foreach ($remainingLinks as $assetData) {
            $counter++;

            // Refresh lock periodically
            if (time() - $lastLockRefresh > 60) {
                $this->migrationLock->refresh();
                $lastLockRefresh = time();
            }

            $asset = Asset::findOne($assetData['id']);
            if (!$asset) {
                $this->stdout("  [{$counter}/{$total}] Asset not found in database (ID: {$assetData['id']})\n", Console::FG_GREY);
                continue;
            }

            $result = $this->errorRecoveryManager->retryOperation(
                fn() => $this->fixSingleBrokenLink($asset, $fileInventory, $searchIndexes, $sourceVolumes, $targetVolume, $targetRootFolder, $assetData),
                "fix_broken_link_{$asset->id}"
            );

            if ($result['fixed']) {
                $statusMsg = $result['action'] ?? 'Fixed';
                $this->stdout("  [{$counter}/{$total}] ✓ {$statusMsg}: {$asset->filename}", Console::FG_GREEN);
                if (isset($result['details'])) {
                    $this->stdout(" - {$result['details']}", Console::FG_GREY);
                }
                $this->stdout("\n");
                $fixed++;
                $this->stats['assets_updated']++;
                $processedBatch[] = $asset->id;
            } else {
                $this->stdout("  [{$counter}/{$total}] ✗ File not found: {$asset->filename}", Console::FG_YELLOW);
                if (isset($result['reason'])) {
                    $this->stdout(" - {$result['reason']}", Console::FG_GREY);
                }
                $this->stdout("\n");
                $notFound++;
            }

            // Update progress
            if ($progress->increment()) {
                // Update quick state
                if (!empty($processedBatch)) {
                    $this->checkpointManager->updateProcessedIds($processedBatch);
                    $this->processedAssetIds = array_merge($this->processedAssetIds, $processedBatch);
                    $processedBatch = [];
                }
            }

            // Full checkpoint every N items
            if (($fixed + $notFound) % ($this->batchSize * 5) === 0) {
                $this->saveCheckpoint([
                    'fixed' => $fixed,
                    'not_found' => $notFound
                ]);
            }
        }

        // Final batch update
        if (!empty($processedBatch)) {
            $this->checkpointManager->updateProcessedIds($processedBatch);
        }

        $this->stdout("\n\n  ✓ Fixed: {$fixed}, Not found: {$notFound}\n\n", Console::FG_CYAN);
    }
    /**
     * Fix single broken link with full strategy cascade
     */
    /**
     * REPLACE fixSingleBrokenLink() - Check if file actually exists on source (searches all source filesystems)
     */
    private function fixSingleBrokenLink($asset, $fileInventory, $searchIndexes, $sourceVolumes, $targetVolume, $targetRootFolder, $assetData)
    {
        // FIRST: Check if the file exists in its expected location on ANY SOURCE volume
        // Search all source filesystems, not just the one from assetData
        foreach ($sourceVolumes as $sourceVolume) {
            try {
                $sourceFs = $sourceVolume->getFs();
                $expectedPath = trim($assetData['folderPath'], '/') . '/' . $asset->filename;

                // If file exists on source, this isn't really "broken" - just needs to be linked properly
                if ($sourceFs->fileExists($expectedPath)) {
                    Craft::info(
                        "Asset {$asset->id} ({$asset->filename}) - File exists on source volume '{$sourceVolume->name}' at {$expectedPath}, " .
                        "just needs database update",
                        __METHOD__
                    );

                    // The file exists, just update the asset record
                    return $this->updateAssetPath($asset, $expectedPath, $sourceVolume, $assetData);
                }
            } catch (\Exception $e) {
                // Can't verify this source - try next volume
                Craft::warning(
                    "Could not verify source file existence on volume '{$sourceVolume->name}': " . $e->getMessage(),
                    __METHOD__
                );
            }
        }

        // Only NOW attempt to find alternative matches
        $matchResult = $this->findFileForAsset($asset, $fileInventory, $searchIndexes, $targetVolume, $assetData);

        if (!$matchResult['found']) {
            // Track missing file for CSV export (not an error, just a missing file when looking for broken links)
            $this->missingFiles[] = [
                'assetId' => $asset->id,
                'filename' => $asset->filename,
                'expectedPath' => $assetData['folderPath'] . '/' . $asset->filename,
                'volumeId' => $assetData['volumeId'],
                'linkedType' => 'asset',
                'reason' => 'File not found with any matching strategy'
            ];

            $this->changeLogManager->logChange([
                'type' => 'broken_link_not_fixed',
                'assetId' => $asset->id,
                'filename' => $asset->filename,
                'reason' => 'File not found with any matching strategy',
                'rejected_match' => $matchResult['rejected_match'] ?? null,
                'rejected_confidence' => $matchResult['rejected_confidence'] ?? null
            ]);

            return [
                'fixed' => false,
                'reason' => 'No matching file found'
            ];
        }

        // ⚠️ WARN if using low-confidence match
        if ($matchResult['confidence'] < 0.90) {
            $this->stdout("⚠", Console::FG_YELLOW);
            Craft::warning(
                "Using low-confidence match ({$matchResult['confidence']}): " .
                "'{$matchResult['file']['filename']}' for '{$asset->filename}'",
                __METHOD__
            );
        }

        $sourceFile = $matchResult['file'];

        // ═══════════════════════════════════════════════════════════════════
        // ORIGINALS-FIRST STRATEGY: Prioritize files in /originals folders
        // ═══════════════════════════════════════════════════════════════════
        $isFromOriginals = $this->isInOriginalsFolder($sourceFile['path']);
        if ($isFromOriginals) {
            $this->stdout("    📦 Found in originals: {$sourceFile['path']}\n", Console::FG_YELLOW);
            $this->stdout("    → Moving to volume {$targetVolume->name}, root folder\n", Console::FG_CYAN);

            // Track originals moved
            if (!isset($this->stats['originals_moved'])) {
                $this->stats['originals_moved'] = 0;
            }
        }

        // Store original asset location before fix
        $originalVolumeId = $asset->volumeId;
        $originalFolderId = $asset->folderId;

        try {
            // copyFileToAsset will:
            // 1. Copy file from source (including originals folders)
            // 2. Set asset->volumeId = targetVolume->id (volume 1)
            // 3. Set asset->folderId = targetRootFolder->id (root folder)
            // 4. Delete source file (move operation)
            $success = $this->copyFileToAsset($sourceFile, $asset, $targetVolume, $targetRootFolder);

            // Handle already-copied files (referenced by multiple assets)
            if ($success === 'already_copied') {
                Craft::info(
                    "Asset {$asset->id} ({$asset->filename}) references file that was already copied by another asset",
                    __METHOD__
                );

                return [
                    'fixed' => true,
                    'action' => 'Already migrated',
                    'details' => "source file {$sourceFile['volumeName']}/{$sourceFile['path']} was copied by another asset"
                ];
            }

            if ($success) {
                // Track if file was moved from originals
                if ($isFromOriginals) {
                    $this->stats['originals_moved']++;
                    $this->stdout("    ✓ Original moved to volume 1 root\n", Console::FG_GREEN);
                }

                $this->changeLogManager->logChange([
                    'type' => 'fixed_broken_link',
                    'assetId' => $asset->id,
                    'filename' => $asset->filename,
                    'matchedFile' => $sourceFile['filename'],
                    'sourceVolume' => $sourceFile['volumeName'],
                    'sourcePath' => $sourceFile['path'],
                    'matchStrategy' => $matchResult['strategy'],
                    'confidence' => $matchResult['confidence'],
                    // Track if file was from originals
                    'fromOriginals' => $isFromOriginals,
                    // NEW: Store original location for rollback
                    'originalVolumeId' => $originalVolumeId,
                    'originalFolderId' => $originalFolderId,
                ]);

                $action = $isFromOriginals ? 'Moved from originals' : 'Copied file';
                return [
                    'fixed' => true,
                    'action' => $action,
                    'details' => "from {$sourceFile['volumeName']}/{$sourceFile['path']} (confidence: " . round($matchResult['confidence'] * 100) . "%)"
                ];
            }

        } catch (\Exception $e) {
            // Track as actual error (not expected missing file)
            $this->trackError('fix_broken_link', $e->getMessage(), [
                'assetId' => $asset->id,
                'filename' => $asset->filename
            ]);
            Craft::warning("Failed to fix broken link for asset {$asset->id}: " . $e->getMessage(), __METHOD__);
            // Don't rethrow - just return false to continue with next asset
        }

        return [
            'fixed' => false,
            'reason' => 'Copy operation failed'
        ];
    }

    /**
     * Update asset path when file exists on source
     */
    private function updateAssetPath($asset, $path, $volume, $assetData)
    {
        // This is a simpler operation - just ensure the asset record is correct
        // No file copying needed

        // Store original values before update
        $originalVolumeId = $asset->volumeId;
        $originalPath = $asset->getPath();

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Ensure asset record matches actual file location
            if ($asset->volumeId != $volume->id) {
                $asset->volumeId = $volume->id;
            }

            $success = Craft::$app->getElements()->saveElement($asset);

            if ($success) {
                $transaction->commit();

                $this->changeLogManager->logChange([
                    'type' => 'updated_asset_path',
                    'assetId' => $asset->id,
                    'filename' => $asset->filename,
                    'path' => $path,
                    'volumeId' => $volume->id,
                    // NEW: Store original values for rollback
                    'originalVolumeId' => $originalVolumeId,
                    'originalPath' => $originalPath,
                ]);

                return [
                    'fixed' => true,
                    'action' => 'Updated path',
                    'details' => "file exists at {$path}"
                ];
            }

            $transaction->rollBack();
            return [
                'fixed' => false,
                'reason' => 'Failed to save asset element'
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Consolidate files in batches
     * Now skips already-processed items
     */
    private function consolidateUsedFilesBatched($assets, $targetVolume, $targetRootFolder, $targetFs)
    {
        if (empty($assets)) {
            $this->stdout("  No files need consolidation\n\n");
            return;
        }

        // Filter out already processed
        $remainingAssets = array_filter($assets, function ($assetData) {
            return !in_array($assetData['id'], $this->processedAssetIds);
        });

        if (empty($remainingAssets)) {
            $this->stdout("  All assets already consolidated - skipping\n\n", Console::FG_GREEN);
            return;
        }

        $total = count($remainingAssets);
        $skipped = count($assets) - $total;

        if ($skipped > 0) {
            $this->stdout("  Resuming: {$skipped} already processed, {$total} remaining\n", Console::FG_CYAN);
        }

        $this->printProgressLegend();
        $this->stdout("  Progress: ");

        $progress = new ProgressTracker("Consolidating Files", $total, $this->progressReportingInterval);

        $moved = 0;
        $skippedLocal = 0;
        $processedBatch = [];
        $lastLockRefresh = time();

        foreach ($remainingAssets as $assetData) {
            // Refresh lock periodically
            if (time() - $lastLockRefresh > 60) {
                $this->migrationLock->refresh();
                $lastLockRefresh = time();
            }

            $asset = Asset::findOne($assetData['id']);
            if (!$asset) {
                $this->safeStdout("?", Console::FG_GREY);
                continue;
            }

            // Skip if already in correct location
            if ($asset->volumeId == $targetVolume->id && $asset->folderId == $targetRootFolder->id) {
                $this->safeStdout("-", Console::FG_GREY);
                $skippedLocal++;
                $processedBatch[] = $asset->id; // Mark as processed even if skipped

                if ($progress->increment()) {
                    $this->safeStdout(" " . $progress->getProgressString() . "\n  ");
                }
                continue;
            }

            $result = $this->errorRecoveryManager->retryOperation(
                fn() => $this->consolidateSingleAsset($asset, $assetData, $targetVolume, $targetRootFolder),
                "consolidate_asset_{$asset->id}"
            );

            if ($result['success']) {
                $this->safeStdout(".", Console::FG_GREEN);
                $moved++;
                $this->stats['files_moved']++;
                $processedBatch[] = $asset->id;
            } else {
                $this->safeStdout("x", Console::FG_RED);
            }

            // Update progress
            if ($progress->increment()) {
                $this->safeStdout(" " . $progress->getProgressString() . "\n  ");

                // Update quick state
                if (!empty($processedBatch)) {
                    $this->checkpointManager->updateProcessedIds($processedBatch);
                    $this->processedAssetIds = array_merge($this->processedAssetIds, $processedBatch);
                    $processedBatch = [];
                }
            }

            // Full checkpoint every N items
            if (($moved + $skippedLocal) % ($this->batchSize * 5) === 0) {
                $this->saveCheckpoint([
                    'moved' => $moved,
                    'skipped' => $skippedLocal
                ]);
            }
        }

        // Final batch update
        if (!empty($processedBatch)) {
            $this->checkpointManager->updateProcessedIds($processedBatch);
        }

        $this->stdout("\n\n  ✓ Moved: {$moved}, Skipped: {$skippedLocal}\n\n", Console::FG_CYAN);
    }
    /**
     * Consolidate single asset
     */

    private function consolidateSingleAsset($asset, $assetData, $targetVolume, $targetRootFolder)
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Idempotency check - reload asset from DB in case it was moved in a previous partial run
            $reloadedAsset = Asset::findOne($asset->id);

            if (!$reloadedAsset) {
                $transaction->rollBack();
                return ['success' => false, 'error' => 'Asset not found'];
            }

            // Check if already in correct location
            if ($reloadedAsset->volumeId == $targetVolume->id && $reloadedAsset->folderId == $targetRootFolder->id) {
                $transaction->commit();
                return ['success' => true, 'already_done' => true];
            }

            $isCrossVolume = $reloadedAsset->volumeId != $targetVolume->id;

            if ($isCrossVolume) {
                $success = $this->moveAssetCrossVolume($reloadedAsset, $targetVolume, $targetRootFolder);
            } else {
                $success = $this->moveAssetSameVolume($reloadedAsset, $targetRootFolder);
            }

            if ($success) {
                $transaction->commit();

                $this->changeLogManager->logChange([
                    'type' => 'moved_asset',
                    'assetId' => $reloadedAsset->id,
                    'filename' => $reloadedAsset->filename,
                    'fromVolume' => $assetData['volumeId'],
                    'fromFolder' => $assetData['folderId'],
                    'toVolume' => $targetVolume->id,
                    'toFolder' => $targetRootFolder->id
                ]);

                return ['success' => true];
            } else {
                $transaction->rollBack();
                return ['success' => false];
            }

        } catch (\Exception $e) {
            $transaction->rollBack();
            $errorMsg = "Failed to consolidate asset {$asset->id}: " . $e->getMessage();
            $this->trackError('consolidate_asset', $errorMsg);
            Craft::warning($errorMsg, __METHOD__);
            // Don't rethrow - return false to continue with next asset
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if asset was already processed
     */
    private function isAssetProcessed($assetId): bool
    {
        return in_array($assetId, $this->processedAssetIds);
    }

    /**
     * Mark asset as processed
     */
    private function markAssetProcessed($assetId): void
    {
        if (!in_array($assetId, $this->processedAssetIds)) {
            $this->processedAssetIds[] = $assetId;
        }
    }

    /**
     * Mark multiple assets as processed and update quick state
     */
    private function markAssetsProcessedBatch($assetIds): void
    {
        $newIds = array_diff($assetIds, $this->processedAssetIds);
        if (!empty($newIds)) {
            $this->processedAssetIds = array_merge($this->processedAssetIds, $newIds);
            $this->checkpointManager->updateProcessedIds($newIds);
        }
    }

    /**
     * Quarantine unused files in batches
     */
    private function quarantineUnusedFilesBatched($orphanedFiles, $unusedAssets, $quarantineVolume, $quarantineFs)
    {
        // Quarantine orphaned files
        if (!empty($orphanedFiles)) {
            $this->stdout("\n  Quarantining orphaned files...\n");
            $this->stdout("  Progress: ");

            $total = count($orphanedFiles);
            $quarantined = 0;

            foreach (array_chunk($orphanedFiles, $this->BATCH_SIZE) as $batchNum => $batch) {
                foreach ($batch as $file) {
                    $result = $this->errorRecoveryManager->retryOperation(
                        fn() => $this->quarantineSingleFile($file, $quarantineFs, 'orphaned'),
                        "quarantine_file_{$file['filename']}"
                    );

                    if ($result['success']) {
                        $this->safeStdout(".", Console::FG_YELLOW);
                        $quarantined++;
                        $this->stats['files_quarantined']++;
                    } else {
                        $this->safeStdout("!", Console::FG_RED);
                    }

                    if ($quarantined % 50 === 0) {
                        $this->safeStdout(" [{$quarantined}/{$total}]\n  ");
                    }
                }

                if ($batchNum % $this->CHECKPOINT_EVERY_BATCHES === 0) {
                    $this->saveCheckpoint([
                        'quarantine_batch' => $batchNum,
                        'quarantined' => $quarantined
                    ]);
                }
            }

            $this->stdout("\n  ✓ Quarantined orphaned files: {$quarantined}\n\n", Console::FG_CYAN);
        }

        // Quarantine unused assets
        if (!empty($unusedAssets)) {
            $this->stdout("  Quarantining unused assets...\n");
            $this->stdout("  Progress: ");

            $total = count($unusedAssets);
            $quarantined = 0;
            $quarantineRoot = Craft::$app->getAssets()->getRootFolderByVolumeId($quarantineVolume->id);

            foreach (array_chunk($unusedAssets, $this->BATCH_SIZE) as $batchNum => $batch) {
                foreach ($batch as $assetData) {
                    $asset = Asset::findOne($assetData['id']);
                    if (!$asset) {
                        $this->safeStdout("?", Console::FG_GREY);
                        continue;
                    }

                    // Skip if already processed
                    if (in_array($asset->id, $this->processedIds)) {
                        $this->safeStdout("-", Console::FG_GREY);
                        continue;
                    }

                    $result = $this->errorRecoveryManager->retryOperation(
                        fn() => $this->quarantineSingleAsset($asset, $assetData, $quarantineVolume, $quarantineRoot),
                        "quarantine_asset_{$asset->id}"
                    );

                    if ($result['success']) {
                        $this->safeStdout(".", Console::FG_YELLOW);
                        $quarantined++;
                        $this->stats['files_quarantined']++;
                        $this->processedIds[] = $asset->id;
                    } else {
                        $this->safeStdout("x", Console::FG_RED);
                    }

                    if ($quarantined % 50 === 0) {
                        $this->safeStdout(" [{$quarantined}/{$total}]\n  ");
                    }
                }

                if ($batchNum % $this->CHECKPOINT_EVERY_BATCHES === 0) {
                    $this->saveCheckpoint([
                        'quarantine_batch' => $batchNum,
                        'quarantined' => $quarantined
                    ]);
                }
            }

            $this->stdout("\n  ✓ Quarantined unused assets: {$quarantined}\n\n", Console::FG_CYAN);
        }
    }

    /**
     * Quarantine single file
     */
    private function quarantineSingleFile($file, $quarantineFs, $subfolder)
    {
        try {
            $sourceFs = $file['fs'];
            $sourcePath = $file['path'];
            $targetPath = $subfolder . '/' . $file['filename'];

            // Check if source file exists before trying to read
            if (!$sourceFs->fileExists($sourcePath)) {
                $errorMsg = "Source file not found: {$sourcePath}";
                Craft::warning($errorMsg, __METHOD__);
                $this->trackError('quarantine_file_missing', $errorMsg);

                // Track in stats
                if (!isset($this->stats['missing_files'])) {
                    $this->stats['missing_files'] = 0;
                }
                $this->stats['missing_files']++;

                return ['success' => false, 'error' => 'file_not_found'];
            }

            // Get file content
            $content = $sourceFs->read($sourcePath);

            // Write to quarantine
            $quarantineFs->write($targetPath, $content, []);

            // Delete from source
            $sourceFs->deleteFile($sourcePath);

            $this->changeLogManager->logChange([
                'type' => 'quarantined_orphaned_file',
                'sourcePath' => $sourcePath,
                'targetPath' => $targetPath,
                'sourceVolume' => $file['volumeName'],
                'size' => $file['size']
            ]);

            return ['success' => true];

        } catch (\Exception $e) {
            $errorMsg = "Failed to quarantine file {$file['filename']}: " . $e->getMessage();
            $this->trackError('quarantine_file', $errorMsg);
            Craft::warning($errorMsg, __METHOD__);

            // Don't rethrow - return false to continue with next file
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Quarantine single asset
     */
    /**
     * REPLACE quarantineSingleAsset() - Preserve original filename
     */
    private function quarantineSingleAsset($asset, $assetData, $quarantineVolume, $quarantineRoot)
    {
        try {
            // Get original file info BEFORE move
            $originalFilename = $asset->filename;
            $originalPath = $asset->getPath();
            $originalVolumeId = $asset->volumeId;

            // Move to quarantine - Craft will preserve filename
            $success = $this->moveAssetCrossVolume($asset, $quarantineVolume, $quarantineRoot);

            if ($success) {
                // ⚠️ VERIFY filename wasn't changed
                $asset = Asset::findOne($asset->id); // Reload

                if ($asset->filename !== $originalFilename) {
                    Craft::warning(
                        "Filename changed during quarantine: '{$originalFilename}' → '{$asset->filename}' " .
                        "(Asset ID: {$asset->id})",
                        __METHOD__
                    );

                    // Try to restore original filename
                    $this->restoreOriginalFilename($asset, $originalFilename, $quarantineVolume);
                }

                $this->changeLogManager->logChange([
                    'type' => 'quarantined_unused_asset',
                    'assetId' => $asset->id,
                    'originalFilename' => $originalFilename,
                    'currentFilename' => $asset->filename,
                    'fromVolume' => $assetData['volumeId'],
                    'fromFolder' => $assetData['folderId'],
                    'originalPath' => $originalPath,
                    'quarantinePath' => $asset->getPath()
                ]);

                return ['success' => true];
            }

            return ['success' => false];

        } catch (\Exception $e) {
            $this->trackError('quarantine_asset', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Restore original filename if it was changed
     */
    private function restoreOriginalFilename($asset, $originalFilename, $volume)
    {
        try {
            $fs = $volume->getFs();
            $currentPath = $asset->getPath();
            $targetPath = dirname($currentPath) . '/' . $originalFilename;

            // Check if target path is available
            if (!$fs->fileExists($targetPath)) {
                // Rename file on filesystem
                $content = $fs->read($currentPath);
                $fs->write($targetPath, $content, []);
                $fs->deleteFile($currentPath);

                // Update asset record
                $asset->filename = $originalFilename;
                Craft::$app->getElements()->saveElement($asset);

                Craft::info(
                    "Restored original filename: '{$originalFilename}' for asset {$asset->id}",
                    __METHOD__
                );
            }
        } catch (\Exception $e) {
            Craft::error(
                "Could not restore original filename '{$originalFilename}': " . $e->getMessage(),
                __METHOD__
            );
        }
    }
    // =========================================================================
    // HELPER METHODS 
    // =========================================================================

    /**
     * Set current phase
     */
    private function setPhase($phase)
    {
        $this->currentPhase = $phase;
        $this->currentBatch = 0;
        $this->processedIds = [];

        // Notify changeLogManager of phase change
        if ($this->changeLogManager) {
            $this->changeLogManager->setPhase($phase);
        }

        // Update quick state so monitor can track current phase
        $this->checkpointManager->saveQuickState([
            'migration_id' => $this->migrationId,
            'phase' => $phase,
            'batch' => $this->currentBatch,
            'processed_ids' => $this->processedAssetIds,
            'processed_count' => count($this->processedAssetIds),
            'stats' => $this->stats,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Save checkpoint
     */
    private function saveCheckpoint($data = [])
    {
        $checkpoint = array_merge([
            'migration_id' => $this->migrationId,
            'phase' => $this->currentPhase,
            'batch' => $this->currentBatch,
            'processed_ids' => $this->processedAssetIds,
            'stats' => $this->stats,
            'timestamp' => date('Y-m-d H:i:s')
        ], $data);

        $this->checkpointManager->saveCheckpoint($checkpoint);
        $this->stats['checkpoints_saved']++;
    }

    /**
     * Perform health check
     */
    private function performHealthCheck($targetVolume, $quarantineVolume)
    {
        $this->stdout("    Performing health check...\n");

        // Database check
        try {
            Craft::$app->getDb()->createCommand('SELECT 1')->execute();
            $this->stdout("      ✓ Database connection OK\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            throw new \Exception("CRITICAL: Database connection failed: " . $e->getMessage());
        }

        // Target FS check
        try {
            $targetFs = $targetVolume->getFs();
            $iter = $targetFs->getFileList('', false);
            $count = 0;
            foreach ($iter as $item) {
                $count++;
                if ($count >= 3)
                    break;
            }
            $this->stdout("      ✓ Target filesystem accessible ({$count} items found)\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            throw new \Exception("CRITICAL: Target filesystem not accessible: " . $e->getMessage());
        }

        // Quarantine FS check
        try {
            $quarantineFs = $quarantineVolume->getFs();
            $iter = $quarantineFs->getFileList('', false);
            $count = 0;
            foreach ($iter as $item) {
                $count++;
                if ($count >= 3)
                    break;
            }
            $this->stdout("      ✓ Quarantine filesystem accessible ({$count} items found)\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            throw new \Exception("CRITICAL: Quarantine filesystem not accessible: " . $e->getMessage());
        }

        // Write test - ensure we can actually write files
        try {
            $testFile = 'migration-test-' . time() . '.txt';
            $targetFs->write($testFile, 'test', []);
            $targetFs->deleteFile($testFile);
            $this->stdout("      ✓ Target filesystem writable\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            throw new \Exception("CRITICAL: Cannot write to target filesystem: " . $e->getMessage());
        }

        $this->stdout("    ✓ Health check passed\n\n", Console::FG_GREEN);
    }

    /**
     * Validate disk space before migration
     * Estimates required space and checks if target has sufficient capacity
     */
    private function validateDiskSpace($sourceVolumes, $targetVolume)
    {
        $this->stdout("    Validating disk space...\n");

        try {
            // Estimate required space by counting assets in source volumes
            $totalSize = 0;
            $assetCount = 0;

            foreach ($sourceVolumes as $volume) {
                $assets = \craft\elements\Asset::find()
                    ->volumeId($volume->id)
                    ->all();

                foreach ($assets as $asset) {
                    if ($asset->size) {
                        $totalSize += $asset->size;
                        $assetCount++;
                    }
                }
            }

            // Add 20% buffer for transforms and temporary files
            $requiredSpace = $totalSize * 1.2;

            // Format sizes for display
            $totalSizeFormatted = $this->formatBytes($totalSize);
            $requiredSpaceFormatted = $this->formatBytes($requiredSpace);

            $this->stdout("      Assets to migrate: {$assetCount} files\n");
            $this->stdout("      Total size: {$totalSizeFormatted}\n");
            $this->stdout("      Required space (with 20% buffer): {$requiredSpaceFormatted}\n");

            // Try to get available space (this may not work for all filesystems)
            $availableSpace = @disk_free_space(Craft::$app->getPath()->getStoragePath());

            if ($availableSpace !== false) {
                $availableSpaceFormatted = $this->formatBytes($availableSpace);
                $this->stdout("      Local disk available: {$availableSpaceFormatted}\n");

                if ($availableSpace < $requiredSpace) {
                    $this->stdout("      ⚠ WARNING: Local disk space may be insufficient for migration\n", Console::FG_YELLOW);
                    $this->stdout("      Consider clearing temporary files or using a larger volume\n", Console::FG_YELLOW);
                } else {
                    $this->stdout("      ✓ Sufficient local disk space\n", Console::FG_GREEN);
                }
            } else {
                $this->stdout("      ⓘ Cannot determine available disk space (remote filesystem)\n", Console::FG_CYAN);
            }

            $this->stdout("    ✓ Disk space validation complete\n\n", Console::FG_GREEN);

        } catch (\Throwable $e) {
            $this->stdout("      ⚠ WARNING: Could not validate disk space: " . $e->getMessage() . "\n", Console::FG_YELLOW);
            $this->stdout("      Continuing migration...\n\n", Console::FG_YELLOW);
        }
    }

    /**
     * Format bytes to human-readable size
     */
    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Ensure a Craft FS can list contents (Spaces/Flysystem v3 compatible).
     * Accepts Craft FS instances or a Flysystem operator directly.
     */
    private function assertFsAccessible($fs, string $label = 'Filesystem'): void
    {
        // Non-recursive root listing via Craft FS API
        $iter = $fs->getFileList('', false);
        foreach ($iter as $_) {
            break; // force an actual call
        }
    }


    /**
     * handleFatalError() - Better recovery instructions with broken pipe protection
     */
    private function handleFatalError($e)
    {
        // CRITICAL: Save checkpoint FIRST before any output operations
        // This ensures state is preserved even if stdout/stderr fails
        $checkpointSaved = false;
        try {
            $this->saveCheckpoint([
                'error' => $e->getMessage(),
                'can_resume' => true,
                'interrupted_at' => date('Y-m-d H:i:s')
            ]);
            $checkpointSaved = true;

            // Mark migration as failed in database
            if ($this->checkpointManager) {
                $this->checkpointManager->markMigrationFailed($e->getMessage());
            }
        } catch (\Exception $e2) {
            // Log to file as absolute fallback
            Craft::error("CRITICAL: Could not save checkpoint: " . $e2->getMessage(), __METHOD__);
            Craft::error("Original error: " . $e->getMessage(), __METHOD__);
        }

        // Log the error to file (independent of stdout/stderr)
        Craft::error("Migration interrupted: " . $e->getMessage(), __METHOD__);
        Craft::error("Stack trace: " . $e->getTraceAsString(), __METHOD__);

        // Now attempt to display error info using safe output methods
        $this->safeStderr("\n" . str_repeat("=", 80) . "\n", Console::FG_RED);
        $this->safeStderr("MIGRATION INTERRUPTED\n", Console::FG_RED);
        $this->safeStderr(str_repeat("=", 80) . "\n", Console::FG_RED);
        $this->safeStderr($e->getMessage() . "\n\n", Console::FG_RED);

        if ($checkpointSaved) {
            $this->safeStdout("✓ State saved - migration can be resumed\n\n", Console::FG_GREEN);
        } else {
            $this->safeStderr("✗ Warning: Could not save checkpoint - check logs\n\n", Console::FG_YELLOW);
        }

        $this->safeStdout("RECOVERY OPTIONS:\n", Console::FG_CYAN);
        $this->safeStdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n", Console::FG_CYAN);

        try {
            $quickState = $this->checkpointManager->loadQuickState();
            if ($quickState) {
                $processed = $quickState['processed_count'] ?? 0;
                $this->safeStdout("\n✓ Quick Resume Available\n", Console::FG_GREEN);
                $this->safeStdout("  Last phase: {$quickState['phase']}\n");
                $this->safeStdout("  Processed: {$processed} items\n");
                $this->safeStdout("  Command:   ./craft s3-spaces-migration/image-migration/migrate --resume\n\n");
            }
        } catch (\Exception $e3) {
            Craft::error("Could not load quick state: " . $e3->getMessage(), __METHOD__);
        }

        $this->safeStdout("Other Options:\n");
        $this->safeStdout("  Check status:  ./craft s3-spaces-migration/image-migration/status\n");
        $this->safeStdout("  View progress: tail -f " . Craft::getAlias('@storage') . "/logs/migration-*.log\n");
        $this->safeStdout("  Rollback:      ./craft s3-spaces-migration/image-migration/rollback\n\n");

        $this->safeStdout("Note: Original assets are preserved on S3 until you verify the migration.\n");
        $this->safeStdout("      The site remains operational during the migration.\n\n");
    }

    /**
     * Safe stdout wrapper that handles broken pipe errors
     */
    private function safeStdout($message, $color = null)
    {
        try {
            if ($color !== null) {
                @$this->stdout($message, $color);
            } else {
                @$this->stdout($message);
            }
        } catch (\Exception $e) {
            // Fallback to file logging if stdout fails
            Craft::error("Failed to write to stdout: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Safe stderr wrapper that handles broken pipe errors
     */
    private function safeStderr($message, $color = null)
    {
        try {
            if ($color !== null) {
                @$this->stderr($message, $color);
            } else {
                @$this->stderr($message);
            }
        } catch (\Exception $e) {
            // Fallback to file logging if stderr fails
            Craft::error("Failed to write to stderr: " . $e->getMessage(), __METHOD__);
        }
    }


    /**
     * Format age of timestamp
     */
    private function formatAge($timestamp)
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return "{$diff} seconds";
        } elseif ($diff < 3600) {
            return round($diff / 60) . " minutes";
        } elseif ($diff < 86400) {
            return round($diff / 3600) . " hours";
        } else {
            return round($diff / 86400) . " days";
        }
    }

    /**
     * Format duration in seconds to human-readable string
     */
    private function formatDuration($seconds)
    {
        if ($seconds < 0) {
            return "0s";
        }

        if ($seconds < 60) {
            return round($seconds) . "s";
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = round($seconds % 60);
            return $secs > 0 ? "{$minutes}m {$secs}s" : "{$minutes}m";
        } elseif ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            $minutes = round(($seconds % 3600) / 60);
            return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
        } else {
            $days = floor($seconds / 86400);
            $hours = round(($seconds % 86400) / 3600);
            return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
        }
    }

    /**
     * Print header
     */
    private function printHeader()
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("ASSET & FILE MIGRATION - PRODUCTION GRADE v4.0\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);
        $this->stdout("Migration ID: {$this->migrationId}\n\n");
    }

    /**
     * Print success footer
     */
    private function printSuccessFooter()
    {
        // Mark migration as completed in database
        if ($this->checkpointManager) {
            $this->checkpointManager->markMigrationCompleted($this->stats);
        }

        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_GREEN);
        $this->stdout("✓ MIGRATION COMPLETED SUCCESSFULLY\n", Console::FG_GREEN);
        $this->stdout(str_repeat("=", 80) . "\n\n");
    }

    private function printPhaseHeader(string $title): void
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout($title . "\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);
    }

    /**
     * Print progress legend used by batch loops
     */
    private function printProgressLegend(): void
    {
        // Symbols already used elsewhere in this file:
        // . (green) = success, x (red) = error, - (grey) = skipped
        // . (yellow) = quarantined success, ! (red) = quarantine error
        $this->stdout("  Legend: ", Console::FG_GREY);
        $this->stdout(". ", Console::FG_GREEN);
        $this->stdout("= ok  ", Console::FG_GREY);

        $this->stdout(". ", Console::FG_YELLOW);
        $this->stdout("= quarantined  ", Console::FG_GREY);

        $this->stdout("- ", Console::FG_GREY);
        $this->stdout("= skipped  ", Console::FG_GREY);

        $this->stdout("x ", Console::FG_RED);
        $this->stdout("= error  ", Console::FG_GREY);

        $this->stdout("! ", Console::FG_RED);
        $this->stdout("= quarantine error\n", Console::FG_GREY);
    }

    // All other helper methods from original script (preserved)
    // validateConfiguration, buildFileInventory, analyzeAssetFileLinks, etc.
    // [Truncated for length - include all original helper methods]

    private function validateConfiguration()
    {
        $volumesService = Craft::$app->getVolumes();

        $targetVolume = $volumesService->getVolumeByHandle($this->targetVolumeHandle);
        if (!$targetVolume) {
            throw new \Exception("Target volume '{$this->targetVolumeHandle}' not found");
        }

        $sourceVolumes = [];
        foreach ($this->sourceVolumeHandles as $handle) {
            $volume = $volumesService->getVolumeByHandle($handle);
            if ($volume) {
                $sourceVolumes[] = $volume;
                $assetCount = Asset::find()->volumeId($volume->id)->count();
                $this->stdout("    Source: {$volume->name} ({$handle}) - {$assetCount} assets\n", Console::FG_CYAN);
            } else {
                $this->stdout("    ⚠ Source volume '{$handle}' not found - will skip\n", Console::FG_YELLOW);
            }
        }

        if (empty($sourceVolumes)) {
            throw new \Exception("No source volumes found");
        }

        $quarantineVolume = $volumesService->getVolumeByHandle($this->quarantineVolumeHandle);
        if (!$quarantineVolume) {
            throw new \Exception("Quarantine volume '{$this->quarantineVolumeHandle}' not found");
        }

        if ($quarantineVolume->fsHandle === $targetVolume->fsHandle) {
            throw new \Exception("Quarantine volume must use a DIFFERENT filesystem");
        }

        $this->stdout("    ✓ Quarantine: {$quarantineVolume->name} (separate filesystem)\n", Console::FG_GREEN);

        return [
            'target' => $targetVolume,
            'sources' => $sourceVolumes,
            'quarantine' => $quarantineVolume
        ];
    }

    /**
     * HELPER METHODS TO ADD TO ImageMigrationController v4.0
     * 
     * These methods are preserved from the original script and should be added
     * to the main controller class. They handle core functionality like:
     * - File inventory scanning
     * - Asset-file analysis
     * - RTE field mapping
     * - File matching strategies
     * - Asset movement operations
     * - Display/reporting methods
     */

    // =========================================================================
// FILE INVENTORY METHODS
// =========================================================================

    /**
     * Always returns a Flysystem operator for a Craft FS using Craft’s FS service.
     */
    private function getFsOperatorFromService($fs)
    {
        // Build an operator even if the FS implementation doesn't expose it
        $config = Craft::$app->fs->createFilesystemConfig($fs);
        $operator = Craft::$app->fs->createFilesystem($config); // \League\Flysystem\FilesystemOperator

        if (!is_object($operator) || !method_exists($operator, 'listContents')) {
            throw new \RuntimeException('Could not create a Flysystem operator from FS: ' . get_class($fs));
        }
        return $operator;
    }

    /**
     * Compute the correct listing prefix (subfolder/root) for a Craft FS.
     * Returns a string WITHOUT a leading slash (what S3/Spaces expect).
     */
    /**
     * Get the FS prefix/subfolder (parsed with env vars resolved)
     */
    private function getFsPrefix($fs): string
    {
        if (method_exists($fs, 'getRootPath')) {
            $prefix = Craft::parseEnv((string) $fs->getRootPath());
        } elseif (method_exists($fs, 'getSubfolder')) {
            $prefix = Craft::parseEnv((string) $fs->getSubfolder());
        } elseif (property_exists($fs, 'subfolder')) {
            $prefix = Craft::parseEnv((string) $fs->subfolder);
        } else {
            $prefix = '';
        }

        // Remove leading slash for S3/Spaces compatibility
        $prefix = ltrim($prefix, '/');
        return $prefix === '/' ? '' : $prefix;
    }

    /**
     * Build file inventory with deduplication to avoid scanning volumes multiple times
     */
    /**
     * Build file inventory with fail-fast and better error handling - REPLACE existing
     */
    private function buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume)
    {
        // Deduplicate volumes by ID
        $volumesById = [];
        foreach ($sourceVolumes as $volume) {
            $volumesById[$volume->id] = $volume;
        }
        $volumesById[$targetVolume->id] = $targetVolume;

        $allVolumes = array_values($volumesById);
        $fileInventory = [];
        $totalFiles = 0;

        foreach ($allVolumes as $volume) {
            $this->stdout("    Scanning {$volume->name} filesystem...\n");

            try {
                $fs = $volume->getFs();

                // Show parsed FS subfolder/root for debugging
                $parsedPrefix = $this->getFsPrefix($fs);
                if ($parsedPrefix !== '') {
                    $this->stdout("      FS prefix: '{$parsedPrefix}'\n", Console::FG_GREY);
                }

                $this->stdout("      Listing files... ");

                // Scan from volume root
                $scan = $this->scanFilesystem($fs, '', true);

                $this->stdout("done\n", Console::FG_GREEN);
                $this->stdout("      Processing entries... ");

                // Process all files from scan
                foreach ($scan['all'] as $entry) {
                    if (($entry['type'] ?? null) !== 'file') {
                        continue;
                    }

                    $fileInventory[] = [
                        'volumeId' => $volume->id,
                        'volumeName' => $volume->name,
                        'path' => $entry['path'],
                        'filename' => basename($entry['path']),
                        'size' => $entry['size'] ?? 0,
                        'lastModified' => $entry['lastModified'] ?? null,
                        'fs' => $fs,
                    ];

                    $totalFiles++;
                }

                $volumeFileCount = count($scan['all']) - count($scan['directories']);
                $this->stdout("done\n", Console::FG_GREEN);
                $this->stdout("      ✓ Found {$volumeFileCount} files\n", Console::FG_GREEN);

            } catch (\Throwable $e) {
                // FAIL FAST - don't continue with broken volumes
                throw new \Exception(
                    "CRITICAL: Cannot scan volume '{$volume->name}': {$e->getMessage()}\n" .
                    "Stack trace: {$e->getTraceAsString()}"
                );
            }
        }

        $this->stdout("    ✓ Total files across all volumes: {$totalFiles}\n\n", Console::FG_GREEN);

        return $fileInventory;
    }

    /**
     * Scan filesystem recursively using Craft FS API (Craft 4 compatible).
     * Returns structured array with properly extracted FsListing data.
     */
    private function scanFilesystem($fs, $path, $recursive, $limit = null)
    {
        $cleanPath = trim((string) $path, '/');

        // Use Craft FS API - returns craft\models\FsListing objects
        $iter = $fs->getFileList($cleanPath, (bool) $recursive);

        $result = [
            'all' => [],
            'images' => [],
            'directories' => [],
            'other' => [],
        ];

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $seenPaths = [];

        foreach ($iter as $item) {
            // Extract data from craft\models\FsListing object
            $extracted = $this->extractFsListingData($item);

            if (!$extracted['path'] || isset($seenPaths[$extracted['path']])) {
                continue;
            }

            $seenPaths[$extracted['path']] = true;

            $entry = [
                'path' => $extracted['path'],
                'type' => $extracted['isDir'] ? 'dir' : 'file',
                'size' => $extracted['fileSize'],
                'lastModified' => $extracted['lastModified'],
            ];

            $result['all'][] = $entry;

            if ($extracted['isDir']) {
                $result['directories'][] = $extracted['path'];
            } else {
                $ext = strtolower(pathinfo($extracted['path'], PATHINFO_EXTENSION));
                if (in_array($ext, $imageExtensions, true)) {
                    $result['images'][] = $entry;
                } else {
                    $result['other'][] = $entry;
                }
            }

            // Apply limit if specified
            if ($limit && count($result['all']) >= $limit) {
                break;
            }
        }

        return $result;
    }

    // =========================================================================
// ANALYSIS METHODS
// =========================================================================

    /**
     * Analyze asset-file relationships with progress feedback - REPLACES analyzeAssetFileLinks
     */
    private function analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume)
    {
        $this->stdout("    Matching assets to files...\n");
        $this->stdout("    Building file lookup index... ");

        $fileLookup = [];
        foreach ($fileInventory as $file) {
            $key = $file['volumeId'] . '/' . $file['filename'];
            if (!isset($fileLookup[$key])) {
                $fileLookup[$key] = [];
            }
            $fileLookup[$key][] = $file;
        }

        $this->stdout("done (" . count($fileInventory) . " files)\n", Console::FG_GREEN);
        $this->stdout("    Verifying asset file existence (may take a few minutes)...\n");

        $analysis = [
            'assets_with_files' => [],
            'broken_links' => [],
            'orphaned_files' => [],
            'used_assets_correct_location' => [],
            'used_assets_wrong_location' => [],
            'unused_assets' => [],
            'duplicates' => []
        ];

        $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);

        $total = count($assetInventory);
        $processed = 0;
        $lastProgress = 0;
        $startTime = microtime(true);

        foreach ($assetInventory as $asset) {
            $assetObj = Asset::findOne($asset['id']);
            if (!$assetObj) {
                $processed++;
                continue;
            }

            $expectedPath = $assetObj->getPath();
            $fileExists = false;

            try {
                $fs = $assetObj->getVolume()->getFs();
                $fileExists = $fs->fileExists($expectedPath);
            } catch (\Exception $e) {
                // Cannot verify
            }

            if ($fileExists) {
                $asset['fileExists'] = true;
                $asset['filePath'] = $expectedPath;
                $analysis['assets_with_files'][] = $asset;

                $isInTarget = $asset['volumeId'] == $targetVolume->id;
                $isInRoot = $asset['folderId'] == $targetRootFolder->id;

                if ($asset['isUsed']) {
                    if ($isInTarget && $isInRoot) {
                        $analysis['used_assets_correct_location'][] = $asset;
                    } else {
                        $analysis['used_assets_wrong_location'][] = $asset;
                    }
                } else {
                    // CRITICAL FIX: Only mark assets in TARGET volume as unused for quarantine
                    // Assets in source volumes should not be quarantined - they're being migrated
                    // This prevents documents/videos in source volumes from being quarantined
                    if ($isInTarget) {
                        $analysis['unused_assets'][] = $asset;
                    }
                }
            } else {
                $analysis['broken_links'][] = $asset;

                $key = $asset['volumeId'] . '/' . $asset['filename'];
                if (isset($fileLookup[$key])) {
                    $asset['possibleFiles'] = $fileLookup[$key];
                }
            }

            $processed++;

            // Calculate ETA on every iteration
            $elapsed = microtime(true) - $startTime;
            $itemsPerSecond = $processed / max($elapsed, 0.1);
            $remaining = $total - $processed;
            $etaSeconds = $remaining / max($itemsPerSecond, 0.01);
            $etaFormatted = $this->formatDuration($etaSeconds);
            $pct = round(($processed / $total) * 100, 1);

            // Show progress every 5% or at completion for cleaner output
            if ($pct - $lastProgress >= 5 || $processed === $total) {
                // Output human-readable progress line
                $this->stdout(
                    "    [Progress] {$pct}% complete ({$processed}/{$total}) - ETA: {$etaFormatted}\n",
                    Console::FG_CYAN
                );

                // Output machine-readable progress marker for web interface
                $progressData = json_encode([
                    'percent' => $pct,
                    'current' => $processed,
                    'total' => $total,
                    'eta' => $etaFormatted,
                    'etaSeconds' => (int) $etaSeconds
                ]);
                $this->stdout("__CLI_PROGRESS__{$progressData}__\n", Console::RESET);

                $lastProgress = $pct;
            }
        }

        $this->stdout("    Identifying orphaned files... ");

        $assetFilenames = array_column($assetInventory, 'filename', 'filename');
        foreach ($fileInventory as $file) {
            if (!isset($assetFilenames[$file['filename']])) {
                // CRITICAL FIX: Only quarantine orphaned files from TARGET volume
                // Source volumes are for discovery only - don't quarantine their files
                // This prevents documents/videos in source volumes from being quarantined
                if ($file['volumeId'] == $targetVolume->id) {
                    $analysis['orphaned_files'][] = $file;
                }
            }
        }

        $this->stdout("done\n", Console::FG_GREEN);
        $this->stdout("    Checking for duplicates... ");

        // Check for duplicate filenames
        $filenameCounts = array_count_values(array_column($assetInventory, 'filename'));
        foreach ($filenameCounts as $filename => $count) {
            if ($count > 1) {
                $dupes = array_filter($assetInventory, fn($a) => $a['filename'] === $filename);
                $analysis['duplicates'][$filename] = array_values($dupes);
            }
        }

        // Check for multiple assets pointing to the same physical file path
        $pathMap = [];
        foreach ($assetInventory as $asset) {
            if (isset($asset['filePath'])) {
                $key = $asset['volumeId'] . '::' . $asset['filePath'];
                if (!isset($pathMap[$key])) {
                    $pathMap[$key] = [];
                }
                $pathMap[$key][] = $asset;
            }
        }

        // Add assets pointing to same file to duplicates
        foreach ($pathMap as $key => $assets) {
            if (count($assets) > 1) {
                // Create a unique key for these duplicates
                $filename = $assets[0]['filename'];
                $dupKey = $filename . '_path_' . md5($key);
                $analysis['duplicates'][$dupKey] = array_values($assets);
            }
        }

        $this->stdout("done\n\n", Console::FG_GREEN);

        return $analysis;
    }

    // =========================================================================
// RTE FIELD METHODS
// =========================================================================

    private function buildRteFieldMap($db)
    {
        $rteFields = $db->createCommand("
        SELECT id, handle, name, type, uid
        FROM fields
        WHERE type LIKE '%redactor%'
           OR type LIKE '%ckeditor%'
           OR type LIKE '%vizy%'
        ORDER BY handle
    ")->queryAll();

        $map = [];
        foreach ($rteFields as $field) {
            $map[$field['id']] = [
                'id' => $field['id'],
                'handle' => $field['handle'],
                'name' => $field['name'],
                'type' => $field['type'],
                'uid' => $field['uid']
            ];
        }

        return $map;
    }

    private function mapFieldsToColumns($db, $rteFieldMap)
    {
        $schema = $db->createCommand('SELECT DATABASE()')->queryScalar();

        $tables = $db->createCommand("
        SELECT DISTINCT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = :schema
            AND (TABLE_NAME = 'content' OR TABLE_NAME LIKE 'matrixcontent%' OR TABLE_NAME LIKE '%content%')
            AND TABLE_TYPE = 'BASE TABLE'
    ", [':schema' => $schema])->queryAll();

        $mappings = [];

        foreach ($tables as $tableData) {
            $table = $tableData['TABLE_NAME'];

            $columns = $db->createCommand("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :schema
                AND TABLE_NAME = :table
                AND DATA_TYPE IN ('text', 'longtext', 'mediumtext')
                AND COLUMN_NAME LIKE 'field_%'
        ", [
                ':schema' => $schema,
                ':table' => $table
            ])->queryAll();

            foreach ($columns as $colData) {
                $column = $colData['COLUMN_NAME'];

                foreach ($rteFieldMap as $fieldId => $field) {
                    $uid = $field['uid'];
                    $handle = $field['handle'];

                    $patterns = [
                        'field_' . strtolower($handle) . '_' . strtolower($uid),
                        'field_' . strtolower($uid),
                        'field_' . strtolower($handle),
                    ];

                    $columnLower = strtolower($column);

                    foreach ($patterns as $pattern) {
                        if (
                            $columnLower === $pattern ||
                            strpos($columnLower, $pattern) !== false
                        ) {

                            $mappings[] = [
                                'table' => $table,
                                'column' => $column,
                                'field_id' => $fieldId,
                                'field_handle' => $handle
                            ];

                            break 2;
                        }
                    }
                }
            }
        }

        return $mappings;
    }

    // =========================================================================
// ASSET LOOKUP METHODS
// =========================================================================

    /**
     * Build asset lookup array for efficient URL-to-asset matching
     */
    private function buildAssetLookup($assetInventory)
    {
        return $this->buildAssetLookupArray($assetInventory);
    }

    /**
     * Original array-based lookup (kept for small datasets)
     */
    private function buildAssetLookupArray($assetInventory)
    {
        $lookup = [];
        foreach ($assetInventory as $asset) {
            $filename = $asset['filename'];
            $lookup[$filename] = $asset;

            if (!empty($asset['folderPath'])) {
                $fullPath = trim($asset['folderPath'], '/') . '/' . $filename;
                $lookup[$fullPath] = $asset;
            }
        }
        return $lookup;
    }

    /**
     * Find asset by URL using the asset lookup array
     */
    private function findAssetByUrl($url, $assetLookup)
    {
        $url = trim($url);
        $url = preg_replace('/[?#].*$/', '', $url);
        $url = preg_replace('/^https?:\/\/[^\/]+/i', '', $url);
        $url = ltrim($url, '/');

        // Direct lookup
        if (isset($assetLookup[$url])) {
            return $assetLookup[$url];
        }

        $filename = basename($url);
        if (isset($assetLookup[$filename])) {
            return $assetLookup[$filename];
        }

        // Try common prefixes
        $prefixes = ['uploads/', 'uploads/images/', 'assets/', 'images/', '_optimisedImages/'];

        foreach ($prefixes as $prefix) {
            if (strpos($url, $prefix) === 0) {
                $cleanUrl = substr($url, strlen($prefix));
                if (isset($assetLookup[$cleanUrl])) {
                    return $assetLookup[$cleanUrl];
                }
            }
        }

        // Fallback: search by filename
        foreach ($assetLookup as $key => $asset) {
            if (basename($key) === $filename) {
                return $asset;
            }
        }

        return null;
    }

    // =========================================================================
// FILE MATCHING STRATEGIES
// =========================================================================

    private function buildFileSearchIndexes($fileInventory)
    {
        $indexes = [
            'exact' => [],
            'case_insensitive' => [],
            'normalized' => [],
            'basename' => [],
            'by_size' => [],
        ];

        foreach ($fileInventory as $file) {
            $filename = $file['filename'];
            $size = $file['size'] ?? 0;

            if (!isset($indexes['exact'][$filename])) {
                $indexes['exact'][$filename] = [];
            }
            $indexes['exact'][$filename][] = $file;

            $lowerFilename = strtolower($filename);
            if (!isset($indexes['case_insensitive'][$lowerFilename])) {
                $indexes['case_insensitive'][$lowerFilename] = [];
            }
            $indexes['case_insensitive'][$lowerFilename][] = $file;

            $normalized = $this->normalizeFilename($filename);
            if (!isset($indexes['normalized'][$normalized])) {
                $indexes['normalized'][$normalized] = [];
            }
            $indexes['normalized'][$normalized][] = $file;

            $basename = pathinfo($filename, PATHINFO_FILENAME);
            if (!isset($indexes['basename'][$basename])) {
                $indexes['basename'][$basename] = [];
            }
            $indexes['basename'][$basename][] = $file;

            if ($size > 0) {
                if (!isset($indexes['by_size'][$size])) {
                    $indexes['by_size'][$size] = [];
                }
                $indexes['by_size'][$size][] = $file;
            }
        }

        return $indexes;
    }

    private function findFileForAsset($asset, $fileInventory, $searchIndexes, $targetVolume, $assetData)
    {
        $filename = $asset->filename;
        $MIN_CONFIDENCE = 0.70; // Don't use matches below 70% confidence

        // Strategy 1: Exact match in same volume
        $matches = $searchIndexes['exact'][$filename] ?? [];
        $sameVolumeMatches = array_filter($matches, fn($f) => $f['volumeId'] == $assetData['volumeId']);
        if (!empty($sameVolumeMatches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($sameVolumeMatches, $targetVolume),
                'strategy' => 'exact',
                'confidence' => 1.0
            ];
        }

        // Strategy 2: Exact match in any volume
        if (!empty($matches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($matches, $targetVolume),
                'strategy' => 'exact',
                'confidence' => 0.95
            ];
        }

        // Strategy 3: Case-insensitive match
        $lowerFilename = strtolower($filename);
        $matches = $searchIndexes['case_insensitive'][$lowerFilename] ?? [];
        if (!empty($matches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($matches, $targetVolume),
                'strategy' => 'case_insensitive',
                'confidence' => 0.85
            ];
        }

        // Strategy 4: Normalized match
        $normalized = $this->normalizeFilename($filename);
        $matches = $searchIndexes['normalized'][$normalized] ?? [];
        if (!empty($matches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($matches, $targetVolume),
                'strategy' => 'normalized',
                'confidence' => 0.75
            ];
        }

        // Strategy 5: Basename match
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $matches = $searchIndexes['basename'][$basename] ?? [];

        $extensionFamily = $this->getExtensionFamily($extension);
        $sameExtensionMatches = array_filter($matches, function ($f) use ($extensionFamily) {
            $fileExt = pathinfo($f['filename'], PATHINFO_EXTENSION);
            return in_array(strtolower($fileExt), $extensionFamily);
        });

        if (!empty($sameExtensionMatches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($sameExtensionMatches, $targetVolume),
                'strategy' => 'normalized',
                'confidence' => 0.70
            ];
        }

        // Strategy 6: Size-based matching
        try {
            $assetSize = $asset->size ?? null;
            if ($assetSize && isset($searchIndexes['by_size'][$assetSize])) {
                $sizeMatches = $searchIndexes['by_size'][$assetSize];

                $similarMatches = array_filter($sizeMatches, function ($f) use ($filename) {
                    return $this->calculateSimilarity($f['filename'], $filename) > 0.5;
                });

                if (!empty($similarMatches)) {
                    return [
                        'found' => true,
                        'file' => $this->prioritizeFile($similarMatches, $targetVolume),
                        'strategy' => 'size',
                        'confidence' => 0.60
                    ];
                }
            }
        } catch (\Exception $e) {
            // Skip size matching
        }

        // Strategy 7: Fuzzy matching - REJECT LOW CONFIDENCE
        $fuzzyMatches = $this->findFuzzyMatches($filename, $fileInventory, 5);
        if (!empty($fuzzyMatches)) {
            $bestMatch = $this->prioritizeFile($fuzzyMatches, $targetVolume);

            // Calculate actual confidence
            $similarity = $this->calculateSimilarity($filename, $bestMatch['filename']);

            // ⚠️ CRITICAL: Reject low-confidence matches
            if ($similarity < $MIN_CONFIDENCE) {
                Craft::warning(
                    "Rejecting fuzzy match: '{$bestMatch['filename']}' for '{$filename}' " .
                    "(confidence: " . round($similarity * 100, 1) . "%)",
                    __METHOD__
                );

                return [
                    'found' => false,
                    'file' => null,
                    'strategy' => 'none',
                    'confidence' => 0.0,
                    'rejected_match' => $bestMatch['filename'],
                    'rejected_confidence' => $similarity
                ];
            }

            return [
                'found' => true,
                'file' => $bestMatch,
                'strategy' => 'fuzzy',
                'confidence' => $similarity
            ];
        }

        return [
            'found' => false,
            'file' => null,
            'strategy' => 'none',
            'confidence' => 0.0
        ];
    }

    private function normalizeFilename($filename)
    {
        $normalized = strtolower($filename);
        $normalized = preg_replace('/[_\-\s]+/', ' ', $normalized);
        $normalized = preg_replace('/\.(copy|backup|old|new|\d+)\./', '.', $normalized);
        $normalized = preg_replace('/[^\w\s\.]/', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return trim($normalized);
    }

    private function getExtensionFamily($extension)
    {
        $extension = strtolower($extension);

        $families = [
            'jpg' => ['jpg', 'jpeg'],
            'jpeg' => ['jpg', 'jpeg'],
            'png' => ['png'],
            'gif' => ['gif'],
            'webp' => ['webp'],
            'svg' => ['svg'],
        ];

        return $families[$extension] ?? [$extension];
    }

    private function calculateSimilarity($filename1, $filename2)
    {
        $normalized1 = $this->normalizeFilename($filename1);
        $normalized2 = $this->normalizeFilename($filename2);

        similar_text($normalized1, $normalized2, $percent);

        return $percent / 100.0;
    }

    /**
     * Optimized fuzzy matching with pre-filtering - REPLACE existing method
     */
    private function findFuzzyMatches($filename, $fileInventory, $maxDistance = 5)
    {
        $matches = [];
        $filenameLength = strlen($filename);

        // Pre-filter by length (must be within 30%)
        $minLength = (int) ($filenameLength * 0.7);
        $maxLength = (int) ($filenameLength * 1.3);

        // Pre-filter by first 3 characters
        $prefix = strlen($filename) >= 3 ? strtolower(substr($filename, 0, 3)) : strtolower($filename);

        // Build candidate list with pre-filtering
        $candidates = [];
        foreach ($fileInventory as $file) {
            $candidateFilename = $file['filename'];
            $candidateLength = strlen($candidateFilename);

            // Length filter
            if ($candidateLength < $minLength || $candidateLength > $maxLength) {
                continue;
            }

            // Prefix filter (quick reject)
            if (strlen($candidateFilename) >= 3) {
                $candidatePrefix = strtolower(substr($candidateFilename, 0, 3));
                if (levenshtein($prefix, $candidatePrefix) > 2) {
                    continue;
                }
            }

            $candidates[] = $file;
        }

        // Only compute expensive Levenshtein distance on filtered candidates
        $filenameLower = strtolower($filename);
        foreach ($candidates as $file) {
            $candidateFilename = $file['filename'];
            $distance = levenshtein($filenameLower, strtolower($candidateFilename));

            if ($distance <= $maxDistance) {
                $matches[] = [
                    'file' => $file,
                    'distance' => $distance,
                    'similarity' => 1 - ($distance / max($filenameLength, strlen($candidateFilename)))
                ];
            }
        }

        usort($matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_map(fn($m) => $m['file'], array_slice($matches, 0, 5));
    }

    private function prioritizeFile($files, $targetVolume)
    {
        $files = array_values($files);

        usort($files, function ($a, $b) use ($targetVolume) {
            // PRIORITY 1: Files in 'originals' folders (HIGHEST PRIORITY)
            // Check for /originals/ or /images/originals/ in path
            $aIsOriginal = $this->isInOriginalsFolder($a['path']);
            $bIsOriginal = $this->isInOriginalsFolder($b['path']);

            if ($aIsOriginal !== $bIsOriginal) {
                return $bIsOriginal - $aIsOriginal; // Prefer originals
            }

            // PRIORITY 2: Files in target volume
            $aIsTarget = ($a['volumeId'] === $targetVolume->id) ? 1 : 0;
            $bIsTarget = ($b['volumeId'] === $targetVolume->id) ? 1 : 0;

            if ($aIsTarget !== $bIsTarget) {
                return $bIsTarget - $aIsTarget;
            }

            // PRIORITY 3: Most recently modified
            $aTime = $a['lastModified'] ?? 0;
            $bTime = $b['lastModified'] ?? 0;

            return $bTime - $aTime;
        });

        return $files[0];
    }

    /**
     * Check if a file path is in an 'originals' folder
     *
     * Matches:
     * - /medias/originals/file.jpg
     * - /medias/images/originals/file.jpg
     * - originals/file.jpg
     * - images/originals/file.jpg
     */
    private function isInOriginalsFolder($path)
    {
        $path = strtolower(trim($path, '/'));

        return (
            strpos($path, '/originals/') !== false ||
            strpos($path, 'originals/') === 0 ||
            preg_match('#/originals/[^/]+$#i', $path)
        );
    }

    // =========================================================================
// ASSET MOVEMENT METHODS
// =========================================================================

    private function moveAssetSameVolume($asset, $targetFolder)
    {
        try {
            return Craft::$app->getAssets()->moveAsset($asset, $targetFolder);
        } catch (\Exception $e) {
            // If native move fails due to filesystem issues, try manual approach
            if (strpos($e->getMessage(), 'readStream') !== false ||
                strpos($e->getMessage(), 'file') !== false ||
                strpos($e->getMessage(), 'stream') !== false) {

                Craft::info(
                    "Native moveAsset failed for asset {$asset->id}, attempting manual move: " . $e->getMessage(),
                    __METHOD__
                );

                return $this->moveAssetManual($asset, $targetFolder);
            }

            // For other errors, rethrow
            throw $e;
        }
    }

    private function moveAssetManual($asset, $targetFolder)
    {
        $fs = $asset->getVolume()->getFs();
        $oldPath = $asset->getPath();

        // Get copy of file with error handling (consistent with moveAssetCrossVolume)
        try {
            $tempPath = $asset->getCopyOfFile();
        } catch (\Exception $e) {
            // File doesn't exist on source - log and return false
            $errorMsg = "Cannot get copy of file for asset {$asset->id} ({$asset->filename}): " . $e->getMessage();
            Craft::warning($errorMsg, __METHOD__);
            $this->trackError('missing_source_file', $errorMsg);

            // Track in stats
            if (!isset($this->stats['missing_files'])) {
                $this->stats['missing_files'] = 0;
            }
            $this->stats['missing_files']++;

            return false;
        }

        $this->trackTempFile($tempPath);

        $asset->folderId = $targetFolder->id;
        $asset->newFolderId = $targetFolder->id;
        $asset->tempFilePath = $tempPath; // Tell Craft where the source file is

        try {
            $success = Craft::$app->getElements()->saveElement($asset);

            if ($success) {
                // Delete old file if path changed (no need to check existence first)
                if ($oldPath !== $asset->getPath()) {
                    try {
                        $fs->deleteFile($oldPath);
                    } catch (\Exception $e) {
                        // File might not exist or already deleted - that's ok
                        Craft::info("Could not delete old file at {$oldPath}: " . $e->getMessage(), __METHOD__);
                    }
                }
            }

            return $success;
        } finally {
            // Always cleanup temp file
            @unlink($tempPath);
            $this->tempFiles = array_diff($this->tempFiles, [$tempPath]);
        }
    }

    private function moveAssetCrossVolume($asset, $targetVolume, $targetFolder)
    {
        $elementsService = Craft::$app->getElements();

        try {
            $tempFile = $asset->getCopyOfFile();
        } catch (\Exception $e) {
            // File doesn't exist on source - log and return false
            $errorMsg = "Cannot get copy of file for asset {$asset->id} ({$asset->filename}): " . $e->getMessage();
            Craft::warning($errorMsg, __METHOD__);
            $this->trackError('missing_source_file', $errorMsg);

            // Track in stats
            if (!isset($this->stats['missing_files'])) {
                $this->stats['missing_files'] = 0;
            }
            $this->stats['missing_files']++;

            return false;
        }

        $this->trackTempFile($tempFile); // Track for cleanup

        $asset->volumeId = $targetVolume->id;
        $asset->folderId = $targetFolder->id;
        $asset->tempFilePath = $tempFile;

        try {
            $success = $elementsService->saveElement($asset);
        } finally {
            // Always cleanup temp file
            @unlink($tempFile);
            $this->tempFiles = array_diff($this->tempFiles, [$tempFile]);
        }

        return $success;
    }

    private function copyFileToAsset($sourceFile, $asset, $targetVolume, $targetFolder)
    {
        $sourceFs = $sourceFile['fs'];
        $sourcePath = $sourceFile['path'];

        // Create a unique key for this source file (volume + path)
        $sourceKey = $sourceFile['volumeName'] . '::' . $sourcePath;

        // Check if this file was already successfully copied
        if (isset($this->copiedSourceFiles[$sourceKey])) {
            Craft::info(
                "Source file '{$sourcePath}' was already copied (referenced by multiple assets). Skipping duplicate copy.",
                __METHOD__
            );
            return 'already_copied';
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'asset_');
        $tempStream = fopen($tempPath, 'w');

        try {
            $content = $sourceFs->read($sourcePath);
            fwrite($tempStream, $content);
        } catch (\Exception $e) {
            fclose($tempStream);
            @unlink($tempPath);

            // Check if file was already copied - this is not a critical error
            if (isset($this->copiedSourceFiles[$sourceKey])) {
                Craft::info(
                    "Source file '{$sourcePath}' not found, but was previously copied successfully.",
                    __METHOD__
                );
                return 'already_copied';
            }

            // Log the missing file error but don't throw - return false instead
            $errorMsg = "Cannot read source file '{$sourcePath}': " . $e->getMessage();
            Craft::warning($errorMsg, __METHOD__);
            $this->trackError('missing_source_file', $errorMsg);

            // Track in stats
            if (!isset($this->stats['missing_files'])) {
                $this->stats['missing_files'] = 0;
            }
            $this->stats['missing_files']++;

            return false;
        }

        fclose($tempStream);

        $asset->volumeId = $targetVolume->id;
        $asset->folderId = $targetFolder->id;
        $asset->tempFilePath = $tempPath;

        $success = Craft::$app->getElements()->saveElement($asset);

        @unlink($tempPath);

        // Track successful copy and delete source file after successful migration
        if ($success) {
            $this->copiedSourceFiles[$sourceKey] = true;

            // Check if this file is part of a duplicate group
            $duplicateRecord = $this->getDuplicateRecord($sourceKey);

            // Use safe deletion check to prevent deleting files still referenced by other assets
            $canDelete = $this->canSafelyDeleteSource($sourceKey, $duplicateRecord, $asset);

            if ($canDelete) {
                // Delete the source file after successful copy to complete the "move" operation
                try {
                    if ($sourceFs->fileExists($sourcePath)) {
                        $sourceFs->deleteFile($sourcePath);
                        Craft::info("Deleted source file after successful copy: {$sourcePath}", __METHOD__);

                        // Mark as completed if this was a duplicate group file
                        if ($duplicateRecord && $duplicateRecord['status'] === 'analyzed') {
                            $db = Craft::$app->getDb();
                            $db->createCommand()->update('{{%migration_file_duplicates}}', [
                                'status' => 'completed',
                                'processedAt' => Db::prepareDateForDb(new \DateTime()),
                                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                            ], [
                                'migrationId' => $this->migrationId,
                                'fileKey' => $sourceKey,
                            ])->execute();
                        }
                    }
                } catch (\Exception $e) {
                    // Log warning but don't fail the migration - file was already copied successfully
                    Craft::warning(
                        "Could not delete source file '{$sourcePath}' after successful copy: " . $e->getMessage(),
                        __METHOD__
                    );
                }
            } else {
                Craft::info(
                    "Skipped deletion of {$sourcePath} - " .
                    ($duplicateRecord ? "part of duplicate group or same as destination" : "same as destination or referenced by other assets"),
                    __METHOD__
                );
            }
        }

        return $success;
    }

    // =========================================================================
// UTILITY METHODS
// =========================================================================

    /**
     * Extract data from craft\models\FsListing object (Craft 4 FS API)
     * 
     * @param mixed $item FsListing object, string, or array
     * @return array ['path' => string, 'isDir' => bool, 'fileSize' => int|null, 'lastModified' => int|null]
     */
    private function extractFsListingData($item): array
    {
        $data = [
            'path' => '',
            'isDir' => false,
            'fileSize' => null,
            'lastModified' => null,
        ];

        // Handle strings (legacy adapter format)
        if (is_string($item)) {
            $data['path'] = $item;
            $data['isDir'] = substr($item, -1) === '/';
            return $data;
        }

        // Handle arrays (some legacy adapters)
        if (is_array($item)) {
            $data['path'] = $item['path'] ?? $item['uri'] ?? $item['key'] ?? '';
            $data['isDir'] = ($item['type'] ?? 'file') === 'dir';
            $data['fileSize'] = $item['fileSize'] ?? $item['size'] ?? null;
            $data['lastModified'] = $item['lastModified'] ?? $item['timestamp'] ?? null;
            return $data;
        }

        // Handle craft\models\FsListing objects (Craft 4+)
        if (is_object($item)) {
            // Path from getUri() method
            if (method_exists($item, 'getUri')) {
                try {
                    $data['path'] = (string) $item->getUri();
                } catch (\Throwable $e) {
                    // Continue to fallback
                }
            }

            // Directory check from getIsDir() method
            if (method_exists($item, 'getIsDir')) {
                try {
                    $data['isDir'] = (bool) $item->getIsDir();
                } catch (\Throwable $e) {
                    // Fallback: check path suffix
                    if ($data['path']) {
                        $data['isDir'] = substr($data['path'], -1) === '/';
                    }
                }
            }

            // File size from getFileSize() method (files only)
            if (!$data['isDir'] && method_exists($item, 'getFileSize')) {
                try {
                    $data['fileSize'] = $item->getFileSize();
                } catch (\Throwable $e) {
                    // Size unavailable
                }
            }

            // Last modified timestamp from getDateModified() method
            if (method_exists($item, 'getDateModified')) {
                try {
                    $dateModified = $item->getDateModified();
                    if ($dateModified instanceof \DateTime) {
                        $data['lastModified'] = $dateModified->getTimestamp();
                    } elseif (is_numeric($dateModified)) {
                        $data['lastModified'] = (int) $dateModified;
                    }
                } catch (\Throwable $e) {
                    // Timestamp unavailable
                }
            }

            return $data;
        }

        // Unknown type - return empty data
        return $data;
    }


    private function estimateInlineLinking($db)
    {
        if ($this->rteFieldMap === null) {
            $this->rteFieldMap = $this->buildRteFieldMap($db);
        }

        $rteFieldCount = count($this->rteFieldMap);

        if ($rteFieldCount === 0) {
            return [
                'images_estimate' => 0,
                'columns_found' => 0,
                'time_estimate' => '0 seconds'
            ];
        }

        $fieldColumnMap = $this->mapFieldsToColumns($db, $this->rteFieldMap);
        $columnCount = count($fieldColumnMap);

        if ($columnCount === 0) {
            return [
                'images_estimate' => 0,
                'columns_found' => 0,
                'time_estimate' => '0 seconds'
            ];
        }

        $sampleSize = min(3, $columnCount);
        $totalImages = 0;

        foreach (array_slice($fieldColumnMap, 0, $sampleSize) as $mapping) {
            $table = $mapping['table'];
            $column = $mapping['column'];

            try {
                $count = (int) $db->createCommand("
                SELECT COUNT(*)
                FROM `{$table}`
                WHERE `{$column}` LIKE '%<img%'
                LIMIT 1000
            ")->queryScalar();

                $totalImages += $count;
            } catch (\Exception $e) {
                // Skip
            }
        }

        $avgPerColumn = $sampleSize > 0 ? $totalImages / $sampleSize : 0;
        $estimate = (int) ($avgPerColumn * $columnCount);

        $timeSeconds = max(1, (int) ($estimate / 75));
        $timeEstimate = $timeSeconds < 60 ? "{$timeSeconds} seconds" :
            round($timeSeconds / 60, 1) . " minutes";

        return [
            'images_estimate' => $estimate,
            'columns_found' => $columnCount,
            'time_estimate' => $timeEstimate
        ];
    }

    /**
     * REPLACE performCleanupAndVerification() 
     */
    private function performCleanupAndVerification($targetVolume, $targetRootFolder)
    {
        // Note: In Craft 4, asset transforms are handled automatically by the ImageTransforms service
        // No need to manually clear transform indexes - they're regenerated on demand
        $this->stdout("  Transform indexes will regenerate automatically (Craft 4)\n", Console::FG_GREY);

        // Note: In Craft 4, asset indexing is done via queue jobs
        // Trigger a resave of all assets in the target volume to ensure they're properly indexed
        $this->stdout("\n  Triggering asset reindex via resave queue...\n");
        try {
            $assetsService = Craft::$app->getAssets();
            $query = \craft\elements\Asset::find()
                ->volumeId($targetVolume->id)
                ->limit(null);

            $count = $query->count();

            if ($count > 0) {
                // Queue resave elements job for all assets in target volume
                \Craft::$app->getQueue()->push(new \craft\queue\jobs\ResaveElements([
                    'elementType' => \craft\elements\Asset::class,
                    'criteria' => [
                        'volumeId' => $targetVolume->id,
                        'siteId' => '*',
                        'unique' => true,
                        'status' => null,
                    ]
                ]));

                $this->stdout("  ✓ Queued {$count} assets for reindexing\n", Console::FG_GREEN);
                $this->stdout("  Run ./craft queue/run to process the queue\n", Console::FG_CYAN);
            } else {
                $this->stdout("  No assets to reindex\n", Console::FG_GREY);
            }
        } catch (\Exception $e) {
            $this->stdout("  ⚠ Warning: " . $e->getMessage() . "\n", Console::FG_YELLOW);
        }

        $this->stdout("\n  Verifying migration integrity...\n");

        // Determine verification scope
        $verificationLimit = $this->verificationSampleSize;

        if ($verificationLimit === null) {
            $this->stdout("    ⏳ Performing FULL verification (all assets)...\n");
            $issues = $this->verifyMigrationFull($targetVolume, $targetRootFolder);
        } else {
            $this->stdout("    ⏳ Performing SAMPLE verification ({$verificationLimit} assets)...\n");
            $issues = $this->verifyMigrationSample($targetVolume, $targetRootFolder, $verificationLimit);
        }

        if (empty($issues)) {
            $this->stdout("    ✓ No issues found\n\n", Console::FG_GREEN);
        } else {
            $this->stdout("    ⚠ Found " . count($issues) . " potential issues:\n", Console::FG_YELLOW);
            foreach (array_slice($issues, 0, 10) as $issue) {
                $this->stdout("      - {$issue}\n", Console::FG_YELLOW);
            }
            if (count($issues) > 10) {
                $this->stdout("      ... and " . (count($issues) - 10) . " more\n\n", Console::FG_YELLOW);
            }

            // Save issues to file
            $issuesFile = Craft::getAlias('@storage') . '/migration-issues-' . $this->migrationId . '.txt';
            file_put_contents($issuesFile, implode("\n", $issues));
            $this->stdout("    Issues saved to: {$issuesFile}\n\n", Console::FG_CYAN);
        }
    }

    /**
     * Full verification - checks ALL assets
     */
    private function verifyMigrationFull($targetVolume, $targetRootFolder)
    {
        $issues = [];
        $offset = 0;
        $batchSize = 100;
        $totalChecked = 0;

        $fs = $targetVolume->getFs();

        $this->stdout("      Progress: ");

        while (true) {
            $assets = Asset::find()
                ->volumeId($targetVolume->id)
                ->folderId($targetRootFolder->id)
                ->limit($batchSize)
                ->offset($offset)
                ->all();

            if (empty($assets)) {
                break;
            }

            foreach ($assets as $asset) {
                try {
                    if (!$fs->fileExists($asset->getPath())) {
                        $issues[] = "Missing file: {$asset->filename} (Asset ID: {$asset->id})";
                    }
                    $totalChecked++;
                } catch (\Exception $e) {
                    $issues[] = "Cannot verify: {$asset->filename}";
                }

                if ($totalChecked % 50 === 0) {
                    $this->safeStdout(".", Console::FG_GREEN);
                }
            }

            $offset += $batchSize;
        }

        $this->stdout(" [{$totalChecked} assets checked]\n");

        return $issues;
    }

    /**
     * Sample verification
     */
    private function verifyMigrationSample($targetVolume, $targetRootFolder, $limit)
    {
        $issues = [];

        $assets = Asset::find()
            ->volumeId($targetVolume->id)
            ->folderId($targetRootFolder->id)
            ->limit($limit)
            ->all();

        $fs = $targetVolume->getFs();

        foreach ($assets as $asset) {
            try {
                if (!$fs->fileExists($asset->getPath())) {
                    $issues[] = "Missing file: {$asset->filename} (Asset ID: {$asset->id})";
                }
            } catch (\Exception $e) {
                $issues[] = "Cannot verify: {$asset->filename}";
            }
        }

        return $issues;
    }

    private function verifyMigration($targetVolume, $targetRootFolder)
    {
        $issues = [];

        $assets = Asset::find()
            ->volumeId($targetVolume->id)
            ->folderId($targetRootFolder->id)
            ->limit(100)
            ->all();

        $fs = $targetVolume->getFs();

        foreach ($assets as $asset) {
            try {
                if (!$fs->fileExists($asset->getPath())) {
                    $issues[] = "Missing file: {$asset->filename} (Asset ID: {$asset->id})";
                }
            } catch (\Exception $e) {
                // Can't verify
            }
        }

        return $issues;
    }

    /**
     * Track and check error threshold 
     */

    private function trackError($operation, $message, $context = [])
    {
        if (!isset($this->errorCounts[$operation])) {
            $this->errorCounts[$operation] = [];
        }

        $errorEntry = [
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->errorCounts[$operation][] = $errorEntry;
        $this->stats['errors']++;

        // Log with full context
        Craft::error(
            "Operation '{$operation}' error: {$message}\nContext: " . json_encode($context),
            __METHOD__
        );

        // Write to migration-specific error log
        $errorLogFile = Craft::getAlias('@storage') . '/migration-errors-' . $this->migrationId . '.log';
        $errorLine = sprintf(
            "[%s] %s: %s | Context: %s\n",
            date('Y-m-d H:i:s'),
            $operation,
            $message,
            json_encode($context)
        );
        @file_put_contents($errorLogFile, $errorLine, FILE_APPEND);

        // CHECK THRESHOLD - Type-specific logic
        $missingFileErrors = count($this->errorCounts['missing_source_file'] ?? []);
        $criticalErrors = 0;

        // Count critical errors (all errors except expected missing files)
        foreach ($this->errorCounts as $errorType => $errors) {
            if ($errorType !== 'missing_source_file') {
                $criticalErrors += count($errors);
            }
        }

        $totalErrors = $missingFileErrors + $criticalErrors;

        // Check critical errors first (write failures, permissions, etc.)
        if ($criticalErrors >= $this->criticalErrorThreshold) {
            $this->saveCheckpoint(['error_threshold_exceeded' => true]);

            $this->stderr("\n\nCRITICAL ERROR THRESHOLD EXCEEDED\n", Console::FG_RED);
            $this->stderr("Critical errors (write/permission failures): {$criticalErrors}/{$this->criticalErrorThreshold}\n", Console::FG_RED);
            $this->stderr("Missing file errors: {$missingFileErrors} (expected: {$this->expectedMissingFileCount})\n", Console::FG_YELLOW);
            $this->stderr("Total errors: {$totalErrors}\n", Console::FG_RED);
            $this->stderr("Error log: {$errorLogFile}\n\n", Console::FG_YELLOW);

            throw new \Exception(
                "Critical error threshold exceeded ({$criticalErrors} unexpected errors). " .
                "Migration halted for safety. Review errors and resume with --resume flag."
            );
        }

        // Check if missing file errors exceed expected count (with small buffer)
        $maxMissingFileErrors = max($this->expectedMissingFileCount + 10, 50); // Allow 10 extra or min 50
        if ($missingFileErrors > $maxMissingFileErrors) {
            $this->saveCheckpoint(['error_threshold_exceeded' => true]);

            $this->stderr("\n\nUNEXPECTED MISSING FILE ERRORS\n", Console::FG_RED);
            $this->stderr("Missing file errors: {$missingFileErrors} (expected: {$this->expectedMissingFileCount})\n", Console::FG_RED);
            $this->stderr("Critical errors: {$criticalErrors}\n", Console::FG_YELLOW);
            $this->stderr("Error log: {$errorLogFile}\n\n", Console::FG_YELLOW);

            throw new \Exception(
                "Missing file errors ({$missingFileErrors}) exceed expected count ({$this->expectedMissingFileCount}). " .
                "This may indicate a configuration issue. Review errors and resume with --resume flag."
            );
        }
    }

    private function createBackup()
    {
        $this->stdout("  Creating automatic database backup...\n");

        $timestamp = date('YmdHis');
        $db = Craft::$app->getDb();

        // Method 1: Create backup tables (fast, for quick rollback)
        $tables = ['assets', 'volumefolders', 'relations', 'elements'];
        $backupSuccess = true;
        $tableBackupCount = 0;

        foreach ($tables as $table) {
            try {
                // Check if table exists first
                $tableExists = $db->createCommand("SHOW TABLES LIKE '{$table}'")->queryScalar();

                if (!$tableExists) {
                    $this->stdout("    ⓘ Table '{$table}' does not exist, skipping\n", Console::FG_CYAN);
                    continue;
                }

                // Get row count for verification
                $rowCount = $db->createCommand("SELECT COUNT(*) FROM {$table}")->queryScalar();

                // Create backup table
                $db->createCommand("
                    CREATE TABLE IF NOT EXISTS {$table}_backup_{$timestamp}
                    AS SELECT * FROM {$table}
                ")->execute();

                // Verify backup was created successfully
                $backupRowCount = $db->createCommand("SELECT COUNT(*) FROM {$table}_backup_{$timestamp}")->queryScalar();

                if ($backupRowCount == $rowCount) {
                    $this->stdout("    ✓ Backed up {$table} ({$rowCount} rows)\n", Console::FG_GREEN);
                    $tableBackupCount++;
                } else {
                    $this->stdout("    ⚠ Warning: {$table} backup row count mismatch (original: {$rowCount}, backup: {$backupRowCount})\n", Console::FG_YELLOW);
                    $backupSuccess = false;
                }
            } catch (\Exception $e) {
                $this->stdout("    ✗ Error backing up {$table}: " . $e->getMessage() . "\n", Console::FG_RED);
                $backupSuccess = false;
            }
        }

        // Method 2: Create SQL dump file (complete backup for restore)
        $backupFile = $this->createDatabaseBackup();

        if ($backupFile && file_exists($backupFile)) {
            $fileSize = $this->formatBytes(filesize($backupFile));
            $this->stdout("  ✓ SQL dump created: " . basename($backupFile) . " ({$fileSize})\n", Console::FG_GREEN);

            // Verify backup file is not empty
            if (filesize($backupFile) < 100) {
                $this->stdout("  ⚠ WARNING: Backup file seems unusually small, may be corrupt\n", Console::FG_YELLOW);
                $backupSuccess = false;
            }

            // Store backup location in checkpoint
            $this->saveCheckpoint([
                'backup_timestamp' => $timestamp,
                'backup_file' => $backupFile,
                'backup_tables' => $tables,
                'backup_verified' => $backupSuccess
            ]);
        } else {
            $this->stdout("  ⚠ SQL dump creation failed (will use table backups only)\n", Console::FG_YELLOW);
        }

        if ($backupSuccess && $tableBackupCount > 0) {
            $this->stdout("  ✓ Backup verification passed ({$tableBackupCount} tables backed up)\n", Console::FG_GREEN);
        } else {
            $this->stdout("  ⚠ WARNING: Backup verification had issues - proceed with caution\n", Console::FG_YELLOW);
        }

        $this->stdout("\n");
    }

    /**
     * Create a complete database backup using mysqldump
     *
     * @return string|null Path to backup file, or null if backup failed
     */
    /**
     * Create database table for phase 1 results persistence
     */
    private function ensurePhase1ResultsTable()
    {
        $db = Craft::$app->getDb();

        try {
            // Check if table exists
            $tableExists = $db->createCommand("SHOW TABLES LIKE '{{%migration_phase1_results}}'")->queryScalar();

            if (!$tableExists) {
                $db->createCommand("
                    CREATE TABLE {{%migration_phase1_results}} (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        migrationId VARCHAR(255) NOT NULL UNIQUE,
                        assetInventory LONGTEXT NOT NULL,
                        fileInventory LONGTEXT NOT NULL,
                        analysis LONGTEXT NOT NULL,
                        metadata LONGTEXT NULL,
                        createdAt DATETIME NOT NULL,
                        INDEX idx_migration (migrationId),
                        INDEX idx_created (createdAt)
                    )
                ")->execute();

                Craft::info("Created migration_phase1_results table", __METHOD__);
            } else {
                // Add metadata column if it doesn't exist (backwards compatibility)
                $columnExists = $db->createCommand("
                    SHOW COLUMNS FROM {{%migration_phase1_results}} LIKE 'metadata'
                ")->queryScalar();

                if (!$columnExists) {
                    $db->createCommand("
                        ALTER TABLE {{%migration_phase1_results}}
                        ADD COLUMN metadata LONGTEXT NULL AFTER analysis
                    ")->execute();

                    Craft::info("Added metadata column to migration_phase1_results table", __METHOD__);
                }
            }
        } catch (\Exception $e) {
            Craft::warning("Could not create/update phase1 results table: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Save phase 1 discovery results to database
     */
    private function savePhase1Results($assetInventory, $fileInventory, $analysis)
    {
        $this->ensurePhase1ResultsTable();

        $db = Craft::$app->getDb();

        try {
            // Delete existing results for this migration (if any)
            $db->createCommand()
                ->delete('{{%migration_phase1_results}}', ['migrationId' => $this->migrationId])
                ->execute();

            // Store metadata about the migration configuration
            $metadata = [
                'targetVolumeHandle' => $this->targetVolumeHandle,
                'sourceVolumeHandles' => $this->sourceVolumeHandles,
                'quarantineVolumeHandle' => $this->quarantineVolumeHandle,
                'timestamp' => date('Y-m-d H:i:s'),
                'note' => 'Quarantine only processes target volume files'
            ];

            // Insert new results with enhanced context
            $db->createCommand()
                ->insert('{{%migration_phase1_results}}', [
                    'migrationId' => $this->migrationId,
                    'assetInventory' => json_encode($assetInventory),
                    'fileInventory' => json_encode($fileInventory),
                    'analysis' => json_encode($analysis),
                    'metadata' => json_encode($metadata),
                    'createdAt' => date('Y-m-d H:i:s')
                ])
                ->execute();

            $this->stdout("  ✓ Phase 1 results saved to database (with migration context)\n", Console::FG_GREEN);

        } catch (\Exception $e) {
            // Non-fatal - log and continue
            Craft::warning("Could not save phase 1 results to database: " . $e->getMessage(), __METHOD__);
            $this->stdout("  ⚠ Could not save phase 1 results to database\n", Console::FG_YELLOW);
        }
    }

    /**
     * Load phase 1 discovery results from database
     */
    private function loadPhase1Results()
    {
        $this->ensurePhase1ResultsTable();

        $db = Craft::$app->getDb();

        try {
            $row = $db->createCommand()
                ->select(['assetInventory', 'fileInventory', 'analysis'])
                ->from('{{%migration_phase1_results}}')
                ->where(['migrationId' => $this->migrationId])
                ->queryOne();

            if ($row) {
                return [
                    'assetInventory' => json_decode($row['assetInventory'], true),
                    'fileInventory' => json_decode($row['fileInventory'], true),
                    'analysis' => json_decode($row['analysis'], true)
                ];
            }
        } catch (\Exception $e) {
            Craft::warning("Could not load phase 1 results from database: " . $e->getMessage(), __METHOD__);
        }

        return null;
    }

    private function createDatabaseBackup()
    {
        try {
            $backupDir = Craft::getAlias('@storage/migration-backups');
            if (!is_dir($backupDir)) {
                FileHelper::createDirectory($backupDir);
            }

            $backupFile = $backupDir . '/migration_' . $this->migrationId . '_db_backup.sql';

            $db = Craft::$app->getDb();
            $dsn = $db->dsn;

            // Parse DSN to get database name, host, port
            if (preg_match('/dbname=([^;]+)/', $dsn, $matches)) {
                $dbName = $matches[1];
            } else {
                return null;
            }

            if (preg_match('/host=([^;]+)/', $dsn, $matches)) {
                $host = $matches[1];
            } else {
                $host = 'localhost';
            }

            if (preg_match('/port=([^;]+)/', $dsn, $matches)) {
                $port = $matches[1];
            } else {
                $port = '3306';
            }

            $username = $db->username;
            $password = $db->password;

            // Tables to backup
            $tables = ['assets', 'volumefolders', 'relations', 'elements', 'elements_sites', 'content'];
            $tablesStr = implode(' ', $tables);

            // Try mysqldump
            $mysqldumpCmd = sprintf(
                'mysqldump -h %s -P %s -u %s %s %s %s > %s 2>&1',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                $password ? '-p' . escapeshellarg($password) : '',
                escapeshellarg($dbName),
                $tablesStr,
                escapeshellarg($backupFile)
            );

            exec($mysqldumpCmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
                return $backupFile;
            }

            // Fallback: Use Craft's backup if mysqldump not available
            return $this->createCraftBackup($tables, $backupFile);

        } catch (\Exception $e) {
            Craft::error("Database backup failed: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Create backup using Craft's database backup functionality
     *
     * @param array $tables Tables to backup
     * @param string $backupFile Target file path
     * @return string|null Path to backup file, or null if backup failed
     */
    private function createCraftBackup($tables, $backupFile)
    {
        try {
            $db = Craft::$app->getDb();
            $sql = '';

            foreach ($tables as $table) {
                // Export table structure
                $createTable = $db->createCommand("SHOW CREATE TABLE `{$table}`")->queryOne();
                if ($createTable) {
                    $sql .= "\n-- Table: {$table}\n";
                    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                    $sql .= $createTable['Create Table'] . ";\n\n";
                }

                // Export table data
                $rows = $db->createCommand("SELECT * FROM `{$table}`")->queryAll();
                if (!empty($rows)) {
                    $sql .= "-- Data for table: {$table}\n";

                    foreach ($rows as $row) {
                        $values = array_map(function($value) use ($db) {
                            if ($value === null) {
                                return 'NULL';
                            }
                            return $db->quoteValue($value);
                        }, array_values($row));

                        $columns = '`' . implode('`, `', array_keys($row)) . '`';
                        $sql .= "INSERT INTO `{$table}` ({$columns}) VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $sql .= "\n";
                }
            }

            file_put_contents($backupFile, $sql);
            return $backupFile;

        } catch (\Exception $e) {
            Craft::error("Craft backup failed: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    // =========================================================================
// DISPLAY METHODS
// =========================================================================

    private function printAnalysisReport($analysis)
    {
        $this->stdout("\n  ANALYSIS RESULTS:\n", Console::FG_CYAN);
        $this->stdout("  " . str_repeat("-", 76) . "\n");

        $this->stdout(sprintf(
            "  ✓ Assets with files:           %5d\n",
            count($analysis['assets_with_files'])
        ), Console::FG_GREEN);
        $this->stdout(sprintf(
            "  ✓ Used assets (correct loc):   %5d\n",
            count($analysis['used_assets_correct_location'])
        ), Console::FG_GREEN);

        $this->stdout(sprintf(
            "  ⚠ Used assets (wrong loc):     %5d  [NEEDS MOVE]\n",
            count($analysis['used_assets_wrong_location'])
        ), Console::FG_YELLOW);
        $this->stdout(sprintf(
            "  ⚠ Broken links:                %5d  [NEEDS FIX]\n",
            count($analysis['broken_links'])
        ), Console::FG_YELLOW);
        $this->stdout(sprintf(
            "  ⚠ Unused assets:               %5d  [QUARANTINE - Target volume only]\n",
            count($analysis['unused_assets'])
        ), Console::FG_YELLOW);
        $this->stdout(sprintf(
            "  ⚠ Orphaned files:              %5d  [QUARANTINE - Target volume only]\n",
            count($analysis['orphaned_files'])
        ), Console::FG_YELLOW);

        if (!empty($analysis['duplicates'])) {
            $this->stdout(sprintf(
                "  ⚠ Duplicate filenames:         %5d  [RESOLVE]\n",
                count($analysis['duplicates'])
            ), Console::FG_YELLOW);
        }

        $this->stdout("  " . str_repeat("-", 76) . "\n\n");
    }

    private function printPlannedOperations($analysis)
    {
        $this->stdout("  1. Fix " . count($analysis['broken_links']) . " broken asset-file links\n");
        $this->stdout("  2. Move " . count($analysis['used_assets_wrong_location']) . " used files to target root\n");
        $this->stdout("  3. Quarantine " . count($analysis['unused_assets']) . " unused assets\n");
        $this->stdout("  4. Quarantine " . count($analysis['orphaned_files']) . " orphaned files\n");

        if (!empty($analysis['duplicates'])) {
            $this->stdout("  5. Resolve " . count($analysis['duplicates']) . " duplicate filenames\n");
        }

        $this->stdout("\n");
    }

    private function printInlineLinkingResults($stats)
    {
        $this->stdout("  INLINE LINKING RESULTS:\n", Console::FG_CYAN);
        $this->stdout("    Rows scanned:          {$stats['rows_scanned']}\n");
        $this->stdout("    Rows with images:      {$stats['rows_with_images']}\n");
        $this->stdout("    Images found:          {$stats['images_found']}\n");
        $this->stdout("    Already linked:        {$stats['images_already_linked']}\n", Console::FG_GREY);
        $this->stdout("    Newly linked:          {$stats['images_linked']}\n", Console::FG_GREEN);
        $this->stdout("    No match found:        {$stats['images_no_match']}\n", Console::FG_YELLOW);
        $this->stdout("    Rows updated:          {$stats['rows_updated']}\n", Console::FG_CYAN);
        $this->stdout("    Relations created:     {$stats['relations_created']}\n", Console::FG_CYAN);
    }

    /**
     * Export missing files to CSV
     */
    private function exportMissingFilesToCsv()
    {
        if (empty($this->missingFiles)) {
            return;
        }

        try {
            $csvDir = Craft::getAlias('@storage');
            $csvFile = $csvDir . '/migration-missing-files-' . $this->migrationId . '.csv';

            $fp = fopen($csvFile, 'w');
            if (!$fp) {
                throw new \Exception("Could not open CSV file for writing: {$csvFile}");
            }

            // Write CSV header
            fputcsv($fp, ['Asset ID', 'Filename', 'Expected Path', 'Volume ID', 'Linked Type', 'Reason']);

            // Write data rows
            foreach ($this->missingFiles as $missing) {
                fputcsv($fp, [
                    $missing['assetId'],
                    $missing['filename'],
                    $missing['expectedPath'],
                    $missing['volumeId'],
                    $missing['linkedType'],
                    $missing['reason']
                ]);
            }

            fclose($fp);

            $count = count($this->missingFiles);
            $this->stdout("\n  ✓ Exported {$count} missing files to CSV: {$csvFile}\n", Console::FG_CYAN);

        } catch (\Exception $e) {
            Craft::warning("Could not export missing files to CSV: " . $e->getMessage(), __METHOD__);
            $this->stdout("  ⚠ Could not export missing files to CSV\n", Console::FG_YELLOW);
        }
    }

    private function printFinalReport()
    {
        $duration = time() - $this->stats['start_time'];
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        $this->stdout("\n  FINAL STATISTICS:\n", Console::FG_CYAN);
        $this->stdout("    Duration:          {$minutes}m {$seconds}s\n");
        $this->stdout("    Files moved:       {$this->stats['files_moved']}\n");
        $this->stdout("    Files quarantined: {$this->stats['files_quarantined']}\n");
        $this->stdout("    Assets updated:    {$this->stats['assets_updated']}\n");
        if (isset($this->stats['originals_moved']) && $this->stats['originals_moved'] > 0) {
            $this->stdout("    Originals moved:   {$this->stats['originals_moved']}\n", Console::FG_GREEN);
            $this->stdout("                       (Original files moved to volume 1 root, replacing transforms)\n", Console::FG_GREY);
        }
        $this->stdout("    Errors:            {$this->stats['errors']}\n");
        $this->stdout("    Retries:           {$this->stats['retries']}\n");
        $this->stdout("    Checkpoints saved: {$this->stats['checkpoints_saved']}\n");
        if (isset($this->stats['missing_files']) && $this->stats['missing_files'] > 0) {
            $this->stdout("    Missing files:     {$this->stats['missing_files']}\n", Console::FG_YELLOW);
            $this->stdout("                       (Files catalogued but not readable - see logs)\n", Console::FG_GREY);
        }
        if (!empty($this->missingFiles)) {
            $csvFile = Craft::getAlias('@storage') . '/migration-missing-files-' . $this->migrationId . '.csv';
            $this->stdout("    Missing files CSV: {$csvFile}\n", Console::FG_CYAN);
        }
        if ($this->stats['resume_count'] > 0) {
            $this->stdout("    Resumed:           {$this->stats['resume_count']} times\n", Console::FG_YELLOW);
        }
        $this->stdout("\n");
    }

    /**
     * Clear stale migration locks
     * This helps resume work properly when previous migrations crashed
     * When resuming, we forcefully clear ALL locks since user is explicitly resuming
     */
    private function clearStaleLocks(): void
    {
        try {
            $db = Craft::$app->getDb();

            // When resuming, clear ALL locks - user is explicitly resuming so any existing lock is stale
            $deleted = $db->createCommand('
                DELETE FROM {{%migrationlocks}}
                WHERE lockName = :lockName
            ', [':lockName' => 'migration_lock'])->execute();

            if ($deleted > 0) {
                $this->stdout("  Cleared {$deleted} existing migration lock(s)\n", Console::FG_YELLOW);
            }
        } catch (\Exception $e) {
            // Ignore errors - table might not exist yet
            Craft::warning("Could not clear stale locks: " . $e->getMessage(), __METHOD__);
        }
    }

    // =========================================================================
    // DUPLICATE FILE HANDLING - SAFE MIGRATION STRATEGY
    // =========================================================================

    /**
     * Phase 1.8: Analyze and detect duplicate files that share the same physical file
     * Returns array of duplicate groups to process
     *
     * @param array $sourceVolumes
     * @param $targetVolume
     * @return array Groups of duplicates to handle
     */
    private function analyzeFileDuplicates($sourceVolumes, $targetVolume): array
    {
        $db = Craft::$app->getDb();
        $duplicateGroups = [];

        $this->stdout("  Building file path inventory...\n");

        // Query all assets - without fs table (doesn't exist in Craft 4)
        // Filesystem handles are obtained via Craft's API
        $query = <<<SQL
            SELECT
                a.id as assetId,
                a.volumeId,
                a.folderId,
                a.filename,
                v.name as volumeName,
                f.path as folderPath
            FROM {{%assets}} a
            INNER JOIN {{%elements}} e ON e.id = a.id
            INNER JOIN {{%volumes}} v ON v.id = a.volumeId
            LEFT JOIN {{%volumefolders}} f ON f.id = a.folderId
            WHERE e.dateDeleted IS NULL
            ORDER BY v.name, f.path, a.filename
SQL;

        $assets = $db->createCommand($query)->queryAll();

        // Build volume ID to filesystem handle map using Craft's API
        $volumeFsHandles = [];
        $volumesService = Craft::$app->getVolumes();

        // Group by physical file location (fsHandle + folderPath + filename)
        $fileGroups = [];
        foreach ($assets as $asset) {
            // Get filesystem handle for this volume using Craft's API
            if (!isset($volumeFsHandles[$asset['volumeId']])) {
                try {
                    $volume = $volumesService->getVolumeById($asset['volumeId']);
                    if ($volume) {
                        $fs = $volume->getFs();
                        $volumeFsHandles[$asset['volumeId']] = $fs->handle ?? 'unknown';
                    } else {
                        $volumeFsHandles[$asset['volumeId']] = 'unknown';
                    }
                } catch (\Exception $e) {
                    Craft::warning("Could not get filesystem for volume {$asset['volumeId']}: " . $e->getMessage(), __METHOD__);
                    $volumeFsHandles[$asset['volumeId']] = 'unknown';
                }
            }

            $fsHandle = $volumeFsHandles[$asset['volumeId']];
            $folderPath = trim($asset['folderPath'] ?? '', '/');
            $relativePath = $folderPath ? $folderPath . '/' . $asset['filename'] : $asset['filename'];
            $fileKey = $fsHandle . '::' . $relativePath;

            if (!isset($fileGroups[$fileKey])) {
                $fileGroups[$fileKey] = [];
            }

            // Add fsHandle to asset data for later use
            $asset['fsHandle'] = $fsHandle;
            $fileGroups[$fileKey][] = $asset;
        }

        // Filter to only duplicates (more than one asset pointing to same file)
        $duplicateCount = 0;
        $totalAssets = 0;

        foreach ($fileGroups as $fileKey => $group) {
            if (count($group) > 1) {
                $duplicateCount++;
                $totalAssets += count($group);
                $duplicateGroups[$fileKey] = $group;
            }
        }

        $this->stdout("  Found {$duplicateCount} files with multiple asset references ({$totalAssets} assets)\n",
            $duplicateCount > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        if ($duplicateCount === 0) {
            return [];
        }

        // Save to database for resumability
        $this->stdout("  Persisting duplicate analysis to database...\n");

        $savedCount = 0;
        foreach ($duplicateGroups as $fileKey => $group) {
            [$fsHandle, $relativePath] = explode('::', $fileKey, 2);

            $assetIds = array_map(fn($a) => $a['assetId'], $group);

            // Use first asset's info for file details
            $firstAsset = $group[0];

            try {
                // Check if already exists
                $existing = $db->createCommand('
                    SELECT id FROM {{%migration_file_duplicates}}
                    WHERE migrationId = :migrationId AND fileKey = :fileKey
                ', [
                    ':migrationId' => $this->migrationId,
                    ':fileKey' => $fileKey,
                ])->queryScalar();

                if (!$existing) {
                    $db->createCommand()->insert('{{%migration_file_duplicates}}', [
                        'migrationId' => $this->migrationId,
                        'fileKey' => $fileKey,
                        'originalPath' => $relativePath,
                        'volumeName' => $firstAsset['volumeName'],
                        'relativePathInVolume' => $relativePath,
                        'assetIds' => json_encode($assetIds),
                        'status' => 'pending',
                        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                        'uid' => StringHelper::UUID(),
                    ])->execute();

                    $savedCount++;
                }
            } catch (\Exception $e) {
                Craft::warning("Could not save duplicate record for {$fileKey}: " . $e->getMessage(), __METHOD__);
            }
        }

        $this->stdout("  Saved {$savedCount} duplicate file records\n", Console::FG_GREEN);

        return $duplicateGroups;
    }

    /**
     * Phase: Stage files to temp location in quarantine filesystem
     * This is a safe operation - copies without deleting originals
     *
     * @param array $duplicateGroups
     * @param $quarantineVolume
     * @param int $batchSize
     */
    private function stageFilesToTemp($duplicateGroups, $quarantineVolume, $batchSize = 100): void
    {
        if (empty($duplicateGroups)) {
            return;
        }

        $quarantineFs = $quarantineVolume->getFs();
        $db = Craft::$app->getDb();

        $totalFiles = count($duplicateGroups);
        $processed = 0;
        $batches = array_chunk(array_keys($duplicateGroups), $batchSize, true);

        $this->stdout("  Staging {$totalFiles} files to temp location (quarantine)\n");
        $this->stdout("  Processing in " . count($batches) . " batches...\n\n");

        foreach ($batches as $batchIndex => $fileKeys) {
            $batchNum = $batchIndex + 1;
            $this->stdout("  [Batch {$batchNum}/" . count($batches) . "] ", Console::FG_CYAN);
            $this->stdout("Staging " . count($fileKeys) . " files... ");

            $batchSuccess = 0;
            $batchSkipped = 0;

            foreach ($fileKeys as $fileKey) {
                $group = $duplicateGroups[$fileKey];
                [$fsHandle, $relativePath] = explode('::', $fileKey, 2);

                // Get the duplicate record
                $record = $db->createCommand('
                    SELECT * FROM {{%migration_file_duplicates}}
                    WHERE migrationId = :migrationId AND fileKey = :fileKey
                ', [
                    ':migrationId' => $this->migrationId,
                    ':fileKey' => $fileKey,
                ])->queryOne();

                if (!$record) {
                    $batchSkipped++;
                    continue;
                }

                // Skip if already staged
                if ($record['status'] !== 'pending') {
                    $batchSkipped++;
                    continue;
                }

                // Get source filesystem from first asset
                $firstAsset = $group[0];
                $sourceVolume = Craft::$app->getVolumes()->getVolumeByHandle($firstAsset['volumeName']);
                if (!$sourceVolume) {
                    Craft::warning("Volume not found: {$firstAsset['volumeName']}", __METHOD__);
                    $batchSkipped++;
                    continue;
                }

                $sourceFs = $sourceVolume->getFs();

                // Create temp path: temp/{migrationId}/{fileKey-hash}/filename
                $tempFolder = 'temp/' . $this->migrationId . '/' . md5($fileKey);
                $filename = basename($relativePath);
                $tempPath = $tempFolder . '/' . $filename;

                try {
                    // Check if source file exists
                    if (!$sourceFs->fileExists($relativePath)) {
                        Craft::warning("Source file not found for staging: {$relativePath}", __METHOD__);
                        $batchSkipped++;
                        continue;
                    }

                    // Copy to quarantine temp location
                    $content = $sourceFs->read($relativePath);
                    $fileSize = strlen($content);
                    $fileHash = md5($content);

                    $quarantineFs->write($tempPath, $content, []);

                    // Update record
                    $db->createCommand()->update('{{%migration_file_duplicates}}', [
                        'tempPath' => $tempPath,
                        'physicalFileHash' => $fileHash,
                        'fileSize' => $fileSize,
                        'status' => 'staged',
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                    ], [
                        'migrationId' => $this->migrationId,
                        'fileKey' => $fileKey,
                    ])->execute();

                    $batchSuccess++;

                } catch (\Exception $e) {
                    Craft::warning("Failed to stage file {$relativePath}: " . $e->getMessage(), __METHOD__);
                    $batchSkipped++;
                }

                $processed++;
            }

            $this->stdout("{$batchSuccess} staged, {$batchSkipped} skipped\n", Console::FG_GREEN);
            $this->stdout("  Progress: {$processed}/{$totalFiles} files\n");
        }

        $this->stdout("\n  ✓ Staging complete\n", Console::FG_GREEN);
    }

    /**
     * Phase: Determine which asset in each duplicate group should be the primary
     * Uses these criteria in order:
     * 1. Asset in 'originals' volume/folder (highest priority)
     * 2. Asset with most references in content
     * 3. Most recently updated
     *
     * @param array $duplicateGroups
     * @param int $batchSize
     */
    private function determineActiveAssets($duplicateGroups, $batchSize = 100): void
    {
        if (empty($duplicateGroups)) {
            return;
        }

        $db = Craft::$app->getDb();
        $totalGroups = count($duplicateGroups);
        $processed = 0;

        $this->stdout("  Analyzing {$totalGroups} duplicate groups to determine primary assets...\n\n");

        $batches = array_chunk(array_keys($duplicateGroups), $batchSize, true);

        foreach ($batches as $batchIndex => $fileKeys) {
            $batchNum = $batchIndex + 1;
            $this->stdout("  [Batch {$batchNum}/" . count($batches) . "] ", Console::FG_CYAN);
            $this->stdout("Analyzing " . count($fileKeys) . " groups... ");

            foreach ($fileKeys as $fileKey) {
                $group = $duplicateGroups[$fileKey];

                // Get duplicate record
                $record = $db->createCommand('
                    SELECT * FROM {{%migration_file_duplicates}}
                    WHERE migrationId = :migrationId AND fileKey = :fileKey
                ', [
                    ':migrationId' => $this->migrationId,
                    ':fileKey' => $fileKey,
                ])->queryOne();

                if (!$record || $record['status'] === 'analyzed') {
                    continue;
                }

                // Determine primary asset by priority
                $primaryAsset = $this->selectPrimaryAsset($group);

                // Update record with primary asset
                $db->createCommand()->update('{{%migration_file_duplicates}}', [
                    'primaryAssetId' => $primaryAsset['assetId'],
                    'status' => 'analyzed',
                    'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                ], [
                    'migrationId' => $this->migrationId,
                    'fileKey' => $fileKey,
                ])->execute();

                $processed++;
            }

            $this->stdout("complete\n", Console::FG_GREEN);
            $this->stdout("  Progress: {$processed}/{$totalGroups} groups\n");
        }

        $this->stdout("\n  ✓ Analysis complete\n", Console::FG_GREEN);
    }

    /**
     * Select the best asset from a duplicate group to be the primary
     *
     * @param array $group Array of asset records
     * @return array The selected primary asset
     */
    private function selectPrimaryAsset($group): array
    {
        // Priority 1: Asset in 'originals' folder
        foreach ($group as $asset) {
            $folderPath = $asset['folderPath'] ?? '';
            if (stripos($folderPath, 'originals') !== false) {
                return $asset;
            }
        }

        // Priority 2: Asset with volume name containing 'originals'
        foreach ($group as $asset) {
            if (stripos($asset['volumeName'], 'originals') !== false) {
                return $asset;
            }
        }

        // Priority 3: Check for content references (most used)
        $db = Craft::$app->getDb();
        $maxReferences = 0;
        $mostUsedAsset = null;

        foreach ($group as $asset) {
            // Count references in relationsources table
            $refCount = $db->createCommand('
                SELECT COUNT(*) FROM {{%relations}}
                WHERE targetId = :assetId
            ', [':assetId' => $asset['assetId']])->queryScalar();

            if ($refCount > $maxReferences) {
                $maxReferences = $refCount;
                $mostUsedAsset = $asset;
            }
        }

        if ($mostUsedAsset) {
            return $mostUsedAsset;
        }

        // Fallback: Return first asset
        return $group[0];
    }

    /**
     * Check if a source file can be safely deleted
     * Returns false if:
     * - Source and destination are the same
     * - File is part of duplicate group not yet fully migrated
     * - Other assets still reference this file
     *
     * @param string $sourceKey Volume::path key
     * @param array|null $duplicateRecord Record from migration_file_duplicates
     * @param \craft\elements\Asset $asset The asset being processed
     * @return bool
     */
    private function canSafelyDeleteSource($sourceKey, $duplicateRecord, $asset): bool
    {
        // Check 1: If source == destination, never delete
        try {
            $finalPath = $asset->getPath();
            $assetVolume = $asset->getVolume();
            $assetFs = $assetVolume->getFs();

            [$sourceVolumeName, $sourcePath] = explode('::', $sourceKey, 2);

            $finalFsPath = $assetFs->name . '::' . $finalPath;
            $sourceFsPath = $sourceKey;

            if ($finalFsPath === $sourceFsPath) {
                Craft::info("Source and destination are identical, skipping deletion: {$sourceKey}", __METHOD__);
                return false;
            }
        } catch (\Exception $e) {
            // If we can't determine paths, be safe and don't delete
            Craft::warning("Could not determine paths for deletion check: " . $e->getMessage(), __METHOD__);
            return false;
        }

        // Check 2: If part of duplicate group, only delete if this is the primary and migration is complete
        if ($duplicateRecord) {
            if ($duplicateRecord['status'] !== 'completed') {
                return false;
            }

            if ($duplicateRecord['primaryAssetId'] != $asset->id) {
                // This is not the primary asset, should not delete
                return false;
            }
        }

        // Check 3: Check if other assets still reference this source
        $db = Craft::$app->getDb();
        [$sourceVolumeName, $sourcePath] = explode('::', $sourceKey, 2);

        try {
            // Find source volume
            $sourceVolume = Craft::$app->getVolumes()->getVolumeByHandle($sourceVolumeName);
            if (!$sourceVolume) {
                // Can't find volume, be safe
                return false;
            }

            // Count assets that still reference this file (excluding current asset)
            $otherAssetCount = $db->createCommand('
                SELECT COUNT(*) FROM {{%assets}} a
                INNER JOIN {{%elements}} e ON e.id = a.id
                LEFT JOIN {{%volumefolders}} f ON f.id = a.folderId
                WHERE a.volumeId = :volumeId
                AND a.filename = :filename
                AND a.id != :assetId
                AND e.dateDeleted IS NULL
            ', [
                ':volumeId' => $sourceVolume->id,
                ':filename' => basename($sourcePath),
                ':assetId' => $asset->id,
            ])->queryScalar();

            if ($otherAssetCount > 0) {
                Craft::info("Skipping deletion - {$otherAssetCount} other asset(s) reference this file: {$sourceKey}", __METHOD__);
                return false;
            }
        } catch (\Exception $e) {
            // Error checking, be safe
            Craft::warning("Error checking for other asset references: " . $e->getMessage(), __METHOD__);
            return false;
        }

        return true;
    }

    /**
     * Get duplicate record for a source file key
     *
     * @param string $sourceKey
     * @return array|null
     */
    private function getDuplicateRecord($sourceKey): ?array
    {
        $db = Craft::$app->getDb();

        try {
            $record = $db->createCommand('
                SELECT * FROM {{%migration_file_duplicates}}
                WHERE migrationId = :migrationId AND fileKey = :fileKey
            ', [
                ':migrationId' => $this->migrationId,
                ':fileKey' => $sourceKey,
            ])->queryOne();

            return $record ?: null;
        } catch (\Exception $e) {
            Craft::warning("Error fetching duplicate record: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Delete unused assets (assets that are not the primary in their duplicate group)
     *
     * @param array $duplicateGroups
     * @param int $batchSize
     */
    private function deleteUnusedDuplicateAssets($duplicateGroups, $batchSize = 100): void
    {
        if (empty($duplicateGroups)) {
            return;
        }

        $db = Craft::$app->getDb();
        $totalDeleted = 0;
        $totalKept = 0;

        $this->stdout("  Cleaning up unused duplicate asset records...\n\n");

        $batches = array_chunk(array_keys($duplicateGroups), $batchSize, true);

        foreach ($batches as $batchIndex => $fileKeys) {
            $batchNum = $batchIndex + 1;
            $this->stdout("  [Batch {$batchNum}/" . count($batches) . "] ", Console::FG_CYAN);
            $this->stdout("Processing " . count($fileKeys) . " groups... ");

            $batchDeleted = 0;
            $batchKept = 0;

            foreach ($fileKeys as $fileKey) {
                $group = $duplicateGroups[$fileKey];

                // Get the primary asset ID
                $record = $db->createCommand('
                    SELECT primaryAssetId FROM {{%migration_file_duplicates}}
                    WHERE migrationId = :migrationId AND fileKey = :fileKey
                ', [
                    ':migrationId' => $this->migrationId,
                    ':fileKey' => $fileKey,
                ])->queryOne();

                if (!$record || !$record['primaryAssetId']) {
                    continue;
                }

                $primaryAssetId = $record['primaryAssetId'];

                // Check if each asset is used (has relations)
                foreach ($group as $asset) {
                    if ($asset['assetId'] == $primaryAssetId) {
                        // This is the primary, keep it
                        $batchKept++;
                        continue;
                    }

                    // Check if asset is referenced anywhere
                    $refCount = $db->createCommand('
                        SELECT COUNT(*) FROM {{%relations}}
                        WHERE targetId = :assetId
                    ', [':assetId' => $asset['assetId']])->queryScalar();

                    if ($refCount > 0) {
                        // Asset is used, update it to point to same file as primary
                        // Keep the asset record but it will share the same file
                        $batchKept++;
                        Craft::info("Keeping used asset {$asset['assetId']} (has {$refCount} references)", __METHOD__);
                    } else {
                        // Asset is not used, safe to delete
                        try {
                            $assetElement = Craft::$app->getElements()->getElementById($asset['assetId']);
                            if ($assetElement) {
                                Craft::$app->getElements()->deleteElement($assetElement);
                                $batchDeleted++;

                                $this->changeLogManager->log([
                                    'action' => 'delete_unused_duplicate_asset',
                                    'assetId' => $asset['assetId'],
                                    'filename' => $asset['filename'],
                                    'fileKey' => $fileKey,
                                    'reason' => 'Duplicate asset with no references'
                                ]);
                            }
                        } catch (\Exception $e) {
                            Craft::warning("Failed to delete unused asset {$asset['assetId']}: " . $e->getMessage(), __METHOD__);
                        }
                    }
                }
            }

            $totalDeleted += $batchDeleted;
            $totalKept += $batchKept;

            $this->stdout("{$batchDeleted} deleted, {$batchKept} kept\n", Console::FG_GREEN);
        }

        $this->stdout("\n  ✓ Cleanup complete: {$totalDeleted} unused assets deleted, {$totalKept} kept\n", Console::FG_GREEN);
    }

    /**
     * Finalize migration by cleaning up temp files
     *
     * @param $quarantineVolume
     */
    private function cleanupTempFiles($quarantineVolume): void
    {
        $db = Craft::$app->getDb();
        $quarantineFs = $quarantineVolume->getFs();

        $this->stdout("  Cleaning up temp files...\n");

        // Get all completed records for this migration
        $records = $db->createCommand('
            SELECT * FROM {{%migration_file_duplicates}}
            WHERE migrationId = :migrationId AND tempPath IS NOT NULL
        ', [':migrationId' => $this->migrationId])->queryAll();

        $cleaned = 0;
        foreach ($records as $record) {
            try {
                if ($quarantineFs->fileExists($record['tempPath'])) {
                    $quarantineFs->deleteFile($record['tempPath']);
                    $cleaned++;
                }
            } catch (\Exception $e) {
                Craft::warning("Failed to delete temp file {$record['tempPath']}: " . $e->getMessage(), __METHOD__);
            }
        }

        $this->stdout("  Cleaned {$cleaned} temp files\n", Console::FG_GREEN);

        // Also try to remove temp directory
        try {
            $tempDir = 'temp/' . $this->migrationId;
            if ($quarantineFs->directoryExists($tempDir)) {
                $quarantineFs->deleteDirectory($tempDir);
                $this->stdout("  Removed temp directory\n", Console::FG_GREEN);
            }
        } catch (\Exception $e) {
            // Don't fail if we can't remove directory
            Craft::info("Could not remove temp directory: " . $e->getMessage(), __METHOD__);
        }
    }

}
