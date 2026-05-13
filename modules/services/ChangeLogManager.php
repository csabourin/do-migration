<?php

namespace csabourin\spaghettiMigrator\services;

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
    private $sequenceFile;
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
        $this->sequenceFile = $logDir . '/' . $migrationId . '.seq';
        $this->flushThreshold = $flushThreshold;

        // Create file if doesn't exist
        if (!file_exists($this->logFile)) {
            touch($this->logFile);
        }

        if (!file_exists($this->sequenceFile)) {
            file_put_contents($this->sequenceFile, '0', LOCK_EX);
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
                if (fwrite($handle, json_encode($change) . "\n") === false) {
                    throw new \Exception("Failed to write entry to changelog: {$this->logFile}");
                }
            }

            // Only clear the buffer once all entries are durably written
            $this->buffer = [];
            $this->bufferSize = 0;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
    public function loadChanges()
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $changes = [];
        $handle = fopen($this->logFile, 'r');
        if ($handle === false) {
            Craft::warning("Cannot open changelog for reading: {$this->logFile}", __METHOD__);
            return [];
        }

        $lineNum = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $lineNum++;
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                $change = json_decode($trimmed, true);
                if (is_array($change)) {
                    $changes[] = $change;
                } else {
                    Craft::warning("Skipping malformed changelog entry at line {$lineNum} in {$this->logFile}", __METHOD__);
                }
            }
        } finally {
            fclose($handle);
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
            if ($handle === false) {
                Craft::warning("Cannot open changelog file for listing: {$file}", __METHOD__);
                continue;
            }
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
        $handle = fopen($this->sequenceFile, 'c+');

        if (!$handle) {
            throw new \Exception("Cannot open sequence file: {$this->sequenceFile}");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \Exception('Cannot acquire lock for changelog sequence');
        }

        try {
            rewind($handle);
            $currentValue = stream_get_contents($handle);
            $sequence = (int)trim($currentValue);
            $sequence++;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)$sequence);
            fflush($handle);

            return $sequence;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function __destruct()
    {
        $this->flush();
    }
}
