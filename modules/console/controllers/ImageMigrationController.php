<?php
namespace csabourin\craftS3SpacesMigration\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\craftS3SpacesMigration\helpers\MigrationConfig;
use csabourin\craftS3SpacesMigration\services\CheckpointManager;
use csabourin\craftS3SpacesMigration\services\ChangeLogManager;
use csabourin\craftS3SpacesMigration\services\ErrorRecoveryManager;
use csabourin\craftS3SpacesMigration\services\RollbackEngine;
use csabourin\craftS3SpacesMigration\services\MigrationLock;
use csabourin\craftS3SpacesMigration\services\MigrationStateService;
use csabourin\craftS3SpacesMigration\services\MigrationOrchestrator;
use csabourin\craftS3SpacesMigration\services\migration\BackupService;
use csabourin\craftS3SpacesMigration\services\migration\ConsolidationService;
use csabourin\craftS3SpacesMigration\services\migration\DuplicateResolutionService;
use csabourin\craftS3SpacesMigration\services\migration\FileOperationsService;
use csabourin\craftS3SpacesMigration\services\migration\InlineLinkingService;
use csabourin\craftS3SpacesMigration\services\migration\InventoryBuilder;
use csabourin\craftS3SpacesMigration\services\migration\LinkRepairService;
use csabourin\craftS3SpacesMigration\services\migration\MigrationReporter;
use csabourin\craftS3SpacesMigration\services\migration\OptimizedImagesService;
use csabourin\craftS3SpacesMigration\services\migration\QuarantineService;
use csabourin\craftS3SpacesMigration\services\migration\ValidationService;
use csabourin\craftS3SpacesMigration\services\migration\VerificationService;
use yii\console\ExitCode;

/**
 * Image Migration Controller (Refactored)
 *
 * ARCHITECTURAL CHANGE:
 * This controller now delegates the main migration process to MigrationOrchestrator,
 * which coordinates all migration services through a clean service-oriented architecture.
 *
 * The controller's role is now limited to:
 * - CLI interface and option handling
 * - Utility actions (monitor, rollback, status, cleanup)
 * - Instantiating and configuring the orchestrator
 *
 * Main migration logic is handled by: MigrationOrchestrator + 11 specialized services
 *
 * @author Christian Sabourin
 * @version 5.0.0 (Refactored to use MigrationOrchestrator)
 */
class ImageMigrationController extends Controller
{
    public $defaultAction = 'migrate';

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    // CLI Options
    public $dryRun = false;
    public $skipBackup = false;
    public $skipInlineDetection = false;
    public $resume = false;
    public $checkpointId = null;
    public $skipLock = false;
    public $yes = false;
    public $olderThanHours = null;

    // Service instances (for utility actions)
    private $checkpointManager;
    private $changeLogManager;
    private $errorRecoveryManager;
    private $rollbackEngine;
    private $migrationLock;
    private $migrationId;

    /**
     * @inheritdoc
     */
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
     * Initialize the controller
     */
    public function init(): void
    {
        parent::init();

        // Load configuration
        $this->config = MigrationConfig::getInstance();

        // Generate unique migration ID
        $this->migrationId = date('Y-m-d-His') . '-' . substr(md5(microtime()), 0, 8);

        // Initialize managers for utility actions
        $this->checkpointManager = new CheckpointManager($this->migrationId);
        $this->changeLogManager = new ChangeLogManager($this->migrationId, $this->config->getChangelogFlushEvery());
        $this->errorRecoveryManager = new ErrorRecoveryManager($this->config->getMaxRetries(), $this->config->getRetryDelayMs());
        $this->rollbackEngine = new RollbackEngine($this->changeLogManager, $this->migrationId);
        $this->migrationLock = new MigrationLock($this->migrationId);

        // Register shutdown handler for cleanup
        register_shutdown_function([$this, 'emergencyCleanup']);
    }

    /**
     * Main migration action - Delegates to MigrationOrchestrator
     *
     * REFACTORED: This method now instantiates MigrationOrchestrator and delegates
     * all migration logic to it. The orchestrator coordinates 11 specialized services
     * to execute the multi-phase migration process.
     */
    public function actionMigrate()
    {
        try {
            // Build options array for orchestrator
            $options = [
                'dryRun' => $this->dryRun,
                'yes' => $this->yes,
                'skipBackup' => $this->skipBackup,
                'skipInlineDetection' => $this->skipInlineDetection,
                'resume' => $this->resume,
                'checkpointId' => $this->checkpointId,
                'skipLock' => $this->skipLock,
            ];

            // CRITICAL FIX: Restore migration ID before creating services during resume
            // This ensures all services use the correct migration ID for logging and checkpoints
            if ($this->resume || $this->checkpointId) {
                $restoredId = $this->restoreMigrationIdForResume();
                if ($restoredId) {
                    $this->migrationId = $restoredId;

                    // Reinitialize managers with restored migration ID
                    $this->checkpointManager = new CheckpointManager($this->migrationId);
                    $this->changeLogManager = new ChangeLogManager($this->migrationId, $this->config->getChangelogFlushEvery());
                    $this->rollbackEngine = new RollbackEngine($this->changeLogManager, $this->migrationId);
                }
            }

            // Instantiate all services
            $reporter = new MigrationReporter($this);
            $validationService = new ValidationService($this, $this->config, $reporter);
            $fileOpsService = new FileOperationsService($this->config);
            $inventoryBuilder = new InventoryBuilder($this, $this->config, $validationService, $fileOpsService, $reporter);

            $inlineLinkingService = new InlineLinkingService(
                $this,
                $this->config,
                $this->changeLogManager,
                $this->errorRecoveryManager,
                $inventoryBuilder,
                $reporter,
                $this->migrationLock
            );

            $duplicateResolutionService = new DuplicateResolutionService(
                $this,
                $this->changeLogManager,
                $this->migrationId
            );

            $linkRepairService = new LinkRepairService(
                $this,
                $this->config,
                $this->changeLogManager,
                $this->checkpointManager,
                $this->errorRecoveryManager,
                $fileOpsService,
                $inventoryBuilder,
                $this->migrationLock
            );

            $consolidationService = new ConsolidationService(
                $this,
                $this->config,
                $this->changeLogManager,
                $this->errorRecoveryManager,
                $this->checkpointManager,
                $fileOpsService,
                $reporter,
                $this->migrationLock
            );

            $quarantineService = new QuarantineService(
                $this,
                $this->config,
                $this->changeLogManager,
                $this->errorRecoveryManager,
                $fileOpsService,
                $reporter
            );

            $verificationService = new VerificationService(
                $this,
                $this->config,
                $this->changeLogManager,
                $reporter,
                $this->migrationId,
                null // verificationSampleSize - null = full verification
            );

            $backupService = new BackupService(
                $this,
                $this->checkpointManager,
                $reporter,
                $this->migrationId,
                $this->config->getSourceVolumeHandles(),
                $this->config->getTargetVolumeHandle(),
                $this->config->getQuarantineVolumeHandle()
            );

            $optimizedImagesService = new OptimizedImagesService(
                $this,
                $this->changeLogManager,
                $inventoryBuilder,
                $reporter,
                $this->dryRun,
                $this->yes
            );

            // Instantiate the orchestrator with all dependencies
            $orchestrator = new MigrationOrchestrator(
                $this,
                $this->config,
                $inventoryBuilder,
                $inlineLinkingService,
                $duplicateResolutionService,
                $linkRepairService,
                $consolidationService,
                $quarantineService,
                $verificationService,
                $backupService,
                $optimizedImagesService,
                $validationService,
                $reporter,
                $this->checkpointManager,
                $this->changeLogManager,
                $this->errorRecoveryManager,
                $this->rollbackEngine,
                $this->migrationLock,
                $this->migrationId,
                $options
            );

            // Execute the migration through the orchestrator
            return $orchestrator->execute();

        } catch (\Exception $e) {
            $this->stderr("\nFATAL ERROR: " . $e->getMessage() . "\n", Console::FG_RED);
            $this->stderr($e->getTraceAsString() . "\n");
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Monitor migration progress
     *
     * Shows real-time progress of an active migration by reading checkpoint data
     * and migration state from the database.
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
                // posix_kill with signal 0 checks if process exists without sending a signal
                $isProcessRunning = posix_kill($pid, 0);
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

        // Show resume instruction if migration is not complete
        if ($status !== 'completed' && !$isProcessRunning) {
            $this->stdout("⚠ Migration appears to be stopped. To resume:\n", Console::FG_YELLOW);
            $this->stdout("  ./craft s3-spaces-migration/image-migration/migrate --resume\n\n");
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Rollback a migration
     *
     * Reverses changes made by the migration by applying the change log in reverse order.
     */
    public function actionRollback($migrationId = null, $phases = null, $mode = 'from', $dryRun = false, $method = null)
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_YELLOW);
        $this->stdout("ROLLBACK ENGINE\n", Console::FG_YELLOW);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_YELLOW);

        if ($this->dryRun || $dryRun) {
            $this->stdout("⚠ DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        // Determine migration ID
        if (!$migrationId) {
            $migrations = $this->checkpointManager->listMigrations();
            if (empty($migrations)) {
                $this->stdout("No migrations found to rollback.\n\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }

            $this->stdout("Available migrations:\n", Console::FG_CYAN);
            foreach ($migrations as $idx => $migration) {
                $this->stdout(sprintf(
                    "  [%d] %s - %s (%s)\n",
                    $idx + 1,
                    $migration['id'],
                    $migration['phase'],
                    $migration['timestamp']
                ), Console::FG_GREY);
            }

            if (!$this->yes) {
                $migrationId = $this->prompt("\nEnter migration ID to rollback:", [
                    'required' => true,
                    'default' => $migrations[0]['id'] ?? null,
                ]);
            } else {
                $migrationId = $migrations[0]['id'] ?? null;
            }
        }

        if (!$migrationId) {
            $this->stdout("No migration ID provided.\n\n");
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Initialize rollback engine for the specific migration
        $changeLogManager = new ChangeLogManager($migrationId, $this->config->getChangelogFlushEvery());
        $rollbackEngine = new RollbackEngine($changeLogManager, $migrationId);

        try {
            $result = $rollbackEngine->rollback(
                $phases,
                $mode,
                $this->dryRun || $dryRun,
                $method,
                $this
            );

            if ($result['success']) {
                $this->stdout("\n✓ Rollback completed successfully\n", Console::FG_GREEN);
                $this->stdout("  Operations reversed: {$result['operations_reversed']}\n");
                $this->stdout("  Phases rolled back: " . implode(', ', $result['phases_rolled_back']) . "\n\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            } else {
                $this->stderr("\n✗ Rollback failed\n", Console::FG_RED);
                $this->stderr("  Error: {$result['error']}\n\n");
                $this->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } catch (\Exception $e) {
            $this->stderr("\nFATAL ERROR during rollback: " . $e->getMessage() . "\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Show migration status and available checkpoints
     */
    public function actionStatus()
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("MIGRATION STATUS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // List all migrations
        $migrations = $this->checkpointManager->listMigrations();

        if (empty($migrations)) {
            $this->stdout("No migrations found.\n\n");
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        foreach ($migrations as $migration) {
            $this->stdout("Migration: {$migration['id']}\n", Console::FG_YELLOW);
            $this->stdout("  Phase: {$migration['phase']}\n");
            $this->stdout("  Timestamp: {$migration['timestamp']}\n");
            $this->stdout("  Processed: {$migration['processed']} items\n");

            if (!empty($migration['checkpoints'])) {
                $this->stdout("  Checkpoints: " . count($migration['checkpoints']) . "\n");
            }

            $this->stdout("\n");
        }

        // Show latest migration details
        $latestMigration = $migrations[0] ?? null;
        if ($latestMigration && !empty($latestMigration['checkpoints'])) {
            $this->stdout("Latest migration checkpoints:\n", Console::FG_CYAN);
            foreach ($latestMigration['checkpoints'] as $idx => $checkpoint) {
                $this->stdout(sprintf(
                    "  [%d] %s - %s (%s items)\n",
                    $idx + 1,
                    $checkpoint['id'],
                    $checkpoint['phase'],
                    $checkpoint['processed']
                ), Console::FG_GREY);
            }
            $this->stdout("\n");
        }

        $this->stdout("To resume a migration:\n");
        $this->stdout("  ./craft s3-spaces-migration/image-migration/migrate --resume\n\n");
        $this->stdout("To resume from a specific checkpoint:\n");
        $this->stdout("  ./craft s3-spaces-migration/image-migration/migrate --checkpointId=<ID>\n\n");
        $this->stdout("__CLI_EXIT_CODE_0__\n");

        return ExitCode::OK;
    }

    /**
     * Cleanup old migration data (checkpoints, change logs)
     */
    public function actionCleanup()
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_YELLOW);
        $this->stdout("CLEANUP OLD MIGRATION DATA\n", Console::FG_YELLOW);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_YELLOW);

        $olderThanHours = $this->olderThanHours ?? $this->config->getCheckpointRetentionHours();

        $this->stdout("Cleaning up data older than {$olderThanHours} hours...\n\n");

        if (!$this->yes) {
            $confirm = $this->confirm("This will delete old checkpoints and change logs. Continue?", true);
            if (!$confirm) {
                $this->stdout("Cleanup cancelled.\n\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }
        }

        try {
            $result = $this->checkpointManager->cleanupOldCheckpoints($olderThanHours);

            $this->stdout("✓ Cleanup complete\n", Console::FG_GREEN);
            $this->stdout("  Checkpoints cleaned: {$result['checkpoints_cleaned']}\n");
            $this->stdout("  Change logs cleaned: {$result['changelogs_cleaned']}\n");
            $this->stdout("  Space freed: {$result['space_freed']}\n\n");
            $this->stdout("__CLI_EXIT_CODE_0__\n");

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error during cleanup: " . $e->getMessage() . "\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Force cleanup of migration locks (use if migration is stuck)
     */
    public function actionForceCleanup()
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_RED);
        $this->stdout("FORCE CLEANUP MIGRATION LOCKS\n", Console::FG_RED);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_RED);

        $this->stdout("⚠ WARNING: This will forcibly remove all migration locks.\n", Console::FG_YELLOW);
        $this->stdout("⚠ Only use this if a migration is stuck and you're sure it's not running.\n\n", Console::FG_YELLOW);

        if (!$this->yes) {
            $confirm = $this->confirm("Are you absolutely sure?", false);
            if (!$confirm) {
                $this->stdout("Force cleanup cancelled.\n\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }
        }

        try {
            // Clear all locks
            $lockDir = Craft::getAlias('@storage/runtime/migration-locks');
            if (is_dir($lockDir)) {
                $files = glob($lockDir . '/*');
                $count = 0;
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                        $count++;
                    }
                }

                $this->stdout("✓ Removed {$count} lock files\n", Console::FG_GREEN);
            } else {
                $this->stdout("No lock directory found\n", Console::FG_GREY);
            }

            // Clear database state
            $stateService = new MigrationStateService();
            $stateService->clearAllMigrationStates();

            $this->stdout("✓ Cleared migration states from database\n", Console::FG_GREEN);
            $this->stdout("\nMigration locks cleared. You can now start a new migration.\n\n");
            $this->stdout("__CLI_EXIT_CODE_0__\n");

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("Error during force cleanup: " . $e->getMessage() . "\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Emergency cleanup handler (called on shutdown)
     */
    public function emergencyCleanup()
    {
        // This is called automatically on shutdown
        // Currently a no-op - lock release is handled by MigrationLock's destructor
    }

    /**
     * Restore migration ID from checkpoint when resuming
     *
     * This is called before services are created to ensure they all use
     * the correct migration ID for resumed migrations.
     *
     * @return string|null Restored migration ID, or null if not found
     */
    private function restoreMigrationIdForResume(): ?string
    {
        // Try quick state first (faster)
        if (!$this->checkpointId) {
            $quickState = $this->checkpointManager->loadQuickState();
            if ($quickState && isset($quickState['migration_id'])) {
                return $quickState['migration_id'];
            }
        }

        // Fall back to full checkpoint loading
        $checkpoint = $this->checkpointManager->loadLatestCheckpoint($this->checkpointId);
        if ($checkpoint && isset($checkpoint['migration_id'])) {
            return $checkpoint['migration_id'];
        }

        return null;
    }
}
