<?php

namespace csabourin\spaghettiMigrator\jobs;

use Craft;
use craft\queue\BaseJob;
use csabourin\spaghettiMigrator\services\MigrationStateService;
use yii\base\Exception;

/**
 * Migration Queue Job
 *
 * Runs the image migration process in Craft's queue system, ensuring:
 * - Background execution (survives page refresh)
 * - Progress tracking via queue API
 * - Integration with checkpoint/resume system
 * - Proper error handling and recovery
 *
 * This job wraps the console ImageMigrationController and executes it
 * via the queue system, providing robust execution for long-running migrations.
 */
class MigrationJob extends BaseJob
{
    /**
     * @var string Migration ID for tracking
     */
    public $migrationId;

    /**
     * @var bool Whether this is a dry run
     */
    public $dryRun = false;

    /**
     * @var bool Whether to skip backup
     */
    public $skipBackup = false;

    /**
     * @var bool Whether to skip inline detection
     */
    public $skipInlineDetection = false;

    /**
     * @var bool Whether to resume from checkpoint
     */
    public $resume = false;

    /**
     * @var string|null Specific checkpoint ID to resume from
     */
    public $checkpointId = null;

    /**
     * @var bool Whether to skip confirmation prompts
     */
    public $yes = true; // Always true for queue jobs

    /**
     * @var string Command being executed (for display)
     */
    public $command = 'image-migration/migrate';

    /**
     * @var MigrationStateService
     */
    private $stateService;

    /**
     * @var float Current progress (0.0 to 1.0)
     */
    private $currentProgress = 0.0;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $this->stateService = new MigrationStateService();
        $this->stateService->ensureTableExists();

        // Generate migration ID if not provided
        if (!$this->migrationId) {
            $this->migrationId = 'migration-' . time() . '-' . uniqid();
        }

        // Save initial migration state
        $this->saveState('running', [
            'phase' => 'initializing',
            'processedCount' => 0,
            'totalCount' => 0,
            'processedIds' => [],
        ]);

        try {
            // Update progress to show we're starting
            $this->currentProgress = 0.01;
            $this->setProgress($queue, 0.01, 'Starting migration...');

            Craft::info("Queue job starting migration: {$this->migrationId}", __METHOD__);

            // Instead of spawning a new CLI process, directly instantiate and execute the controller
            // This is the proper pattern for queue jobs in Craft CMS
            $controller = new \csabourin\spaghettiMigrator\console\controllers\ImageMigrationController(
                'image-migration',
                Craft::$app
            );

            // Set controller options from job properties
            $controller->dryRun = $this->dryRun;
            $controller->skipBackup = $this->skipBackup;
            $controller->skipInlineDetection = $this->skipInlineDetection;
            $controller->resume = $this->resume;
            $controller->checkpointId = $this->checkpointId;
            $controller->yes = $this->yes;

            // Initialize the controller
            $controller->init();

            // Execute the migration with progress tracking
            $exitCode = $this->executeControllerWithProgress($queue, $controller);

            if ($exitCode === 0) {
                // Mark as completed
                $this->saveState('completed', [
                    'phase' => 'completed',
                    'processedIds' => [],
                ]);

                $this->currentProgress = 1.0;
                $this->setProgress($queue, 1, 'Migration completed successfully');

                Craft::info("Migration job completed successfully: {$this->migrationId}", __METHOD__);
            } else {
                throw new Exception("Migration controller returned non-zero exit code: {$exitCode}");
            }

        } catch (\Throwable $e) {
            // Save error state
            $this->saveState('failed', [
                'phase' => 'error',
                'error' => $e->getMessage(),
                'processedIds' => [],
            ]);

            Craft::error("Migration job failed: {$e->getMessage()}", __METHOD__);
            Craft::error($e->getTraceAsString(), __METHOD__);

            throw $e;
        }
    }

    /**
     * Execute controller directly
     *
     * The migration controller already updates MigrationStateService with progress,
     * which can be monitored via the monitor command or dashboard. We simply execute
     * the controller action and let it run to completion.
     */
    private function executeControllerWithProgress($queue, $controller): int
    {
        try {
            // Execute the migration controller action
            // Progress tracking is handled by MigrationStateService within the controller
            $exitCode = $controller->actionMigrate();

            // Update queue progress to 99% when controller finishes
            $this->setProgress($queue, 0.99, 'Finalizing...');

            return $exitCode;
        } catch (\Throwable $e) {
            Craft::error("Migration controller error: " . $e->getMessage(), __METHOD__);
            Craft::error($e->getTraceAsString(), __METHOD__);
            throw new Exception("Migration failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Save migration state
     */
    private function saveState(string $status, array $data = []): void
    {
        $this->stateService->saveMigrationState(array_merge([
            'migrationId' => $this->migrationId,
            'status' => $status,
            'command' => $this->command,
            'pid' => getmypid(),
        ], $data));
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        $desc = 'Migrating assets from AWS to DigitalOcean Spaces';

        if ($this->dryRun) {
            $desc .= ' (dry run)';
        }

        if ($this->resume) {
            $desc .= ' (resuming)';
        }

        return $desc;
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        // Allow up to 48 hours for very large migrations
        return 48 * 60 * 60;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        // Don't retry - use the checkpoint/resume system instead
        return false;
    }
}
