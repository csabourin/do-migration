<?php

namespace csabourin\craftS3SpacesMigration\services;

use Craft;
use craft\helpers\FileHelper;

/**
 * Change Log Manager
 * Manages change logging for rollback functionality
 */
class ChangeLogManager
{
    private $migrationId;
    private $logFile;
    private $buffer = [];
    private $bufferSize = 0;
    private $flushThreshold;
    private $currentPhase = 'unknown';

    public function __construct($migrationId, $flushThreshold = 5)
    {
        $this->migrationId = $migrationId;
        $logDir = Craft::getAlias('@storage/migration-changelogs');

        if (!is_dir($logDir)) {
            FileHelper::createDirectory($logDir);
        }

        $this->logFile = $logDir . '/' . $migrationId . '.jsonl';
        $this->flushThreshold = $flushThreshold;

        // Create file if doesn't exist
        if (!file_exists($this->logFile)) {
            touch($this->logFile);
        }
    }

    /**
     * Set the current phase for logging
     *
     * @param string $phase Phase name
     */
    public function setPhase($phase)
    {
        $this->currentPhase = $phase;
        // Flush buffer when phase changes to ensure clean boundaries
        $this->flush();
    }

    /**
     * Log a change entry
     */
    public function logChange($change)
    {
        $change['sequence'] = $this->getNextSequence();
        $change['timestamp'] = date('Y-m-d H:i:s');
        $change['phase'] = $this->currentPhase; // Add phase tracking

        $this->buffer[] = $change;
        $this->bufferSize++;

        if ($this->bufferSize >= $this->flushThreshold) {
            $this->flush();
        }
    }

    /**
     * Flush buffered changes to log file atomically
     */

    public function flush()
    {
        if (empty($this->buffer)) {
            return;
        }

        $handle = fopen($this->logFile, 'a');
        if (!$handle) {
            throw new \Exception("Cannot open changelog file for writing: {$this->logFile}");
        }

        // Acquire exclusive lock
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \Exception("Cannot acquire lock on changelog file");
        }

        try {
            foreach ($this->buffer as $change) {
                fwrite($handle, json_encode($change) . "\n");
            }
        } finally {
            // Always release lock
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->buffer = [];
        $this->bufferSize = 0;
    }
    public function loadChanges()
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $changes = [];
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $change = json_decode($line, true);
            if ($change) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    public function listMigrations()
    {
        $logDir = Craft::getAlias('@storage/migration-changelogs');
        $files = glob($logDir . '/*.jsonl');
        $migrations = [];

        foreach ($files as $file) {
            $lineCount = 0;
            $handle = fopen($file, 'r');
            while (!feof($handle)) {
                if (fgets($handle)) {
                    $lineCount++;
                }
            }
            fclose($handle);

            $migrations[] = [
                'id' => basename($file, '.jsonl'),
                'timestamp' => date('Y-m-d H:i:s', filemtime($file)),
                'change_count' => $lineCount,
                'file' => $file
            ];
        }

        usort($migrations, fn($a, $b) => filemtime($b['file']) - filemtime($a['file']));

        return $migrations;
    }

    private function getNextSequence()
    {
        static $sequence = 0;
        return ++$sequence;
    }

    public function __destruct()
    {
        $this->flush();
    }
}
