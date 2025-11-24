<?php

namespace csabourin\spaghettiMigrator\services;

use Craft;

/**
 * MigrationLock Service
 * Provides distributed locking mechanism for migration operations
 */
class MigrationLock
{
    private $lockName;
    private $migrationId;
    private $isLocked = false;
    private $lockTimeout = 43200; // 12 hours

    public function __construct($migrationId)
    {
        $this->migrationId = $migrationId;
        $this->lockName = 'migration_lock';
    }

    /**
     * Acquire lock - allows same migration to resume
     * Uses database transactions with FOR UPDATE to prevent race conditions
     */
    public function acquire($timeout = 3, $isResume = false): bool
    {
        $db = Craft::$app->getDb();

        // Ensure lock table exists first
        $this->ensureLockTable($db);

        // Clean stale locks first
        $this->cleanStaleLocks($db);

        $startTime = time();

        while (time() - $startTime < $timeout) {
            $transaction = null;

            try {
                // Start transaction to ensure atomicity
                $transaction = $db->beginTransaction();

                // Use FOR UPDATE to lock the row for reading (prevents race conditions)
                // This will block other transactions from reading this row until we commit
                $existingLock = $db->createCommand('
                    SELECT migrationId, lockedAt, expiresAt
                    FROM {{%migrationlocks}}
                    WHERE lockName = :lockName
                    FOR UPDATE
                ', [':lockName' => $this->lockName])->queryOne();

                if ($existingLock) {
                    $expiresAt = strtotime($existingLock['expiresAt']);

                    // Check if lock is expired
                    if ($expiresAt < time()) {
                        // Lock expired, delete it and create new one
                        $db->createCommand('
                            DELETE FROM {{%migrationlocks}}
                            WHERE lockName = :lockName
                        ', [':lockName' => $this->lockName])->execute();

                        // Insert new lock
                        $db->createCommand()->insert('{{%migrationlocks}}', [
                            'lockName' => $this->lockName,
                            'migrationId' => $this->migrationId,
                            'lockedAt' => date('Y-m-d H:i:s'),
                            'lockedBy' => gethostname() . ':' . getmypid(),
                            'expiresAt' => date('Y-m-d H:i:s', time() + $this->lockTimeout)
                        ])->execute();

                        $transaction->commit();
                        $this->isLocked = true;
                        return true;
                    }

                    // If resuming the same migration, that's OK
                    if ($isResume && $existingLock['migrationId'] === $this->migrationId) {
                        // Update the lock with new expiry
                        $db->createCommand('
                            UPDATE {{%migrationlocks}}
                            SET lockedAt = :lockedAt,
                                lockedBy = :lockedBy,
                                expiresAt = :expiresAt
                            WHERE lockName = :lockName
                        ', [
                            ':lockedAt' => date('Y-m-d H:i:s'),
                            ':lockedBy' => gethostname() . ':' . getmypid(),
                            ':expiresAt' => date('Y-m-d H:i:s', time() + $this->lockTimeout),
                            ':lockName' => $this->lockName
                        ])->execute();

                        $transaction->commit();
                        $this->isLocked = true;
                        return true;
                    }

                    // Different migration is still running - rollback and wait
                    $transaction->rollBack();
                    usleep(500000);
                    continue;
                }

                // No lock exists - create one
                $db->createCommand()->insert('{{%migrationlocks}}', [
                    'lockName' => $this->lockName,
                    'migrationId' => $this->migrationId,
                    'lockedAt' => date('Y-m-d H:i:s'),
                    'lockedBy' => gethostname() . ':' . getmypid(),
                    'expiresAt' => date('Y-m-d H:i:s', time() + $this->lockTimeout)
                ])->execute();

                $transaction->commit();
                $this->isLocked = true;
                return true;

            } catch (\yii\db\IntegrityException $e) {
                // Race condition despite FOR UPDATE (shouldn't happen but handle it)
                if ($transaction !== null && $transaction->getIsActive()) {
                    $transaction->rollBack();
                }
                usleep(500000);
                continue;
            } catch (\Exception $e) {
                // Rollback on any error
                if ($transaction !== null && $transaction->getIsActive()) {
                    $transaction->rollBack();
                }

                Craft::error("Failed to acquire lock: " . $e->getMessage(), __METHOD__);
                usleep(500000);
                continue;
            }
        }

        return false;
    }

    /**
     * Refresh lock to prevent timeout during long operations
     */
    public function refresh(): bool
    {
        if (!$this->isLocked) {
            return false;
        }

        try {
            $db = Craft::$app->getDb();
            $db->createCommand('
            UPDATE {{%migrationlocks}}
            SET expiresAt = :expiresAt
            WHERE lockName = :lockName AND migrationId = :migrationId
        ', [
                ':expiresAt' => date('Y-m-d H:i:s', time() + $this->lockTimeout),
                ':lockName' => $this->lockName,
                ':migrationId' => $this->migrationId
            ])->execute();

            return true;
        } catch (\Exception $e) {
            Craft::error("Failed to refresh migration lock: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    private function cleanStaleLocks($db): void
    {
        try {
            $db->createCommand('
            DELETE FROM {{%migrationlocks}}
            WHERE expiresAt < :now
        ', [':now' => date('Y-m-d H:i:s')])->execute();
        } catch (\Exception $e) {
            // Ignore if table doesn't exist
        }
    }

    public function release(): void
    {
        if (!$this->isLocked) {
            return;
        }

        try {
            $db = Craft::$app->getDb();
            $db->createCommand('
            DELETE FROM {{%migrationlocks}}
            WHERE lockName = :lockName AND migrationId = :migrationId
        ', [
                ':lockName' => $this->lockName,
                ':migrationId' => $this->migrationId
            ])->execute();
        } catch (\Exception $e) {
            Craft::error("Failed to release migration lock: " . $e->getMessage(), __METHOD__);
        }

        $this->isLocked = false;
    }

    private function ensureLockTable($db): bool
    {
        try {
            $db->createCommand("
                CREATE TABLE IF NOT EXISTS {{%migrationlocks}} (
                    lockName VARCHAR(255) PRIMARY KEY,
                    migrationId VARCHAR(255) NOT NULL,
                    lockedAt DATETIME NOT NULL,
                    lockedBy VARCHAR(255) NOT NULL,
                    expiresAt DATETIME NOT NULL,
                    INDEX idx_expires (expiresAt),
                    INDEX idx_migration (migrationId)
                )
            ")->execute();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
