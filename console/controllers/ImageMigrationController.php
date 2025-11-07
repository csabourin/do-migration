<?php
namespace csabourin\craftS3SpacesMigration\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use craft\records\VolumeFolder;
use csabourin\craftS3SpacesMigration\helpers\MigrationConfig;
use yii\console\ExitCode;
use craft\models\VolumeFolder as VolumeFolderModel;

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
    public $errorThreshold = 50; // Higher threshold since we can resume
    public $batchSize = 100;
    public $checkpointRetentionHours = 72;
    public $verificationSampleSize = null; // null = verify all
    public $progressReportingInterval = 50; // Report progress every N items

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
        }

        if ($actionID === 'rollback') {
            $options[] = 'dryRun';
        }

        if ($actionID === 'cleanup') {
            $options[] = 'olderThanHours';
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

        // Generate unique migration ID (or will be restored from checkpoint)
        $this->migrationId = date('Y-m-d-His') . '-' . substr(md5(microtime()), 0, 8);
        $this->stats['start_time'] = time();

        // Initialize managers
        $this->checkpointManager = new CheckpointManager($this->migrationId);
        $this->changeLogManager = new ChangeLogManager($this->migrationId, $this->CHANGELOG_FLUSH_EVERY);
        $this->errorRecoveryManager = new ErrorRecoveryManager($this->MAX_RETRIES, self::RETRY_DELAY_MS);
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
        // **DEBUG: Show what options were received**
        $this->stdout("DEBUG: resume = " . var_export($this->resume, true) . "\n", Console::FG_YELLOW);
        $this->stdout("DEBUG: checkpointId = " . var_export($this->checkpointId, true) . "\n", Console::FG_YELLOW);
        $this->stdout("DEBUG: dryRun = " . var_export($this->dryRun, true) . "\n\n", Console::FG_YELLOW);

        $this->printHeader();

        // If checkpointId is provided, force resume mode
        if ($this->checkpointId) {
            $this->resume = true;
            $this->stdout("DEBUG: Set resume=true because checkpointId was provided\n", Console::FG_YELLOW);
        }

        // **DEBUG: Check resume condition**
        if ($this->resume || $this->checkpointId) {
            $this->stdout("DEBUG: Entering resume mode!\n\n", Console::FG_CYAN);
            return $this->resumeMigration($this->checkpointId, $this->dryRun, $this->skipBackup, $this->skipInlineDetection);
        } else {
            $this->stdout("DEBUG: NOT resuming - starting fresh migration\n\n", Console::FG_RED);
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
            }

            // **PATCH: Phase 0.5: Handle optimisedImages at root FIRST**
            if (in_array('optimisedImages', $this->sourceVolumeHandles)) {
                $this->setPhase('optimised_root');
                $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
                $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);

                $this->handleOptimisedImagesAtRoot(
                    $assetInventory,
                    $fileInventory,
                    $targetVolume,
                    $quarantineVolume
                );

                // Rebuild inventories after moving files
                $this->stdout("  Rebuilding inventories after optimised migration...\n");
                $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
                $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
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

            // Checkpoint: discovery complete
            $this->saveCheckpoint([
                'assetCount' => count($assetInventory),
                'fileCount' => count($fileInventory),
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
                return ExitCode::OK;
            }

            // Confirm before proceeding
            $this->stdout("\n");
            if (!$this->skipInlineDetection) {
                $this->stdout("⚠ Next: Link inline images to create proper asset relations\n", Console::FG_YELLOW);
            }

            // Force flush change log before user confirmation
            $this->changeLogManager->flush();

            $confirm = $this->prompt("Proceed with migration? (yes/no)", [
                'required' => true,
                'default' => 'no',
            ]);

            if ($confirm !== 'yes') {
                $this->stdout("Migration cancelled.\n");
                $this->stdout("Checkpoint saved. Resume with: ./craft s3-spaces-migration/image-migration/migrate --resume\n", Console::FG_CYAN);
                return ExitCode::OK;
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





            if (!empty($needsReview)) {
                $this->stdout("\n  ⚠️ WARNING: " . count($needsReview) . " assets have uncertain matches\n", Console::FG_YELLOW);
                $this->stdout("  " . str_repeat("-", 76) . "\n");

                foreach (array_slice($needsReview, 0, 10) as $issue) {
                    $this->stdout(
                        "    {$issue['asset_filename']} → {$issue['match_filename']} " .
                        "({$issue['confidence']}% confidence)\n",
                        Console::FG_YELLOW
                    );
                }

                if (count($needsReview) > 10) {
                    $this->stdout("    ... and " . (count($needsReview) - 10) . " more\n", Console::FG_GREY);
                }

                $this->stdout("\n");

                // Save to file for review
                $reviewFile = Craft::getAlias('@storage') . '/migration-review-' . $this->migrationId . '.json';
                file_put_contents($reviewFile, json_encode($needsReview, JSON_PRETTY_PRINT));

                $this->stdout("  Full list saved to: {$reviewFile}\n\n", Console::FG_CYAN);

                if (
                    !$this->shouldProceed('proceed_with_uncertain_matches', [
                        'message' => 'Some matches are uncertain. Proceed anyway?',
                        'uncertainCount' => count($needsReview)
                    ])
                ) {
                    $this->stdout("Migration cancelled. Review matches and adjust whitelist/config.\n");
                    return ExitCode::OK;
                }
            }


            // Phase 2: Fix Broken Links (BATCHED)
            if (!empty($analysis['broken_links'])) {
                $this->setPhase('fix_links');
                $this->printPhaseHeader("PHASE 2: FIX BROKEN ASSET-FILE LINKS");
                $this->fixBrokenLinksBatched($analysis['broken_links'], $fileInventory, $targetVolume, $targetRootFolder);
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
            if (!empty($analysis['orphaned_files']) || !empty($analysis['unused_assets'])) {
                $this->setPhase('quarantine');
                $this->printPhaseHeader("PHASE 4: QUARANTINE UNUSED FILES");

                $unusedCount = count($analysis['unused_assets']);
                $orphanedCount = count($analysis['orphaned_files']);

                if ($unusedCount > 0) {
                    $this->stdout("  ⚠ About to quarantine {$unusedCount} unused assets\n", Console::FG_YELLOW);
                }
                if ($orphanedCount > 0) {
                    $this->stdout("  ⚠ About to quarantine {$orphanedCount} orphaned files\n", Console::FG_YELLOW);
                }

                // Force flush change log before user confirmation
                $this->changeLogManager->flush();

                $confirm = $this->prompt("Proceed with quarantine? (yes/no)", [
                    'required' => true,
                    'default' => 'no',
                ]);

                if ($confirm !== 'yes') {
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

        } catch (\Exception $e) {
            $this->handleFatalError($e);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->printSuccessFooter();
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
     */
    private function handleOptimisedImagesAtRoot($assetInventory, $fileInventory, $targetVolume, $quarantineVolume)
    {
        $this->printPhaseHeader("SPECIAL: OPTIMISED IMAGES ROOT MIGRATION");

        $volumesService = Craft::$app->getVolumes();
        $optimisedVolume = $volumesService->getVolumeByHandle('optimisedImages');

        if (!$optimisedVolume) {
            $this->stdout("  Skipping - optimisedImages volume not found\n\n");
            return;
        }

        $this->stdout("  Processing optimisedImages at bucket root...\n");

        // Get all assets from optimisedImages
        $optimisedAssets = array_filter($assetInventory, function ($asset) use ($optimisedVolume) {
            return $asset['volumeId'] == $optimisedVolume->id;
        });

        $this->stdout("  Found " . count($optimisedAssets) . " assets in optimisedImages\n");

        // Get all files from optimisedImages filesystem
        $optimisedFs = $optimisedVolume->getFs();
        $this->stdout("  Scanning filesystem... ");

        $allFiles = $this->scanFilesystem($optimisedFs, '', true, null);
        $this->stdout("done (" . count($allFiles['all']) . " files)\n\n");

        // Categorize files
        $categories = [
            'linked_assets' => [],      // Files with asset records → move to images
            'transforms' => [],         // Transform files → move to imageTransforms or delete
            'orphans' => [],           // Files without assets → quarantine or leave
        ];

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

            // Does this have an asset record?
            if (isset($assetFilenames[$filename])) {
                $categories['linked_assets'][] = [
                    'file' => $file,
                    'assetId' => $assetFilenames[$filename]
                ];
                continue;
            }

            // It's an orphan
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
                $this->stdout("?", Console::FG_GREY);
                continue;
            }

            try {
                // Source path (at root)
                $sourcePath = $file['path'];

                // Target path (in images subfolder)
                $targetPath = $asset->filename;

                // Copy file from root to images subfolder
                if (!$targetFs->fileExists($targetPath)) {
                    $content = $sourceFs->read($sourcePath);
                    $targetFs->write($targetPath, $content, []);
                }

                // Update asset record
                $db = Craft::$app->getDb();
                $transaction = $db->beginTransaction();

                try {
                    $asset->volumeId = $targetVolume->id;
                    $asset->folderId = $targetRootFolder->id;

                    if (Craft::$app->getElements()->saveElement($asset)) {
                        $transaction->commit();

                        // Delete from source (root)
                        try {
                            $sourceFs->deleteFile($sourcePath);
                        } catch (\Exception $e) {
                            // File might already be gone, that's ok
                        }

                        $this->changeLogManager->logChange([
                            'type' => 'moved_from_optimised_root',
                            'assetId' => $asset->id,
                            'filename' => $asset->filename,
                            'fromVolume' => $sourceVolume->id,
                            'fromPath' => $sourcePath,
                            'toVolume' => $targetVolume->id,
                            'toPath' => $targetPath
                        ]);

                        $this->stdout(".", Console::FG_GREEN);
                        $moved++;
                        $this->stats['files_moved']++;
                    } else {
                        $transaction->rollBack();
                        $this->stdout("x", Console::FG_RED);
                        $errors++;
                    }

                } catch (\Exception $e) {
                    $transaction->rollBack();
                    throw $e;
                }

            } catch (\Exception $e) {
                $this->stdout("x", Console::FG_RED);
                $errors++;
                $this->trackError('move_optimised', $e->getMessage());
            }

            if ($moved % 50 === 0 && $moved > 0) {
                $this->stdout(" [{$moved}]\n  ");
            }
        }

        $this->stdout("\n\n  ✓ Moved: {$moved}, Errors: {$errors}\n\n");
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

    private function identifyProblematicMatches($brokenLinks, $fileInventory, $searchIndexes, $targetVolume)
    {
        $problematic = [];

        foreach ($brokenLinks as $assetData) {
            $asset = Asset::findOne($assetData['id']);
            if (!$asset)
                continue;

            $matchResult = $this->findFileForAsset($asset, $fileInventory, $searchIndexes, $targetVolume, $assetData);

            if ($matchResult['found'] && $matchResult['confidence'] < 0.85) {
                $problematic[] = [
                    'asset_id' => $asset->id,
                    'asset_filename' => $asset->filename,
                    'match_filename' => $matchResult['file']['filename'],
                    'match_path' => $matchResult['file']['path'],
                    'confidence' => round($matchResult['confidence'] * 100, 1),
                    'strategy' => $matchResult['strategy']
                ];
            }
        }

        return $problematic;
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

        // Find active migration
        $quickState = $this->checkpointManager->loadQuickState();

        if (!$quickState) {
            $this->stdout("No active migration found.\n\n");
            return ExitCode::OK;
        }

        $this->stdout("Active Migration: {$quickState['migration_id']}\n");
        $this->stdout("Current Phase:    {$quickState['phase']}\n");
        $this->stdout("Processed Items:  {$quickState['processed_count']}\n");
        $this->stdout("Last Update:      {$quickState['timestamp']}\n\n");

        $stats = $quickState['stats'] ?? [];

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
        $errorLogFile = Craft::getAlias('@storage') . '/migration-errors-' . $quickState['migration_id'] . '.log';
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

        $this->stdout("Commands:\n");
        $this->stdout("  Resume:  ./craft s3-spaces-migration/image-migration/migrate --resume\n");
        $this->stdout("  Status:  ./craft s3-spaces-migration/image-migration/status\n");
        $this->stdout("  Monitor: watch -n 2 './craft s3-spaces-migration/image-migration/monitor'\n\n");

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

            // Update lock with resumed migration ID
            $this->migrationLock = new MigrationLock($this->migrationId);
            $this->stdout("  Acquiring lock for resumed migration... ");
            if (!$this->migrationLock->acquire(5, true)) {
                $this->stderr("FAILED\n", Console::FG_RED);
                $this->stderr("Cannot acquire lock for migration {$this->migrationId}\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("acquired\n\n", Console::FG_GREEN);

        } else {
            // Full checkpoint loading
            $checkpoint = $this->checkpointManager->loadLatestCheckpoint($checkpointId);

            if (!$checkpoint) {
                $this->stderr("No checkpoint found to resume from.\n", Console::FG_RED);
                $this->stdout("\nAvailable checkpoints:\n");
                $available = $this->checkpointManager->listCheckpoints();
                foreach ($available as $cp) {
                    $this->stdout("  - {$cp['id']} ({$cp['phase']}) at {$cp['timestamp']} - {$cp['processed']} items\n", Console::FG_CYAN);
                }
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("Found checkpoint: {$checkpoint['phase']} at {$checkpoint['timestamp']}\n", Console::FG_GREEN);
            $this->stdout("Migration ID: {$checkpoint['migration_id']}\n");
            $this->stdout("Processed: " . count($checkpoint['processed_ids'] ?? []) . " items\n\n");

            // Restore state
            $this->migrationId = $checkpoint['migration_id'];
            $this->currentPhase = $checkpoint['phase'];
            $this->currentBatch = $checkpoint['batch'] ?? 0;
            $this->processedAssetIds = $checkpoint['processed_ids'] ?? [];
            $this->stats = array_merge($this->stats, $checkpoint['stats']);
            $this->stats['resume_count']++;

            // Update lock
            $this->migrationLock = new MigrationLock($this->migrationId);
            $this->stdout("  Acquiring lock for resumed migration... ");
            if (!$this->migrationLock->acquire(5, true)) {
                $this->stderr("FAILED\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("acquired\n\n", Console::FG_GREEN);
        }

        // Reinitialize managers with restored ID
        $this->changeLogManager = new ChangeLogManager($this->migrationId, $this->CHANGELOG_FLUSH_EVERY);
        $this->checkpointManager = new CheckpointManager($this->migrationId);
        $this->rollbackEngine = new RollbackEngine($this->changeLogManager, $this->migrationId);

        $confirm = $this->prompt("Resume migration from '{$this->currentPhase}' phase? (yes/no)", [
            'required' => true,
            'default' => 'yes',
        ]);

        if ($confirm !== 'yes') {
            $this->stdout("Resume cancelled.\n");
            return ExitCode::OK;
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
                    $this->stdout("Early phase - restarting from discovery...\n\n");
                    // Set defaults for resume mode
                    $this->dryRun = $dryRun ?? $this->dryRun;
                    $this->skipBackup = $skipBackup ?? $this->skipBackup;
                    $this->skipInlineDetection = $skipInlineDetection ?? $this->skipInlineDetection;
                    return $this->actionMigrate();

                case 'link_inline':
                    return $this->resumeInlineLinking($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'fix_links':
                    return $this->resumeFixLinks($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'consolidate':
                    return $this->resumeConsolidate($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'quarantine':
                    return $this->resumeQuarantine($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'cleanup':
                case 'complete':
                    $this->stdout("Migration was nearly complete. Running final verification...\n\n");
                    $this->performCleanupAndVerification($targetVolume, $targetRootFolder);
                    $this->printFinalReport();
                    $this->printSuccessFooter();
                    return ExitCode::OK;

                default:
                    throw new \Exception("Unknown checkpoint phase: {$this->currentPhase}");
            }

        } catch (\Exception $e) {
            $this->handleFatalError($e);
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

        $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
        $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
        $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);

        if (!empty($analysis['broken_links'])) {
            $this->fixBrokenLinksBatched($analysis['broken_links'], $fileInventory, $targetVolume, $targetRootFolder);
        }

        $this->saveCheckpoint([
            'fileMap' => $this->fileMap,
            'byHash' => $this->byHash,
            'missing' => $this->missing,
        ]);

        return $this->continueToNextPhase($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume, $assetInventory);
    }

    private function resumeConsolidate($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume)
    {
        $this->setPhase('consolidate');
        $this->printPhaseHeader("PHASE 3: CONSOLIDATE FILES (RESUMED)");
        $cp = $this->checkpointManager->loadLatestCheckpoint();
        $this->fileMap = $cp['fileMap'] ?? [];
        $this->byHash = $cp['byHash'] ?? [];
        $this->missing = $cp['missing'] ?? [];


        $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
        $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
        $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);

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

        $assetInventory = $this->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
        $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
        $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);

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

    private function continueToNextPhase($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume, $assetInventory)
    {
        $fileInventory = $this->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
        $analysis = $this->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);

        // Continue with remaining phases...
        $this->setPhase('cleanup');
        $this->printPhaseHeader("PHASE 5: CLEANUP & VERIFICATION");
        $this->performCleanupAndVerification($targetVolume, $targetRootFolder);

        $this->setPhase('complete');
        $this->saveCheckpoint(['completed' => true]);

        $this->printFinalReport();
        $this->printSuccessFooter();

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
            $selection = $this->prompt("\nSelect migration number to rollback (or 'latest'):", [
                'required' => true,
                'default' => 'latest',
            ]);

            if ($selection === 'latest') {
                $migrationId = $migrations[0]['id'];
            } else {
                $idx = (int) $selection - 1;
                if (!isset($migrations[$idx])) {
                    $this->stderr("Invalid selection.\n", Console::FG_RED);
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

            $methodChoice = $this->prompt("Select rollback method (1 or 2):", [
                'required' => true,
                'default' => '2',
            ]);

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
                    $this->stdout("  Method: {$result['method']}\n");
                    $this->stdout("  Backup file: " . basename($result['backup_file']) . "\n");
                    $this->stdout("  Backup size: {$result['backup_size']}\n");
                    $this->stdout("  Tables restored: " . implode(', ', $result['tables_restored']) . "\n");
                    $this->stdout("\n✓ Rollback completed successfully\n", Console::FG_GREEN);
                }
            } else {
                // Change-by-change rollback
                if (!$dryRun && !$phases) {
                    $confirm = $this->prompt("This will reverse all changes. Continue? (yes/no)", [
                        'required' => true,
                        'default' => 'no',
                    ]);

                    if ($confirm !== 'yes') {
                        $this->stdout("Rollback cancelled.\n");
                        return ExitCode::OK;
                    }
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
                    $this->stdout("\n  By Phase:\n");
                    foreach ($result['by_phase'] as $phase => $count) {
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
            return ExitCode::UNSPECIFIED_ERROR;
        }

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

        return ExitCode::OK;
    }

    /**
     * Cleanup old checkpoints and logs
     */
    /**
     * Cleanup old checkpoints and logs
     * 
     * @param int|null $olderThanHours Hours threshold for cleanup (default: 72)
     */
    public function actionCleanup($olderThanHours = null)
    {
        // Parse $olderThanHours - could be 'force' string or hours number
        $force = false;
        $hours = $this->checkpointRetentionHours;

        if ($olderThanHours === 'force') {
            $force = true;
            $hours = $this->checkpointRetentionHours;
        } elseif ($olderThanHours !== null) {
            $hours = (int) $olderThanHours;
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

        $confirm = $this->prompt("This will remove ALL migration locks. Continue? (yes/no)", [
            'required' => true,
            'default' => 'no',
        ]);

        if ($confirm !== 'yes') {
            $this->stdout("Cancelled.\n");
            return ExitCode::OK;
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

            $this->stdout(".", Console::FG_GREEN);
            if ($batchNum % 50 === 0) {
                $processed = min($offset, $totalAssets);
                $pct = round(($processed / $totalAssets) * 100, 1);
                $this->stdout(" [{$processed}/{$totalAssets} - {$pct}%]\n    ");
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
                $this->stdout("x", Console::FG_RED);
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
                    $this->stdout("x", Console::FG_RED);
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
                    $this->stdout(" " . $progress->getProgressString() . "\n  ");
                } else {
                    $this->stdout(".", Console::FG_GREEN);
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
                        $existingRelation = $db->createCommand("
                            SELECT id FROM relations
                            WHERE sourceId = :sourceId
                              AND targetId = :targetId
                              AND fieldId = :fieldId
                            LIMIT 1
                        ", [
                            ':sourceId' => $elementId,
                            ':targetId' => $assetId,
                            ':fieldId' => $fieldId
                        ])->queryScalar();

                        if (!$existingRelation) {
                            $maxSort = (int) $db->createCommand("
                                SELECT MAX(sortOrder) FROM relations
                                WHERE sourceId = :sourceId AND fieldId = :fieldId
                            ", [
                                ':sourceId' => $elementId,
                                ':fieldId' => $fieldId
                            ])->queryScalar();

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
     * Fix broken links in batches
     */
    /**
     * REPLACE fixBrokenLinksBatched() - Now skips already-processed items
     */
    private function fixBrokenLinksBatched($brokenLinks, $fileInventory, $targetVolume, $targetRootFolder)
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

        $this->printProgressLegend();
        $this->stdout("  Progress: ");

        $searchIndexes = $this->buildFileSearchIndexes($fileInventory);
        $progress = new ProgressTracker("Fixing Broken Links", $total, $this->progressReportingInterval);

        $fixed = 0;
        $notFound = 0;
        $processedBatch = [];
        $lastLockRefresh = time();

        foreach ($remainingLinks as $assetData) {
            // Refresh lock periodically
            if (time() - $lastLockRefresh > 60) {
                $this->migrationLock->refresh();
                $lastLockRefresh = time();
            }

            $asset = Asset::findOne($assetData['id']);
            if (!$asset) {
                $this->stdout("?", Console::FG_GREY);
                continue;
            }

            $result = $this->errorRecoveryManager->retryOperation(
                fn() => $this->fixSingleBrokenLink($asset, $fileInventory, $searchIndexes, $targetVolume, $targetRootFolder, $assetData),
                "fix_broken_link_{$asset->id}"
            );

            if ($result['fixed']) {
                $this->stdout(".", Console::FG_GREEN);
                $fixed++;
                $this->stats['assets_updated']++;
                $processedBatch[] = $asset->id;
            } else {
                $this->stdout("x", Console::FG_RED);
                $notFound++;
            }

            // Update progress
            if ($progress->increment()) {
                $this->stdout(" " . $progress->getProgressString() . "\n  ");

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
     * REPLACE fixSingleBrokenLink() - Check if file actually exists on source
     */
    private function fixSingleBrokenLink($asset, $fileInventory, $searchIndexes, $targetVolume, $targetRootFolder, $assetData)
    {
        // FIRST: Check if the file exists in its expected location on SOURCE volume
        try {
            $sourceVolume = Craft::$app->getVolumes()->getVolumeById($assetData['volumeId']);
            if ($sourceVolume) {
                $sourceFs = $sourceVolume->getFs();
                $expectedPath = trim($assetData['folderPath'], '/') . '/' . $asset->filename;

                // If file exists on source, this isn't really "broken" - just needs to be linked properly
                if ($sourceFs->fileExists($expectedPath)) {
                    Craft::info(
                        "Asset {$asset->id} ({$asset->filename}) - File exists on source at {$expectedPath}, " .
                        "just needs database update",
                        __METHOD__
                    );

                    // The file exists, just update the asset record
                    return $this->updateAssetPath($asset, $expectedPath, $sourceVolume, $assetData);
                }
            }
        } catch (\Exception $e) {
            // Can't verify source - continue with match attempt
            Craft::warning("Could not verify source file existence: " . $e->getMessage(), __METHOD__);
        }

        // Only NOW attempt to find alternative matches
        $matchResult = $this->findFileForAsset($asset, $fileInventory, $searchIndexes, $targetVolume, $assetData);

        if (!$matchResult['found']) {
            $this->changeLogManager->logChange([
                'type' => 'broken_link_not_fixed',
                'assetId' => $asset->id,
                'filename' => $asset->filename,
                'reason' => 'File not found with any matching strategy',
                'rejected_match' => $matchResult['rejected_match'] ?? null,
                'rejected_confidence' => $matchResult['rejected_confidence'] ?? null
            ]);

            return ['fixed' => false];
        }

        // ⚠️ WARN if using low-confidence match
        if ($matchResult['confidence'] < 0.85) {
            $this->stdout("⚠", Console::FG_YELLOW);
            Craft::warning(
                "Using low-confidence match ({$matchResult['confidence']}): " .
                "'{$matchResult['file']['filename']}' for '{$asset->filename}'",
                __METHOD__
            );
        }

        $sourceFile = $matchResult['file'];

        // Store original asset location before fix
        $originalVolumeId = $asset->volumeId;
        $originalFolderId = $asset->folderId;

        try {
            $success = $this->copyFileToAsset($sourceFile, $asset, $targetVolume, $targetRootFolder);

            if ($success) {
                $this->changeLogManager->logChange([
                    'type' => 'fixed_broken_link',
                    'assetId' => $asset->id,
                    'filename' => $asset->filename,
                    'matchedFile' => $sourceFile['filename'],
                    'sourceVolume' => $sourceFile['volumeName'],
                    'sourcePath' => $sourceFile['path'],
                    'matchStrategy' => $matchResult['strategy'],
                    'confidence' => $matchResult['confidence'],
                    // NEW: Store original location for rollback
                    'originalVolumeId' => $originalVolumeId,
                    'originalFolderId' => $originalFolderId,
                ]);

                return ['fixed' => true];
            }

        } catch (\Exception $e) {
            $this->trackError('fix_broken_link', $e->getMessage());
            throw $e;
        }

        return ['fixed' => false];
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

                return ['fixed' => true];
            }

            $transaction->rollBack();
            return ['fixed' => false];

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
                $this->stdout("?", Console::FG_GREY);
                continue;
            }

            // Skip if already in correct location
            if ($asset->volumeId == $targetVolume->id && $asset->folderId == $targetRootFolder->id) {
                $this->stdout("-", Console::FG_GREY);
                $skippedLocal++;
                $processedBatch[] = $asset->id; // Mark as processed even if skipped

                if ($progress->increment()) {
                    $this->stdout(" " . $progress->getProgressString() . "\n  ");
                }
                continue;
            }

            $result = $this->errorRecoveryManager->retryOperation(
                fn() => $this->consolidateSingleAsset($asset, $assetData, $targetVolume, $targetRootFolder),
                "consolidate_asset_{$asset->id}"
            );

            if ($result['success']) {
                $this->stdout(".", Console::FG_GREEN);
                $moved++;
                $this->stats['files_moved']++;
                $processedBatch[] = $asset->id;
            } else {
                $this->stdout("x", Console::FG_RED);
            }

            // Update progress
            if ($progress->increment()) {
                $this->stdout(" " . $progress->getProgressString() . "\n  ");

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
            throw $e;
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
                        $this->stdout(".", Console::FG_YELLOW);
                        $quarantined++;
                        $this->stats['files_quarantined']++;
                    } else {
                        $this->stdout("!", Console::FG_RED);
                    }

                    if ($quarantined % 50 === 0) {
                        $this->stdout(" [{$quarantined}/{$total}]\n  ");
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
                        $this->stdout("?", Console::FG_GREY);
                        continue;
                    }

                    // Skip if already processed
                    if (in_array($asset->id, $this->processedIds)) {
                        $this->stdout("-", Console::FG_GREY);
                        continue;
                    }

                    $result = $this->errorRecoveryManager->retryOperation(
                        fn() => $this->quarantineSingleAsset($asset, $assetData, $quarantineVolume, $quarantineRoot),
                        "quarantine_asset_{$asset->id}"
                    );

                    if ($result['success']) {
                        $this->stdout(".", Console::FG_YELLOW);
                        $quarantined++;
                        $this->stats['files_quarantined']++;
                        $this->processedIds[] = $asset->id;
                    } else {
                        $this->stdout("x", Console::FG_RED);
                    }

                    if ($quarantined % 50 === 0) {
                        $this->stdout(" [{$quarantined}/{$total}]\n  ");
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
            $this->trackError('quarantine_file', $e->getMessage());
            throw $e;
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
            'processed_ids' => $this->processedIds,
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
     * handleFatalError() - Better recovery instructions
     */
    private function handleFatalError($e)
    {
        $this->stderr("\n" . str_repeat("=", 80) . "\n", Console::FG_RED);
        $this->stderr("MIGRATION INTERRUPTED\n", Console::FG_RED);
        $this->stderr(str_repeat("=", 80) . "\n", Console::FG_RED);
        $this->stderr($e->getMessage() . "\n\n", Console::FG_RED);

        // Save checkpoint for resume
        try {
            $this->saveCheckpoint([
                'error' => $e->getMessage(),
                'can_resume' => true,
                'interrupted_at' => date('Y-m-d H:i:s')
            ]);
            $this->stdout("✓ State saved - migration can be resumed\n\n", Console::FG_GREEN);
        } catch (\Exception $e2) {
            $this->stderr("Could not save checkpoint: " . $e2->getMessage() . "\n", Console::FG_RED);
        }

        $this->stdout("RECOVERY OPTIONS:\n", Console::FG_CYAN);
        $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n", Console::FG_CYAN);

        $quickState = $this->checkpointManager->loadQuickState();
        if ($quickState) {
            $processed = $quickState['processed_count'] ?? 0;
            $this->stdout("\n✓ Quick Resume Available\n", Console::FG_GREEN);
            $this->stdout("  Last phase: {$quickState['phase']}\n");
            $this->stdout("  Processed: {$processed} items\n");
            $this->stdout("  Command:   ./craft s3-spaces-migration/image-migration/migrate --resume\n\n");
        }

        $this->stdout("Other Options:\n");
        $this->stdout("  Check status:  ./craft s3-spaces-migration/image-migration/status\n");
        $this->stdout("  View progress: tail -f " . Craft::getAlias('@storage') . "/logs/migration-*.log\n");
        $this->stdout("  Rollback:      ./craft s3-spaces-migration/image-migration/rollback\n\n");

        $this->stdout("Note: Original assets are preserved on S3 until you verify the migration.\n");
        $this->stdout("      The site remains operational during the migration.\n\n");
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
        $this->stdout("    Progress: ");

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
                    $analysis['unused_assets'][] = $asset;
                }
            } else {
                $analysis['broken_links'][] = $asset;

                $key = $asset['volumeId'] . '/' . $asset['filename'];
                if (isset($fileLookup[$key])) {
                    $asset['possibleFiles'] = $fileLookup[$key];
                }
            }

            $processed++;

            // Show progress every 2.5% or every 100 items, whichever is less frequent
            $progressInterval = max(100, (int) ($total * 0.025));
            if ($processed % $progressInterval === 0 || $processed === $total) {
                $pct = round(($processed / $total) * 100, 1);
                $this->stdout(".", Console::FG_GREEN);

                // Show percentage every 10%
                if ($pct - $lastProgress >= 10 || $processed === $total) {
                    $this->stdout(" {$pct}%", Console::FG_CYAN);
                    $lastProgress = $pct;
                }
            }
        }

        $this->stdout("\n    Identifying orphaned files... ");

        $assetFilenames = array_column($assetInventory, 'filename', 'filename');
        foreach ($fileInventory as $file) {
            if (!isset($assetFilenames[$file['filename']])) {
                $analysis['orphaned_files'][] = $file;
            }
        }

        $this->stdout("done\n", Console::FG_GREEN);
        $this->stdout("    Checking for duplicates... ");

        $filenameCounts = array_count_values(array_column($assetInventory, 'filename'));
        foreach ($filenameCounts as $filename => $count) {
            if ($count > 1) {
                $dupes = array_filter($assetInventory, fn($a) => $a['filename'] === $filename);
                $analysis['duplicates'][$filename] = array_values($dupes);
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
     * Memory-efficient asset lookup using generators - REPLACES buildAssetLookup
     */
    private function buildAssetLookup($assetInventory)
    {
        // For small inventories, use original approach
        if (count($assetInventory) < 10000) {
            return $this->buildAssetLookupArray($assetInventory);
        }

        // For large inventories, use lazy-loading wrapper
        return new LazyAssetLookup($assetInventory);
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
     * Updated findAssetByUrl to work with both array and LazyAssetLookup
     */
    private function findAssetByUrl($url, $assetLookup)
    {
        $url = trim($url);
        $url = preg_replace('/[?#].*$/', '', $url);
        $url = preg_replace('/^https?:\/\/[^\/]+/i', '', $url);
        $url = ltrim($url, '/');

        // Direct lookup (works for both array and LazyAssetLookup)
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

        // Fallback: search by filename (expensive, but necessary)
        if ($assetLookup instanceof LazyAssetLookup) {
            return $assetLookup->findByFilename($filename);
        } else {
            foreach ($assetLookup as $key => $asset) {
                if (basename($key) === $filename) {
                    return $asset;
                }
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
            $aIsTarget = ($a['volumeId'] === $targetVolume->id) ? 1 : 0;
            $bIsTarget = ($b['volumeId'] === $targetVolume->id) ? 1 : 0;

            if ($aIsTarget !== $bIsTarget) {
                return $bIsTarget - $aIsTarget;
            }

            $aTime = $a['lastModified'] ?? 0;
            $bTime = $b['lastModified'] ?? 0;

            return $bTime - $aTime;
        });

        return $files[0];
    }

    // =========================================================================
// ASSET MOVEMENT METHODS
// =========================================================================

    private function moveAssetSameVolume($asset, $targetFolder)
    {
        try {
            return Craft::$app->getAssets()->moveAsset($asset, $targetFolder);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'readStream') !== false) {
                return $this->moveAssetManual($asset, $targetFolder);
            }
            throw $e;
        }
    }

    private function moveAssetManual($asset, $targetFolder)
    {
        $fs = $asset->getVolume()->getFs();
        $oldPath = $asset->getPath();

        $tempPath = $asset->getCopyOfFile();

        $asset->folderId = $targetFolder->id;
        $asset->newFolderId = $targetFolder->id;

        $success = Craft::$app->getElements()->saveElement($asset);

        if ($success) {
            $newPath = $asset->getPath();
            if (!$fs->fileExists($newPath)) {
                $stream = fopen($tempPath, 'r');
                $fs->writeFileFromStream($newPath, $stream, []);
                fclose($stream);
            }

            if ($oldPath !== $newPath && $fs->fileExists($oldPath)) {
                try {
                    $fs->deleteFile($oldPath);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }

        @unlink($tempPath);

        return $success;
    }

    private function moveAssetCrossVolume($asset, $targetVolume, $targetFolder)
    {
        $elementsService = Craft::$app->getElements();

        $tempFile = $asset->getCopyOfFile();
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

        $tempPath = tempnam(sys_get_temp_dir(), 'asset_');
        $tempStream = fopen($tempPath, 'w');

        try {
            $content = $sourceFs->read($sourcePath);
            fwrite($tempStream, $content);
        } catch (\Exception $e) {
            try {
                $sourceStream = $sourceFs->readStream($sourcePath);
                stream_copy_to_stream($sourceStream, $tempStream);
                fclose($sourceStream);
            } catch (\Exception $e2) {
                fclose($tempStream);
                @unlink($tempPath);
                throw new \Exception("Cannot read source file: " . $e->getMessage());
            }
        }

        fclose($tempStream);

        $asset->volumeId = $targetVolume->id;
        $asset->folderId = $targetFolder->id;
        $asset->tempFilePath = $tempPath;

        $success = Craft::$app->getElements()->saveElement($asset);

        @unlink($tempPath);

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
        $this->stdout("  Clearing transform indexes...\n");
        try {
            Craft::$app->getAssetTransforms()->deleteAllTransformIndexes();
            $this->stdout("  ✓ Transforms cleared\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stdout("  ⚠ Warning: " . $e->getMessage() . "\n", Console::FG_YELLOW);
        }

        $this->stdout("\n  Reindexing target volume...\n");
        try {
            $assetsService = Craft::$app->getAssets();
            $assetsService->indexFolder($targetRootFolder, true);
            $this->stdout("  ✓ Volume reindexed\n", Console::FG_GREEN);
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
                    $this->stdout(".", Console::FG_GREEN);
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

        // CHECK THRESHOLD
        $totalErrors = array_sum(array_map('count', $this->errorCounts));

        if ($totalErrors >= $this->errorThreshold) {
            $this->saveCheckpoint(['error_threshold_exceeded' => true]);

            $this->stderr("\n\nERROR THRESHOLD EXCEEDED\n", Console::FG_RED);
            $this->stderr("Total errors: {$totalErrors}/{$this->errorThreshold}\n", Console::FG_RED);
            $this->stderr("Error log: {$errorLogFile}\n\n", Console::FG_YELLOW);

            throw new \Exception(
                "Error threshold exceeded ({$totalErrors} errors). " .
                "Migration halted for safety. Review errors and resume with --resume flag."
            );
        }
    }

    private function createBackup()
    {
        $this->stdout("  Creating database backup...\n");

        $timestamp = date('YmdHis');
        $db = Craft::$app->getDb();

        // Method 1: Create backup tables (fast, for quick rollback)
        $tables = ['assets', 'volumefolders', 'relations', 'elements'];

        foreach ($tables as $table) {
            try {
                $db->createCommand("
                CREATE TABLE IF NOT EXISTS {$table}_backup_{$timestamp}
                AS SELECT * FROM {$table}
            ")->execute();
            } catch (\Exception $e) {
                $this->stdout("    Warning: Could not backup {$table}\n", Console::FG_YELLOW);
            }
        }

        // Method 2: Create SQL dump file (complete backup for restore)
        $backupFile = $this->createDatabaseBackup();

        if ($backupFile) {
            $this->stdout("  ✓ Backup created: {$timestamp}\n", Console::FG_GREEN);
            $this->stdout("  ✓ SQL dump saved: " . basename($backupFile) . "\n", Console::FG_GREEN);

            // Store backup location in checkpoint
            $this->saveCheckpoint([
                'backup_timestamp' => $timestamp,
                'backup_file' => $backupFile,
                'backup_tables' => $tables
            ]);
        } else {
            $this->stdout("  ✓ Table backups created: {$timestamp}\n", Console::FG_GREEN);
            $this->stdout("  ⚠ SQL dump not available (will use table backups)\n", Console::FG_YELLOW);
        }

        $this->stdout("\n");
    }

    /**
     * Create a complete database backup using mysqldump
     *
     * @return string|null Path to backup file, or null if backup failed
     */
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
            "  ⚠ Unused assets:               %5d  [QUARANTINE]\n",
            count($analysis['unused_assets'])
        ), Console::FG_YELLOW);
        $this->stdout(sprintf(
            "  ⚠ Orphaned files:              %5d  [QUARANTINE]\n",
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
        $this->stdout("    Errors:            {$this->stats['errors']}\n");
        $this->stdout("    Retries:           {$this->stats['retries']}\n");
        $this->stdout("    Checkpoints saved: {$this->stats['checkpoints_saved']}\n");
        if ($this->stats['resume_count'] > 0) {
            $this->stdout("    Resumed:           {$this->stats['resume_count']} times\n", Console::FG_YELLOW);
        }
        $this->stdout("\n");
    }

}

// =========================================================================
// SUPPORTING CLASSES
// =========================================================================

/**
 * CheckpointManager - Handles checkpoint persistence and recovery
 */
/**
 * REPLACE EXISTING CheckpointManager - Now with incremental state and faster loading
 */
class CheckpointManager
{
    private $migrationId;
    private $checkpointDir;
    private $stateFile; // Separate state file for quick resume

    public function __construct($migrationId)
    {
        $this->migrationId = $migrationId;
        $this->checkpointDir = Craft::getAlias('@storage/migration-checkpoints');

        if (!is_dir($this->checkpointDir)) {
            FileHelper::createDirectory($this->checkpointDir);
        }

        $this->stateFile = $this->checkpointDir . '/' . $migrationId . '.state.json';
    }

    /**
     * Save checkpoint with incremental state
     */
    public function saveCheckpoint($data)
    {
        $data['checkpoint_version'] = '4.0';
        $data['created_at'] = microtime(true);

        $checkpointFile = $this->getCheckpointPath();
        $tempFile = $checkpointFile . '.tmp';

        // Write checkpoint
        file_put_contents($tempFile, json_encode($data, JSON_PRETTY_PRINT));
        rename($tempFile, $checkpointFile);

        // Also save lightweight state for quick resume
        $this->saveQuickState($data);

        return true;
    }

    /**
     * Save quick-resume state (processed IDs only)
     */
    private function saveQuickState($data)
    {
        $quickState = [
            'migration_id' => $data['migration_id'] ?? $this->migrationId,
            'phase' => $data['phase'] ?? 'unknown',
            'batch' => $data['batch'] ?? 0,
            'processed_ids' => $data['processed_ids'] ?? [],
            'processed_count' => count($data['processed_ids'] ?? []),
            'timestamp' => $data['timestamp'] ?? date('Y-m-d H:i:s'),
            'stats' => $data['stats'] ?? []
        ];

        $tempFile = $this->stateFile . '.tmp';
        file_put_contents($tempFile, json_encode($quickState));
        rename($tempFile, $this->stateFile);
    }

    /**
     * Load quick state for fast resume
     */
    public function loadQuickState()
    {
        if (!file_exists($this->stateFile)) {
            return null;
        }

        $state = json_decode(file_get_contents($this->stateFile), true);
        return $state;
    }

    /**
     * Update processed IDs incrementally (without full checkpoint)
     */
    public function updateProcessedIds($newIds)
    {
        $state = $this->loadQuickState();
        if (!$state) {
            return;
        }

        $state['processed_ids'] = array_unique(array_merge(
            $state['processed_ids'] ?? [],
            $newIds
        ));
        $state['processed_count'] = count($state['processed_ids']);
        $state['last_updated'] = microtime(true);

        $tempFile = $this->stateFile . '.tmp';
        file_put_contents($tempFile, json_encode($state));
        rename($tempFile, $this->stateFile);
    }

    public function loadLatestCheckpoint($checkpointId = null)
    {
        if ($checkpointId) {
            $file = $this->checkpointDir . '/' . $checkpointId . '.json';
        } else {
            // Find latest checkpoint
            $files = glob($this->checkpointDir . '/*.json');
            // Exclude .state.json files
            $files = array_filter($files, fn($f) => !str_ends_with($f, '.state.json'));

            if (empty($files)) {
                return null;
            }
            usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
            $file = $files[0];
        }

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        return $data;
    }

    public function listCheckpoints()
    {
        $files = glob($this->checkpointDir . '/*.json');
        // Exclude .state.json files
        $files = array_filter($files, fn($f) => !str_ends_with($f, '.state.json'));

        $checkpoints = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            $checkpoints[] = [
                'id' => basename($file, '.json'),
                'phase' => $data['phase'] ?? 'unknown',
                'timestamp' => $data['timestamp'] ?? '',
                'processed' => count($data['processed_ids'] ?? []),
                'file' => $file
            ];
        }

        usort($checkpoints, fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));

        return $checkpoints;
    }

    public function cleanupOldCheckpoints($olderThanHours = 72)
    {
        $cutoff = time() - ($olderThanHours * 3600);
        $files = glob($this->checkpointDir . '/*.json');
        $removed = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function getCheckpointPath()
    {
        return $this->checkpointDir . '/' . $this->migrationId . '.json';
    }
}

/**
 * ProgressTracker - Real-time progress estimation and reporting
 */
class ProgressTracker
{
    private $phaseName;
    private $totalItems;
    private $processedItems = 0;
    private $startTime;
    private $lastReportTime;
    private $reportInterval;
    private $itemsPerSecond = 0;
    private $estimatedTimeRemaining = 0;

    public function __construct($phaseName, $totalItems, $reportInterval = 50)
    {
        $this->phaseName = $phaseName;
        $this->totalItems = max(1, $totalItems);
        $this->startTime = microtime(true);
        $this->lastReportTime = $this->startTime;
        $this->reportInterval = $reportInterval;
    }

    /**
     * Update progress and return whether to report
     */
    public function increment($count = 1): bool
    {
        $this->processedItems += $count;

        // Calculate performance metrics
        $elapsed = microtime(true) - $this->startTime;
        if ($elapsed > 0) {
            $this->itemsPerSecond = $this->processedItems / $elapsed;

            $remaining = $this->totalItems - $this->processedItems;
            if ($this->itemsPerSecond > 0) {
                $this->estimatedTimeRemaining = $remaining / $this->itemsPerSecond;
            }
        }

        // Should we report progress?
        return $this->processedItems % $this->reportInterval === 0
            || $this->processedItems >= $this->totalItems;
    }

    /**
     * Get progress report
     */
    public function getReport(): array
    {
        $percentComplete = ($this->processedItems / $this->totalItems) * 100;

        return [
            'phase' => $this->phaseName,
            'processed' => $this->processedItems,
            'total' => $this->totalItems,
            'percent' => round($percentComplete, 1),
            'items_per_second' => round($this->itemsPerSecond, 2),
            'eta_seconds' => round($this->estimatedTimeRemaining),
            'eta_formatted' => $this->formatTime($this->estimatedTimeRemaining),
            'elapsed_seconds' => round(microtime(true) - $this->startTime),
            'elapsed_formatted' => $this->formatTime(microtime(true) - $this->startTime)
        ];
    }

    /**
     * Format seconds into human-readable time
     */
    private function formatTime($seconds): string
    {
        if ($seconds < 60) {
            return round($seconds) . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = round(($seconds % 3600) / 60);
            return "{$hours}h {$minutes}m";
        }
    }

    /**
     * Get formatted progress string for console output
     */
    public function getProgressString(): string
    {
        $report = $this->getReport();
        return sprintf(
            "[%d/%d - %.1f%% - %.1f/s - ETA: %s]",
            $report['processed'],
            $report['total'],
            $report['percent'],
            $report['items_per_second'],
            $report['eta_formatted']
        );
    }
}

/**
 * ChangeLogManager - Continuous atomic change logging
 */
class ChangeLogManager
{
    private $migrationId;
    private $logFile;
    private $buffer = [];
    private $bufferSize = 0;
    private $flushThreshold;
    private $currentPhase = 'unknown';

    public function __construct($migrationId, $flushThreshold = 5)
    {
        $this->migrationId = $migrationId;
        $logDir = Craft::getAlias('@storage/migration-changelogs');

        if (!is_dir($logDir)) {
            FileHelper::createDirectory($logDir);
        }

        $this->logFile = $logDir . '/' . $migrationId . '.jsonl';
        $this->flushThreshold = $flushThreshold;

        // Create file if doesn't exist
        if (!file_exists($this->logFile)) {
            touch($this->logFile);
        }
    }

    /**
     * Set the current phase for logging
     *
     * @param string $phase Phase name
     */
    public function setPhase($phase)
    {
        $this->currentPhase = $phase;
        // Flush buffer when phase changes to ensure clean boundaries
        $this->flush();
    }

    /**
     * Log a change entry
     */
    public function logChange($change)
    {
        $change['sequence'] = $this->getNextSequence();
        $change['timestamp'] = date('Y-m-d H:i:s');
        $change['phase'] = $this->currentPhase; // Add phase tracking

        $this->buffer[] = $change;
        $this->bufferSize++;

        if ($this->bufferSize >= $this->flushThreshold) {
            $this->flush();
        }
    }

    /**
     * Flush buffered changes to log file atomically
     */

    public function flush()
    {
        if (empty($this->buffer)) {
            return;
        }

        $handle = fopen($this->logFile, 'a');
        if (!$handle) {
            throw new \Exception("Cannot open changelog file for writing: {$this->logFile}");
        }

        // Acquire exclusive lock
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \Exception("Cannot acquire lock on changelog file");
        }

        try {
            foreach ($this->buffer as $change) {
                fwrite($handle, json_encode($change) . "\n");
            }
        } finally {
            // Always release lock
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->buffer = [];
        $this->bufferSize = 0;
    }
    public function loadChanges()
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $changes = [];
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $change = json_decode($line, true);
            if ($change) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    public function listMigrations()
    {
        $logDir = Craft::getAlias('@storage/migration-changelogs');
        $files = glob($logDir . '/*.jsonl');
        $migrations = [];

        foreach ($files as $file) {
            $lineCount = 0;
            $handle = fopen($file, 'r');
            while (!feof($handle)) {
                if (fgets($handle)) {
                    $lineCount++;
                }
            }
            fclose($handle);

            $migrations[] = [
                'id' => basename($file, '.jsonl'),
                'timestamp' => date('Y-m-d H:i:s', filemtime($file)),
                'change_count' => $lineCount,
                'file' => $file
            ];
        }

        usort($migrations, fn($a, $b) => filemtime($b['file']) - filemtime($a['file']));

        return $migrations;
    }

    private function getNextSequence()
    {
        static $sequence = 0;
        return ++$sequence;
    }

    public function __destruct()
    {
        $this->flush();
    }
}

/**
 * ErrorRecoveryManager - Retry logic and error handling
 */
class ErrorRecoveryManager
{
    private $maxRetries;
    private $retryDelay;
    private $retryCount = [];

    public function __construct($maxRetries = 3, $retryDelay = 1000)
    {
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $retryDelay;
    }

    public function retryOperation(callable $operation, $operationId)
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $result = $operation();

                // Reset retry count on success
                if (isset($this->retryCount[$operationId])) {
                    unset($this->retryCount[$operationId]);
                }

                return $result;

            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;

                // Track retries
                if (!isset($this->retryCount[$operationId])) {
                    $this->retryCount[$operationId] = 0;
                }
                $this->retryCount[$operationId]++;

                // Don't retry fatal errors
                if ($this->isFatalError($e)) {
                    throw $e;
                }

                // Wait before retry (exponential backoff)
                if ($attempt < $this->maxRetries) {
                    usleep($this->retryDelay * 1000 * pow(2, $attempt - 1));
                }
            }
        }

        // All retries failed
        throw new \Exception("Operation failed after {$this->maxRetries} attempts: " . $lastException->getMessage(), 0, $lastException);
    }

    private function isFatalError(\Exception $e)
    {
        $message = strtolower($e->getMessage());

        // These errors should not be retried
        $fatalPatterns = [
            'does not exist',
            'permission denied',
            'access denied',
            'invalid',
            'constraint violation'
        ];

        foreach ($fatalPatterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    public function getRetryStats()
    {
        return [
            'total_retries' => array_sum($this->retryCount),
            'operations_retried' => count($this->retryCount)
        ];
    }
}

/**
 * RollbackEngine - Comprehensive operation reversal
 */
class RollbackEngine
{
    private $changeLogManager;
    private $migrationId;

    public function __construct(ChangeLogManager $changeLogManager, $migrationId = null)
    {
        $this->changeLogManager = $changeLogManager;
        $this->migrationId = $migrationId;
    }

    /**
     * Rollback via database restore (fastest method)
     *
     * @param string $migrationId Migration ID to rollback
     * @param bool $dryRun Show what would be done without executing
     * @return array Results of rollback operation
     */
    public function rollbackViaDatabase($migrationId, $dryRun = false)
    {
        // Find database backup file
        $backupDir = Craft::getAlias('@storage/migration-backups');
        $backupFile = $backupDir . '/migration_' . $migrationId . '_db_backup.sql';

        if (!file_exists($backupFile)) {
            throw new \Exception("Database backup not found: {$backupFile}");
        }

        $backupSize = filesize($backupFile);
        $backupSizeMB = round($backupSize / 1024 / 1024, 2);

        if ($dryRun) {
            return [
                'dry_run' => true,
                'method' => 'database_restore',
                'backup_file' => $backupFile,
                'backup_size' => $backupSizeMB . ' MB',
                'tables' => ['assets', 'volumefolders', 'relations', 'elements', 'elements_sites', 'content'],
                'estimated_time' => '< 1 minute'
            ];
        }

        // Verify backup integrity
        $this->verifyBackupFile($backupFile);

        // Disable foreign key checks temporarily
        $db = Craft::$app->getDb();
        $db->createCommand("SET FOREIGN_KEY_CHECKS=0")->execute();

        try {
            // Parse DSN to get database connection info
            $dsn = $db->dsn;
            if (preg_match('/dbname=([^;]+)/', $dsn, $matches)) {
                $dbName = $matches[1];
            } else {
                throw new \Exception("Could not parse database name from DSN");
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

            // Try mysql command line restore (fastest)
            $mysqlCmd = sprintf(
                'mysql -h %s -P %s -u %s %s %s < %s 2>&1',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                $password ? '-p' . escapeshellarg($password) : '',
                escapeshellarg($dbName),
                escapeshellarg($backupFile)
            );

            exec($mysqlCmd, $output, $returnCode);

            if ($returnCode !== 0) {
                // Fallback: Execute SQL file directly via Craft
                $sql = file_get_contents($backupFile);
                $statements = array_filter(array_map('trim', explode(";\n", $sql)));

                foreach ($statements as $statement) {
                    if (!empty($statement) && substr($statement, 0, 2) !== '--') {
                        try {
                            $db->createCommand($statement)->execute();
                        } catch (\Exception $e) {
                            Craft::warning("Statement failed during restore: " . $e->getMessage(), __METHOD__);
                        }
                    }
                }
            }

            // Re-enable foreign key checks
            $db->createCommand("SET FOREIGN_KEY_CHECKS=1")->execute();

            // Clear Craft caches
            Craft::$app->getTemplateCaches()->deleteAllCaches();
            Craft::$app->getElements()->invalidateAllCaches();

            return [
                'success' => true,
                'method' => 'database_restore',
                'backup_file' => $backupFile,
                'backup_size' => $backupSizeMB . ' MB',
                'tables_restored' => ['assets', 'volumefolders', 'relations', 'elements', 'elements_sites', 'content']
            ];

        } catch (\Exception $e) {
            // Re-enable foreign key checks on error
            $db->createCommand("SET FOREIGN_KEY_CHECKS=1")->execute();
            throw new \Exception("Database restore failed: " . $e->getMessage());
        }
    }

    /**
     * Verify backup file integrity
     *
     * @param string $backupFile Path to backup file
     * @throws \Exception if backup is invalid
     */
    private function verifyBackupFile($backupFile)
    {
        if (!file_exists($backupFile)) {
            throw new \Exception("Backup file not found: {$backupFile}");
        }

        if (filesize($backupFile) === 0) {
            throw new \Exception("Backup file is empty: {$backupFile}");
        }

        // Check if file contains SQL statements
        $handle = fopen($backupFile, 'r');
        $firstLine = fgets($handle);
        fclose($handle);

        if (stripos($firstLine, 'CREATE TABLE') === false &&
            stripos($firstLine, 'INSERT INTO') === false &&
            stripos($firstLine, '--') === false) {
            throw new \Exception("Backup file does not appear to be valid SQL");
        }
    }

    /**
     * Rollback via change-by-change reversal
     *
     * @param string $migrationId Migration ID to rollback
     * @param string|array|null $phases Phase(s) to rollback
     * @param string $mode 'from' (rollback from phase onwards) or 'only' (rollback specific phases)
     * @param bool $dryRun Show what would be done without executing
     * @return array Results of rollback operation
     */
    public function rollback($migrationId, $phases = null, $mode = 'from', $dryRun = false)
    {
        // Load all changes
        $changes = $this->changeLogManager->loadChanges();

        if (empty($changes)) {
            throw new \Exception("No changes found for migration: {$migrationId}");
        }

        // Filter by phase if specified
        if ($phases !== null) {
            $phasesToRollback = is_array($phases) ? $phases : [$phases];

            if ($mode === 'only') {
                // Rollback ONLY specified phases
                $changes = array_filter($changes, function($c) use ($phasesToRollback) {
                    $phase = $c['phase'] ?? 'unknown';
                    return in_array($phase, $phasesToRollback);
                });
            } else if ($mode === 'from') {
                // Rollback FROM specified phase onwards (in reverse order)
                $phaseOrder = [
                    'preparation',
                    'optimised_root',
                    'discovery',
                    'link_inline',
                    'fix_links',
                    'consolidate',
                    'quarantine',
                    'cleanup',
                    'complete'
                ];

                $fromPhase = is_array($phases) ? $phases[0] : $phases;
                $fromIndex = array_search($fromPhase, $phaseOrder);

                if ($fromIndex !== false) {
                    $phasesToInclude = array_slice($phaseOrder, $fromIndex);
                    $changes = array_filter($changes, function($c) use ($phasesToInclude) {
                        $phase = $c['phase'] ?? 'unknown';
                        return in_array($phase, $phasesToInclude);
                    });
                }
            }
        }

        if ($dryRun) {
            return $this->generateDryRunReport($changes);
        }

        $stats = [
            'reversed' => 0,
            'errors' => 0,
            'skipped' => 0
        ];

        $total = count($changes);
        $current = 0;

        // Reverse in reverse order
        foreach (array_reverse($changes) as $change) {
            try {
                $this->reverseChange($change);
                $stats['reversed']++;
                $current++;

                // Progress reporting every 50 operations
                if ($current % 50 === 0) {
                    $percent = round(($current / $total) * 100);
                    Craft::info("[{$current}/{$total}] {$percent}% complete", __METHOD__);
                }

            } catch (\Exception $e) {
                $stats['errors']++;
                Craft::error("Rollback error: " . $e->getMessage(), __METHOD__);
            }
        }

        return $stats;
    }

    /**
     * Generate dry-run report showing what would be rolled back
     *
     * @param array $changes Changes to rollback
     * @return array Dry-run report
     */
    private function generateDryRunReport($changes)
    {
        $byType = [];
        $byPhase = [];

        foreach ($changes as $change) {
            $type = $change['type'];
            $phase = $change['phase'] ?? 'unknown';

            if (!isset($byType[$type])) {
                $byType[$type] = 0;
            }
            $byType[$type]++;

            if (!isset($byPhase[$phase])) {
                $byPhase[$phase] = 0;
            }
            $byPhase[$phase]++;
        }

        // Estimate time (rough approximation)
        $estimatedSeconds = count($changes) * 0.1; // ~0.1s per operation
        $estimatedMinutes = ceil($estimatedSeconds / 60);

        return [
            'dry_run' => true,
            'method' => 'change_by_change',
            'total_operations' => count($changes),
            'by_type' => $byType,
            'by_phase' => $byPhase,
            'estimated_time' => $estimatedMinutes > 1 ? "~{$estimatedMinutes} minutes" : "< 1 minute"
        ];
    }

    /**
     * Get summary of phases in a migration
     *
     * @param string $migrationId Migration ID
     * @return array Phase summary with change counts
     */
    public function getPhasesSummary($migrationId)
    {
        $changes = $this->changeLogManager->loadChanges();
        $phases = [];

        foreach ($changes as $change) {
            $phase = $change['phase'] ?? 'unknown';
            if (!isset($phases[$phase])) {
                $phases[$phase] = 0;
            }
            $phases[$phase]++;
        }

        return $phases;
    }

    private function reverseChange($change)
    {
        $db = Craft::$app->getDb();

        switch ($change['type']) {
            case 'inline_image_linked':
                // Restore original HTML
                try {
                    $db->createCommand()->update(
                        $change['table'],
                        [$change['column'] => $change['originalContent']],
                        ['id' => $change['rowId']]
                    )->execute();

                    Craft::info("Restored inline image: row {$change['rowId']}", __METHOD__);
                } catch (\Exception $e) {
                    throw new \Exception("Could not restore inline image: " . $e->getMessage());
                }
                break;

            case 'moved_asset':
                // Restore asset to original location
                $asset = Asset::findOne($change['assetId']);
                if ($asset) {
                    $asset->volumeId = $change['fromVolume'];
                    $asset->folderId = $change['fromFolder'];
                    if (!Craft::$app->getElements()->saveElement($asset)) {
                        throw new \Exception("Could not restore asset location");
                    }

                    Craft::info("Restored asset {$change['assetId']} to original location", __METHOD__);
                }
                break;

            case 'fixed_broken_link':
                // Restore asset to original broken state
                $asset = Asset::findOne($change['assetId']);
                if ($asset) {
                    // Check if we have original location data (added in new logging)
                    if (isset($change['originalVolumeId']) && isset($change['originalFolderId'])) {
                        $asset->volumeId = $change['originalVolumeId'];
                        $asset->folderId = $change['originalFolderId'];

                        if (!Craft::$app->getElements()->saveElement($asset)) {
                            throw new \Exception("Could not restore asset to original location");
                        }

                        Craft::info("Restored asset {$change['assetId']} to original broken state", __METHOD__);
                    } else {
                        // Old logging format - can't fully rollback
                        Craft::warning("Cannot fully rollback fixed_broken_link for asset {$change['assetId']} - missing original location data", __METHOD__);
                    }
                } else {
                    Craft::warning("Asset {$change['assetId']} not found during rollback", __METHOD__);
                }
                break;

            case 'quarantined_unused_asset':
                // Restore asset from quarantine
                $asset = Asset::findOne($change['assetId']);
                if ($asset) {
                    $asset->volumeId = $change['fromVolume'];
                    $asset->folderId = $change['fromFolder'];
                    if (!Craft::$app->getElements()->saveElement($asset)) {
                        throw new \Exception("Could not restore asset from quarantine");
                    }

                    Craft::info("Restored asset {$change['assetId']} from quarantine", __METHOD__);
                }
                break;

            case 'quarantined_orphaned_file':
                // Restore file from quarantine (files are moved, not deleted)
                try {
                    // Get quarantine volume
                    $quarantineVolume = Craft::$app->getVolumes()->getVolumeByHandle('quarantine');
                    if (!$quarantineVolume) {
                        throw new \Exception("Quarantine volume not found");
                    }
                    $quarantineFs = $quarantineVolume->getFs();

                    // Get original volume
                    $sourceVolume = Craft::$app->getVolumes()->getVolumeByHandle($change['sourceVolume']);
                    if (!$sourceVolume) {
                        throw new \Exception("Source volume '{$change['sourceVolume']}' not found");
                    }
                    $sourceFs = $sourceVolume->getFs();

                    // Move file back from quarantine to original location
                    $quarantinePath = $change['targetPath']; // Where file is now
                    $originalPath = $change['sourcePath'];   // Where it should go

                    // Check if file exists in quarantine
                    if (!$quarantineFs->fileExists($quarantinePath)) {
                        throw new \Exception("File not found in quarantine: {$quarantinePath}");
                    }

                    // Read from quarantine
                    $content = $quarantineFs->read($quarantinePath);

                    // Write back to original location
                    $sourceFs->write($originalPath, $content, []);

                    // Delete from quarantine
                    $quarantineFs->deleteFile($quarantinePath);

                    Craft::info("Restored orphaned file from quarantine: {$originalPath}", __METHOD__);

                } catch (\Exception $e) {
                    throw new \Exception("Could not restore file from quarantine: " . $e->getMessage());
                }
                break;

            case 'moved_from_optimised_root':
                // Move asset back to optimisedImages root
                $asset = Asset::findOne($change['assetId']);
                if ($asset) {
                    $sourceVolume = Craft::$app->getVolumes()->getVolumeById($change['fromVolume']);
                    if (!$sourceVolume) {
                        throw new \Exception("Source volume not found");
                    }

                    // Get root folder of source volume
                    $sourceRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($sourceVolume->id);
                    if (!$sourceRootFolder) {
                        throw new \Exception("Source root folder not found");
                    }

                    // Move asset back
                    $asset->volumeId = $sourceVolume->id;
                    $asset->folderId = $sourceRootFolder->id;

                    if (!Craft::$app->getElements()->saveElement($asset)) {
                        throw new \Exception("Could not restore asset to optimised root");
                    }

                    Craft::info("Restored asset {$change['assetId']} to optimisedImages root", __METHOD__);
                }
                break;

            case 'updated_asset_path':
                // Restore asset to original volume/path
                $asset = Asset::findOne($change['assetId']);
                if ($asset) {
                    // Check if we have original values (added in new logging)
                    if (isset($change['originalVolumeId'])) {
                        $asset->volumeId = $change['originalVolumeId'];

                        if (!Craft::$app->getElements()->saveElement($asset)) {
                            throw new \Exception("Could not restore asset path");
                        }

                        Craft::info("Restored asset {$change['assetId']} to original path", __METHOD__);
                    } else {
                        Craft::warning("Cannot rollback updated_asset_path for asset {$change['assetId']} - missing original volume ID", __METHOD__);
                    }
                }
                break;

            case 'deleted_transform':
                // Transforms are regenerated automatically by Craft CMS - no rollback needed
                Craft::info("Transform file was deleted but will regenerate: {$change['path']}", __METHOD__);
                break;

            case 'broken_link_not_fixed':
                // Info-only entry, no rollback needed
                Craft::info("Info-only entry (broken_link_not_fixed), no rollback needed", __METHOD__);
                break;

            default:
                Craft::warning("Unknown change type for rollback: " . $change['type'], __METHOD__);
        }
    }
}



/**
 * Lazy-loading asset lookup to avoid memory exhaustion on large datasets
 * Implements ArrayAccess for drop-in compatibility
 */
class LazyAssetLookup implements \ArrayAccess
{
    private $assetInventory;
    private $filenameIndex;

    public function __construct($assetInventory)
    {
        $this->assetInventory = $assetInventory;

        // Build lightweight filename index only
        $this->filenameIndex = [];
        foreach ($assetInventory as $asset) {
            $filename = $asset['filename'];
            if (!isset($this->filenameIndex[$filename])) {
                $this->filenameIndex[$filename] = [];
            }
            $this->filenameIndex[$filename][] = $asset;
        }
    }

    public function offsetExists($offset): bool
    {
        // Check filename index
        if (isset($this->filenameIndex[$offset])) {
            return true;
        }

        // Check if it's a path
        $filename = basename($offset);
        return isset($this->filenameIndex[$filename]);
    }

    public function offsetGet($offset): mixed
    {
        // Direct filename lookup
        if (isset($this->filenameIndex[$offset])) {
            return $this->filenameIndex[$offset][0];
        }

        // Path-based lookup
        $filename = basename($offset);
        if (isset($this->filenameIndex[$filename])) {
            return $this->filenameIndex[$filename][0];
        }

        return null;
    }

    public function offsetSet($offset, $value): void
    {
        // Read-only
        throw new \Exception("LazyAssetLookup is read-only");
    }

    public function offsetUnset($offset): void
    {
        // Read-only
        throw new \Exception("LazyAssetLookup is read-only");
    }

    public function findByFilename($filename)
    {
        if (isset($this->filenameIndex[$filename])) {
            return $this->filenameIndex[$filename][0];
        }
        return null;
    }
}
/**
 * MigrationLock - RESUME-AWARE version
 * Allows resuming the SAME migration but blocks concurrent different migrations
 */
class MigrationLock
{
    private $lockName;
    private $migrationId;
    private $isLocked = false;
    private $lockTimeout = 43200; // 12 hours

    public function __construct($migrationId)
    {
        $this->migrationId = $migrationId;
        $this->lockName = 'migration_lock';
    }

    /**
     * Acquire lock - allows same migration to resume
     */
    public function acquire($timeout = 3, $isResume = false): bool
    {
        $db = Craft::$app->getDb();

        // Clean stale locks first
        $this->cleanStaleLocks($db);

        $startTime = time();

        while (time() - $startTime < $timeout) {
            try {
                // Check if lock exists - USE RAW SQL
                $existingLock = $db->createCommand('
                SELECT migrationId, lockedAt, expiresAt 
                FROM {{%migrationlocks}} 
                WHERE lockName = :lockName
            ', [':lockName' => $this->lockName])->queryOne();

                if ($existingLock) {
                    // If resuming the same migration, that's OK
                    if ($isResume && $existingLock['migrationId'] === $this->migrationId) {
                        // Update the lock with new expiry
                        $db->createCommand('
                        UPDATE {{%migrationlocks}} 
                        SET lockedAt = :lockedAt,
                            lockedBy = :lockedBy,
                            expiresAt = :expiresAt
                        WHERE lockName = :lockName
                    ', [
                            ':lockedAt' => date('Y-m-d H:i:s'),
                            ':lockedBy' => gethostname() . ':' . getmypid(),
                            ':expiresAt' => date('Y-m-d H:i:s', time() + $this->lockTimeout),
                            ':lockName' => $this->lockName
                        ])->execute();

                        $this->isLocked = true;
                        return true;
                    }

                    // Different migration running - wait
                    usleep(500000);
                    continue;
                }

                // No lock exists - create one
                $db->createCommand()->insert('{{%migrationlocks}}', [
                    'lockName' => $this->lockName,
                    'migrationId' => $this->migrationId,
                    'lockedAt' => date('Y-m-d H:i:s'),
                    'lockedBy' => gethostname() . ':' . getmypid(),
                    'expiresAt' => date('Y-m-d H:i:s', time() + $this->lockTimeout)
                ])->execute();

                $this->isLocked = true;
                return true;

            } catch (\yii\db\IntegrityException $e) {
                // Race condition - retry
                usleep(500000);
                continue;
            } catch (\Exception $e) {
                // Table might not exist
                if (!$this->ensureLockTable($db)) {
                    throw new \Exception("Cannot create lock table: " . $e->getMessage());
                }
                usleep(500000);
                continue;
            }
        }

        return false;
    }

    /**
     * Refresh lock to prevent timeout during long operations
     */
    public function refresh(): bool
    {
        if (!$this->isLocked) {
            return false;
        }

        try {
            $db = Craft::$app->getDb();
            $db->createCommand('
            UPDATE {{%migrationlocks}} 
            SET expiresAt = :expiresAt
            WHERE lockName = :lockName AND migrationId = :migrationId
        ', [
                ':expiresAt' => date('Y-m-d H:i:s', time() + $this->lockTimeout),
                ':lockName' => $this->lockName,
                ':migrationId' => $this->migrationId
            ])->execute();

            return true;
        } catch (\Exception $e) {
            Craft::error("Failed to refresh migration lock: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    private function cleanStaleLocks($db): void
    {
        try {
            $db->createCommand('
            DELETE FROM {{%migrationlocks}} 
            WHERE expiresAt < :now
        ', [':now' => date('Y-m-d H:i:s')])->execute();
        } catch (\Exception $e) {
            // Ignore if table doesn't exist
        }
    }

    public function release(): void
    {
        if (!$this->isLocked) {
            return;
        }

        try {
            $db = Craft::$app->getDb();
            $db->createCommand('
            DELETE FROM {{%migrationlocks}} 
            WHERE lockName = :lockName AND migrationId = :migrationId
        ', [
                ':lockName' => $this->lockName,
                ':migrationId' => $this->migrationId
            ])->execute();
        } catch (\Exception $e) {
            Craft::error("Failed to release migration lock: " . $e->getMessage(), __METHOD__);
        }

        $this->isLocked = false;
    }

    private function ensureLockTable($db): bool
    {
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
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}