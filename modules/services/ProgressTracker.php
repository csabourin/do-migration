<?php

namespace csabourin\spaghettiMigrator\services;

use csabourin\spaghettiMigrator\helpers\MigrationConfig;

/**
 * Progress Tracker
 * Real-time progress estimation and reporting for migration operations
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

    public function __construct($phaseName, $totalItems, $reportInterval = null)
    {
        $this->phaseName = $phaseName;
        $this->totalItems = max(1, $totalItems);
        $this->startTime = microtime(true);
        $this->lastReportTime = $this->startTime;

        // Use config value if not explicitly provided
        if ($reportInterval === null) {
            try {
                $config = MigrationConfig::getInstance();
                $reportInterval = $config->getProgressReportInterval();
            } catch (\Exception $e) {
                // Default to 50 if config is not available (e.g., in tests)
                $reportInterval = 50;
            }
        }
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
            'elapsed_seconds' => round(microtime(true) - $this->startTime, 2),
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
