<?php

namespace csabourin\spaghettiMigrator\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use csabourin\spaghettiMigrator\services\ChangeLogManager;

/**
 * Rollback Engine - Comprehensive Rollback Operations
 *
 * Handles all rollback functionality for the migration system:
 * - Database-level rollback via backup restore (fastest)
 * - Change-by-change rollback (most flexible)
 * - Phase-based rollback (partial recovery)
 * - Detailed dry-run reporting
 */
class RollbackEngine
{
    private $changeLogManager;
    private $migrationId;

    public function __construct(ChangeLogManager $changeLogManager, $migrationId = null)
    {
        $this->changeLogManager = $changeLogManager;
        $this->migrationId = $migrationId;
    }

    /**
     * Rollback via database restore (fastest method)
     *
     * @param string $migrationId Migration ID to rollback
     * @param bool $dryRun Show what would be done without executing
     * @return array Results of rollback operation
     */
    public function rollbackViaDatabase($migrationId, $dryRun = false)
    {
        // Find database backup file
        $backupDir = Craft::getAlias('@storage/migration-backups');
        $backupFile = $backupDir . '/migration_' . $migrationId . '_db_backup.sql';

        if (!file_exists($backupFile)) {
            throw new \Exception("Database backup not found: {$backupFile}");
        }

        $backupSize = filesize($backupFile);
        $backupSizeMB = round($backupSize / 1024 / 1024, 2);

        if ($dryRun) {
            return [
                'dry_run' => true,
                'method' => 'database_restore',
                'backup_file' => $backupFile,
                'backup_size' => $backupSizeMB . ' MB',
                'tables' => ['assets', 'volumefolders', 'relations', 'elements', 'elements_sites', 'content'],
                'estimated_time' => '< 1 minute'
            ];
        }

        // Verify backup integrity
        $this->verifyBackupFile($backupFile);

        // Disable foreign key checks temporarily
        $db = Craft::$app->getDb();
        $db->createCommand("SET FOREIGN_KEY_CHECKS=0")->execute();

        try {
            // Parse DSN to get database connection info
            $dsn = $db->dsn;
            if (preg_match('/dbname=([^;]+)/', $dsn, $matches)) {
                $dbName = $matches[1];
            } else {
                throw new \Exception("Could not parse database name from DSN");
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

            // Try mysql command line restore (fastest)
            // Use MySQL config file for credentials to avoid password exposure in ps aux
            $configFile = null;
            $returnCode = 1; // Default to failure

            try {
                // Create temporary MySQL config file with secure permissions
                $configFile = sys_get_temp_dir() . '/mysql_' . uniqid() . '.cnf';

                $configContent = "[client]\n";
                $configContent .= "user=" . $username . "\n";
                if ($password) {
                    $configContent .= "password=" . $password . "\n";
                }
                $configContent .= "host=" . $host . "\n";
                $configContent .= "port=" . $port . "\n";

                file_put_contents($configFile, $configContent);
                chmod($configFile, 0600); // Owner read/write only

                // Validate backup file path to prevent command injection
                $realBackupFile = realpath($backupFile);
                if ($realBackupFile === false || strpos($realBackupFile, $backupDir) !== 0) {
                    throw new \Exception("Invalid backup file path");
                }

                $mysqlCmd = sprintf(
                    'mysql --defaults-extra-file=%s %s < %s 2>&1',
                    escapeshellarg($configFile),
                    escapeshellarg($dbName),
                    escapeshellarg($realBackupFile)
                );

                exec($mysqlCmd, $output, $returnCode);

            } finally {
                // Always delete the config file, even if an error occurs
                if ($configFile && file_exists($configFile)) {
                    @unlink($configFile);
                }
            }

            if ($returnCode !== 0) {
                // Fallback: Execute SQL file directly via Craft
                $sql = file_get_contents($backupFile);
                $statements = array_filter(array_map('trim', explode(";\n", $sql)));

                foreach ($statements as $statement) {
                    if (!empty($statement) && substr($statement, 0, 2) !== '--') {
                        try {
                            $db->createCommand($statement)->execute();
                        } catch (\Exception $e) {
                            Craft::warning("Statement failed during restore: " . $e->getMessage(), __METHOD__);
                        }
                    }
                }
            }

            // Re-enable foreign key checks
            $db->createCommand("SET FOREIGN_KEY_CHECKS=1")->execute();

            // Clear Craft caches
            Craft::$app->getTemplateCaches()->deleteAllCaches();
            Craft::$app->getElements()->invalidateAllCaches();

            return [
                'success' => true,
                'method' => 'database_restore',
                'backup_file' => $backupFile,
                'backup_size' => $backupSizeMB . ' MB',
                'tables_restored' => ['assets', 'volumefolders', 'relations', 'elements', 'elements_sites', 'content']
            ];

        } catch (\Exception $e) {
            // Re-enable foreign key checks on error
            $db->createCommand("SET FOREIGN_KEY_CHECKS=1")->execute();
            throw new \Exception("Database restore failed: " . $e->getMessage());
        }
    }

    /**
     * Verify backup file integrity
     *
     * @param string $backupFile Path to backup file
     * @throws \Exception if backup is invalid
     */
    private function verifyBackupFile($backupFile)
    {
        if (!file_exists($backupFile)) {
            throw new \Exception("Backup file not found: {$backupFile}");
        }

        if (filesize($backupFile) === 0) {
            throw new \Exception("Backup file is empty: {$backupFile}");
        }

        // Verify file is within expected backup directory
        $backupDir = Craft::getAlias('@storage/migration-backups');
        $realBackupFile = realpath($backupFile);
        $realBackupDir = realpath($backupDir);

        if ($realBackupFile === false) {
            throw new \Exception("Invalid backup file path");
        }

        if ($realBackupDir === false || strpos($realBackupFile, $realBackupDir) !== 0) {
            throw new \Exception("Backup file must be within the migration-backups directory");
        }

        // Check file extension
        if (!preg_match('/\.sql$/i', $backupFile)) {
            throw new \Exception("Backup file must have .sql extension");
        }

        // Check if file contains valid SQL statements
        $handle = fopen($backupFile, 'r');
        $validSqlFound = false;
        $lineCount = 0;
        $maxLinesToCheck = 100; // Check first 100 lines

        while (($line = fgets($handle)) !== false && $lineCount < $maxLinesToCheck) {
            $trimmedLine = trim($line);
            $lineCount++;

            // Skip empty lines and comments
            if (empty($trimmedLine) || substr($trimmedLine, 0, 2) === '--') {
                continue;
            }

            // Look for valid SQL keywords
            if (preg_match('/^(CREATE|INSERT|UPDATE|DELETE|DROP|ALTER|USE)\s+/i', $trimmedLine)) {
                $validSqlFound = true;
                break;
            }
        }
        fclose($handle);

        if (!$validSqlFound) {
            throw new \Exception("Backup file does not contain valid SQL statements");
        }

        // Check for suspicious content that might indicate malicious SQL
        $content = file_get_contents($backupFile, false, null, 0, 8192); // Read first 8KB
        $suspiciousPatterns = [
            '/\bINTO\s+OUTFILE\b/i',          // File writes
            '/\bLOAD_FILE\b/i',                // File reads
            '/\bINTO\s+DUMPFILE\b/i',          // Binary file writes
            '/\beval\s*\(/i',                  // Eval functions
            '/\bexec\s*\(/i',                  // Exec functions
            '/\bsystem\s*\(/i',                // System calls
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new \Exception("Backup file contains suspicious SQL commands");
            }
        }
    }

    /**
     * Rollback via change-by-change reversal
     *
     * @param string $migrationId Migration ID to rollback
     * @param string|array|null $phases Phase(s) to rollback
     * @param string $mode 'from' (rollback from phase onwards) or 'only' (rollback specific phases)
     * @param bool $dryRun Show what would be done without executing
     * @return array Results of rollback operation
     */
    public function rollback($migrationId, $phases = null, $mode = 'from', $dryRun = false)
    {
        // Load all changes
        $changes = $this->changeLogManager->loadChanges();

        if (empty($changes)) {
            throw new \Exception("No changes found for migration: {$migrationId}");
        }

        // Filter by phase if specified
        if ($phases !== null) {
            $phasesToRollback = is_array($phases) ? $phases : [$phases];

            if ($mode === 'only') {
                // Rollback ONLY specified phases
                $changes = array_filter($changes, function($c) use ($phasesToRollback) {
                    $phase = $c['phase'] ?? 'unknown';
                    return in_array($phase, $phasesToRollback);
                });
            } else if ($mode === 'from') {
                // Rollback FROM specified phase onwards (in reverse order)
                $phaseOrder = [
                    'preparation',
                    'optimised_root',
                    'discovery',
                    'link_inline',
                    'fix_links',
                    'consolidate',
                    'quarantine',
                    'cleanup',
                    'complete'
                ];

                $fromPhase = is_array($phases) ? $phases[0] : $phases;
                $fromIndex = array_search($fromPhase, $phaseOrder);

                if ($fromIndex !== false) {
                    $phasesToInclude = array_slice($phaseOrder, $fromIndex);
                    $changes = array_filter($changes, function($c) use ($phasesToInclude) {
                        $phase = $c['phase'] ?? 'unknown';
                        return in_array($phase, $phasesToInclude);
                    });
                }
            }
        }

        if ($dryRun) {
            return $this->generateDryRunReport($changes);
        }

        $stats = [
            'reversed' => 0,
            'errors' => 0,
            'skipped' => 0
        ];

        $total = count($changes);
        $current = 0;

        // Reverse in reverse order
        foreach (array_reverse($changes) as $change) {
            try {
                $this->reverseChange($change);
                $stats['reversed']++;
                $current++;

                // Progress reporting every 50 operations
                if ($current % 50 === 0) {
                    $percent = round(($current / $total) * 100);
                    Craft::info("[{$current}/{$total}] {$percent}% complete", __METHOD__);
                }

            } catch (\Exception $e) {
                $stats['errors']++;
                Craft::error("Rollback error: " . $e->getMessage(), __METHOD__);
            }
        }

        return $stats;
    }

    /**
     * Generate dry-run report showing what would be rolled back
     *
     * @param array $changes Changes to rollback
     * @return array Dry-run report
     */
    private function generateDryRunReport($changes)
    {
        $byType = [];
        $byPhase = [];

        foreach ($changes as $change) {
            $type = $change['type'];
            $phase = $change['phase'] ?? 'unknown';

            if (!isset($byType[$type])) {
                $byType[$type] = 0;
            }
            $byType[$type]++;

            if (!isset($byPhase[$phase])) {
                $byPhase[$phase] = 0;
            }
            $byPhase[$phase]++;
        }

        // Estimate time (rough approximation)
        $estimatedSeconds = count($changes) * 0.1; // ~0.1s per operation
        $estimatedMinutes = ceil($estimatedSeconds / 60);

        return [
            'dry_run' => true,
            'method' => 'change_by_change',
            'total_operations' => count($changes),
            'by_type' => $byType,
            'by_phase' => $byPhase,
            'estimated_time' => $estimatedMinutes > 1 ? "~{$estimatedMinutes} minutes" : "< 1 minute"
        ];
    }

    /**
     * Get summary of phases in a migration
     *
     * @param string $migrationId Migration ID
     * @return array Phase summary with change counts
     */
    public function getPhasesSummary($migrationId)
    {
        $changes = $this->changeLogManager->loadChanges();
        $phases = [];

        foreach ($changes as $change) {
            $phase = $change['phase'] ?? 'unknown';
            if (!isset($phases[$phase])) {
                $phases[$phase] = 0;
            }
            $phases[$phase]++;
        }

        return $phases;
    }

    private function reverseChange($change)
    {
        $db = Craft::$app->getDb();

        switch ($change['type']) {
            case 'inline_image_linked':
                // Restore original HTML
                try {
                    $db->createCommand()->update(
                        $change['table'],
                        [$change['column'] => $change['originalContent']],
                        ['id' => $change['rowId']]
                    )->execute();

                    Craft::info("Restored inline image: row {$change['rowId']}", __METHOD__);
                } catch (\Exception $e) {
                    throw new \Exception("Could not restore inline image: " . $e->getMessage());
                }
                break;

            case 'moved_asset':
                // Restore asset to original location
                $asset = Asset::findOne($change['assetId']);
                if ($asset) {
                    $asset->volumeId = $change['fromVolume'];
                    $asset->folderId = $change['fromFolder'];
                    if (!Craft::$app->getElements()->saveElement($asset)) {
                        throw new \Exception("Could not restore asset location");
                    }

                    Craft::info("Restored asset {$change['assetId']} to original location", __METHOD__);
                }
                break;

            case 'fixed_broken_link':
                // Restore asset to original broken state
                $asset = Asset::findOne($change['assetId']);
                if ($asset) {
                    // Check if we have original location data (added in new logging)
                    if (isset($change['originalVolumeId']) && isset($change['originalFolderId'])) {
                        $asset->volumeId = $change['originalVolumeId'];
                        $asset->folderId = $change['originalFolderId'];

                        if (!Craft::$app->getElements()->saveElement($asset)) {
                            throw new \Exception("Could not restore asset to original location");
                        }

                        Craft::info("Restored asset {$change['assetId']} to original broken state", __METHOD__);
                    } else {
                        // Old logging format - can't fully rollback
                        Craft::warning("Cannot fully rollback fixed_broken_link for asset {$change['assetId']} - missing original location data", __METHOD__);
                    }
                } else {
                    Craft::warning("Asset {$change['assetId']} not found during rollback", __METHOD__);
                }
                break;

            case 'quarantined_unused_asset':
                // Restore asset from quarantine
                $asset = Asset::findOne($change['assetId']);
                if ($asset) {
                    $asset->volumeId = $change['fromVolume'];
                    $asset->folderId = $change['fromFolder'];
                    if (!Craft::$app->getElements()->saveElement($asset)) {
                        throw new \Exception("Could not restore asset from quarantine");
                    }

                    Craft::info("Restored asset {$change['assetId']} from quarantine", __METHOD__);
                }
                break;

            case 'quarantined_orphaned_file':
                // Restore file from quarantine (files are moved, not deleted)
                try {
                    // Get quarantine volume
                    $quarantineVolume = Craft::$app->getVolumes()->getVolumeByHandle('quarantine');
                    if (!$quarantineVolume) {
                        throw new \Exception("Quarantine volume not found");
                    }
                    $quarantineFs = $quarantineVolume->getFs();

                    // Get original volume
                    $sourceVolume = Craft::$app->getVolumes()->getVolumeByHandle($change['sourceVolume']);
                    if (!$sourceVolume) {
                        throw new \Exception("Source volume '{$change['sourceVolume']}' not found");
                    }
                    $sourceFs = $sourceVolume->getFs();

                    // Move file back from quarantine to original location
                    $quarantinePath = $change['targetPath']; // Where file is now
                    $originalPath = $change['sourcePath'];   // Where it should go

                    // Check if file exists in quarantine
                    if (!$quarantineFs->fileExists($quarantinePath)) {
                        throw new \Exception("File not found in quarantine: {$quarantinePath}");
                    }

                    // Read from quarantine
                    $content = $quarantineFs->read($quarantinePath);

                    // Write back to original location
                    $sourceFs->write($originalPath, $content, []);

                    // Delete from quarantine
                    $quarantineFs->deleteFile($quarantinePath);

                    Craft::info("Restored orphaned file from quarantine: {$originalPath}", __METHOD__);

                } catch (\Exception $e) {
                    throw new \Exception("Could not restore file from quarantine: " . $e->getMessage());
                }
                break;

            case 'moved_from_optimised_root':
                // Move asset back to optimisedImages root
                $asset = Asset::findOne($change['assetId']);
                if ($asset) {
                    $sourceVolume = Craft::$app->getVolumes()->getVolumeById($change['fromVolume']);
                    if (!$sourceVolume) {
                        throw new \Exception("Source volume not found");
                    }

                    // Get root folder of source volume
                    $sourceRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($sourceVolume->id);
                    if (!$sourceRootFolder) {
                        throw new \Exception("Source root folder not found");
                    }

                    // Move asset back
                    $asset->volumeId = $sourceVolume->id;
                    $asset->folderId = $sourceRootFolder->id;

                    if (!Craft::$app->getElements()->saveElement($asset)) {
                        throw new \Exception("Could not restore asset to optimised root");
                    }

                    Craft::info("Restored asset {$change['assetId']} to optimisedImages root", __METHOD__);
                }
                break;

            case 'updated_asset_path':
                // Restore asset to original volume/path
                $asset = Asset::findOne($change['assetId']);
                if ($asset) {
                    // Check if we have original values (added in new logging)
                    if (isset($change['originalVolumeId'])) {
                        $asset->volumeId = $change['originalVolumeId'];

                        if (!Craft::$app->getElements()->saveElement($asset)) {
                            throw new \Exception("Could not restore asset path");
                        }

                        Craft::info("Restored asset {$change['assetId']} to original path", __METHOD__);
                    } else {
                        Craft::warning("Cannot rollback updated_asset_path for asset {$change['assetId']} - missing original volume ID", __METHOD__);
                    }
                }
                break;

            case 'deleted_transform':
                // Transforms are regenerated automatically by Craft CMS - no rollback needed
                Craft::info("Transform file was deleted but will regenerate: {$change['path']}", __METHOD__);
                break;

            case 'broken_link_not_fixed':
                // Info-only entry, no rollback needed
                Craft::info("Info-only entry (broken_link_not_fixed), no rollback needed", __METHOD__);
                break;

            default:
                Craft::warning("Unknown change type for rollback: " . $change['type'], __METHOD__);
        }
    }
}
