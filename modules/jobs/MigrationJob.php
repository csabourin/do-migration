<?php

namespace csabourin\craftS3SpacesMigration\jobs;

use Craft;
use craft\queue\BaseJob;
use csabourin\craftS3SpacesMigration\services\MigrationStateService;
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
            // Build command arguments
            $args = [
                '--interactive' => '0',
                '--yes' => '1',
            ];

            if ($this->dryRun) {
                $args['--dryRun'] = '1';
            }

            if ($this->skipBackup) {
                $args['--skipBackup'] = '1';
            }

            if ($this->skipInlineDetection) {
                $args['--skipInlineDetection'] = '1';
            }

            if ($this->resume) {
                $args['--resume'] = '1';
            }

            if ($this->checkpointId) {
                $args['--checkpointId'] = $this->checkpointId;
            }

            // Build the command string
            $craftPath = Craft::getAlias('@root/craft');
            $command = $craftPath . ' s3-spaces-migration/image-migration/migrate';

            foreach ($args as $key => $value) {
                $command .= " {$key}";
                if ($value !== '1' && $value !== true) {
                    $command .= '=' . escapeshellarg($value);
                }
            }

            Craft::info("Queue job executing command: {$command}", __METHOD__);

            // Update progress to show we're starting
            $this->currentProgress = 0.01;
            $this->setProgress($queue, 0.01, 'Starting migration...');

            // Execute the command with progress tracking
            $this->executeWithProgress($queue, $command);

            // Mark as completed
            $this->saveState('completed', [
                'phase' => 'completed',
                'processedIds' => [],
            ]);

            $this->currentProgress = 1.0;
            $this->setProgress($queue, 1, 'Migration completed successfully');

            Craft::info("Migration job completed successfully: {$this->migrationId}", __METHOD__);

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
     * Execute command with real-time progress tracking
     */
    private function executeWithProgress($queue, string $command): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new Exception('Failed to start migration process');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $lastProgress = 0;
        $lastStateCheck = 0;
        $phaseMap = [
            'preparation' => 0.05,
            'discovery' => 0.15,
            'fix_links' => 0.35,
            'consolidate' => 0.60,
            'quarantine' => 0.80,
            'cleanup' => 0.95,
            'completed' => 1.0,
        ];

        try {
            while (true) {
                $status = proc_get_status($process);

                // Read output
                if (!feof($pipes[1])) {
                    $chunk = fread($pipes[1], 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $output .= $chunk;

                        // Parse progress from output
                        $this->parseProgressFromOutput($queue, $chunk);
                    }
                }

                // Read errors
                if (!feof($pipes[2])) {
                    $chunk = fread($pipes[2], 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $output .= $chunk;
                        Craft::warning("Migration stderr: {$chunk}", __METHOD__);
                    }
                }

                // Check migration state every 5 seconds
                $now = time();
                if ($now - $lastStateCheck >= 5) {
                    $state = $this->stateService->getMigrationState($this->migrationId);

                    if ($state) {
                        $phase = $state['phase'] ?? 'unknown';
                        $processedCount = $state['processedCount'] ?? 0;
                        $totalCount = $state['totalCount'] ?? 0;

                        // Calculate progress based on phase
                        $phaseProgress = $phaseMap[$phase] ?? $lastProgress;

                        // If we have counts, use them to refine progress
                        if ($totalCount > 0) {
                            $countProgress = $processedCount / $totalCount;
                            // Blend phase progress with count progress
                            $progress = $phaseProgress + ($countProgress * 0.15); // Each phase gets ~15% of total
                            $progress = min($progress, 0.99); // Cap at 99% until complete
                        } else {
                            $progress = $phaseProgress;
                        }

                        if ($progress > $lastProgress) {
                            $label = ucfirst(str_replace('_', ' ', $phase));
                            if ($totalCount > 0) {
                                $label .= " ({$processedCount}/{$totalCount})";
                            }

                            $this->currentProgress = $progress;
                            $this->setProgress($queue, $progress, $label);
                            $lastProgress = $progress;
                        }
                    }

                    $lastStateCheck = $now;
                }

                if (!$status['running']) {
                    break;
                }

                usleep(100000); // 100ms
            }

            // Get final output (safely handle closed streams)
            if (is_resource($pipes[1])) {
                $finalOutput = stream_get_contents($pipes[1]);
                if ($finalOutput !== false) {
                    $output .= $finalOutput;
                }
            }
            if (is_resource($pipes[2])) {
                $finalError = stream_get_contents($pipes[2]);
                if ($finalError !== false) {
                    $output .= $finalError;
                }
            }
        } finally {
            // Ensure resources are always cleaned up
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new Exception("Migration process failed with exit code {$exitCode}. Output: " . substr($output, -500));
        }
    }

    /**
     * Parse progress information from command output
     */
    private function parseProgressFromOutput($queue, string $output): void
    {
        // Look for common progress patterns in output
        $patterns = [
            '/PHASE \d+: (.+)$/m' => function($matches, $queue) {
                // Just update label, keep current progress
                $this->setProgress($queue, $this->currentProgress, $matches[1]);
            },
            '/Processing batch (\d+)\/(\d+)/i' => function($matches, $queue) {
                $current = (int)$matches[1];
                $total = (int)$matches[2];
                if ($total > 0) {
                    $progress = $current / $total;
                    $this->currentProgress = $progress;
                    $this->setProgress($queue, $progress, "Processing batch {$current}/{$total}");
                }
            },
            '/(\d+)\/(\d+) assets/i' => function($matches, $queue) {
                $current = (int)$matches[1];
                $total = (int)$matches[2];
                if ($total > 0) {
                    $progress = $current / $total;
                    $this->currentProgress = $progress;
                    $this->setProgress($queue, $progress, "{$current}/{$total} assets");
                }
            },
        ];

        foreach ($patterns as $pattern => $callback) {
            if (preg_match($pattern, $output, $matches)) {
                $callback($matches, $queue);
            }
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
