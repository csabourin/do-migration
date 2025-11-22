<?php

namespace csabourin\craftS3SpacesMigration\services\migration;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;

/**
 * Migration Reporter
 *
 * Handles all output formatting, progress reporting, and result presentation
 * for the migration process.
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class MigrationReporter
{
    /**
     * @var Controller The controller instance for stdout/stderr
     */
    private $controller;

    /**
     * @var string Migration ID
     */
    private $migrationId;

    /**
     * @var array Missing files tracking for CSV export
     */
    private $missingFiles = [];

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance for output
     * @param string $migrationId Unique migration identifier
     */
    public function __construct(Controller $controller, string $migrationId)
    {
        $this->controller = $controller;
        $this->migrationId = $migrationId;
    }

    /**
     * Print main migration header
     */
    public function printHeader(): void
    {
        $this->controller->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->controller->stdout("ASSET & FILE MIGRATION - PRODUCTION GRADE v4.0\n", Console::FG_CYAN);
        $this->controller->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);
        $this->controller->stdout("Migration ID: {$this->migrationId}\n\n");
    }

    /**
     * Print success footer
     *
     * @param $checkpointManager CheckpointManager instance
     * @param array $stats Migration statistics
     */
    public function printSuccessFooter($checkpointManager, array $stats): void
    {
        // Mark migration as completed in database
        if ($checkpointManager) {
            $checkpointManager->markMigrationCompleted($stats);
        }

        $this->controller->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_GREEN);
        $this->controller->stdout("✓ MIGRATION COMPLETED SUCCESSFULLY\n", Console::FG_GREEN);
        $this->controller->stdout(str_repeat("=", 80) . "\n\n");
    }

    /**
     * Print phase header
     *
     * @param string $title Phase title
     */
    public function printPhaseHeader(string $title): void
    {
        $this->controller->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->controller->stdout($title . "\n", Console::FG_CYAN);
        $this->controller->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);
    }

    /**
     * Print progress legend used by batch loops
     */
    public function printProgressLegend(): void
    {
        $this->controller->stdout("  Legend: ", Console::FG_GREY);
        $this->controller->stdout(". ", Console::FG_GREEN);
        $this->controller->stdout("= ok  ", Console::FG_GREY);

        $this->controller->stdout(". ", Console::FG_YELLOW);
        $this->controller->stdout("= quarantined  ", Console::FG_GREY);

        $this->controller->stdout("- ", Console::FG_GREY);
        $this->controller->stdout("= skipped  ", Console::FG_GREY);

        $this->controller->stdout("x ", Console::FG_RED);
        $this->controller->stdout("= error  ", Console::FG_GREY);

        $this->controller->stdout("! ", Console::FG_RED);
        $this->controller->stdout("= quarantine error\n", Console::FG_GREY);
    }

    /**
     * Print analysis report
     *
     * @param array $analysis Analysis results from InventoryBuilder
     */
    public function printAnalysisReport(array $analysis): void
    {
        $this->controller->stdout("\n  ANALYSIS RESULTS:\n", Console::FG_CYAN);
        $this->controller->stdout("  " . str_repeat("-", 76) . "\n");

        $this->controller->stdout(sprintf(
            "  ✓ Assets with files:           %5d\n",
            count($analysis['assets_with_files'])
        ), Console::FG_GREEN);
        $this->controller->stdout(sprintf(
            "  ✓ Used assets (correct loc):   %5d\n",
            count($analysis['used_assets_correct_location'])
        ), Console::FG_GREEN);

        $this->controller->stdout(sprintf(
            "  ⚠ Used assets (wrong loc):     %5d  [NEEDS MOVE]\n",
            count($analysis['used_assets_wrong_location'])
        ), Console::FG_YELLOW);
        $this->controller->stdout(sprintf(
            "  ⚠ Broken links:                %5d  [NEEDS FIX]\n",
            count($analysis['broken_links'])
        ), Console::FG_YELLOW);
        $this->controller->stdout(sprintf(
            "  ⚠ Unused assets:               %5d  [QUARANTINE - Target volume only]\n",
            count($analysis['unused_assets'])
        ), Console::FG_YELLOW);
        $this->controller->stdout(sprintf(
            "  ⚠ Orphaned files:              %5d  [QUARANTINE - Target volume only]\n",
            count($analysis['orphaned_files'])
        ), Console::FG_YELLOW);

        if (!empty($analysis['duplicates'])) {
            $this->controller->stdout(sprintf(
                "  ⚠ Duplicate filenames:         %5d  [RESOLVE]\n",
                count($analysis['duplicates'])
            ), Console::FG_YELLOW);
        }

        $this->controller->stdout("  " . str_repeat("-", 76) . "\n\n");
    }

    /**
     * Print planned operations
     *
     * @param array $analysis Analysis results
     */
    public function printPlannedOperations(array $analysis): void
    {
        $this->controller->stdout("  1. Fix " . count($analysis['broken_links']) . " broken asset-file links\n");
        $this->controller->stdout("  2. Move " . count($analysis['used_assets_wrong_location']) . " used files to target root\n");
        $this->controller->stdout("  3. Quarantine " . count($analysis['unused_assets']) . " unused assets\n");
        $this->controller->stdout("  4. Quarantine " . count($analysis['orphaned_files']) . " orphaned files\n");

        if (!empty($analysis['duplicates'])) {
            $this->controller->stdout("  5. Resolve " . count($analysis['duplicates']) . " duplicate filenames\n");
        }

        $this->controller->stdout("\n");
    }

    /**
     * Print inline linking results
     *
     * @param array $stats Inline linking statistics
     */
    public function printInlineLinkingResults(array $stats): void
    {
        $this->controller->stdout("  INLINE LINKING RESULTS:\n", Console::FG_CYAN);
        $this->controller->stdout("    Rows scanned:          {$stats['rows_scanned']}\n");
        $this->controller->stdout("    Rows with images:      {$stats['rows_with_images']}\n");
        $this->controller->stdout("    Images found:          {$stats['images_found']}\n");
        $this->controller->stdout("    Already linked:        {$stats['images_already_linked']}\n", Console::FG_GREY);
        $this->controller->stdout("    Newly linked:          {$stats['images_linked']}\n", Console::FG_GREEN);
        $this->controller->stdout("    No match found:        {$stats['images_no_match']}\n", Console::FG_YELLOW);
        $this->controller->stdout("    Rows updated:          {$stats['rows_updated']}\n", Console::FG_CYAN);
        $this->controller->stdout("    Relations created:     {$stats['relations_created']}\n", Console::FG_CYAN);
    }

    /**
     * Print final migration report
     *
     * @param array $stats Migration statistics
     */
    public function printFinalReport(array $stats): void
    {
        $duration = time() - $stats['start_time'];
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        $this->controller->stdout("\n  FINAL STATISTICS:\n", Console::FG_CYAN);
        $this->controller->stdout("    Duration:          {$minutes}m {$seconds}s\n");
        $this->controller->stdout("    Files moved:       {$stats['files_moved']}\n");
        $this->controller->stdout("    Files quarantined: {$stats['files_quarantined']}\n");
        $this->controller->stdout("    Assets updated:    {$stats['assets_updated']}\n");
        if (isset($stats['originals_moved']) && $stats['originals_moved'] > 0) {
            $this->controller->stdout("    Originals moved:   {$stats['originals_moved']}\n", Console::FG_GREEN);
            $this->controller->stdout("                       (Original files moved to volume 1 root, replacing transforms)\n", Console::FG_GREY);
        }
        $this->controller->stdout("    Errors:            {$stats['errors']}\n");
        $this->controller->stdout("    Retries:           {$stats['retries']}\n");
        $this->controller->stdout("    Checkpoints saved: {$stats['checkpoints_saved']}\n");
        if (isset($stats['missing_files']) && $stats['missing_files'] > 0) {
            $this->controller->stdout("    Missing files:     {$stats['missing_files']}\n", Console::FG_YELLOW);
            $this->controller->stdout("                       (Files catalogued but not readable - see logs)\n", Console::FG_GREY);
        }
        if (!empty($this->missingFiles)) {
            $csvFile = Craft::getAlias('@storage') . '/migration-missing-files-' . $this->migrationId . '.csv';
            $this->controller->stdout("    Missing files CSV: {$csvFile}\n", Console::FG_CYAN);
        }
        if ($stats['resume_count'] > 0) {
            $this->controller->stdout("    Resumed:           {$stats['resume_count']} times\n", Console::FG_YELLOW);
        }
        $this->controller->stdout("\n");
    }

    /**
     * Export missing files to CSV
     *
     * @param array $missingFiles Array of missing file data
     */
    public function exportMissingFilesToCsv(array $missingFiles): void
    {
        if (empty($missingFiles)) {
            return;
        }

        $this->missingFiles = $missingFiles;

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
            foreach ($missingFiles as $missing) {
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

            $count = count($missingFiles);
            $this->controller->stdout("\n  ✓ Exported {$count} missing files to CSV: {$csvFile}\n", Console::FG_CYAN);

        } catch (\Exception $e) {
            Craft::warning("Could not export missing files to CSV: " . $e->getMessage(), __METHOD__);
            $this->controller->stdout("  ⚠ Could not export missing files to CSV\n", Console::FG_YELLOW);
        }
    }

    /**
     * Format bytes to human-readable string
     *
     * @param int|float $bytes Number of bytes
     * @return string Formatted size (e.g., "1.5 MB")
     */
    public function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Format age of timestamp
     *
     * @param int $timestamp Unix timestamp
     * @return string Human-readable age (e.g., "5 minutes")
     */
    public function formatAge(int $timestamp): string
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
     *
     * @param int|float $seconds Duration in seconds
     * @return string Human-readable duration (e.g., "5m 30s")
     */
    public function formatDuration($seconds): string
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
     * Safe stdout wrapper that handles broken pipe errors
     *
     * @param string $message Message to output
     * @param int|null $color Console color constant
     */
    public function safeStdout(string $message, $color = null): void
    {
        try {
            if ($color !== null) {
                $this->controller->stdout($message, $color);
            } else {
                $this->controller->stdout($message);
            }
        } catch (\Exception $e) {
            // Fallback to file logging if stdout fails
            Craft::error("Failed to write to stdout: " . $e->getMessage(), __METHOD__);
        } catch (\Throwable $e) {
            // Catch any other errors (broken pipe, etc.)
            Craft::error("Failed to write to stdout: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Safe stderr wrapper that handles broken pipe errors
     *
     * @param string $message Message to output
     * @param int|null $color Console color constant
     */
    public function safeStderr(string $message, $color = null): void
    {
        try {
            if ($color !== null) {
                $this->controller->stderr($message, $color);
            } else {
                $this->controller->stderr($message);
            }
        } catch (\Exception $e) {
            // Fallback to file logging if stderr fails
            Craft::error("Failed to write to stderr: " . $e->getMessage(), __METHOD__);
        } catch (\Throwable $e) {
            // Catch any other errors (broken pipe, etc.)
            Craft::error("Failed to write to stderr: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Print fatal error message with recovery instructions
     *
     * @param \Exception $e Exception that caused the error
     * @param $checkpointManager CheckpointManager instance
     * @param bool $checkpointSaved Whether checkpoint was saved successfully
     */
    public function printFatalError(\Exception $e, $checkpointManager, bool $checkpointSaved): void
    {
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
            $quickState = $checkpointManager->loadQuickState();
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
     * Get controller instance for direct stdout/stderr access
     *
     * @return Controller
     */
    public function getController(): Controller
    {
        return $this->controller;
    }
}
