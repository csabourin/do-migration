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
                'updatedAt' => null,
            ];
        }

        $contents = @file_get_contents($stateFile);
        if ($contents === false) {
            return [
                'completedModules' => [],
                'updatedAt' => null,
            ];
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return [
                'completedModules' => [],
                'updatedAt' => null,
            ];
        }

        $completedModules = $data['completedModules'] ?? [];
        if (!is_array($completedModules)) {
            $completedModules = [];
        }

        return [
            'completedModules' => array_values(array_unique(array_map('strval', $completedModules))),
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
