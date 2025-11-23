<?php

namespace csabourin\spaghettiMigrator\services;

use Craft;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;

/**
 * Migration State Manager
 *
 * Handles migration state tracking, configuration status, and checkpoint management.
 * Extracted from MigrationController to improve separation of concerns.
 */
class MigrationStateManager
{
    private MigrationProgressService $progressService;

    public function __construct(?MigrationProgressService $progressService = null)
    {
        $this->progressService = $progressService ?? new MigrationProgressService();
    }

    /**
     * Get the current migration state
     */
    public function getMigrationState(): array
    {
        // Check for checkpoints - files are saved as {migrationId}.json, NOT checkpoint-*.json
        $checkpointDir = Craft::getAlias('@storage/migration-checkpoints');
        $hasCheckpoint = false;

        if (is_dir($checkpointDir)) {
            $files = glob($checkpointDir . '/*.json');
            // Exclude .state.json files, only count actual checkpoint files
            $checkpointFiles = array_filter($files, function($file) {
                return !str_ends_with($file, '.state.json');
            });
            $hasCheckpoint = count($checkpointFiles) > 0;
        }

        // Also check for active locks - indicates interrupted migration
        $hasActiveLock = $this->hasActiveMigrationLock();

        // Check for changelogs
        $logDir = Craft::getAlias('@storage/migration-logs');
        $hasChangelog = is_dir($logDir) && count(glob($logDir . '/changelog-*.json')) > 0;

        // Determine current phase based on completed actions
        $currentPhase = 0;

        $state = $this->progressService->getState();
        $completedModules = $state['completedModules'] ?? [];
        if (!is_array($completedModules)) {
            $completedModules = [];
        }

        // Check filesystem status
        $filesystems = Craft::$app->getFs()->getAllFilesystems();
        $hasDoFilesystems = false;
        foreach ($filesystems as $fs) {
            if (strpos($fs->handle, '_do') !== false) {
                $hasDoFilesystems = true;
                $completedModules[] = 'filesystem';
                $currentPhase = max($currentPhase, 1);
                break;
            }
        }

        return [
            'hasCheckpoint' => $hasCheckpoint,
            'hasChangelog' => $hasChangelog,
            'hasDoFilesystems' => $hasDoFilesystems,
            'hasActiveLock' => $hasActiveLock,
            'currentPhase' => $currentPhase,
            'completedModules' => $completedModules,
            'canResume' => $hasCheckpoint || $hasActiveLock, // Resume if checkpoints OR active lock exists
            'lastUpdated' => $state['updatedAt'] ?? null,
        ];
    }

    /**
     * Check if there's an active migration lock
     */
    public function hasActiveMigrationLock(): bool
    {
        try {
            $db = Craft::$app->getDb();

            // Check for any migration lock
            $lock = $db->createCommand('
                SELECT migrationId, lockedAt, expiresAt
                FROM {{%migrationlocks}}
                WHERE lockName = :lockName
                LIMIT 1
            ', [':lockName' => 'migration_lock'])->queryOne();

            return $lock !== false;
        } catch (\Exception $e) {
            // Table might not exist or other error
            return false;
        }
    }

    /**
     * Get configuration status
     */
    public function getConfigurationStatus(): array
    {
        try {
            $config = MigrationConfig::getInstance();

            // Use getter methods which properly handle both plugin settings and config file
            $doAccessKey = $config->getDoAccessKey();
            $doSecretKey = $config->getDoSecretKey();
            $doBaseUrl = $config->getDoBaseUrl();
            $doBucket = $config->getDoBucket();
            $doRegion = $config->getDoRegion();
            $awsBucket = $config->getAwsBucket();
            $awsRegion = $config->getAwsRegion();
            $awsAccessKey = $config->getAwsAccessKey();
            $awsSecretKey = $config->getAwsSecretKey();

            return [
                'isConfigured' => true,
                'hasDoCredentials' => !empty($doAccessKey) && !empty($doSecretKey),
                'hasDoUrl' => !empty($doBaseUrl),
                'hasDoBucket' => !empty($doBucket),
                'hasAwsConfig' => !empty($awsBucket),
                'hasAwsCredentials' => !empty($awsAccessKey) && !empty($awsSecretKey),
                'doRegion' => $doRegion,
                'doBucket' => $doBucket,
                'awsBucket' => $awsBucket,
                'awsRegion' => $awsRegion,
            ];
        } catch (\Exception $e) {
            // Return all expected keys with default values when config fails
            return [
                'isConfigured' => false,
                'hasDoCredentials' => false,
                'hasDoUrl' => false,
                'hasDoBucket' => false,
                'hasAwsConfig' => false,
                'hasAwsCredentials' => false,
                'doRegion' => '',
                'doBucket' => '',
                'awsBucket' => '',
                'awsRegion' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get checkpoint information
     */
    public function getCheckpoints(): array
    {
        $checkpointDir = Craft::getAlias('@storage/migration-checkpoints');
        $checkpoints = [];

        if (is_dir($checkpointDir)) {
            $files = glob($checkpointDir . '/checkpoint-*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $checkpoints[] = [
                        'filename' => basename($file),
                        'timestamp' => $data['checkpoint']['timestamp'] ?? null,
                        'progress' => $data['checkpoint']['progress'] ?? [],
                        'phase' => $data['checkpoint']['phase'] ?? null,
                    ];
                }
            }

            // Sort by timestamp descending
            usort($checkpoints, function($a, $b) {
                return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
            });
        }

        return $checkpoints;
    }

    /**
     * Get migration changelogs
     */
    public function getChangelogs(): array
    {
        $changelogDir = Craft::getAlias('@storage/migration-logs');
        $changelogs = [];

        if (is_dir($changelogDir)) {
            $files = glob($changelogDir . '/changelog-*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $changelogs[] = [
                        'filename' => basename($file),
                        'filepath' => $file,
                        'timestamp' => $data['timestamp'] ?? filemtime($file),
                        'operation' => $data['operation'] ?? 'unknown',
                        'summary' => $data['summary'] ?? [],
                        'changes' => $data['changes'] ?? [],
                    ];
                }
            }

            // Sort by timestamp descending
            usort($changelogs, function($a, $b) {
                return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
            });
        }

        return [
            'changelogs' => $changelogs,
            'directory' => $changelogDir,
        ];
    }

    /**
     * Get last N lines of a log file
     */
    public function getLogTail(string $filepath, int $lines = 100): array
    {
        if (!file_exists($filepath)) {
            return [];
        }

        $file = new \SplFileObject($filepath, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $lines = min($lines, $lastLine);

        $result = [];
        for ($i = $lastLine - $lines; $i <= $lastLine; $i++) {
            $file->seek($i);
            $line = $file->current();
            if (trim($line)) {
                $result[] = $line;
            }
        }

        return $result;
    }

    /**
     * Test DO Spaces connection
     */
    public function testConnection(): array
    {
        try {
            $config = MigrationConfig::getInstance();
            $doConfig = $config->get('digitalocean');

            // Simple validation
            $errors = [];
            if (empty($doConfig['accessKey'])) {
                $errors[] = 'DO_S3_ACCESS_KEY is not configured';
            }
            if (empty($doConfig['secretKey'])) {
                $errors[] = 'DO_S3_SECRET_KEY is not configured';
            }
            if (empty($doConfig['bucket'])) {
                $errors[] = 'DO_S3_BUCKET is not configured';
            }

            if (!empty($errors)) {
                return [
                    'success' => false,
                    'errors' => $errors,
                ];
            }

            return [
                'success' => true,
                'message' => 'Configuration looks valid. Run pre-flight checks to verify connection.',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
