<?php

namespace csabourin\craftS3SpacesMigration\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use yii\db\Exception;

/**
 * Migration State Service
 * Manages persistent migration state in the database for recovery after page refresh
 */
class MigrationStateService
{
    /**
     * Create or update migration state
     */
    public function saveMigrationState(array $data): bool
    {
        $migrationId = $data['migrationId'] ?? $data['migration_id'] ?? null;

        if (!$migrationId) {
            Craft::error('Cannot save migration state: migrationId is required', __METHOD__);
            return false;
        }

        $now = Db::prepareDateForDb(new \DateTime());

        // Check if state already exists
        $existingState = $this->getMigrationState($migrationId);

        $stateData = [
            'migrationId' => $migrationId,
            'sessionId' => $data['sessionId'] ?? null,
            'phase' => $data['phase'] ?? 'unknown',
            'status' => $data['status'] ?? 'running',
            'pid' => $data['pid'] ?? null,
            'command' => $data['command'] ?? null,
            'processedCount' => $data['processedCount'] ?? $data['processed_count'] ?? 0,
            'totalCount' => $data['totalCount'] ?? $data['total_count'] ?? 0,
            'currentBatch' => $data['currentBatch'] ?? $data['batch'] ?? 0,
            'processedIds' => is_array($data['processedIds'] ?? $data['processed_ids'] ?? [])
                ? json_encode($data['processedIds'] ?? $data['processed_ids'])
                : ($data['processedIds'] ?? $data['processed_ids'] ?? '[]'),
            'stats' => is_array($data['stats'] ?? [])
                ? json_encode($data['stats'])
                : ($data['stats'] ?? '{}'),
            'errorMessage' => $data['errorMessage'] ?? $data['error'] ?? null,
            'checkpointFile' => $data['checkpointFile'] ?? null,
            'lastUpdatedAt' => $now,
            'dateUpdated' => $now,
        ];

        try {
            if ($existingState) {
                // Update existing state
                Craft::$app->getDb()->createCommand()
                    ->update('{{%migration_state}}', $stateData, ['migrationId' => $migrationId])
                    ->execute();
            } else {
                // Insert new state
                $stateData['startedAt'] = $data['startedAt'] ?? $now;
                $stateData['dateCreated'] = $now;

                Craft::$app->getDb()->createCommand()
                    ->insert('{{%migration_state}}', $stateData)
                    ->execute();
            }

            return true;
        } catch (Exception $e) {
            Craft::error('Failed to save migration state: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Get migration state by ID
     */
    public function getMigrationState(string $migrationId): ?array
    {
        $result = (new Query())
            ->select('*')
            ->from('{{%migration_state}}')
            ->where(['migrationId' => $migrationId])
            ->one();

        if (!$result) {
            return null;
        }

        // Decode JSON fields
        if (!empty($result['processedIds'])) {
            $result['processedIds'] = json_decode($result['processedIds'], true) ?? [];
        } else {
            $result['processedIds'] = [];
        }

        if (!empty($result['stats'])) {
            $result['stats'] = json_decode($result['stats'], true) ?? [];
        } else {
            $result['stats'] = [];
        }

        return $result;
    }

    /**
     * Get all running migrations
     */
    public function getRunningMigrations(): array
    {
        $results = (new Query())
            ->select('*')
            ->from('{{%migration_state}}')
            ->where(['status' => 'running'])
            ->orderBy(['lastUpdatedAt' => SORT_DESC])
            ->all();

        foreach ($results as &$result) {
            // Decode JSON fields
            if (!empty($result['processedIds'])) {
                $result['processedIds'] = json_decode($result['processedIds'], true) ?? [];
            } else {
                $result['processedIds'] = [];
            }

            if (!empty($result['stats'])) {
                $result['stats'] = json_decode($result['stats'], true) ?? [];
            } else {
                $result['stats'] = [];
            }

            // Check if process is still running
            $result['isProcessRunning'] = $this->isProcessRunning($result['pid'] ?? null);
        }

        return $results;
    }

    /**
     * Get the latest migration (running or most recent)
     */
    public function getLatestMigration(): ?array
    {
        // First try to find a running migration
        $running = $this->getRunningMigrations();
        if (!empty($running)) {
            return $running[0];
        }

        // Otherwise get the most recent one
        $result = (new Query())
            ->select('*')
            ->from('{{%migration_state}}')
            ->orderBy(['lastUpdatedAt' => SORT_DESC])
            ->one();

        if (!$result) {
            return null;
        }

        // Decode JSON fields
        if (!empty($result['processedIds'])) {
            $result['processedIds'] = json_decode($result['processedIds'], true) ?? [];
        } else {
            $result['processedIds'] = [];
        }

        if (!empty($result['stats'])) {
            $result['stats'] = json_decode($result['stats'], true) ?? [];
        } else {
            $result['stats'] = [];
        }

        $result['isProcessRunning'] = $this->isProcessRunning($result['pid'] ?? null);

        return $result;
    }

    /**
     * Update migration status
     */
    public function updateMigrationStatus(string $migrationId, string $status, ?string $errorMessage = null): bool
    {
        $now = Db::prepareDateForDb(new \DateTime());

        $data = [
            'status' => $status,
            'lastUpdatedAt' => $now,
            'dateUpdated' => $now,
        ];

        if ($errorMessage) {
            $data['errorMessage'] = $errorMessage;
        }

        if ($status === 'completed' || $status === 'failed') {
            $data['completedAt'] = $now;
        }

        try {
            Craft::$app->getDb()->createCommand()
                ->update('{{%migration_state}}', $data, ['migrationId' => $migrationId])
                ->execute();

            return true;
        } catch (Exception $e) {
            Craft::error('Failed to update migration status: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Cleanup old migration states
     */
    public function cleanupOldStates(int $olderThanDays = 7): int
    {
        $cutoffDate = Db::prepareDateForDb(new \DateTime("-{$olderThanDays} days"));

        try {
            return Craft::$app->getDb()->createCommand()
                ->delete('{{%migration_state}}', [
                    'and',
                    ['in', 'status', ['completed', 'failed']],
                    ['<', 'completedAt', $cutoffDate]
                ])
                ->execute();
        } catch (Exception $e) {
            Craft::error('Failed to cleanup old migration states: ' . $e->getMessage(), __METHOD__);
            return 0;
        }
    }

    /**
     * Check if a process is still running
     */
    private function isProcessRunning(?int $pid): bool
    {
        if (!$pid) {
            return false;
        }

        // Check if process exists
        if (function_exists('posix_kill')) {
            // posix_kill with signal 0 checks if process exists without killing it
            return @posix_kill($pid, 0);
        }

        // Fallback for systems without posix
        if (file_exists("/proc/$pid")) {
            return true;
        }

        // Try ps command as last resort
        exec("ps -p $pid", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Ensure the migration_state table exists
     */
    public function ensureTableExists(): bool
    {
        $db = Craft::$app->getDb();

        if ($db->tableExists('{{%migration_state}}')) {
            return true;
        }

        // Run the install
        try {
            $install = new \csabourin\craftS3SpacesMigration\Install();
            $install->db = $db;
            return $install->safeUp();
        } catch (\Throwable $e) {
            Craft::error('Failed to create migration_state table: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
