<?php

namespace csabourin\craftS3SpacesMigration\services\migration;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use csabourin\craftS3SpacesMigration\services\CheckpointManager;

/**
 * Backup Service
 *
 * Handles all backup operations for migration safety including:
 * - Database table backups
 * - SQL dump creation
 * - Phase 1 results persistence
 * - Backup verification
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class BackupService
{
    /**
     * @var Controller Controller instance for output
     */
    private $controller;

    /**
     * @var CheckpointManager Checkpoint manager
     */
    private $checkpointManager;

    /**
     * @var MigrationReporter Reporter for output
     */
    private $reporter;

    /**
     * @var string Migration ID
     */
    private $migrationId;

    /**
     * @var array Source volume handles
     */
    private $sourceVolumeHandles;

    /**
     * @var string Target volume handle
     */
    private $targetVolumeHandle;

    /**
     * @var string Quarantine volume handle
     */
    private $quarantineVolumeHandle;

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param CheckpointManager $checkpointManager Checkpoint manager
     * @param MigrationReporter $reporter Reporter for output
     * @param string $migrationId Migration ID
     * @param array $sourceVolumeHandles Source volume handles
     * @param string $targetVolumeHandle Target volume handle
     * @param string $quarantineVolumeHandle Quarantine volume handle
     */
    public function __construct(
        Controller $controller,
        CheckpointManager $checkpointManager,
        MigrationReporter $reporter,
        string $migrationId,
        array $sourceVolumeHandles,
        string $targetVolumeHandle,
        string $quarantineVolumeHandle
    ) {
        $this->controller = $controller;
        $this->checkpointManager = $checkpointManager;
        $this->reporter = $reporter;
        $this->migrationId = $migrationId;
        $this->sourceVolumeHandles = $sourceVolumeHandles;
        $this->targetVolumeHandle = $targetVolumeHandle;
        $this->quarantineVolumeHandle = $quarantineVolumeHandle;
    }

    /**
     * Create comprehensive backup
     *
     * Creates both table-level backups and SQL dump for complete safety.
     */
    public function createBackup(): void
    {
        $this->controller->stdout("  Creating automatic database backup...\n");

        $timestamp = date('YmdHis');
        $db = Craft::$app->getDb();

        // Method 1: Create backup tables (fast, for quick rollback)
        $tables = ['assets', 'volumefolders', 'relations', 'elements'];
        $backupSuccess = true;
        $tableBackupCount = 0;

        foreach ($tables as $table) {
            try {
                // Check if table exists first
                $tableExists = $db->createCommand("SHOW TABLES LIKE '{$table}'")->queryScalar();

                if (!$tableExists) {
                    $this->controller->stdout("    ⓘ Table '{$table}' does not exist, skipping\n", Console::FG_CYAN);
                    continue;
                }

                // Get row count for verification
                $rowCount = $db->createCommand("SELECT COUNT(*) FROM {$table}")->queryScalar();

                // Create backup table
                $db->createCommand("
                    CREATE TABLE IF NOT EXISTS {$table}_backup_{$timestamp}
                    AS SELECT * FROM {$table}
                ")->execute();

                // Verify backup was created successfully
                $backupRowCount = $db->createCommand("SELECT COUNT(*) FROM {$table}_backup_{$timestamp}")->queryScalar();

                if ($backupRowCount == $rowCount) {
                    $this->controller->stdout("    ✓ Backed up {$table} ({$rowCount} rows)\n", Console::FG_GREEN);
                    $tableBackupCount++;
                } else {
                    $this->controller->stdout("    ⚠ Warning: {$table} backup row count mismatch (original: {$rowCount}, backup: {$backupRowCount})\n", Console::FG_YELLOW);
                    $backupSuccess = false;
                }
            } catch (\Exception $e) {
                $this->controller->stdout("    ✗ Error backing up {$table}: " . $e->getMessage() . "\n", Console::FG_RED);
                $backupSuccess = false;
            }
        }

        // Method 2: Create SQL dump file (complete backup for restore)
        $backupFile = $this->createDatabaseBackup();

        if ($backupFile && file_exists($backupFile)) {
            $fileSize = $this->reporter->formatBytes(filesize($backupFile));
            $this->controller->stdout("  ✓ SQL dump created: " . basename($backupFile) . " ({$fileSize})\n", Console::FG_GREEN);

            // Verify backup file is not empty
            if (filesize($backupFile) < 100) {
                $this->controller->stdout("  ⚠ WARNING: Backup file seems unusually small, may be corrupt\n", Console::FG_YELLOW);
                $backupSuccess = false;
            }

            // Store backup location in checkpoint
            $this->checkpointManager->saveCheckpoint([
                'backup_timestamp' => $timestamp,
                'backup_file' => $backupFile,
                'backup_tables' => $tables,
                'backup_verified' => $backupSuccess
            ]);
        } else {
            $this->controller->stdout("  ⚠ SQL dump creation failed (will use table backups only)\n", Console::FG_YELLOW);
        }

        if ($backupSuccess && $tableBackupCount > 0) {
            $this->controller->stdout("  ✓ Backup verification passed ({$tableBackupCount} tables backed up)\n", Console::FG_GREEN);
        } else {
            $this->controller->stdout("  ⚠ WARNING: Backup verification had issues - proceed with caution\n", Console::FG_YELLOW);
        }

        $this->controller->stdout("\n");
    }

    /**
     * Create database backup using mysqldump or Craft backup
     *
     * @return string|null Path to backup file, or null if backup failed
     */
    private function createDatabaseBackup(): ?string
    {
        try {
            $backupDir = Craft::getAlias('@storage/migration-backups');
            if (!is_dir($backupDir)) {
                FileHelper::createDirectory($backupDir);
            }

            $backupFile = $backupDir . '/migration_' . $this->migrationId . '_db_backup.sql';

            $db = Craft::$app->getDb();
            $dsn = $db->dsn;

            // Parse DSN to get database name, host, port
            if (preg_match('/dbname=([^;]+)/', $dsn, $matches)) {
                $dbName = $matches[1];
            } else {
                return null;
            }

            if (preg_match('/host=([^;]+)/', $dsn, $matches)) {
                $host = $matches[1];
            } else {
                $host = 'localhost';
            }

            if (preg_match('/port=([^;]+)/', $dsn, $matches)) {
                $port = $matches[1];
            } else {
                $port = '3306';
            }

            $username = $db->username;
            $password = $db->password;

            // Tables to backup
            $tables = ['assets', 'volumefolders', 'relations', 'elements', 'elements_sites', 'content'];
            $tablesStr = implode(' ', $tables);

            // Try mysqldump
            $mysqldumpCmd = sprintf(
                'mysqldump -h %s -P %s -u %s %s %s %s > %s 2>&1',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                $password ? '-p' . escapeshellarg($password) : '',
                escapeshellarg($dbName),
                $tablesStr,
                escapeshellarg($backupFile)
            );

            exec($mysqldumpCmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
                return $backupFile;
            }

            // Fallback: Use Craft's backup if mysqldump not available
            return $this->createCraftBackup($tables, $backupFile);

        } catch (\Exception $e) {
            Craft::error("Database backup failed: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Create backup using Craft's database backup functionality
     *
     * @param array $tables Tables to backup
     * @param string $backupFile Target file path
     * @return string|null Path to backup file, or null if backup failed
     */
    private function createCraftBackup(array $tables, string $backupFile): ?string
    {
        try {
            $db = Craft::$app->getDb();
            $sql = '';

            foreach ($tables as $table) {
                // Export table structure
                $createTable = $db->createCommand("SHOW CREATE TABLE `{$table}`")->queryOne();
                if ($createTable) {
                    $sql .= "\n-- Table: {$table}\n";
                    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                    $sql .= $createTable['Create Table'] . ";\n\n";
                }

                // Export table data
                $rows = $db->createCommand("SELECT * FROM `{$table}`")->queryAll();
                if (!empty($rows)) {
                    $sql .= "-- Data for table: {$table}\n";

                    foreach ($rows as $row) {
                        $values = array_map(function ($value) use ($db) {
                            if ($value === null) {
                                return 'NULL';
                            }
                            return $db->quoteValue($value);
                        }, array_values($row));

                        $sql .= "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
                    }

                    $sql .= "\n";
                }
            }

            file_put_contents($backupFile, $sql);

            if (file_exists($backupFile) && filesize($backupFile) > 0) {
                return $backupFile;
            }

            return null;

        } catch (\Exception $e) {
            Craft::error("Craft backup failed: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Ensure phase 1 results table exists
     */
    public function ensurePhase1ResultsTable(): void
    {
        $db = Craft::$app->getDb();

        try {
            // Check if table exists
            $tableExists = $db->createCommand("SHOW TABLES LIKE '{{%migration_phase1_results}}'")->queryScalar();

            if (!$tableExists) {
                $db->createCommand("
                    CREATE TABLE {{%migration_phase1_results}} (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        migrationId VARCHAR(255) NOT NULL UNIQUE,
                        assetInventory LONGTEXT NOT NULL,
                        fileInventory LONGTEXT NOT NULL,
                        analysis LONGTEXT NOT NULL,
                        metadata LONGTEXT NULL,
                        createdAt DATETIME NOT NULL,
                        INDEX idx_migration (migrationId),
                        INDEX idx_created (createdAt)
                    )
                ")->execute();

                Craft::info("Created migration_phase1_results table", __METHOD__);
            } else {
                // Add metadata column if it doesn't exist (backwards compatibility)
                $columnExists = $db->createCommand("
                    SHOW COLUMNS FROM {{%migration_phase1_results}} LIKE 'metadata'
                ")->queryScalar();

                if (!$columnExists) {
                    $db->createCommand("
                        ALTER TABLE {{%migration_phase1_results}}
                        ADD COLUMN metadata LONGTEXT NULL AFTER analysis
                    ")->execute();

                    Craft::info("Added metadata column to migration_phase1_results table", __METHOD__);
                }
            }
        } catch (\Exception $e) {
            Craft::warning("Could not create/update phase1 results table: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Save phase 1 discovery results to database
     *
     * @param array $assetInventory Asset inventory
     * @param array $fileInventory File inventory
     * @param array $analysis Analysis results
     */
    public function savePhase1Results(array $assetInventory, array $fileInventory, array $analysis): void
    {
        $this->ensurePhase1ResultsTable();

        $db = Craft::$app->getDb();

        try {
            // Delete existing results for this migration (if any)
            $db->createCommand()
                ->delete('{{%migration_phase1_results}}', ['migrationId' => $this->migrationId])
                ->execute();

            // Store metadata about the migration configuration
            $metadata = [
                'targetVolumeHandle' => $this->targetVolumeHandle,
                'sourceVolumeHandles' => $this->sourceVolumeHandles,
                'quarantineVolumeHandle' => $this->quarantineVolumeHandle,
                'timestamp' => date('Y-m-d H:i:s'),
                'note' => 'Quarantine only processes target volume files'
            ];

            // Insert new results with enhanced context
            $db->createCommand()
                ->insert('{{%migration_phase1_results}}', [
                    'migrationId' => $this->migrationId,
                    'assetInventory' => json_encode($assetInventory),
                    'fileInventory' => json_encode($fileInventory),
                    'analysis' => json_encode($analysis),
                    'metadata' => json_encode($metadata),
                    'createdAt' => date('Y-m-d H:i:s')
                ])
                ->execute();

            $this->controller->stdout("  ✓ Phase 1 results saved to database (with migration context)\n", Console::FG_GREEN);

        } catch (\Exception $e) {
            // Non-fatal - log and continue
            Craft::warning("Could not save phase 1 results to database: " . $e->getMessage(), __METHOD__);
            $this->controller->stdout("  ⚠ Could not save phase 1 results to database\n", Console::FG_YELLOW);
        }
    }

    /**
     * Load phase 1 discovery results from database
     *
     * @return array|null Phase 1 results or null if not found
     */
    public function loadPhase1Results(): ?array
    {
        $this->ensurePhase1ResultsTable();

        $db = Craft::$app->getDb();

        try {
            $row = $db->createCommand()
                ->select(['assetInventory', 'fileInventory', 'analysis'])
                ->from('{{%migration_phase1_results}}')
                ->where(['migrationId' => $this->migrationId])
                ->queryOne();

            if ($row) {
                return [
                    'assetInventory' => json_decode($row['assetInventory'], true),
                    'fileInventory' => json_decode($row['fileInventory'], true),
                    'analysis' => json_decode($row['analysis'], true)
                ];
            }
        } catch (\Exception $e) {
            Craft::warning("Could not load phase 1 results from database: " . $e->getMessage(), __METHOD__);
        }

        return null;
    }
}
