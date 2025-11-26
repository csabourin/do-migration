<?php

namespace csabourin\spaghettiMigrator\services;

use Craft;

/**
 * Progress Reporter Service
 *
 * Provides real-time progress reporting for both CLI and queue-based commands.
 * Controllers call this service to report progress, which is saved to migration_state
 * and displayed in the dashboard via polling.
 *
 * Benefits:
 * - Works for both CLI and queue execution
 * - No dependency on STDOUT capture
 * - Structured progress data
 * - Real-time dashboard updates
 */
class ProgressReporter
{
    private ?string $migrationId = null;
    private ?MigrationStateService $stateService = null;
    private string $outputBuffer = '';
    private int $lastFlushTime = 0;
    private int $flushInterval = 2; // Flush every 2 seconds

    // Track current state to avoid overwriting progress
    private int $processedCount = 0;
    private int $totalCount = 0;
    private string $phase = 'unknown';
    private string $status = 'running';
    private ?string $errorMessage = null;

    /**
     * Initialize progress reporter with migration ID
     */
    public function __construct(?string $migrationId = null)
    {
        $this->migrationId = $migrationId;
        $this->stateService = new MigrationStateService();
        $this->stateService->ensureTableExists();
        $this->lastFlushTime = time();
    }

    /**
     * Set migration ID for progress tracking
     */
    public function setMigrationId(string $migrationId): void
    {
        $this->migrationId = $migrationId;
    }

    /**
     * Log a message to progress output
     *
     * @param string $message The message to log
     * @param bool $newline Whether to append newline (default: true)
     */
    public function log(string $message, bool $newline = true): void
    {
        $this->outputBuffer .= $message . ($newline ? "\n" : '');

        // Auto-flush every N seconds
        if (time() - $this->lastFlushTime >= $this->flushInterval) {
            $this->flush();
        }
    }

    /**
     * Log with formatting (colored output in CLI, plain in dashboard)
     *
     * @param string $message The message to log
     * @param string $type Type: 'info', 'success', 'warning', 'error'
     */
    public function logFormatted(string $message, string $type = 'info'): void
    {
        $symbols = [
            'info' => 'ℹ',
            'success' => '✓',
            'warning' => '⚠',
            'error' => '✗',
        ];

        $symbol = $symbols[$type] ?? 'ℹ';
        $this->log("{$symbol} {$message}");
    }

    /**
     * Log a section header
     */
    public function logSection(string $title, int $width = 80): void
    {
        $this->log('');
        $this->log(str_repeat('=', $width));
        $this->log(strtoupper($title));
        $this->log(str_repeat('=', $width));
        $this->log('');
    }

    /**
     * Log a sub-section
     */
    public function logSubSection(string $title, int $width = 80): void
    {
        $this->log('');
        $this->log($title);
        $this->log(str_repeat('-', strlen($title)));
    }

    /**
     * Update progress with counts
     *
     * @param int $processed Number of items processed
     * @param int $total Total number of items
     * @param string|null $phase Current phase name
     */
    public function updateProgress(int $processed, int $total, ?string $phase = null): void
    {
        if (!$this->migrationId || !$this->stateService) {
            return;
        }

        // Update tracked state
        $this->processedCount = $processed;
        $this->totalCount = $total;
        if ($phase !== null) {
            $this->phase = $phase;
        }

        $percentage = $total > 0 ? round(($processed / $total) * 100) : 0;
        $this->log("Progress: {$processed}/{$total} ({$percentage}%)");

        // Flush will save all tracked state including progress
        $this->flush();
    }

    /**
     * Flush output buffer to migration state
     */
    public function flush(): void
    {
        if (!$this->migrationId || !$this->stateService) {
            return;
        }

        if (empty($this->outputBuffer)) {
            return;
        }

        // Limit output size to 50KB
        $maxSize = 50000;
        $output = $this->outputBuffer;
        if (strlen($output) > $maxSize) {
            $output = '... (output truncated) ...' . "\n" . substr($output, -$maxSize);
        }

        try {
            // Save ALL tracked state, not just output
            // This preserves progress data set by updateProgress()
            $state = [
                'migrationId' => $this->migrationId,
                'output' => $output,
                'status' => $this->status,
                'processedCount' => $this->processedCount,
                'totalCount' => $this->totalCount,
                'phase' => $this->phase,
            ];

            // Include error message if set
            if ($this->errorMessage !== null) {
                $state['errorMessage'] = $this->errorMessage;
            }

            $this->stateService->saveMigrationState($state);

            $this->lastFlushTime = time();
        } catch (\Throwable $e) {
            // Silently fail to avoid breaking command execution
            Craft::error('Failed to flush progress output: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Mark command as completed
     */
    public function complete(string $message = 'Command completed successfully'): void
    {
        $this->log('');
        $this->logSection('COMPLETED');
        $this->log($message);

        // Update tracked status
        $this->status = 'completed';

        // Flush will save all state including completed status
        $this->flush();
    }

    /**
     * Mark command as failed
     */
    public function fail(string $message, ?\Throwable $exception = null): void
    {
        $this->log('');
        $this->logSection('FAILED');
        $this->logFormatted($message, 'error');

        if ($exception) {
            $this->log('');
            $this->log('Exception: ' . $exception->getMessage());
            if (getenv('CRAFT_ENVIRONMENT') === 'dev') {
                $this->log('');
                $this->log('Stack trace:');
                $this->log($exception->getTraceAsString());
            }
        }

        // Update tracked status and error message
        $this->status = 'failed';
        $this->errorMessage = $message;

        // Flush will save all state including failed status and error message
        $this->flush();
    }

    /**
     * Get current output buffer
     */
    public function getOutput(): string
    {
        return $this->outputBuffer;
    }

    /**
     * Clear output buffer
     */
    public function clear(): void
    {
        $this->outputBuffer = '';
    }

    /**
     * Destructor - ensure final flush if needed
     */
    public function __destruct()
    {
        // Only flush if there's buffered output
        // flush() will preserve all tracked state (status, progress, etc.)
        if (!empty($this->outputBuffer)) {
            $this->flush();
        }
    }
}
