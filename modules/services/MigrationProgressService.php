<?php

namespace csabourin\craftS3SpacesMigration\services;

use Craft;
use craft\helpers\FileHelper;

/**
 * Manages persistent dashboard progress state
 */
class MigrationProgressService
{
    private const STATE_FILENAME = 'migration-dashboard-progress.json';

    private string $storageDirectory;

    public function __construct(?string $storageDirectory = null)
    {
        $this->storageDirectory = $storageDirectory ?? Craft::getAlias('@storage/migration-dashboard');
    }

    /**
     * Retrieve persisted state information
     */
    public function getState(): array
    {
        $this->ensureStorageDirectory();
        $stateFile = $this->getStateFilePath();

        if (!is_file($stateFile)) {
            return [
                'completedModules' => [],
                'runningModules' => [],
                'failedModules' => [],
                'moduleStates' => [],
                'updatedAt' => null,
            ];
        }

        $contents = @file_get_contents($stateFile);
        if ($contents === false) {
            return [
                'completedModules' => [],
                'runningModules' => [],
                'failedModules' => [],
                'moduleStates' => [],
                'updatedAt' => null,
            ];
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return [
                'completedModules' => [],
                'runningModules' => [],
                'failedModules' => [],
                'moduleStates' => [],
                'updatedAt' => null,
            ];
        }

        $completedModules = $data['completedModules'] ?? [];
        if (!is_array($completedModules)) {
            $completedModules = [];
        }

        $runningModules = $data['runningModules'] ?? [];
        if (!is_array($runningModules)) {
            $runningModules = [];
        }

        $failedModules = $data['failedModules'] ?? [];
        if (!is_array($failedModules)) {
            $failedModules = [];
        }

        $moduleStates = $data['moduleStates'] ?? [];
        if (!is_array($moduleStates)) {
            $moduleStates = [];
        }

        return [
            'completedModules' => array_values(array_unique(array_map('strval', $completedModules))),
            'runningModules' => array_values(array_unique(array_map('strval', $runningModules))),
            'failedModules' => array_values(array_unique(array_map('strval', $failedModules))),
            'moduleStates' => $moduleStates,
            'updatedAt' => $data['updatedAt'] ?? null,
        ];
    }

    /**
     * Persist completed module identifiers
     */
    public function persistCompletedModules(array $modules): bool
    {
        $this->ensureStorageDirectory();

        $normalized = [];
        foreach ($modules as $moduleId) {
            if (is_string($moduleId) && $moduleId !== '') {
                $normalized[$moduleId] = true;
            }
        }

        $state = [
            'completedModules' => array_keys($normalized),
            'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return (bool) @file_put_contents($this->getStateFilePath(), $json, LOCK_EX);
    }

    /**
     * Persist module state (running, completed, failed)
     */
    public function persistModuleState(array $completedModules, array $runningModules, array $failedModules, array $moduleStates = []): bool
    {
        $this->ensureStorageDirectory();

        $state = [
            'completedModules' => array_values(array_unique(array_filter($completedModules, 'is_string'))),
            'runningModules' => array_values(array_unique(array_filter($runningModules, 'is_string'))),
            'failedModules' => array_values(array_unique(array_filter($failedModules, 'is_string'))),
            'moduleStates' => $moduleStates,
            'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return (bool) @file_put_contents($this->getStateFilePath(), $json, LOCK_EX);
    }

    /**
     * Update module state for a specific module
     */
    public function updateModuleStatus(string $moduleId, string $status, ?string $error = null): bool
    {
        $this->ensureStorageDirectory();

        // Load current state
        $currentState = $this->getState();

        // Remove from all arrays
        $completedModules = array_diff($currentState['completedModules'], [$moduleId]);
        $runningModules = array_diff($currentState['runningModules'], [$moduleId]);
        $failedModules = array_diff($currentState['failedModules'], [$moduleId]);
        $moduleStates = $currentState['moduleStates'];

        // Add to appropriate array and update state
        switch ($status) {
            case 'running':
                $runningModules[] = $moduleId;
                $moduleStates[$moduleId] = [
                    'status' => 'running',
                    'startedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ];
                break;
            case 'completed':
                $completedModules[] = $moduleId;
                $moduleStates[$moduleId] = [
                    'status' => 'completed',
                    'completedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ];
                break;
            case 'failed':
                $failedModules[] = $moduleId;
                $moduleStates[$moduleId] = [
                    'status' => 'failed',
                    'failedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'error' => $error,
                ];
                break;
            default:
                return false;
        }

        return $this->persistModuleState(
            array_values($completedModules),
            array_values($runningModules),
            array_values($failedModules),
            $moduleStates
        );
    }

    /**
     * Remove persisted state if older than the provided age
     */
    public function purgeOldState(int $maxAgeSeconds = 604800): bool
    {
        $stateFile = $this->getStateFilePath();
        if (!is_file($stateFile)) {
            return false;
        }

        $modified = @filemtime($stateFile);
        if ($modified === false) {
            return false;
        }

        if ((time() - $modified) >= $maxAgeSeconds) {
            return $this->purgeState();
        }

        return false;
    }

    /**
     * Force removal of the persisted state file
     */
    public function purgeState(): bool
    {
        $stateFile = $this->getStateFilePath();
        if (!is_file($stateFile)) {
            return false;
        }

        try {
            FileHelper::unlink($stateFile);
            return true;
        } catch (\Throwable $e) {
            Craft::error('Failed to remove migration dashboard state: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    private function ensureStorageDirectory(): void
    {
        try {
            FileHelper::createDirectory($this->storageDirectory);
        } catch (\Throwable $e) {
            Craft::error('Failed to create migration dashboard storage directory: ' . $e->getMessage(), __METHOD__);
        }
    }

    private function getStateFilePath(): string
    {
        return $this->storageDirectory . DIRECTORY_SEPARATOR . self::STATE_FILENAME;
    }
}
