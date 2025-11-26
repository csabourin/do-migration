<?php

namespace csabourin\spaghettiMigrator\jobs;

use Craft;
use craft\queue\BaseJob;
use csabourin\spaghettiMigrator\services\CommandExecutionService;
use csabourin\spaghettiMigrator\services\MigrationStateService;
use yii\base\Exception;

/**
 * Console Command Queue Job
 *
 * Generic queue job for running any migration console command in the background.
 * Provides progress tracking and error handling for long-running operations.
 *
 * This allows any migration command to be executed via the queue system,
 * ensuring they survive page refreshes and can be monitored.
 */
class ConsoleCommandJob extends BaseJob
{
    /**
     * @var string Full command path (e.g., 'image-migration/migrate')
     */
    public $command;

    /**
     * @var array Command arguments
     */
    public $args = [];

    /**
     * @var string|null Migration ID for state tracking
     */
    public $migrationId;

    /**
     * @var bool Whether to track progress in migration_state table
     */
    public $trackState = true;

    /**
     * @var int|null Override default TTR (time to reserve) in seconds
     */
    public $ttr = null;

    /**
     * @var float Current progress (0.0 to 1.0)
     */
    private $currentProgress = 0.0;

    /**
     * @var MigrationStateService
     */
    private $stateService;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        if (!$this->command) {
            throw new Exception('Command is required');
        }

        // Initialize state service if tracking is enabled
        if ($this->trackState) {
            $this->stateService = new MigrationStateService();
            $this->stateService->ensureTableExists();

            // Generate migration ID if not provided
            if (!$this->migrationId) {
                $this->migrationId = 'job-' . time() . '-' . uniqid();
            }

            // Save initial state
            $this->saveState('running', [
                'phase' => 'starting',
                'processedCount' => 0,
                'totalCount' => 0,
            ]);
        }

        try {
            $fullCommandPath = "spaghetti-migrator/{$this->command}";
            $supportsYes = CommandExecutionService::commandSupportsYes($fullCommandPath);

            if (!$supportsYes) {
                unset($this->args['yes']);
            }

            // Build the command
            $craftPath = Craft::getAlias('@root/craft');
            $fullCommand = "{$craftPath} spaghetti-migrator/{$this->command}";

            // Add migrationId argument for progress tracking
            if ($this->migrationId) {
                $fullCommand .= " --migrationId=" . escapeshellarg($this->migrationId);
            }

            // Add arguments
            foreach ($this->args as $key => $value) {
                if ($value === false || $value === '' || $value === '0' || $value === 0) {
                    continue;
                }

                if ($value === true || $value === '1' || $value === 1) {
                    $fullCommand .= " --{$key}";
                } else {
                    $fullCommand .= " --{$key}=" . escapeshellarg($value);
                }
            }

            // Add non-interactive flag
            $fullCommand .= ' --interactive=0';

            Craft::info("Queue job executing: {$fullCommand}", __METHOD__);

            // Update progress
            $this->currentProgress = 0.01;
            $this->setProgress($queue, 0.01, 'Starting command...');

            // Execute with progress tracking
            $this->executeWithProgress($queue, $fullCommand);

            // Mark as completed
            if ($this->trackState) {
                $this->saveState('completed', [
                    'phase' => 'completed',
                ]);
            }

            $this->currentProgress = 1.0;
            $this->setProgress($queue, 1, 'Command completed successfully');

            Craft::info("Command completed successfully: {$this->command}", __METHOD__);

        } catch (\Throwable $e) {
            // Save error state
            if ($this->trackState) {
                $this->saveState('failed', [
                    'phase' => 'error',
                    'error' => $e->getMessage(),
                ]);
            }

            Craft::error("Command failed: {$e->getMessage()}", __METHOD__);
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

        $process = proc_open($command . ' 2>&1', $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new Exception('Failed to start process');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $lastProgressUpdate = microtime(true);
        $lastOutputUpdate = microtime(true);
        $progressValue = 0.01;

        while (true) {
            $status = proc_get_status($process);

            // Read stdout
            if (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $output .= $chunk;

                    // Parse and update progress
                    $this->parseProgress($queue, $chunk, $progressValue);

                    // Log significant output
                    if (preg_match('/(error|warning|success|complete|failed)/i', $chunk)) {
                        Craft::info("Command output: " . trim($chunk), __METHOD__);
                    }

                    // Note: Output saving disabled - ProgressReporter handles this now
                    // Commands use BaseConsoleController->output() which writes to ProgressReporter
                    // Saving here would overwrite ProgressReporter's output
                    // $now = microtime(true);
                    // if ($now - $lastOutputUpdate >= 2.0) {
                    //     $this->saveOutputToState($output);
                    //     $lastOutputUpdate = $now;
                    // }
                }
            }

            // Read stderr
            if (!feof($pipes[2])) {
                $chunk = fread($pipes[2], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $output .= $chunk;
                    Craft::warning("Command stderr: {$chunk}", __METHOD__);

                    // Note: Output saving disabled - ProgressReporter handles this now
                    // $now = microtime(true);
                    // if ($now - $lastOutputUpdate >= 2.0) {
                    //     $this->saveOutputToState($output);
                    //     $lastOutputUpdate = $now;
                    // }
                }
            }

            // Update progress periodically even without specific markers
            $now = microtime(true);
            if ($now - $lastProgressUpdate >= 10) {
                // Gradually increment progress to show activity
                $progressValue = min($progressValue + 0.01, 0.98);
                $this->currentProgress = $progressValue;
                $this->setProgress($queue, $progressValue, 'Processing...');
                $lastProgressUpdate = $now;
            }

            // Check state if tracking is enabled
            if ($this->trackState && $this->migrationId) {
                $state = $this->stateService->getMigrationState($this->migrationId);
                if ($state) {
                    $processedCount = $state['processedCount'] ?? 0;
                    $totalCount = $state['totalCount'] ?? 0;

                    if ($totalCount > 0) {
                        $stateProgress = $processedCount / $totalCount;
                        $progressValue = max($progressValue, min($stateProgress, 0.99));
                        $this->currentProgress = $progressValue;
                        $this->setProgress($queue, $progressValue, "{$processedCount}/{$totalCount} processed");
                    }
                }
            }

            if (!$status['running']) {
                break;
            }

            usleep(100000); // 100ms
        }

        // Get remaining output
        $output .= stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        // Note: Final output save disabled - ProgressReporter handles this now
        // This was overwriting ProgressReporter's formatted output with raw stdout
        // $this->saveOutputToState($output);

        $exitCode = proc_close($process);

        // Check for CLI exit code marker in output (more reliable than proc_close)
        // Craft console controllers output __CLI_EXIT_CODE_N__ markers
        if (preg_match('/__CLI_EXIT_CODE_(\d+)__/', $output, $matches)) {
            $actualExitCode = (int)$matches[1];
            Craft::info("Detected CLI exit code marker: {$actualExitCode} (proc_close returned {$exitCode})", __METHOD__);
            $exitCode = $actualExitCode;
        }

        if ($exitCode !== 0) {
            // Try to find error message in output
            $errorLines = array_filter(
                explode("\n", $output),
                fn($line) => stripos($line, 'error') !== false || stripos($line, 'exception') !== false
            );

            $errorMessage = !empty($errorLines)
                ? implode("\n", array_slice($errorLines, -5))
                : substr($output, -500);

            // Save failed state with output before throwing
            $this->saveState('failed', [
                'phase' => 'error',
                'errorMessage' => $errorMessage,
            ]);

            throw new Exception("Command failed with exit code {$exitCode}. Error: {$errorMessage}");
        }
    }

    /**
     * Parse progress from command output
     */
    private function parseProgress($queue, string $output, &$currentProgress): void
    {
        // Common progress patterns
        $patterns = [
            // "Processing 50/100"
            '/Processing\s+(\d+)\s*\/\s*(\d+)/i' => function($matches) use ($queue, &$currentProgress) {
                $progress = (int)$matches[1] / (int)$matches[2];
                $currentProgress = max($currentProgress, min($progress, 0.99));
                $this->currentProgress = $currentProgress;
                $this->setProgress($queue, $currentProgress, "Processing {$matches[1]}/{$matches[2]}");
            },

            // "Batch 5/10"
            '/Batch\s+(\d+)\s*\/\s*(\d+)/i' => function($matches) use ($queue, &$currentProgress) {
                $progress = (int)$matches[1] / (int)$matches[2];
                $currentProgress = max($currentProgress, min($progress, 0.99));
                $this->currentProgress = $currentProgress;
                $this->setProgress($queue, $currentProgress, "Batch {$matches[1]}/{$matches[2]}");
            },

            // "Phase X: Title"
            '/PHASE\s+\d+:\s*(.+)$/im' => function($matches) use ($queue, &$currentProgress) {
                // Keep current progress, just update label
                $this->setProgress($queue, $this->currentProgress, trim($matches[1]));
            },

            // "✓ Something completed"
            '/✓\s*(.+)$/m' => function($matches) use ($queue, &$currentProgress) {
                // Keep current progress, just update label
                $this->setProgress($queue, $this->currentProgress, trim($matches[1]));
            },

            // "Done" or "Complete"
            '/(Done|Complete|Completed|Success|Successfully)/i' => function($matches) use ($queue, &$currentProgress) {
                $currentProgress = 0.99;
                $this->currentProgress = $currentProgress;
                $this->setProgress($queue, $currentProgress, 'Finalizing...');
            },
        ];

        foreach ($patterns as $pattern => $callback) {
            if (preg_match($pattern, $output, $matches)) {
                $callback($matches);
            }
        }
    }

    /**
     * Save migration state
     */
    private function saveState(string $status, array $data = []): void
    {
        if (!$this->trackState || !$this->stateService || !$this->migrationId) {
            return;
        }

        $this->stateService->saveMigrationState(array_merge([
            'migrationId' => $this->migrationId,
            'status' => $status,
            'command' => $this->command,
            'pid' => getmypid(),
        ], $data));
    }

    /**
     * Save command output to migration state for polling
     */
    private function saveOutputToState(string $output): void
    {
        if (!$this->trackState || !$this->stateService || !$this->migrationId) {
            return;
        }

        // Limit output size to prevent database bloat (keep last 50KB)
        $maxOutputSize = 50000;
        if (strlen($output) > $maxOutputSize) {
            $output = '... (output truncated) ...' . "\n" . substr($output, -$maxOutputSize);
        }

        $this->stateService->saveMigrationState([
            'migrationId' => $this->migrationId,
            'status' => 'running',
            'command' => $this->command,
            'pid' => getmypid(),
            'output' => $output,
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        $commandName = str_replace('/', ' ', $this->command);
        return 'Running: ' . ucwords($commandName);
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        // Use custom TTR if provided
        if ($this->ttr !== null) {
            return $this->ttr;
        }

        // Default based on command type
        $longRunningCommands = [
            'image-migration/migrate',
            'url-replacement/replace-s3-urls',
            'transform-pre-generation/generate',
            'migration-diag/analyze',
        ];

        foreach ($longRunningCommands as $longCmd) {
            if (strpos($this->command, $longCmd) !== false) {
                return 48 * 60 * 60; // 48 hours
            }
        }

        // Default: 2 hours for other commands
        return 2 * 60 * 60;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        // Don't auto-retry - let users manually retry
        return false;
    }
}
