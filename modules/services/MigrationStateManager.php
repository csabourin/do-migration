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
        $resumableStates = CheckpointManager::listResumableStates();
        $hasCheckpoint = !empty(array_filter($resumableStates, static function(array $state): bool {
            return ($state['source'] ?? '') === 'checkpoint';
        }));
        $hasQuickState = !empty(array_filter($resumableStates, static function(array $state): bool {
            return ($state['source'] ?? '') === 'quick_state';
        }));

        // Also check for active locks - indicates interrupted migration
        $hasActiveLock = $this->hasActiveMigrationLock();

        // Check for changelogs
        $logDir = Craft::getAlias('@storage/migration-logs');
        $hasChangelog = is_dir($logDir) && count(glob($logDir . '/changelog-*.json')) > 0;

        $state = $this->progressService->getState();
        $completedModules = $state['completedModules'] ?? [];
        if (!is_array($completedModules)) {
            $completedModules = [];
        }

        // Check filesystem status for informational display only. A configured DO filesystem
        // does not mean the filesystem switch has actually been performed.
        $filesystems = Craft::$app->getFs()->getAllFilesystems();
        $hasDoFilesystems = false;
        foreach ($filesystems as $fs) {
            if (strpos($fs->handle, '_do') !== false) {
                $hasDoFilesystems = true;
                break;
            }
        }

        // Determine current phase from the highest completed phase whose key action is done.
        // Phases: -1 Prerequisites, 0 Setup, 1 Preflight, 2 Switch, 3 Migration,
        //          4 Consolidation, 5 URL Replacement, 6 Templates, 7 Validation,
        //          8 Transforms, 9 Audit
        $currentPhase = $this->computeCurrentPhase($completedModules);

        return [
            'hasCheckpoint' => $hasCheckpoint,
            'hasQuickState' => $hasQuickState,
            'hasChangelog' => $hasChangelog,
            'hasDoFilesystems' => $hasDoFilesystems,
            'hasActiveLock' => $hasActiveLock,
            'currentPhase' => $currentPhase,
            'completedModules' => $completedModules,
            'canResume' => $hasCheckpoint || $hasQuickState || $hasActiveLock,
            'resumableStates' => array_slice($resumableStates, 0, 10),
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
            $doEndpoint = $config->getDoEndpoint();
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
                'doBaseUrl' => $doBaseUrl,
                'doEndpoint' => $doEndpoint,
                'doBucket' => $doBucket,
                'awsBucket' => $awsBucket,
                'awsRegion' => $awsRegion,
                'awsBucketEnvVar' => $config->getAwsEnvVarBucket(),
                'awsRegionEnvVar' => $config->getAwsEnvVarRegion(),
                'awsAccessKeyEnvVar' => $config->getAwsEnvVarAccessKey(),
                'awsSecretKeyEnvVar' => $config->getAwsEnvVarSecretKey(),
                'doBucketEnvVar' => $config->getDoEnvVarBucketName(),
                'doBaseUrlEnvVar' => $config->getDoEnvVarBaseUrlName(),
                'doAccessKeyEnvVar' => $config->getDoEnvVarAccessKeyName(),
                'doSecretKeyEnvVar' => $config->getDoEnvVarSecretKeyName(),
                'doRegionEnvVar' => $config->getDoEnvVarRegionName(),
                'doEndpointEnvVar' => $config->getDoEnvVarEndpointName(),
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
                'doBaseUrl' => '',
                'doEndpoint' => '',
                'doBucket' => '',
                'awsBucket' => '',
                'awsRegion' => '',
                'awsBucketEnvVar' => '',
                'awsRegionEnvVar' => '',
                'awsAccessKeyEnvVar' => '',
                'awsSecretKeyEnvVar' => '',
                'doBucketEnvVar' => '',
                'doBaseUrlEnvVar' => '',
                'doAccessKeyEnvVar' => '',
                'doSecretKeyEnvVar' => '',
                'doRegionEnvVar' => '',
                'doEndpointEnvVar' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get checkpoint information
     */
    public function getCheckpoints(): array
    {
        return CheckpointManager::listResumableStates();
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
     * Compute the highest completed phase number from the set of completed module IDs.
     *
     * The "key" module for each phase is the action that produces its primary output.
     * A phase is considered complete when its key module appears in $completedModules.
     * We return the highest such phase number; if nothing is completed we return 0
     * (the user is about to start Phase 0 Setup).
     */
    private function computeCurrentPhase(array $completedModules): int
    {
        // Map: phase number => module IDs that, when any is present, mark the phase done.
        // Listed in descending order so we can short-circuit on the first match.
        $phaseKeyModules = [
            9 => ['static-asset-scan', 'plugin-config-audit', 'fs-diag-compare'],
            8 => ['transform-pregeneration', 'transform-pregeneration-verify', 'add-optimised-field'],
            7 => ['migration-diag', 'migration-diag-missing', 'post-migration-commands'],
            6 => ['template-replace', 'template-verify'],
            5 => ['url-replacement', 'url-replacement-verify', 'extended-url', 'extended-url-json'],
            4 => ['volume-consolidation-merge', 'volume-consolidation-flatten', 'migration-diag-move'],
            3 => ['image-migration', 'image-migration-cleanup'],
            2 => ['switch-to-do', 'switch-verify'],
            1 => ['migration-check', 'migration-check-analyze'],
            0 => ['filesystem', 'volume-config', 'volume-config-quarantine'],
        ];

        foreach ($phaseKeyModules as $phase => $keyModules) {
            foreach ($keyModules as $moduleId) {
                if (in_array($moduleId, $completedModules)) {
                    return $phase;
                }
            }
        }

        // Nothing completed — user is at the beginning (phase -1 Prerequisites not tracked here)
        return 0;
    }

    /**
     * Validate DO Spaces configuration
     */
    public function testConnection(): array
    {
        try {
            $config = MigrationConfig::getInstance();
            $doAccessKey = $config->getDoAccessKey();
            $doSecretKey = $config->getDoSecretKey();
            $doBucket = $config->getDoBucket();
            $doBaseUrl = $config->getDoBaseUrl();
            $doEndpoint = $config->getDoEndpoint();

            // Validate required configuration values. Actual provider connectivity is
            // covered by the pre-flight checks and filesystem switch diagnostics.
            $errors = [];
            if (empty($doAccessKey)) {
                $errors[] = $config->getDoEnvVarAccessKeyName() . ' is not configured';
            }
            if (empty($doSecretKey)) {
                $errors[] = $config->getDoEnvVarSecretKeyName() . ' is not configured';
            }
            if (empty($doBucket)) {
                $errors[] = $config->getDoEnvVarBucketName() . ' is not configured';
            }
            if (empty($doBaseUrl)) {
                $errors[] = $config->getDoEnvVarBaseUrlName() . ' is not configured';
            }
            if (empty($doEndpoint)) {
                $errors[] = $config->getDoEnvVarEndpointName() . ' is not configured';
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
                'config' => [
                    'bucket' => $doBucket,
                    'baseUrl' => $doBaseUrl,
                    'endpoint' => $doEndpoint,
                    'region' => $config->getDoRegion(),
                ],
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
