<?php

namespace modules\services;

use Craft;
use craft\helpers\FileHelper;

/**
 * Checkpoint Manager
 * Manages migration checkpoints for resume functionality
 */
class CheckpointManager
{
    private $migrationId;
    private $checkpointDir;
    private $stateFile; // Separate state file for quick resume

    public function __construct($migrationId)
    {
        $this->migrationId = $migrationId;
        $this->checkpointDir = Craft::getAlias('@storage/migration-checkpoints');

        if (!is_dir($this->checkpointDir)) {
            FileHelper::createDirectory($this->checkpointDir);
        }

        $this->stateFile = $this->checkpointDir . '/' . $migrationId . '.state.json';
    }

    /**
     * Save checkpoint with incremental state
     */
    public function saveCheckpoint($data)
    {
        $data['checkpoint_version'] = '4.0';
        $data['created_at'] = microtime(true);

        $checkpointFile = $this->getCheckpointPath();
        $tempFile = $checkpointFile . '.tmp';

        // Write checkpoint
        file_put_contents($tempFile, json_encode($data, JSON_PRETTY_PRINT));
        rename($tempFile, $checkpointFile);

        // Also save lightweight state for quick resume
        $this->saveQuickState($data);

        return true;
    }

    /**
     * Save quick-resume state (processed IDs only)
     */
    public function saveQuickState($data)
    {
        $quickState = [
            'migration_id' => $data['migration_id'] ?? $this->migrationId,
            'phase' => $data['phase'] ?? 'unknown',
            'batch' => $data['batch'] ?? 0,
            'processed_ids' => $data['processed_ids'] ?? [],
            'processed_count' => count($data['processed_ids'] ?? []),
            'timestamp' => $data['timestamp'] ?? date('Y-m-d H:i:s'),
            'stats' => $data['stats'] ?? []
        ];

        $tempFile = $this->stateFile . '.tmp';
        file_put_contents($tempFile, json_encode($quickState));
        rename($tempFile, $this->stateFile);
    }

    /**
     * Load quick state for fast resume
     */
    public function loadQuickState()
    {
        if (!file_exists($this->stateFile)) {
            return null;
        }

        $state = json_decode(file_get_contents($this->stateFile), true);
        return $state;
    }

    /**
     * Update processed IDs incrementally (without full checkpoint)
     */
    public function updateProcessedIds($newIds)
    {
        $state = $this->loadQuickState();
        if (!$state) {
            return;
        }

        $state['processed_ids'] = array_unique(array_merge(
            $state['processed_ids'] ?? [],
            $newIds
        ));
        $state['processed_count'] = count($state['processed_ids']);
        $state['last_updated'] = microtime(true);

        $tempFile = $this->stateFile . '.tmp';
        file_put_contents($tempFile, json_encode($state));
        rename($tempFile, $this->stateFile);
    }

    public function loadLatestCheckpoint($checkpointId = null)
    {
        if ($checkpointId) {
            $file = $this->checkpointDir . '/' . $checkpointId . '.json';
        } else {
            // Find latest checkpoint
            $files = glob($this->checkpointDir . '/*.json');
            // Exclude .state.json files
            $files = array_filter($files, fn($f) => !str_ends_with($f, '.state.json'));

            if (empty($files)) {
                return null;
            }
            usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
            $file = $files[0];
        }

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        return $data;
    }

    public function listCheckpoints()
    {
        $files = glob($this->checkpointDir . '/*.json');
        // Exclude .state.json files
        $files = array_filter($files, fn($f) => !str_ends_with($f, '.state.json'));

        $checkpoints = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            $checkpoints[] = [
                'id' => basename($file, '.json'),
                'phase' => $data['phase'] ?? 'unknown',
                'timestamp' => $data['timestamp'] ?? '',
                'processed' => count($data['processed_ids'] ?? []),
                'file' => $file
            ];
        }

        usort($checkpoints, fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));

        return $checkpoints;
    }

    public function cleanupOldCheckpoints($olderThanHours = 72)
    {
        $cutoff = time() - ($olderThanHours * 3600);
        $files = glob($this->checkpointDir . '/*.json');
        $removed = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function getCheckpointPath()
    {
        return $this->checkpointDir . '/' . $this->migrationId . '.json';
    }
}
