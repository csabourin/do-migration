<?php

namespace csabourin\spaghettiMigrator\services;

use Craft;
use yii\helpers\FileHelper;

/**
 * Checkpoint Manager
 * Manages migration checkpoints for resume functionality
 */
class CheckpointManager
{
    private const DEFAULT_STATUS = 'running';

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
     * Return the latest resumable migration state across checkpoints and quick states.
     *
     * Quick state files are preferred when they are newer because they are written
     * more frequently than full checkpoints and preserve finer-grained resume data.
     *
     * @param string|null $checkpointId Specific checkpoint ID to load
     * @return array|null
     */
    public static function findLatestResumableState(?string $checkpointId = null): ?array
    {
        $states = self::listResumableStates($checkpointId);

        return $states[0] ?? null;
    }

    /**
     * Resolve the migration ID that should be resumed.
     *
     * @param string|null $checkpointId Specific checkpoint ID to resolve
     * @return string|null
     */
    public static function resolveMigrationIdForResume(?string $checkpointId = null): ?string
    {
        $state = self::findLatestResumableState($checkpointId);

        return $state['migration_id'] ?? $state['migrationId'] ?? null;
    }

    /**
     * List resumable states across full checkpoints and quick-state files.
     *
     * @param string|null $checkpointId Specific checkpoint ID to load
     * @return array
     */
    public static function listResumableStates(?string $checkpointId = null): array
    {
        $checkpointDir = Craft::getAlias('@storage/migration-checkpoints');
        if (!is_dir($checkpointDir)) {
            return [];
        }

        $candidates = [];

        if ($checkpointId !== null) {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $checkpointId)) {
                throw new \InvalidArgumentException('Invalid checkpoint ID format');
            }

            $checkpointPath = $checkpointDir . '/' . basename($checkpointId) . '.json';
            if (is_file($checkpointPath) && !str_ends_with($checkpointPath, '.state.json')) {
                $decoded = self::decodeStateFile($checkpointPath);
                if ($decoded !== null) {
                    $candidates[] = self::formatResumableState($checkpointPath, $decoded, 'checkpoint');
                }
            }

            return $candidates;
        }

        foreach (glob($checkpointDir . '/*.state.json') ?: [] as $file) {
            $decoded = self::decodeStateFile($file);
            if ($decoded === null) {
                continue;
            }

            $candidates[] = self::formatResumableState($file, $decoded, 'quick_state');
        }

        foreach (glob($checkpointDir . '/*.json') ?: [] as $file) {
            if (str_ends_with($file, '.state.json')) {
                continue;
            }

            $decoded = self::decodeStateFile($file);
            if ($decoded === null) {
                continue;
            }

            $candidates[] = self::formatResumableState($file, $decoded, 'checkpoint');
        }

        usort($candidates, static function(array $a, array $b): int {
            return ($b['modifiedAt'] ?? 0) <=> ($a['modifiedAt'] ?? 0);
        });

        return $candidates;
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

        $handle = fopen($tempFile, 'w');

        if (!$handle || !flock($handle, LOCK_EX)) {
            throw new \Exception("Cannot acquire checkpoint lock for {$tempFile}");
        }

        try {
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT));
            fflush($handle);

            flock($handle, LOCK_UN);
            fclose($handle);

            if (!@rename($tempFile, $checkpointFile)) {
                throw new \Exception("Failed to rename checkpoint file from {$tempFile} to {$checkpointFile}");
            }
        } finally {
            if (isset($handle) && is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }

            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

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
            'stats' => $data['stats'] ?? [],
            'total_count' => $data['total_count'] ?? 0,
            'status' => $data['status'] ?? self::DEFAULT_STATUS,
            'phase_progress' => $data['phase_progress'] ?? [],
            'checkpoint_file' => $data['checkpoint_file'] ?? null,
            'error' => $data['error'] ?? null,
            'can_resume' => $data['can_resume'] ?? true,
            'interrupted_at' => $data['interrupted_at'] ?? null,
        ];

        return $this->persistQuickState($quickState);
    }

    /**
     * Merge new quick-state data into the existing resumable state.
     *
     * @param array $data
     * @return bool
     */
    public function updateQuickState(array $data): bool
    {
        $existing = $this->loadQuickState() ?? [
            'migration_id' => $this->migrationId,
            'phase' => $data['phase'] ?? 'unknown',
            'batch' => 0,
            'processed_ids' => [],
            'processed_count' => 0,
            'timestamp' => date('Y-m-d H:i:s'),
            'stats' => [],
            'total_count' => 0,
            'status' => self::DEFAULT_STATUS,
            'phase_progress' => [],
            'checkpoint_file' => null,
            'error' => null,
            'can_resume' => true,
            'interrupted_at' => null,
        ];

        $existingProcessedIds = $existing['processed_ids'] ?? [];
        $newProcessedIds = $data['processed_ids'] ?? [];

        if (!is_array($existingProcessedIds)) {
            $existingProcessedIds = [];
        }

        if (!is_array($newProcessedIds)) {
            $newProcessedIds = [];
        }

        $mergedStats = $existing['stats'] ?? [];
        if (isset($data['stats']) && is_array($data['stats'])) {
            $mergedStats = array_merge($mergedStats, $data['stats']);
        }

        $mergedPhaseProgress = $existing['phase_progress'] ?? [];
        if (isset($data['phase_progress']) && is_array($data['phase_progress'])) {
            $mergedPhaseProgress = array_merge($mergedPhaseProgress, $data['phase_progress']);
        }

        $quickState = array_merge($existing, $data);
        $quickState['migration_id'] = $existing['migration_id'] ?? ($data['migration_id'] ?? $this->migrationId);
        $quickState['processed_ids'] = array_values(array_unique(array_merge($existingProcessedIds, $newProcessedIds)));
        $quickState['processed_count'] = count($quickState['processed_ids']);
        $quickState['stats'] = $mergedStats;
        $quickState['phase_progress'] = $mergedPhaseProgress;
        $quickState['timestamp'] = $data['timestamp'] ?? date('Y-m-d H:i:s');
        $quickState['status'] = $data['status'] ?? ($existing['status'] ?? self::DEFAULT_STATUS);

        return $this->persistQuickState($quickState);
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
        if (!is_array($newIds) || $newIds === []) {
            return;
        }

        $this->updateQuickState([
            'processed_ids' => $newIds,
            'last_updated' => microtime(true),
        ]);
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
            $preferredFile = $this->getCheckpointPath();
            if (is_file($preferredFile)) {
                $this->validatePathWithinCheckpointDir($preferredFile);
                $file = $preferredFile;
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
        }

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        return $data;
    }

    public function listCheckpoints()
    {
        return array_values(array_filter(
            self::listResumableStates(),
            static fn(array $state): bool => ($state['source'] ?? '') === 'checkpoint'
        ));
    }

    public function cleanupOldCheckpoints($olderThanHours = 72)
    {
        $cutoff = time() - ($olderThanHours * 3600);
        $result = $this->cleanupOldFiles(
            glob($this->checkpointDir . '/*.json') ?: [],
            $cutoff,
            function (string $file): void {
                $this->validatePathWithinCheckpointDir($file);
            },
            'checkpoint'
        );

        return $result['removed'];
    }

    /**
     * Cleanup old checkpoint and changelog files with detailed operator output.
     */
    public function cleanupOldMigrationData($olderThanHours = 72): array
    {
        $cutoff = time() - ($olderThanHours * 3600);
        $checkpointResult = $this->cleanupOldFiles(
            glob($this->checkpointDir . '/*.json') ?: [],
            $cutoff,
            function (string $file): void {
                $this->validatePathWithinCheckpointDir($file);
            },
            'checkpoint'
        );

        $changelogDir = Craft::getAlias('@storage/migration-changelogs');
        $changelogFiles = [];
        if (is_dir($changelogDir)) {
            $changelogFiles = array_merge(
                glob($changelogDir . '/*.jsonl') ?: [],
                glob($changelogDir . '/*.seq') ?: []
            );
        }

        $changelogResult = $this->cleanupOldFiles(
            $changelogFiles,
            $cutoff,
            function (string $file) use ($changelogDir): void {
                $this->validatePathWithinDirectory($file, $changelogDir, 'changelog');
            },
            'changelog'
        );

        return [
            'checkpoints_cleaned' => $checkpointResult['removed'],
            'changelogs_cleaned' => $changelogResult['removed'],
            'space_freed' => $this->formatBytes($checkpointResult['space_freed'] + $changelogResult['space_freed']),
            'space_freed_bytes' => $checkpointResult['space_freed'] + $changelogResult['space_freed'],
            'failed' => $checkpointResult['failed'] + $changelogResult['failed'],
        ];
    }

    /**
     * Remove old files after validating each candidate path.
     */
    private function cleanupOldFiles(array $files, int $cutoff, callable $validator, string $label): array
    {
        $removed = 0;
        $failed = 0;
        $spaceFreed = 0;

        foreach ($files as $file) {
            try {
                $validator($file);

                $modifiedAt = filemtime($file);
                if ($modifiedAt === false) {
                    throw new \Exception("Could not read modification time for {$file}");
                }

                // Check if file is old enough to delete
                if ($modifiedAt < $cutoff) {
                    $fileSize = filesize($file);

                    // Attempt to delete the file
                    if (!unlink($file)) {
                        $failed++;
                        Craft::warning("Failed to delete old {$label} file: {$file}", __METHOD__);
                    } else {
                        $removed++;
                        $spaceFreed += $fileSize !== false ? $fileSize : 0;
                        Craft::info("Deleted old {$label} file: {$file}", __METHOD__);
                    }
                }
            } catch (\Exception $e) {
                // Log security violations or other errors
                $failed++;
                Craft::error("Error while attempting to delete {$label} file {$file}: " . $e->getMessage(), __METHOD__);
            }
        }

        if ($failed > 0) {
            Craft::warning("Failed to delete {$failed} {$label} file(s) during cleanup", __METHOD__);
        }

        return [
            'removed' => $removed,
            'failed' => $failed,
            'space_freed' => $spaceFreed,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;

        foreach ($units as $unit) {
            if ($size < 1024) {
                return number_format($size, 2) . ' ' . $unit;
            }

            $size /= 1024;
        }

        return number_format($size, 2) . ' PB';
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
     * List all migrations from the database
     * Used by status and rollback commands
     *
     * @return array Array of migrations with id, phase, timestamp, and processed count
     */
    public function listMigrations(): array
    {
        $recentMigrations = $this->migrationStateService->getRecentMigrations(50, true);

        $migrations = [];
        foreach ($recentMigrations as $migration) {
            $migrations[] = [
                'id' => $migration['migrationId'],
                'phase' => $migration['phase'] ?? 'unknown',
                'timestamp' => $migration['lastUpdatedAt'] ?? $migration['dateCreated'] ?? 'unknown',
                'processed' => $migration['processedCount'] ?? 0,
                'status' => $migration['status'] ?? 'unknown',
                'checkpoints' => [], // Could be populated if needed
            ];
        }

        return $migrations;
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
        $normalizedCheckpointDir = FileHelper::normalizePath($this->checkpointDir);
        $normalizedFilePath = FileHelper::normalizePath($filePath);

        if ($normalizedCheckpointDir === false || !is_dir($normalizedCheckpointDir)) {
            throw new \Exception('Checkpoint directory does not exist or is not accessible');
        }

        if ($normalizedFilePath === false) {
            throw new \Exception('Invalid file path');
        }

        if ($this->pathContainsSymlink($normalizedFilePath)) {
            Craft::error("Path traversal attempt detected via symlink: {$filePath}", __METHOD__);
            throw new \Exception('Path traversal detected: symlinks are not allowed in checkpoint paths');
        }

        $checkpointPrefix = rtrim($normalizedCheckpointDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($normalizedFilePath !== $normalizedCheckpointDir && strpos($normalizedFilePath, $checkpointPrefix) !== 0) {
            Craft::error("Path traversal attempt detected: {$filePath}", __METHOD__);
            throw new \Exception('Path traversal detected: file must be within checkpoint directory');
        }
    }

    /**
     * Validate that a file path is inside an expected directory.
     */
    private function validatePathWithinDirectory(string $filePath, string $directory, string $label): void
    {
        $normalizedDirectory = FileHelper::normalizePath($directory);
        $normalizedFilePath = FileHelper::normalizePath($filePath);

        if ($normalizedDirectory === false || !is_dir($normalizedDirectory)) {
            throw new \Exception(ucfirst($label) . ' directory does not exist or is not accessible');
        }

        if ($normalizedFilePath === false) {
            throw new \Exception('Invalid file path');
        }

        if ($this->pathContainsSymlink($normalizedFilePath)) {
            Craft::error("Path traversal attempt detected via symlink: {$filePath}", __METHOD__);
            throw new \Exception('Path traversal detected: symlinks are not allowed in cleanup paths');
        }

        $directoryPrefix = rtrim($normalizedDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($normalizedFilePath !== $normalizedDirectory && strpos($normalizedFilePath, $directoryPrefix) !== 0) {
            Craft::error("Path traversal attempt detected: {$filePath}", __METHOD__);
            throw new \Exception('Path traversal detected: file must be within ' . $label . ' directory');
        }
    }

    private function pathContainsSymlink(string $path): bool
    {
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $currentPath = DIRECTORY_SEPARATOR;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($currentPath === DIRECTORY_SEPARATOR) {
                $currentPath .= $part;
            } else {
                $currentPath .= DIRECTORY_SEPARATOR . $part;
            }

            if (file_exists($currentPath)) {
                if (is_link($currentPath)) {
                    return true;
                }
            } else {
                // Stop checking once we reach a non-existent segment
                break;
            }
        }

        return false;
    }

    /**
     * Persist quick state atomically and mirror it into migration_state.
     *
     * @param array $quickState
     * @return bool
     */
    private function persistQuickState(array $quickState): bool
    {
        $tempFile = $this->stateFile . '.tmp';
        $encoded = json_encode($quickState);
        if ($encoded === false) {
            return false;
        }

        file_put_contents($tempFile, $encoded);
        rename($tempFile, $this->stateFile);

        return $this->migrationStateService->saveMigrationState([
            'migrationId' => $quickState['migration_id'],
            'phase' => $quickState['phase'] ?? 'unknown',
            'status' => $quickState['status'] ?? self::DEFAULT_STATUS,
            'processedCount' => $quickState['processed_count'] ?? count($quickState['processed_ids'] ?? []),
            'totalCount' => $quickState['total_count'] ?? 0,
            'currentBatch' => $quickState['batch'] ?? 0,
            'processedIds' => $quickState['processed_ids'] ?? [],
            'stats' => $quickState['stats'] ?? [],
            'checkpointFile' => $quickState['checkpoint_file'] ?? null,
            'errorMessage' => $quickState['error'] ?? null,
        ]);
    }

    /**
     * Decode a resumable state file.
     *
     * @param string $file
     * @return array|null
     */
    private static function decodeStateFile(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Normalize checkpoint/quick-state metadata for dashboard use.
     *
     * @param string $file
     * @param array $decoded
     * @param string $source
     * @return array
     */
    private static function formatResumableState(string $file, array $decoded, string $source): array
    {
        $migrationId = $decoded['migration_id'] ?? $decoded['migrationId'] ?? basename($file, $source === 'quick_state' ? '.state.json' : '.json');
        $processedIds = $decoded['processed_ids'] ?? $decoded['processedIds'] ?? [];
        if (!is_array($processedIds)) {
            $processedIds = [];
        }

        $modifiedAt = filemtime($file) ?: 0;

        return [
            'id' => basename($file, $source === 'quick_state' ? '.state.json' : '.json'),
            'migration_id' => $migrationId,
            'migrationId' => $migrationId,
            'phase' => $decoded['phase'] ?? 'unknown',
            'timestamp' => $decoded['timestamp'] ?? date('Y-m-d H:i:s', $modifiedAt),
            'processed' => count($processedIds),
            'processedCount' => count($processedIds),
            'batch' => $decoded['batch'] ?? 0,
            'status' => $decoded['status'] ?? self::DEFAULT_STATUS,
            'checkpointId' => $source === 'checkpoint' ? basename($file, '.json') : null,
            'source' => $source,
            'file' => $file,
            'modifiedAt' => $modifiedAt,
        ];
    }
}
