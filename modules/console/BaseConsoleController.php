<?php

namespace csabourin\spaghettiMigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\services\ProgressReporter;

/**
 * Base Console Controller
 *
 * Custom base controller for all Spaghetti Migrator console controllers.
 * Provides automatic ProgressReporter integration for dashboard real-time updates.
 *
 * NOTE: We cannot use typed properties for $defaultAction because:
 * 1. Older versions of Craft 4 and Yii2 don't have typed $defaultAction
 * 2. Our test stubs need to remain compatible with both old and new versions
 * 3. PHP doesn't allow child classes to add/change type declarations on inherited properties
 *
 * All plugin console controllers should extend this class instead of
 * extending craft\console\Controller directly for consistency.
 *
 * @author Christian Sabourin
 * @since 5.0.0
 */
class BaseConsoleController extends Controller
{
    /**
     * @var string The default action to run when no action is specified
     */
    public $defaultAction = 'index';

    /**
     * @var string|null Migration ID for progress tracking (passed by queue)
     */
    public $migrationId;

    /**
     * @var ProgressReporter|null Progress reporter for real-time dashboard updates
     */
    protected $progress;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'migrationId';
        return $options;
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // Initialize ProgressReporter if migrationId is provided (queue execution)
        if ($this->migrationId) {
            $this->progress = new ProgressReporter($this->migrationId);
        }
    }

    /**
     * @inheritdoc
     */
    public function afterAction($action, $result)
    {
        // Auto-flush progress after action completes
        if ($this->progress) {
            // If action returned ExitCode::OK (0), mark as completed
            // Otherwise mark as failed
            if ($result === 0 || $result === \yii\console\ExitCode::OK) {
                $this->progress->complete("Command completed successfully");
            } else {
                $this->progress->fail("Command failed with exit code: {$result}");
            }
        }

        return parent::afterAction($action, $result);
    }

    /**
     * Output helper that writes to both CLI and progress reporter
     *
     * Use this instead of $this->stdout() to ensure output appears
     * in both direct CLI execution and dashboard when run via queue.
     *
     * @param string $message The message to output
     * @param int|null $color Console color constant (e.g., Console::FG_GREEN)
     */
    protected function output(string $message, ?int $color = null): void
    {
        // Always output to CLI (for direct execution)
        if ($color !== null) {
            $this->stdout($message, $color);
        } else {
            $this->stdout($message);
        }

        // Also log to progress reporter if available (queue execution)
        if ($this->progress) {
            // Strip ANSI color codes for database storage
            $cleanMessage = preg_replace('/\033\[[0-9;]*m/', '', $message);
            $this->progress->log($cleanMessage, false);
        }
    }
}
