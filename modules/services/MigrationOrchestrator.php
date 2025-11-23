<?php

namespace csabourin\spaghettiMigrator\services;

use Craft;
use craft\console\Controller;
use craft\console\ExitCode;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\ChangeLogManager;
use csabourin\spaghettiMigrator\services\CheckpointManager;
use csabourin\spaghettiMigrator\services\ErrorRecoveryManager;
use csabourin\spaghettiMigrator\services\MigrationLock;
use csabourin\spaghettiMigrator\services\RollbackEngine;
use csabourin\spaghettiMigrator\services\migration\BackupService;
use csabourin\spaghettiMigrator\services\migration\ConsolidationService;
use csabourin\spaghettiMigrator\services\migration\DuplicateResolutionService;
use csabourin\spaghettiMigrator\services\migration\InlineLinkingService;
use csabourin\spaghettiMigrator\services\migration\InventoryBuilder;
use csabourin\spaghettiMigrator\services\migration\LinkRepairService;
use csabourin\spaghettiMigrator\services\migration\MigrationReporter;
use csabourin\spaghettiMigrator\services\migration\NestedFilesystemService;
use csabourin\spaghettiMigrator\services\migration\QuarantineService;
use csabourin\spaghettiMigrator\services\migration\ValidationService;
use csabourin\spaghettiMigrator\services\migration\VerificationService;

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
     * @var NestedFilesystemService Nested filesystem service
     */
    private $nestedFilesystemService;

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
     * @var int Current batch number for resume tracking
     */
    private $currentBatch = 0;

    /**
     * @var array Processed asset IDs for resume tracking
     */
    private $processedAssetIds = [];

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
     * @param NestedFilesystemService $nestedFilesystemService Nested filesystem service
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
        NestedFilesystemService $nestedFilesystemService,
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
        $this->nestedFilesystemService = $nestedFilesystemService;
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
        $this->controller->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_YELLOW);
        $this->controller->stdout("RESUMING MIGRATION FROM CHECKPOINT\n", Console::FG_YELLOW);
        $this->controller->stdout(str_repeat("=", 80) . "\n\n", Console::FG_YELLOW);

        // Try quick state first for faster resume
        $quickState = $this->checkpointManager->loadQuickState();

        if ($quickState && !$this->options['checkpointId']) {
            $this->controller->stdout("Found quick-resume state:\n", Console::FG_CYAN);
            $this->controller->stdout("  Phase: {$quickState['phase']}\n");
            $this->controller->stdout("  Processed: {$quickState['processed_count']} items\n");
            $this->controller->stdout("  Last updated: {$quickState['timestamp']}\n\n");

            // Restore from quick state
            $this->migrationId = $quickState['migration_id'];
            $this->processedAssetIds = $quickState['processed_ids'] ?? [];
            $this->currentPhase = $quickState['phase'];
            $this->stats = array_merge($this->stats, $quickState['stats'] ?? []);

            // Clear any stale locks before acquiring for resume
            $this->clearStaleLocks();

            // Update lock with resumed migration ID
            $this->migrationLock = new MigrationLock($this->migrationId);
            $this->controller->stdout("  Acquiring lock for resumed migration... ");
            if (!$this->migrationLock->acquire(5, true)) {
                $this->controller->stderr("FAILED\n", Console::FG_RED);
                $this->controller->stderr("Cannot acquire lock for migration {$this->migrationId}\n");
                $this->controller->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->controller->stdout("acquired\n\n", Console::FG_GREEN);

        } else {
            // Full checkpoint loading
            $checkpoint = $this->checkpointManager->loadLatestCheckpoint($this->options['checkpointId']);

            if (!$checkpoint) {
                $this->controller->stderr("No checkpoint found to resume from.\n", Console::FG_RED);
                $this->controller->stdout("\nAvailable checkpoints:\n");
                $available = $this->checkpointManager->listCheckpoints();
                foreach ($available as $cp) {
                    $this->controller->stdout("  - {$cp['id']} ({$cp['phase']}) at {$cp['timestamp']} - {$cp['processed']} items\n", Console::FG_CYAN);
                }
                $this->controller->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->controller->stdout("Found checkpoint: {$checkpoint['phase']} at {$checkpoint['timestamp']}\n", Console::FG_GREEN);
            $this->controller->stdout("Migration ID: {$checkpoint['migration_id']}\n");
            $this->controller->stdout("Processed: " . count($checkpoint['processed_ids'] ?? []) . " items\n\n");

            // Restore state
            $this->migrationId = $checkpoint['migration_id'];
            $this->currentPhase = $checkpoint['phase'];
            $this->currentBatch = $checkpoint['batch'] ?? 0;
            $this->processedAssetIds = $checkpoint['processed_ids'] ?? [];
            $this->expectedMissingFileCount = $checkpoint['expectedMissingFiles'] ?? 0;
            $this->stats = array_merge($this->stats, $checkpoint['stats']);
            $this->stats['resume_count']++;

            // Clear any stale locks before acquiring for resume
            $this->clearStaleLocks();

            // Update lock
            $this->migrationLock = new MigrationLock($this->migrationId);
            $this->controller->stdout("  Acquiring lock for resumed migration... ");
            if (!$this->migrationLock->acquire(5, true)) {
                $this->controller->stderr("FAILED\n", Console::FG_RED);
                $this->controller->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->controller->stdout("acquired\n\n", Console::FG_GREEN);
        }

        // Reinitialize managers with restored ID ONLY if they're bound to a different migration ID
        // (Controller may have already initialized them correctly before creating services)
        $needsReinit = !$this->checkpointManager ||
                       $this->checkpointManager->getMigrationId() !== $this->migrationId;

        if ($needsReinit) {
            $this->changeLogManager = new ChangeLogManager($this->migrationId, $this->config->getChangelogFlushEvery());
            $this->checkpointManager = new CheckpointManager($this->migrationId);
            $this->rollbackEngine = new RollbackEngine($this->changeLogManager, $this->migrationId);
        }

        if (!$this->options['yes']) {
            $confirm = $this->controller->prompt("Resume migration from '{$this->currentPhase}' phase? (yes/no)", [
                'required' => true,
                'default' => 'yes',
            ]);

            if ($confirm !== 'yes') {
                $this->controller->stdout("Resume cancelled.\n");
                $this->controller->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }
        } else {
            $this->controller->stdout("⚠ Auto-confirmed resume (--yes flag)\n", Console::FG_YELLOW);
        }

        try {
            // Validate and restore volumes
            $volumes = $this->validationService->validateConfiguration();
            $targetVolume = $volumes['target'];
            $sourceVolumes = $volumes['sources'];
            $quarantineVolume = $volumes['quarantine'];

            $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);
            if (!$targetRootFolder) {
                throw new \Exception("Cannot find root folder for target volume");
            }

            // Resume from specific phase
            $this->controller->stdout("Resuming from phase: {$this->currentPhase}\n", Console::FG_CYAN);
            $this->controller->stdout("Already processed: " . count($this->processedAssetIds) . " items\n\n");

            switch ($this->currentPhase) {
                case 'preparation':
                case 'discovery':
                case 'optimised_root':
                    $this->controller->stdout("Early phase - restarting from discovery...\n\n");
                    return $this->execute();

                case 'link_inline':
                    return $this->resumeInlineLinking($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'safe_duplicates':
                    $this->controller->stdout("Resuming from safe_duplicates phase with granular tracking...\n\n");
                    return $this->resumeSafeDuplicates($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'resolve_duplicates':
                    $this->controller->stdout("Resuming from resolve_duplicates phase with granular tracking...\n\n");
                    return $this->resumeResolveDuplicates($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'fix_links':
                    return $this->resumeFixLinks($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'consolidate':
                    return $this->resumeConsolidate($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'quarantine':
                    return $this->resumeQuarantine($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);

                case 'cleanup':
                case 'complete':
                    $this->controller->stdout("Migration was nearly complete. Running final verification...\n\n");
                    $this->verificationService->performCleanupAndVerification($targetVolume, $targetRootFolder);
                    $this->reporter->printFinalReport($this->stats);
                    $this->reporter->printSuccessFooter();
                    $this->controller->stdout("__CLI_EXIT_CODE_0__\n");
                    return ExitCode::OK;

                default:
                    throw new \Exception("Unknown checkpoint phase: {$this->currentPhase}");
            }

        } catch (\Exception $e) {
            return $this->handleFatalError($e);
        }
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
            'batch' => $this->currentBatch,
            'processed_ids' => $this->processedAssetIds,
            'processed_count' => count($this->processedAssetIds),
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

        $this->nestedFilesystemService->handleOptimisedImagesAtRoot(
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
        $this->reporter->printPhaseHeader("PHASE 5.5: UPDATE FILESYSTEM SUBFOLDERS");
        $this->verificationService->updateMigratedFilesystemSubfolders();
    }

    /**
     * Handle fatal error
     *
     * @param \Exception $e Exception
     * @return int Exit code
     */
    private function handleFatalError(\Exception $e): int
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

        // Display error using reporter (which uses safe output methods)
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
     * Clear stale migration locks
     */
    private function clearStaleLocks(): void
    {
        // This would call the migration lock service to clear stale locks
        // Implementation depends on the MigrationLock class
    }

    /**
     * Resume helper methods for each phase
     */

    /**
     * Resume inline linking phase
     */
    private function resumeInlineLinking(array $sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume): int
    {
        $this->setPhase('link_inline');
        $this->reporter->printPhaseHeader("PHASE 1.5: LINKING INLINE IMAGES (RESUMED)");

        $assetInventory = $this->inventoryBuilder->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
        $linkingStats = $this->inlineLinkingService->linkInlineImagesBatched(
            Craft::$app->getDb(),
            $assetInventory,
            $targetVolume,
            fn($data) => $this->saveCheckpoint($data)
        );

        if ($linkingStats['rows_updated'] > 0) {
            $assetInventory = $this->inventoryBuilder->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
        }

        return $this->continueToNextPhase($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume, $assetInventory);
    }

    /**
     * Resume safe duplicates phase
     */
    private function resumeSafeDuplicates(array $sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume): int
    {
        $this->setPhase('safe_duplicates');
        $this->reporter->printPhaseHeader("PHASE 1.7: SAFE FILE DUPLICATE DETECTION & STAGING (RESUMED)");

        $db = Craft::$app->getDb();

        // Load duplicate groups from database
        $this->controller->stdout("  Loading duplicate file records from database...\n");
        $records = $db->createCommand('
            SELECT * FROM {{%migration_file_duplicates}}
            WHERE migrationId = :migrationId
            ORDER BY id
        ', [':migrationId' => $this->migrationId])->queryAll();

        if (empty($records)) {
            $this->controller->stdout("  No duplicate file records found - analyzing...\n");
            $duplicateGroups = $this->duplicateResolutionService->analyzeFileDuplicates($sourceVolumes, $targetVolume);
        } else {
            $this->controller->stdout("  Loaded " . count($records) . " duplicate file records\n", Console::FG_GREEN);

            // Reconstruct duplicateGroups from database records
            $duplicateGroups = [];
            foreach ($records as $record) {
                $fileKey = $record['fileKey'];
                $assetIds = json_decode($record['assetIds'], true);

                // Fetch asset details for each asset ID
                if (!empty($assetIds)) {
                    $assets = $db->createCommand('
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
                        WHERE a.id IN (' . implode(',', $assetIds) . ')
                        AND e.dateDeleted IS NULL
                    ')->queryAll();

                    if (!empty($assets)) {
                        // Add fsHandle to each asset
                        foreach ($assets as &$asset) {
                            $volume = Craft::$app->getVolumes()->getVolumeById($asset['volumeId']);
                            if ($volume) {
                                $fs = $volume->getFs();
                                $asset['fsHandle'] = $fs->handle ?? 'unknown';
                            }
                        }
                        unset($asset);

                        $duplicateGroups[$fileKey] = $assets;
                    }
                }
            }

            $this->controller->stdout("  Reconstructed " . count($duplicateGroups) . " duplicate groups\n", Console::FG_GREEN);
        }

        if (!empty($duplicateGroups)) {
            $batchSize = $this->config->getBatchSize();

            // Sub-phase 1: Stage files to temp location (will skip already staged)
            $this->controller->stdout("\n");
            $this->reporter->printPhaseHeader("PHASE 1.7a: STAGING FILES TO SAFE LOCATION (RESUMED)");
            $this->duplicateResolutionService->stageFilesToTemp($duplicateGroups, $quarantineVolume, $batchSize);

            // Sub-phase 2: Determine primary assets (will skip already analyzed)
            $this->controller->stdout("\n");
            $this->reporter->printPhaseHeader("PHASE 1.7b: DETERMINING PRIMARY ASSETS (RESUMED)");
            $this->duplicateResolutionService->determineActiveAssets($duplicateGroups, $batchSize);

            // Sub-phase 3: Delete unused duplicate asset records
            $this->controller->stdout("\n");
            $this->reporter->printPhaseHeader("PHASE 1.7c: CLEANING UP DUPLICATE ASSETS (RESUMED)");
            $this->duplicateResolutionService->deleteUnusedDuplicateAssets($duplicateGroups, $batchSize);

            $this->saveCheckpoint(['safe_duplicates_complete' => true]);
            $this->changeLogManager->flush();

            $this->controller->stdout("\n  ✓ Safe duplicate handling complete\n", Console::FG_GREEN);

            // CRITICAL SAFETY CHECK: Verify files are safely backed up before proceeding
            $this->controller->stdout("\n");
            $this->reporter->printPhaseHeader("PHASE 1.7d: VERIFY FILE SAFETY (RESUMED)");
            $safetyReport = $this->duplicateResolutionService->verifyFileSafety($duplicateGroups, $quarantineVolume);

            if ($safetyReport['unsafe'] > 0) {
                $msg = "CRITICAL: {$safetyReport['unsafe']} files are not safely backed up.";
                $this->controller->stderr("\n{$msg}\n\n", Console::FG_RED);
                throw new \Exception($msg);
            }

            $this->controller->stdout("\n");
        } else {
            $this->controller->stdout("  No duplicate files detected\n", Console::FG_GREEN);
            $this->saveCheckpoint(['safe_duplicates_complete' => true]);
        }

        // Continue to next phase (resolve_duplicates)
        return $this->resumeResolveDuplicates($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);
    }

    /**
     * Resume resolve duplicates phase
     */
    private function resumeResolveDuplicates(array $sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume): int
    {
        $this->setPhase('resolve_duplicates');
        $this->reporter->printPhaseHeader("PHASE 1.8: RESOLVE DUPLICATE ASSETS (RESUMED)");

        // Try to load Phase 1 results from database first
        $phase1Results = $this->backupService->loadPhase1Results();

        if ($phase1Results && isset($phase1Results['analysis']['duplicates'])) {
            $this->controller->stdout("  ✓ Loaded duplicate analysis from database\n", Console::FG_GREEN);
            $duplicates = $phase1Results['analysis']['duplicates'];
        } else {
            $this->controller->stdout("  No cached analysis found - rebuilding inventories...\n", Console::FG_GREY);
            $assetInventory = $this->inventoryBuilder->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
            $fileInventory = $this->inventoryBuilder->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
            $analysis = $this->inventoryBuilder->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);
            $duplicates = $analysis['duplicates'] ?? [];
        }

        if (!empty($duplicates)) {
            // Filter out already processed duplicate sets
            $remainingDuplicates = [];
            $processedAssets = 0;

            foreach ($duplicates as $filename => $dupAssets) {
                // Check if all assets in this set have been processed
                $allProcessed = true;
                foreach ($dupAssets as $assetData) {
                    if (!in_array($assetData['id'], $this->processedAssetIds)) {
                        $allProcessed = false;
                        break;
                    }
                }

                if (!$allProcessed) {
                    $remainingDuplicates[$filename] = $dupAssets;
                } else {
                    $processedAssets += count($dupAssets);
                }
            }

            if (!empty($remainingDuplicates)) {
                $duplicateCount = count($remainingDuplicates);
                $skippedCount = count($duplicates) - $duplicateCount;

                if ($skippedCount > 0) {
                    $this->controller->stdout("  Resuming: {$skippedCount} duplicate sets already processed\n", Console::FG_CYAN);
                }

                $this->controller->stdout("  Found {$duplicateCount} sets of duplicate filenames to resolve\n", Console::FG_YELLOW);
                $this->controller->stdout("  Resolving duplicates by merging into best candidate...\n\n");

                $this->duplicateResolutionService->resolveDuplicateAssets($remainingDuplicates, $targetVolume);

                // Rebuild inventory after resolving duplicates
                $this->controller->stdout("\n  Rebuilding asset inventory after duplicate resolution...\n");
                $this->saveCheckpoint(['duplicates_resolved' => true]);
                $this->changeLogManager->flush();
            } else {
                $this->controller->stdout("  All duplicate sets already processed - skipping\n", Console::FG_GREEN);
                $this->saveCheckpoint(['duplicates_resolved' => true]);
            }
        } else {
            $this->controller->stdout("  No duplicate assets to resolve\n", Console::FG_GREEN);
            $this->saveCheckpoint(['duplicates_resolved' => true]);
        }

        // Continue to next phase (fix_links)
        return $this->resumeFixLinks($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);
    }

    /**
     * Resume fix links phase
     */
    private function resumeFixLinks(array $sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume): int
    {
        $this->setPhase('fix_links');
        $this->reporter->printPhaseHeader("PHASE 2: FIX BROKEN LINKS (RESUMED)");

        // Try to load Phase 1 results from database first
        $phase1Results = $this->backupService->loadPhase1Results();

        if ($phase1Results && !$this->needsInventoryRefresh()) {
            // Use cached results - much faster than rebuilding
            $this->controller->stdout("  ✓ Loaded Phase 1 results from database (skipping rebuild)\n", Console::FG_GREEN);
            $assetInventory = $phase1Results['assetInventory'];
            $fileInventory = $phase1Results['fileInventory'];
            $analysis = $phase1Results['analysis'];
        } else {
            // Need to rebuild (inline linking or duplicates modified inventory)
            if ($phase1Results && $this->needsInventoryRefresh()) {
                $this->controller->stdout("  ⚠ Inventory modified by previous phases - rebuilding\n", Console::FG_YELLOW);
            } else {
                $this->controller->stdout("  No cached Phase 1 results found - building inventories\n", Console::FG_GREY);
            }
            $assetInventory = $this->inventoryBuilder->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
            $fileInventory = $this->inventoryBuilder->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
            $analysis = $this->inventoryBuilder->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);
        }

        if (!empty($analysis['broken_links'])) {
            $this->linkRepairService->fixBrokenLinksBatched(
                $analysis['broken_links'],
                $fileInventory,
                $sourceVolumes,
                $targetVolume,
                $targetRootFolder,
                fn($data) => $this->saveCheckpoint($data)
            );

            // Export missing files to CSV after phase 2
            $this->verificationService->exportMissingFilesToCsv();
        }

        // Continue to next phase (consolidate)
        return $this->resumeConsolidate($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);
    }

    /**
     * Resume consolidate phase
     */
    private function resumeConsolidate(array $sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume): int
    {
        $this->setPhase('consolidate');
        $this->reporter->printPhaseHeader("PHASE 3: CONSOLIDATE FILES (RESUMED)");

        // Try to load Phase 1 results from database first
        $phase1Results = $this->backupService->loadPhase1Results();

        if ($phase1Results && !$this->needsInventoryRefresh()) {
            // Use cached results - much faster than rebuilding
            $this->controller->stdout("  ✓ Loaded Phase 1 results from database (skipping rebuild)\n", Console::FG_GREEN);
            $assetInventory = $phase1Results['assetInventory'];
            $fileInventory = $phase1Results['fileInventory'];
            $analysis = $phase1Results['analysis'];
        } else {
            // Need to rebuild (inline linking or duplicates modified inventory)
            if ($phase1Results && $this->needsInventoryRefresh()) {
                $this->controller->stdout("  ⚠ Inventory modified by previous phases - rebuilding\n", Console::FG_YELLOW);
            } else {
                $this->controller->stdout("  No cached Phase 1 results found - building inventories\n", Console::FG_GREY);
            }
            $assetInventory = $this->inventoryBuilder->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
            $fileInventory = $this->inventoryBuilder->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
            $analysis = $this->inventoryBuilder->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);
        }

        $this->consolidationService->consolidateUsedFilesBatched(
            $analysis['used_assets_wrong_location'],
            $targetVolume,
            $targetRootFolder,
            fn($data) => $this->saveCheckpoint($data)
        );

        // Continue to next phase (quarantine)
        return $this->resumeQuarantine($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume);
    }

    /**
     * Resume quarantine phase
     */
    private function resumeQuarantine(array $sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume): int
    {
        $this->setPhase('quarantine');
        $this->reporter->printPhaseHeader("PHASE 4: QUARANTINE (RESUMED)");

        // Try to load Phase 1 results from database first
        $phase1Results = $this->backupService->loadPhase1Results();

        if ($phase1Results && !$this->needsInventoryRefresh()) {
            // Use cached results - much faster than rebuilding
            $this->controller->stdout("  ✓ Loaded Phase 1 results from database (skipping rebuild)\n", Console::FG_GREEN);
            $assetInventory = $phase1Results['assetInventory'];
            $fileInventory = $phase1Results['fileInventory'];
            $analysis = $phase1Results['analysis'];
        } else {
            // Need to rebuild (inline linking or duplicates modified inventory)
            if ($phase1Results && $this->needsInventoryRefresh()) {
                $this->controller->stdout("  ⚠ Inventory modified by previous phases - rebuilding\n", Console::FG_YELLOW);
            } else {
                $this->controller->stdout("  No cached Phase 1 results found - building inventories\n", Console::FG_GREY);
            }
            $assetInventory = $this->inventoryBuilder->buildAssetInventoryBatched($sourceVolumes, $targetVolume);
            $fileInventory = $this->inventoryBuilder->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
            $analysis = $this->inventoryBuilder->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);
        }

        if (!empty($analysis['orphaned_files']) || !empty($analysis['unused_assets'])) {
            $quarantineFs = $quarantineVolume->getFs();
            $this->quarantineService->quarantineUnusedFilesBatched(
                $analysis['orphaned_files'],
                $analysis['unused_assets'],
                $quarantineVolume,
                $quarantineFs,
                fn($data) => $this->saveCheckpoint($data)
            );
        }

        return $this->continueToNextPhase($sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume, $assetInventory ?? []);
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
    private function needsInventoryRefresh(): bool
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

    /**
     * Continue to next phase
     */
    private function continueToNextPhase(array $sourceVolumes, $targetVolume, $targetRootFolder, $quarantineVolume, array $assetInventory): int
    {
        $fileInventory = $this->inventoryBuilder->buildFileInventory($sourceVolumes, $targetVolume, $quarantineVolume);
        $analysis = $this->inventoryBuilder->analyzeAssetFileLinks($assetInventory, $fileInventory, $targetVolume, $quarantineVolume);

        // Continue with remaining phases...
        $this->setPhase('cleanup');
        $this->reporter->printPhaseHeader("PHASE 5: CLEANUP & VERIFICATION");
        $this->verificationService->performCleanupAndVerification($targetVolume, $targetRootFolder);

        // Phase 5.5: Update filesystem subfolders for migrated volumes
        $this->controller->stdout("\n");
        $this->reporter->printPhaseHeader("PHASE 5.5: UPDATE FILESYSTEM SUBFOLDERS");
        $this->verificationService->updateMigratedFilesystemSubfolders();

        $this->setPhase('complete');
        $this->saveCheckpoint(['completed' => true]);

        $this->reporter->printFinalReport($this->stats);
        $this->reporter->printSuccessFooter();

        $this->controller->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }
}
