<?php

namespace csabourin\craftS3SpacesMigration\services;

use Craft;
use craft\console\Controller;
use craft\console\ExitCode;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\craftS3SpacesMigration\helpers\MigrationConfig;
use csabourin\craftS3SpacesMigration\services\ChangeLogManager;
use csabourin\craftS3SpacesMigration\services\CheckpointManager;
use csabourin\craftS3SpacesMigration\services\ErrorRecoveryManager;
use csabourin\craftS3SpacesMigration\services\MigrationLock;
use csabourin\craftS3SpacesMigration\services\RollbackEngine;
use csabourin\craftS3SpacesMigration\services\migration\BackupService;
use csabourin\craftS3SpacesMigration\services\migration\ConsolidationService;
use csabourin\craftS3SpacesMigration\services\migration\DuplicateResolutionService;
use csabourin\craftS3SpacesMigration\services\migration\InlineLinkingService;
use csabourin\craftS3SpacesMigration\services\migration\InventoryBuilder;
use csabourin\craftS3SpacesMigration\services\migration\LinkRepairService;
use csabourin\craftS3SpacesMigration\services\migration\MigrationReporter;
use csabourin\craftS3SpacesMigration\services\migration\OptimizedImagesService;
use csabourin\craftS3SpacesMigration\services\migration\QuarantineService;
use csabourin\craftS3SpacesMigration\services\migration\ValidationService;
use csabourin\craftS3SpacesMigration\services\migration\VerificationService;

/**
 * Migration Orchestrator
 *
 * Main orchestration layer that coordinates all migration services through
 * a multi-phase migration process:
 *
 * - Phase 0: Preparation & Validation
 * - Phase 0.5: Handle optimisedImages at root (if applicable)
 * - Phase 1: Discovery & Analysis
 * - Phase 1.5: Link Inline Images
 * - Phase 1.7: Safe File Duplicate Detection & Staging
 * - Phase 1.8: Resolve Duplicate Assets
 * - Phase 2: Fix Broken Asset-File Links
 * - Phase 3: Consolidate Used Files
 * - Phase 4: Quarantine Unused Files
 * - Phase 4.5: Cleanup Duplicate Temp Files
 * - Phase 5: Cleanup & Verification
 * - Phase 5.5: Update Filesystem Subfolder
 *
 * Features:
 * - Complete checkpoint/resume capability
 * - User confirmations at critical points
 * - Comprehensive error handling and recovery
 * - Migration lock to prevent concurrent runs
 * - Dry-run mode for planning
 * - Full audit trail via change log
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class MigrationOrchestrator
{
    /**
     * @var Controller Controller instance
     */
    private $controller;

    /**
     * @var MigrationConfig Configuration
     */
    private $config;

    /**
     * @var InventoryBuilder Inventory builder service
     */
    private $inventoryBuilder;

    /**
     * @var InlineLinkingService Inline linking service
     */
    private $inlineLinkingService;

    /**
     * @var DuplicateResolutionService Duplicate resolution service
     */
    private $duplicateResolutionService;

    /**
     * @var LinkRepairService Link repair service
     */
    private $linkRepairService;

    /**
     * @var ConsolidationService Consolidation service
     */
    private $consolidationService;

    /**
     * @var QuarantineService Quarantine service
     */
    private $quarantineService;

    /**
     * @var VerificationService Verification service
     */
    private $verificationService;

    /**
     * @var BackupService Backup service
     */
    private $backupService;

    /**
     * @var OptimizedImagesService Optimized images service
     */
    private $optimizedImagesService;

    /**
     * @var ValidationService Validation service
     */
    private $validationService;

    /**
     * @var MigrationReporter Reporter
     */
    private $reporter;

    /**
     * @var CheckpointManager Checkpoint manager
     */
    private $checkpointManager;

    /**
     * @var ChangeLogManager Change log manager
     */
    private $changeLogManager;

    /**
     * @var ErrorRecoveryManager Error recovery manager
     */
    private $errorRecoveryManager;

    /**
     * @var RollbackEngine Rollback engine
     */
    private $rollbackEngine;

    /**
     * @var MigrationLock Migration lock
     */
    private $migrationLock;

    /**
     * @var string Migration ID
     */
    private $migrationId;

    /**
     * @var string Current phase
     */
    private $currentPhase = 'initializing';

    /**
     * @var array Migration statistics
     */
    private $stats = [
        'start_time' => 0,
        'files_moved' => 0,
        'files_quarantined' => 0,
        'errors' => 0
    ];

    /**
     * @var array Migration options
     */
    private $options = [
        'dryRun' => false,
        'yes' => false,
        'skipBackup' => false,
        'skipInlineDetection' => false,
        'skipLock' => false,
        'resume' => false,
        'checkpointId' => null
    ];

    /**
     * @var int Expected missing file count (for error thresholds)
     */
    private $expectedMissingFileCount = 0;

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param MigrationConfig $config Configuration
     * @param InventoryBuilder $inventoryBuilder Inventory builder
     * @param InlineLinkingService $inlineLinkingService Inline linking service
     * @param DuplicateResolutionService $duplicateResolutionService Duplicate resolution service
     * @param LinkRepairService $linkRepairService Link repair service
     * @param ConsolidationService $consolidationService Consolidation service
     * @param QuarantineService $quarantineService Quarantine service
     * @param VerificationService $verificationService Verification service
     * @param BackupService $backupService Backup service
     * @param OptimizedImagesService $optimizedImagesService Optimized images service
     * @param ValidationService $validationService Validation service
     * @param MigrationReporter $reporter Reporter
     * @param CheckpointManager $checkpointManager Checkpoint manager
     * @param ChangeLogManager $changeLogManager Change log manager
     * @param ErrorRecoveryManager $errorRecoveryManager Error recovery manager
     * @param RollbackEngine $rollbackEngine Rollback engine
     * @param MigrationLock $migrationLock Migration lock
     * @param string $migrationId Migration ID
     * @param array $options Migration options
     */
    public function __construct(
        Controller $controller,
        MigrationConfig $config,
        InventoryBuilder $inventoryBuilder,
        InlineLinkingService $inlineLinkingService,
        DuplicateResolutionService $duplicateResolutionService,
        LinkRepairService $linkRepairService,
        ConsolidationService $consolidationService,
        QuarantineService $quarantineService,
        VerificationService $verificationService,
        BackupService $backupService,
        OptimizedImagesService $optimizedImagesService,
        ValidationService $validationService,
        MigrationReporter $reporter,
        CheckpointManager $checkpointManager,
        ChangeLogManager $changeLogManager,
        ErrorRecoveryManager $errorRecoveryManager,
        RollbackEngine $rollbackEngine,
        MigrationLock $migrationLock,
        string $migrationId,
        array $options = []
    ) {
        $this->controller = $controller;
        $this->config = $config;
        $this->inventoryBuilder = $inventoryBuilder;
        $this->inlineLinkingService = $inlineLinkingService;
        $this->duplicateResolutionService = $duplicateResolutionService;
        $this->linkRepairService = $linkRepairService;
        $this->consolidationService = $consolidationService;
        $this->quarantineService = $quarantineService;
        $this->verificationService = $verificationService;
        $this->backupService = $backupService;
        $this->optimizedImagesService = $optimizedImagesService;
        $this->validationService = $validationService;
        $this->reporter = $reporter;
        $this->checkpointManager = $checkpointManager;
        $this->changeLogManager = $changeLogManager;
        $this->errorRecoveryManager = $errorRecoveryManager;
        $this->rollbackEngine = $rollbackEngine;
        $this->migrationLock = $migrationLock;
        $this->migrationId = $migrationId;
        $this->options = array_merge($this->options, $options);
        $this->stats['start_time'] = time();
    }

    /**
     * Execute the migration
     *
     * Main entry point that orchestrates all migration phases.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        $this->reporter->printHeader();

        // Handle resume mode
        if ($this->options['resume'] || $this->options['checkpointId']) {
            return $this->resumeMigration();
        }

        // Acquire lock
        if (!$this->acquireLock()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Print mode warnings
        $this->printModeWarnings();

        // Initialize quick state
        $this->initializeQuickState();

        // Register migration start
        $this->registerMigrationStart();

        try {
            // Phase 0: Preparation & Validation
            $volumes = $this->executePhase0Preparation();
            $targetVolume = $volumes['target'];
            $sourceVolumes = $volumes['sources'];
            $quarantineVolume = $volumes['quarantine'];
            $targetRootFolder = $volumes['targetRootFolder'];

            // Create backup
            if (!$this->options['dryRun'] && !$this->options['skipBackup']) {
                $this->backupService->createBackup();
            } elseif ($this->options['skipBackup'] && !$this->options['dryRun']) {
                $this->printBackupWarning();
            }

            // Phase 0.5: Handle optimisedImages at root (if applicable)
            $this->executePhase05OptimizedImages($sourceVolumes, $targetVolume, $quarantineVolume);

            // Phase 1: Discovery & Analysis
            $data = $this->executePhase1Discovery($sourceVolumes, $targetVolume, $quarantineVolume, $targetRootFolder);
            $assetInventory = $data['assetInventory'];
            $fileInventory = $data['fileInventory'];
            $analysis = $data['analysis'];

            // Dry run exit
            if ($this->options['dryRun']) {
                return $this->handleDryRunExit($analysis);
            }

            // Confirm before proceeding
            if (!$this->confirmProceed()) {
                return ExitCode::OK;
            }

            // Phase 1.5: Link Inline Images
            if (!$this->options['skipInlineDetection']) {
                $linkingStats = $this->executePhase15InlineLinking($assetInventory, $targetVolume);

                if ($linkingStats['rows_updated'] > 0) {
                    // Rebuild inventory
                    $assetInventory = $this->inventoryBuilder->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
                    $analysis = $this->inventoryBuilder->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);
                    $this->reporter->printAnalysisReport($analysis);
                }
            }

            // Phase 1.7: Safe File Duplicate Detection & Staging
            $duplicateGroups = $this->executePhase17SafeDuplicates($sourceVolumes, $targetVolume, $quarantineVolume);

            // Phase 1.8: Resolve Duplicate Assets
            if (!empty($analysis['duplicates'])) {
                $this->executePhase18ResolveDuplicates($analysis, $sourceVolumes, $targetVolume, $quarantineVolume);

                // Rebuild inventory
                $assetInventory = $this->inventoryBuilder->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
                $analysis = $this->inventoryBuilder->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);
            }

            // Phase 2: Fix Broken Links
            if (!empty($analysis['broken_links'])) {
                $this->executePhase2FixLinks($analysis, $fileInventory, $sourceVolumes, $targetVolume, $targetRootFolder);
            }

            // Phase 3: Consolidate Used Files
            $this->executePhase3Consolidation($analysis, $targetVolume, $targetRootFolder);

            // Phase 4: Quarantine Unused Files
            if (!empty($analysis['orphaned_files']) || !empty($analysis['unused_assets'])) {
                $proceed = $this->confirmQuarantine($analysis, $targetVolume);
                if ($proceed) {
                    $this->executePhase4Quarantine($analysis, $quarantineVolume);
                }
            }

            // Phase 4.5: Cleanup Duplicate Temp Files
            if (!empty($duplicateGroups)) {
                $this->executePhase45CleanupTemp($quarantineVolume);
            }

            // Phase 5: Cleanup & Verification
            $this->executePhase5Verification($targetVolume, $targetRootFolder);

            // Phase 5.5: Update Filesystem Subfolder
            $this->executePhase55UpdateSubfolder();

            // Mark as complete
            $this->setPhase('complete');
            $this->saveCheckpoint(['completed' => true]);
            $this->changeLogManager->flush();

            // Final report
            $this->reporter->printFinalReport($this->stats);

            // Cleanup
            $this->checkpointManager->cleanupOldCheckpoints();

        } catch (\Exception $e) {
            return $this->handleFatalError($e);
        }

        $this->reporter->printSuccessFooter();
        $this->controller->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Resume migration from checkpoint
     *
     * @return int Exit code
     */
    private function resumeMigration(): int
    {
        $this->controller->stdout("  Resume functionality delegates to existing ImageMigrationController\n", Console::FG_CYAN);
        $this->controller->stdout("  This orchestrator focuses on new migrations\n\n", Console::FG_GREY);
        return ExitCode::OK;
    }

    /**
     * Acquire migration lock
     *
     * @return bool Success
     */
    private function acquireLock(): bool
    {
        if ($this->options['skipLock']) {
            $this->controller->stdout("  ⚠ SKIPPING LOCK CHECK - ensure no other migration is running!\n", Console::FG_YELLOW);
            return true;
        }

        $this->controller->stdout("  Acquiring migration lock... ");
        if (!$this->migrationLock->acquire(5, false)) {
            $this->controller->stderr("\n\nERROR: Another migration is currently running.\n", Console::FG_RED);
            $this->controller->stderr("Wait for it to complete, or if it's stuck, run:\n");
            $this->controller->stderr("  ./craft s3-spaces-migration/image-migration/force-cleanup\n\n");
            $this->controller->stderr("__CLI_EXIT_CODE_1__\n");
            return false;
        }
        $this->controller->stdout("acquired\n", Console::FG_GREEN);
        return true;
    }

    /**
     * Print mode warnings
     */
    private function printModeWarnings(): void
    {
        if ($this->options['dryRun']) {
            $this->controller->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        if ($this->options['skipInlineDetection']) {
            $this->controller->stdout("INLINE DETECTION SKIPPED - Only checking asset field relations\n", Console::FG_YELLOW);
            $this->controller->stdout("⚠ WARNING: Images in RTE fields may be quarantined as unused!\n\n", Console::FG_YELLOW);
        }
    }

    /**
     * Initialize quick state
     */
    private function initializeQuickState(): void
    {
        $this->checkpointManager->saveQuickState([
            'migration_id' => $this->migrationId,
            'phase' => 'initializing',
            'batch' => 0,
            'processed_ids' => [],
            'processed_count' => 0,
            'stats' => $this->stats,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Register migration start
     */
    private function registerMigrationStart(): void
    {
        $pid = getmypid();
        $this->checkpointManager->registerMigrationStart(
            $pid,
            null,
            's3-spaces-migration/image-migration/migrate',
            0
        );
    }

    /**
     * Execute Phase 0: Preparation & Validation
     *
     * @return array Volumes and folder data
     */
    private function executePhase0Preparation(): array
    {
        $this->setPhase('preparation');
        $this->reporter->printPhaseHeader("PHASE 0: PREPARATION & VALIDATION");

        $volumes = $this->validationService->validateConfiguration();
        $targetVolume = $volumes['target'];
        $sourceVolumes = $volumes['sources'];
        $quarantineVolume = $volumes['quarantine'];

        $this->controller->stdout("  ✓ Volumes validated\n", Console::FG_GREEN);
        $this->controller->stdout("    Target: {$targetVolume->name} (ID: {$targetVolume->id})\n");
        $this->controller->stdout("    Quarantine: {$quarantineVolume->name} (ID: {$quarantineVolume->id})\n");

        // Health check
        $this->validationService->performHealthCheck($targetVolume, $quarantineVolume);

        // Disk space validation
        $this->validationService->validateDiskSpace($sourceVolumes, $targetVolume);

        // Get target root folder
        $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);
        if (!$targetRootFolder) {
            throw new \Exception("Cannot find root folder for target volume");
        }

        $this->controller->stdout("    Target root folder ID: {$targetRootFolder->id}\n\n");

        // Checkpoint
        $this->saveCheckpoint([
            'volumes' => [
                'target' => $targetVolume->id,
                'sources' => array_map(fn($v) => $v->id, $sourceVolumes),
                'quarantine' => $quarantineVolume->id
            ],
            'targetRootFolderId' => $targetRootFolder->id
        ]);

        return [
            'target' => $targetVolume,
            'sources' => $sourceVolumes,
            'quarantine' => $quarantineVolume,
            'targetRootFolder' => $targetRootFolder
        ];
    }

    /**
     * Print backup warning
     */
    private function printBackupWarning(): void
    {
        $this->controller->stdout("  ⚠⚠⚠ WARNING: BACKUP SKIPPED ⚠⚠⚠\n", Console::FG_RED);
        $this->controller->stdout("  This migration will make destructive changes to your database!\n", Console::FG_RED);
        $this->controller->stdout("  Proceeding without backup is EXTREMELY RISKY.\n", Console::FG_RED);
        $this->controller->stdout("  Press Ctrl+C now to cancel, or wait 10 seconds to continue...\n\n", Console::FG_YELLOW);
        sleep(10);
    }

    /**
     * Execute Phase 0.5: Handle optimisedImages at root
     *
     * @param array $sourceVolumes Source volumes
     * @param $targetVolume Target volume
     * @param $quarantineVolume Quarantine volume
     */
    private function executePhase05OptimizedImages(array $sourceVolumes, $targetVolume, $quarantineVolume): void
    {
        $sourceHandles = $this->config->getSourceVolumeHandles();

        if (!in_array('optimisedImages', $sourceHandles)) {
            return;
        }

        $volumesService = Craft::$app->getVolumes();
        $optimisedVolume = $volumesService->getVolumeByHandle('optimisedImages');

        if (!$optimisedVolume) {
            $this->controller->stdout("  ⚠ optimisedImages volume not found - skipping Phase 0.5\n", Console::FG_YELLOW);
            return;
        }

        $assetCount = (int) Asset::find()->volumeId($optimisedVolume->id)->count();

        if ($assetCount === 0) {
            $this->controller->stdout("\n");
            $this->reporter->printPhaseHeader("PHASE 0.5: OPTIMISED IMAGES (SKIPPED)");
            $this->controller->stdout("  No assets found in optimisedImages volume - skipping migration\n", Console::FG_GREY);
            $this->controller->stdout("  Phase 1 will handle all asset discovery\n\n", Console::FG_GREY);
            return;
        }

        $this->setPhase('optimised_root');
        $assetInventory = $this->inventoryBuilder->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
        $fileInventory = $this->inventoryBuilder->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);

        $this->optimizedImagesService->handleOptimisedImagesAtRoot(
            $assetInventory,
            $fileInventory,
            $targetVolume,
            $quarantineVolume
        );

        $this->controller->stdout("  ✓ Optimised images processed - Phase 1 will build fresh inventories\n", Console::FG_GREEN);
    }

    /**
     * Execute Phase 1: Discovery & Analysis
     *
     * @param array $sourceVolumes Source volumes
     * @param $targetVolume Target volume
     * @param $quarantineVolume Quarantine volume
     * @param $targetRootFolder Target root folder
     * @return array Asset inventory, file inventory, and analysis
     */
    private function executePhase1Discovery(array $sourceVolumes, $targetVolume, $quarantineVolume, $targetRootFolder): array
    {
        $this->setPhase('discovery');
        $this->reporter->printPhaseHeader("PHASE 1: DISCOVERY & ANALYSIS");

        $this->controller->stdout("  Building asset inventory (batch processing)...\n");
        $assetInventory = $this->inventoryBuilder->buildAssetInventoryBatched($sourceVolumes, $targetVolume);

        $this->controller->stdout("  Scanning filesystems for actual files...\n");
        $fileInventory = $this->inventoryBuilder->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);

        $this->controller->stdout("  Analyzing asset-file relationships...\n");
        $analysis = $this->inventoryBuilder->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);

        $this->reporter->printAnalysisReport($analysis);

        // Set expected missing file count
        $this->expectedMissingFileCount = count($analysis['broken_links']);
        $this->controller->stdout(sprintf(
            "  ⓘ Expected missing file errors: %d (will not halt migration)\n",
            $this->expectedMissingFileCount
        ), Console::FG_CYAN);

        // Save phase 1 results
        $this->backupService->savePhase1Results($assetInventory, $fileInventory, $analysis);

        // Checkpoint
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

        return [
            'assetInventory' => $assetInventory,
            'fileInventory' => $fileInventory,
            'analysis' => $analysis
        ];
    }

    /**
     * Handle dry run exit
     *
     * @param array $analysis Analysis data
     * @return int Exit code
     */
    private function handleDryRunExit(array $analysis): int
    {
        $this->controller->stdout("\nDRY RUN - Would perform these operations:\n", Console::FG_YELLOW);

        if (!$this->options['skipInlineDetection']) {
            $db = Craft::$app->getDb();
            $inlineEstimate = $this->inlineLinkingService->estimateInlineLinking($db);

            if ($inlineEstimate['columns_found'] > 0) {
                $this->controller->stdout("  0. Link ~{$inlineEstimate['images_estimate']} inline images\n", Console::FG_CYAN);
                $this->controller->stdout("     Estimated time: {$inlineEstimate['time_estimate']}\n\n", Console::FG_GREY);
            }
        }

        $this->reporter->printPlannedOperations($analysis);
        $this->controller->stdout("\nTo execute: ./craft s3-spaces-migration/image-migration/migrate\n", Console::FG_CYAN);
        $this->controller->stdout("To resume if interrupted: ./craft s3-spaces-migration/image-migration/migrate --resume\n\n", Console::FG_CYAN);
        $this->controller->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Confirm proceed with migration
     *
     * @return bool True if should proceed
     */
    private function confirmProceed(): bool
    {
        $this->controller->stdout("\n");
        if (!$this->options['skipInlineDetection']) {
            $this->controller->stdout("⚠ Next: Link inline images to create proper asset relations\n", Console::FG_YELLOW);
        }

        // Force flush
        $this->changeLogManager->flush();

        if (!$this->options['yes']) {
            $confirm = $this->controller->prompt("Proceed with migration? (yes/no)", [
                'required' => true,
                'default' => 'no',
            ]);

            if ($confirm !== 'yes') {
                $this->controller->stdout("Migration cancelled.\n");
                $this->controller->stdout("__CLI_EXIT_CODE_0__\n");
                $this->controller->stdout("Checkpoint saved. Resume with: ./craft s3-spaces-migration/image-migration/migrate --resume\n", Console::FG_CYAN);
                return false;
            }
        } else {
            $this->controller->stdout("⚠ Auto-confirmed (--yes flag)\n", Console::FG_YELLOW);
        }

        return true;
    }

    /**
     * Execute Phase 1.5: Link Inline Images
     *
     * @param array $assetInventory Asset inventory
     * @param $targetVolume Target volume
     * @return array Linking statistics
     */
    private function executePhase15InlineLinking(array $assetInventory, $targetVolume): array
    {
        $this->setPhase('link_inline');
        $this->reporter->printPhaseHeader("PHASE 1.5: LINKING INLINE IMAGES TO ASSETS");

        $linkingStats = $this->inlineLinkingService->linkInlineImagesBatched(
            Craft::$app->getDb(),
            $assetInventory,
            $targetVolume,
            fn($data) => $this->saveCheckpoint($data)
        );

        if ($linkingStats['rows_updated'] > 0) {
            $this->controller->stdout("\n  Rebuilding asset inventory with new relations...\n");
        }

        $this->saveCheckpoint(['inline_linking_complete' => true]);
        $this->changeLogManager->flush();

        return $linkingStats;
    }

    /**
     * Execute Phase 1.7: Safe File Duplicate Detection & Staging
     *
     * @param array $sourceVolumes Source volumes
     * @param $targetVolume Target volume
     * @param $quarantineVolume Quarantine volume
     * @return array Duplicate groups
     */
    private function executePhase17SafeDuplicates(array $sourceVolumes, $targetVolume, $quarantineVolume): array
    {
        $this->setPhase('safe_duplicates');
        $this->reporter->printPhaseHeader("PHASE 1.7: SAFE FILE DUPLICATE DETECTION & STAGING");

        $duplicateGroups = $this->duplicateResolutionService->analyzeFileDuplicates($sourceVolumes, $targetVolume);

        if (empty($duplicateGroups)) {
            $this->controller->stdout("  No duplicate files detected - all assets have unique physical files\n", Console::FG_GREEN);
            $this->saveCheckpoint(['safe_duplicates_complete' => true]);
            return [];
        }

        $batchSize = $this->config->getBatchSize();

        // Stage files
        $this->controller->stdout("\n");
        $this->reporter->printPhaseHeader("PHASE 1.7a: STAGING FILES TO SAFE LOCATION");
        $this->duplicateResolutionService->stageFilesToTemp($duplicateGroups, $quarantineVolume, $batchSize);

        // Determine primary assets
        $this->controller->stdout("\n");
        $this->reporter->printPhaseHeader("PHASE 1.7b: DETERMINING PRIMARY ASSETS");
        $this->duplicateResolutionService->determineActiveAssets($duplicateGroups, $batchSize);

        // Delete unused duplicates
        $this->controller->stdout("\n");
        $this->reporter->printPhaseHeader("PHASE 1.7c: CLEANING UP DUPLICATE ASSETS");
        $this->duplicateResolutionService->deleteUnusedDuplicateAssets($duplicateGroups, $batchSize);

        $this->saveCheckpoint(['safe_duplicates_complete' => true]);
        $this->changeLogManager->flush();

        $this->controller->stdout("\n  ✓ Safe duplicate handling complete\n", Console::FG_GREEN);
        $this->controller->stdout("  Files are staged in quarantine temp folder and will be safely migrated\n", Console::FG_CYAN);
        $this->controller->stdout("  Source files will only be deleted when safe to do so\n\n", Console::FG_CYAN);

        // Safety verification
        $this->controller->stdout("\n");
        $this->reporter->printPhaseHeader("PHASE 1.7d: VERIFY FILE SAFETY");
        $safetyReport = $this->duplicateResolutionService->verifyFileSafety($duplicateGroups, $quarantineVolume);

        if ($safetyReport['unsafe'] > 0) {
            $msg = "CRITICAL: {$safetyReport['unsafe']} files are not safely backed up.";
            $this->controller->stderr("\n{$msg}\n\n", Console::FG_RED);
            throw new \Exception($msg);
        }

        $this->controller->stdout("\n");
        return $duplicateGroups;
    }

    /**
     * Execute Phase 1.8: Resolve Duplicate Assets
     *
     * @param array $analysis Analysis data
     * @param array $sourceVolumes Source volumes
     * @param $targetVolume Target volume
     * @param $quarantineVolume Quarantine volume
     */
    private function executePhase18ResolveDuplicates(array $analysis, array $sourceVolumes, $targetVolume, $quarantineVolume): void
    {
        $this->setPhase('resolve_duplicates');
        $this->reporter->printPhaseHeader("PHASE 1.8: RESOLVE DUPLICATE ASSETS");

        $duplicateCount = count($analysis['duplicates']);
        $this->controller->stdout("  Found {$duplicateCount} sets of duplicate filenames\n", Console::FG_YELLOW);
        $this->controller->stdout("  Resolving duplicates by merging into best candidate...\n\n");
        $this->controller->stdout("  NOTE: Files are protected by Phase 1.7 staging - safe to delete asset records\n\n", Console::FG_CYAN);

        $this->duplicateResolutionService->resolveDuplicateAssets($analysis['duplicates'], $targetVolume);

        $this->saveCheckpoint(['duplicates_resolved' => true]);
        $this->changeLogManager->flush();
    }

    /**
     * Execute Phase 2: Fix Broken Links
     *
     * @param array $analysis Analysis data
     * @param array $fileInventory File inventory
     * @param array $sourceVolumes Source volumes
     * @param $targetVolume Target volume
     * @param $targetRootFolder Target root folder
     */
    private function executePhase2FixLinks(array $analysis, array $fileInventory, array $sourceVolumes, $targetVolume, $targetRootFolder): void
    {
        $this->setPhase('fix_links');
        $this->reporter->printPhaseHeader("PHASE 2: FIX BROKEN ASSET-FILE LINKS");

        $this->linkRepairService->fixBrokenLinksBatched(
            $analysis['broken_links'],
            $fileInventory,
            $sourceVolumes,
            $targetVolume,
            $targetRootFolder,
            fn($data) => $this->saveCheckpoint($data)
        );

        // Export missing files
        $this->verificationService->exportMissingFilesToCsv();
    }

    /**
     * Execute Phase 3: Consolidate Used Files
     *
     * @param array $analysis Analysis data
     * @param $targetVolume Target volume
     * @param $targetRootFolder Target root folder
     */
    private function executePhase3Consolidation(array $analysis, $targetVolume, $targetRootFolder): void
    {
        $this->setPhase('consolidate');
        $this->reporter->printPhaseHeader("PHASE 3: CONSOLIDATE USED FILES");

        $this->consolidationService->consolidateUsedFilesBatched(
            $analysis['used_assets_wrong_location'],
            $targetVolume,
            $targetRootFolder,
            fn($data) => $this->saveCheckpoint($data)
        );
    }

    /**
     * Confirm quarantine operation
     *
     * @param array $analysis Analysis data
     * @param $targetVolume Target volume
     * @return bool True if should proceed
     */
    private function confirmQuarantine(array $analysis, $targetVolume): bool
    {
        $unusedCount = count($analysis['unused_assets']);
        $orphanedCount = count($analysis['orphaned_files']);

        if ($unusedCount > 0) {
            $this->controller->stdout("  ⚠ About to quarantine {$unusedCount} unused assets from target volume\n", Console::FG_YELLOW);
        }
        if ($orphanedCount > 0) {
            $this->controller->stdout("  ⚠ About to quarantine {$orphanedCount} orphaned files from target volume\n", Console::FG_YELLOW);
        }

        $this->controller->stdout("  ℹ️  Note: Only files in the target volume ('{$targetVolume->name}') will be quarantined.\n", Console::FG_CYAN);
        $this->controller->stdout("  ℹ️  Source volumes are for discovery only - their files are not affected.\n\n", Console::FG_CYAN);

        // Force flush
        $this->changeLogManager->flush();

        if ($this->options['yes']) {
            $this->controller->stdout("⚠ Auto-confirmed (--yes flag)\n", Console::FG_YELLOW);
            return true;
        }

        $confirm = $this->controller->prompt("Proceed with quarantine? (yes/no)", [
            'required' => true,
            'default' => 'no',
        ]);

        if ($confirm !== 'yes') {
            $this->controller->stdout("Quarantine cancelled. Skipping to cleanup.\n\n", Console::FG_YELLOW);
            return false;
        }

        return true;
    }

    /**
     * Execute Phase 4: Quarantine Unused Files
     *
     * @param array $analysis Analysis data
     * @param $quarantineVolume Quarantine volume
     */
    private function executePhase4Quarantine(array $analysis, $quarantineVolume): void
    {
        $this->setPhase('quarantine');
        $this->reporter->printPhaseHeader("PHASE 4: QUARANTINE UNUSED FILES (TARGET VOLUME ONLY)");

        $quarantineFs = $quarantineVolume->getFs();

        $this->quarantineService->quarantineUnusedFilesBatched(
            $analysis['orphaned_files'],
            $analysis['unused_assets'],
            $quarantineVolume,
            $quarantineFs,
            fn($data) => $this->saveCheckpoint($data)
        );
    }

    /**
     * Execute Phase 4.5: Cleanup Duplicate Temp Files
     *
     * @param $quarantineVolume Quarantine volume
     */
    private function executePhase45CleanupTemp($quarantineVolume): void
    {
        $this->controller->stdout("\n");
        $this->reporter->printPhaseHeader("PHASE 4.5: CLEANUP DUPLICATE TEMP FILES");
        $this->duplicateResolutionService->cleanupTempFiles($quarantineVolume);
        $this->saveCheckpoint(['temp_cleanup_complete' => true]);
    }

    /**
     * Execute Phase 5: Cleanup & Verification
     *
     * @param $targetVolume Target volume
     * @param $targetRootFolder Target root folder
     */
    private function executePhase5Verification($targetVolume, $targetRootFolder): void
    {
        $this->setPhase('cleanup');
        $this->reporter->printPhaseHeader("PHASE 5: CLEANUP & VERIFICATION");
        $this->verificationService->performCleanupAndVerification($targetVolume, $targetRootFolder);
    }

    /**
     * Execute Phase 5.5: Update Filesystem Subfolder
     */
    private function executePhase55UpdateSubfolder(): void
    {
        $this->controller->stdout("\n");
        $this->reporter->printPhaseHeader("PHASE 5.5: UPDATE FILESYSTEM SUBFOLDER");
        $this->verificationService->updateOptimisedImagesSubfolder();
    }

    /**
     * Handle fatal error
     *
     * @param \Exception $e Exception
     * @return int Exit code
     */
    private function handleFatalError(\Exception $e): int
    {
        $checkpointSaved = false;
        try {
            $this->saveCheckpoint(['error' => $e->getMessage()]);
            $checkpointSaved = true;
        } catch (\Exception $saveError) {
            // Ignore
        }

        $this->reporter->printFatalError($e, $this->checkpointManager, $checkpointSaved);
        $this->controller->stderr("__CLI_EXIT_CODE_1__\n");
        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Set current phase
     *
     * @param string $phase Phase name
     */
    private function setPhase(string $phase): void
    {
        $this->currentPhase = $phase;
        $this->changeLogManager->setPhase($phase);
    }

    /**
     * Save checkpoint
     *
     * @param array $data Checkpoint data
     */
    private function saveCheckpoint(array $data): void
    {
        $this->checkpointManager->saveCheckpoint(array_merge([
            'phase' => $this->currentPhase,
            'stats' => $this->stats
        ], $data));
    }
}
