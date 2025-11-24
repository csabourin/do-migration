<?php

namespace csabourin\spaghettiMigrator\services;

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
    private $migrationStateService;

    public function __construct($migrationId)
    {
        // Validate migration ID format to prevent path traversal
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $migrationId)) {
            throw new \InvalidArgumentException('Invalid migration ID format. Only alphanumeric characters, hyphens, and underscores are allowed.');
        }

        // Use basename to strip any directory traversal attempts
        $safeMigrationId = basename($migrationId);

        // Additional check: ensure basename didn't change the ID (indicates traversal attempt)
        if ($safeMigrationId !== $migrationId) {
            throw new \InvalidArgumentException('Migration ID contains invalid path characters');
        }

        $this->migrationId = $safeMigrationId;
        $this->checkpointDir = Craft::getAlias('@storage/migration-checkpoints');

        if (!is_dir($this->checkpointDir)) {
            FileHelper::createDirectory($this->checkpointDir);
        }

        $this->stateFile = $this->checkpointDir . '/' . $safeMigrationId . '.state.json';

        // Verify the state file path is within the checkpoint directory
        $this->validatePathWithinCheckpointDir($this->stateFile);

        $this->migrationStateService = new MigrationStateService();

        // Ensure the migration_state table exists
        $this->migrationStateService->ensureTableExists();
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

        // Persist to database for web interface recovery
        $this->migrationStateService->saveMigrationState([
            'migrationId' => $data['migration_id'] ?? $this->migrationId,
            'phase' => $data['phase'] ?? 'unknown',
            'status' => 'running',
            'processedCount' => count($data['processed_ids'] ?? []),
            'totalCount' => $data['total_count'] ?? 0,
            'currentBatch' => $data['batch'] ?? 0,
            'processedIds' => $data['processed_ids'] ?? [],
            'stats' => $data['stats'] ?? [],
            'checkpointFile' => basename($checkpointFile),
        ]);

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

        // Also persist to database
        $this->migrationStateService->saveMigrationState([
            'migrationId' => $quickState['migration_id'],
            'phase' => $quickState['phase'],
            'status' => 'running',
            'processedCount' => $quickState['processed_count'],
            'currentBatch' => $quickState['batch'],
            'processedIds' => $quickState['processed_ids'],
            'stats' => $quickState['stats'],
        ]);
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
            // Validate checkpoint ID format
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $checkpointId)) {
                throw new \InvalidArgumentException('Invalid checkpoint ID format');
            }

            $safeCheckpointId = basename($checkpointId);
            $file = $this->checkpointDir . '/' . $safeCheckpointId . '.json';

            // Verify path is within checkpoint directory
            $this->validatePathWithinCheckpointDir($file);
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

            // Verify path is within checkpoint directory (defense in depth)
            $this->validatePathWithinCheckpointDir($file);
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
        $failed = 0;

        foreach ($files as $file) {
            try {
                // Verify file is within checkpoint directory (defense in depth)
                $this->validatePathWithinCheckpointDir($file);

                // Check if file is old enough to delete
                if (filemtime($file) < $cutoff) {
                    // Attempt to delete the file
                    if (!unlink($file)) {
                        $failed++;
                        Craft::warning("Failed to delete old checkpoint file: {$file}", __METHOD__);
                    } else {
                        $removed++;
                        Craft::info("Deleted old checkpoint file: {$file}", __METHOD__);
                    }
                }
            } catch (\Exception $e) {
                // Log security violations or other errors
                $failed++;
                Craft::error("Error while attempting to delete checkpoint file {$file}: " . $e->getMessage(), __METHOD__);
            }
        }

        if ($failed > 0) {
            Craft::warning("Failed to delete {$failed} checkpoint file(s) during cleanup", __METHOD__);
        }

        return $removed;
    }

    private function getCheckpointPath()
    {
        return $this->checkpointDir . '/' . $this->migrationId . '.json';
    }

    /**
     * Register a migration as started with PID and session info
     */
    public function registerMigrationStart($pid, $sessionId = null, $command = null, $totalCount = 0)
    {
        return $this->migrationStateService->saveMigrationState([
            'migrationId' => $this->migrationId,
            'sessionId' => $sessionId,
            'phase' => 'initializing',
            'status' => 'running',
            'pid' => $pid,
            'command' => $command,
            'totalCount' => $totalCount,
            'processedCount' => 0,
            'currentBatch' => 0,
            'processedIds' => [],
            'stats' => [],
        ]);
    }

    /**
     * Mark migration as completed
     */
    public function markMigrationCompleted($stats = [])
    {
        return $this->migrationStateService->updateMigrationStatus(
            $this->migrationId,
            'completed',
            null
        );
    }

    /**
     * Mark migration as failed
     */
    public function markMigrationFailed($errorMessage)
    {
        return $this->migrationStateService->updateMigrationStatus(
            $this->migrationId,
            'failed',
            $errorMessage
        );
    }

    /**
     * Get migration state from database
     */
    public function getMigrationState()
    {
        return $this->migrationStateService->getMigrationState($this->migrationId);
    }

    /**
     * Get the current migration ID
     *
     * @return string Migration ID
     */
    public function getMigrationId(): string
    {
        return $this->migrationId;
    }

    /**
     * Validate that a file path is within the checkpoint directory
     * Prevents path traversal attacks
     *
     * @param string $filePath Path to validate
     * @throws \Exception if path is outside checkpoint directory
     */
    private function validatePathWithinCheckpointDir($filePath)
    {
        // Get real paths (resolves symlinks and relative paths)
        $realCheckpointDir = realpath($this->checkpointDir);

        // For files that don't exist yet, check the parent directory
        if (file_exists($filePath)) {
            $realFilePath = realpath($filePath);
        } else {
            $parentDir = dirname($filePath);
            if (file_exists($parentDir)) {
                $realParentPath = realpath($parentDir);
                $realFilePath = $realParentPath . '/' . basename($filePath);
            } else {
                throw new \Exception('Invalid file path: parent directory does not exist');
            }
        }

        // Verify checkpoint directory exists
        if ($realCheckpointDir === false) {
            throw new \Exception('Checkpoint directory does not exist or is not accessible');
        }

        // Verify file path is within checkpoint directory
        if ($realFilePath === false || strpos($realFilePath, $realCheckpointDir) !== 0) {
            Craft::error("Path traversal attempt detected: {$filePath}", __METHOD__);
            throw new \Exception('Path traversal detected: file must be within checkpoint directory');
        }
    }
}
