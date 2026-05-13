<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use csabourin\spaghettiMigrator\services\CheckpointManager;

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
        $tableHandles = ['assets', 'volumefolders', 'relations', 'elements'];
        $backupSuccess = true;
        $tableBackupCount = 0;
        $schema = $db->getSchema();

        foreach ($tableHandles as $handle) {
            $prefixedTable = '{{%' . $handle . '}}';
            $rawName = $schema->getRawTableName($prefixedTable);
            $backupRawName = $rawName . '_backup_' . $timestamp;

            try {
                // Portable table-existence check via schema introspection
                if ($schema->getTableSchema($prefixedTable) === null) {
                    $this->controller->stdout("    ⓘ Table '{$handle}' does not exist, skipping\n", Console::FG_CYAN);
                    continue;
                }

                // Get row count for verification
                $rowCount = $db->createCommand(
                    'SELECT COUNT(*) FROM ' . $db->quoteTableName($rawName)
                )->queryScalar();

                // Create backup table (using quoted names — never interpolate raw user data)
                $db->createCommand(
                    'CREATE TABLE IF NOT EXISTS ' . $db->quoteTableName($backupRawName) .
                    ' AS SELECT * FROM ' . $db->quoteTableName($rawName)
                )->execute();

                // Verify backup was created successfully
                $backupRowCount = $db->createCommand(
                    'SELECT COUNT(*) FROM ' . $db->quoteTableName($backupRawName)
                )->queryScalar();

                if ($backupRowCount == $rowCount) {
                    $this->controller->stdout("    ✓ Backed up {$handle} ({$rowCount} rows)\n", Console::FG_GREEN);
                    $tableBackupCount++;
                } else {
                    $this->controller->stdout("    ⚠ Warning: {$handle} backup row count mismatch (original: {$rowCount}, backup: {$backupRowCount})\n", Console::FG_YELLOW);
                    $backupSuccess = false;
                }
            } catch (\Exception $e) {
                $this->controller->stdout("    ✗ Error backing up {$handle}: " . $e->getMessage() . "\n", Console::FG_RED);
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

            // Store backup metadata in a separate file — NOT via saveCheckpoint() which
            // would overwrite the migration orchestrator's phase/processed_ids state.
            $this->saveBackupMetadata($timestamp, $backupFile, $tableHandles, $backupSuccess);
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

            // Resolve actual (prefixed) table names for mysqldump
            $tableHandles = ['assets', 'volumefolders', 'relations', 'elements', 'elements_sites', 'content'];
            $schema = $db->getSchema();
            $tables = array_map(
                fn($h) => $schema->getRawTableName('{{%' . $h . '}}'),
                $tableHandles
            );
            $tableArgs = implode(' ', array_map('escapeshellarg', $tables));

            // Use a temp credentials file so the password is never on the command line
            $configFile = null;
            $returnCode = 1;
            try {
                $configFile = sys_get_temp_dir() . '/mysql_' . uniqid() . '.cnf';
                touch($configFile);
                chmod($configFile, 0600);

                $configContent = "[client]\n";
                $configContent .= "user=" . $username . "\n";
                if ($password) {
                    $configContent .= "password=" . $password . "\n";
                }
                $configContent .= "host=" . $host . "\n";
                $configContent .= "port=" . $port . "\n";
                file_put_contents($configFile, $configContent);

                // Redirect stderr to a separate file so errors never corrupt the SQL dump
                $errFile = $backupFile . '.err';
                $mysqldumpCmd = sprintf(
                    'mysqldump --defaults-extra-file=%s %s %s > %s 2>%s',
                    escapeshellarg($configFile),
                    escapeshellarg($dbName),
                    $tableArgs,
                    escapeshellarg($backupFile),
                    escapeshellarg($errFile)
                );

                exec($mysqldumpCmd, $output, $returnCode);

                // Log mysqldump errors if any
                if (file_exists($errFile)) {
                    $errContent = trim(file_get_contents($errFile));
                    if ($errContent !== '') {
                        Craft::warning("mysqldump warnings: " . $errContent, __METHOD__);
                    }
                    @unlink($errFile);
                }
            } finally {
                if ($configFile && file_exists($configFile)) {
                    $size = filesize($configFile);
                    if ($size > 0) {
                        file_put_contents($configFile, str_repeat("\0", $size));
                    }
                    @unlink($configFile);
                }
            }

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
            $handle = fopen($backupFile, 'w');
            if (!$handle) {
                throw new \Exception("Cannot open backup file for writing: {$backupFile}");
            }

            try {
                foreach ($tables as $table) {
                    $quotedTable = $db->quoteTableName($table);

                    // Export table structure (MySQL only; skipped on other drivers)
                    if ($db->getDriverName() === 'mysql') {
                        $createTable = $db->createCommand("SHOW CREATE TABLE {$quotedTable}")->queryOne();
                        if ($createTable) {
                            fwrite($handle, "\n-- Table: {$table}\n");
                            fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n");
                            fwrite($handle, ($createTable['Create Table'] ?? '') . ";\n\n");
                        }
                    }

                    // Export table data in batches to avoid loading entire table into RAM
                    fwrite($handle, "-- Data for table: {$table}\n");
                    $batchSize = 500;
                    $offset = 0;

                    while (true) {
                        $rows = $db->createCommand(
                            "SELECT * FROM {$quotedTable} LIMIT {$batchSize} OFFSET {$offset}"
                        )->queryAll();

                        if (empty($rows)) {
                            break;
                        }

                        foreach ($rows as $row) {
                            $values = array_map(static function ($value) use ($db) {
                                return $value === null ? 'NULL' : $db->quoteValue($value);
                            }, array_values($row));

                            fwrite($handle, "INSERT INTO {$quotedTable} VALUES (" . implode(', ', $values) . ");\n");
                        }

                        $offset += $batchSize;

                        if (count($rows) < $batchSize) {
                            break;
                        }
                    }

                    fwrite($handle, "\n");
                }
            } finally {
                fclose($handle);
            }

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
     * Persist backup metadata to a sidecar file so it never clobbers the
     * migration orchestrator's checkpoint (which stores phase/processed_ids).
     */
    private function saveBackupMetadata(string $timestamp, ?string $backupFile, array $tableHandles, bool $verified): void
    {
        try {
            $metaFile = Craft::getAlias('@storage/migration-checkpoints') .
                '/' . $this->migrationId . '.backup.json';

            file_put_contents($metaFile, json_encode([
                'migration_id' => $this->migrationId,
                'backup_timestamp' => $timestamp,
                'backup_file' => $backupFile,
                'backup_tables' => $tableHandles,
                'backup_verified' => $verified,
                'created_at' => date('Y-m-d H:i:s'),
            ]));
        } catch (\Exception $e) {
            Craft::warning("Could not save backup metadata: " . $e->getMessage(), __METHOD__);
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
