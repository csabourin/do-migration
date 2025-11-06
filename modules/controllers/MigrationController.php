<?php

namespace modules\controllers;

use Craft;
use craft\web\Controller;
use modules\helpers\MigrationConfig;
use yii\web\Response;

/**
 * Migration Dashboard Controller
 *
 * Provides a Control Panel interface for orchestrating the AWS to DigitalOcean migration
 * with step-by-step guidance through all 14 migration modules.
 */
class MigrationController extends Controller
{
    /**
     * @var bool Allow anonymous access to dashboard (set to false in production)
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Render the main migration dashboard
     */
    public function actionIndex(): Response
    {
        // Get current migration state
        $state = $this->getMigrationState();

        // Get configuration status
        $config = $this->getConfigurationStatus();

        return $this->renderTemplate('ncc-module/dashboard', [
            'state' => $state,
            'config' => $config,
            'modules' => $this->getModuleDefinitions(),
        ]);
    }

    /**
     * API: Get migration status
     */
    public function actionGetStatus(): Response
    {
        $this->requireAcceptsJson();

        return $this->asJson([
            'success' => true,
            'state' => $this->getMigrationState(),
            'config' => $this->getConfigurationStatus(),
        ]);
    }

    /**
     * API: Run a specific migration command
     */
    public function actionRunCommand(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $command = $request->getBodyParam('command');
        $argsParam = $request->getBodyParam('args', '[]');
        $args = is_string($argsParam) ? json_decode($argsParam, true) : $argsParam;
        $args = $args ?: []; // Ensure it's an array even if json_decode fails
        $dryRun = $request->getBodyParam('dryRun', false);

        if (!$command) {
            return $this->asJson([
                'success' => false,
                'error' => 'Command is required',
            ]);
        }

        // Validate command
        $allowedCommands = $this->getAllowedCommands();
        if (!in_array($command, $allowedCommands)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid command',
            ]);
        }

        try {
            // Build the full command
            $fullCommand = "ncc-module/{$command}";

            // Add dry run flag if requested
            if ($dryRun) {
                $args['dryRun'] = '1';
            }

            // Execute the command
            $result = $this->executeConsoleCommand($fullCommand, $args);

            return $this->asJson([
                'success' => true,
                'output' => $result['output'],
                'exitCode' => $result['exitCode'],
            ]);

        } catch (\Exception $e) {
            Craft::error('Migration command failed: ' . $e->getMessage(), __METHOD__);
            Craft::error('Stack trace: ' . $e->getTraceAsString(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => CRAFT_ENVIRONMENT === 'dev' ? $e->getTraceAsString() : null,
            ]);
        }
    }

    /**
     * API: Get checkpoint information
     */
    public function actionGetCheckpoint(): Response
    {
        $this->requireAcceptsJson();

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

        return $this->asJson([
            'success' => true,
            'checkpoints' => $checkpoints,
        ]);
    }

    /**
     * API: Get migration logs
     */
    public function actionGetLogs(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $lines = $request->getQueryParam('lines', 100);

        $logDir = Craft::getAlias('@storage/logs');
        $logFile = $logDir . '/web.log';

        $logs = [];
        if (file_exists($logFile)) {
            $logs = $this->getTailOfFile($logFile, $lines);
        }

        return $this->asJson([
            'success' => true,
            'logs' => $logs,
        ]);
    }

    /**
     * API: Test DO Spaces connection
     */
    public function actionTestConnection(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

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
                return $this->asJson([
                    'success' => false,
                    'errors' => $errors,
                ]);
            }

            return $this->asJson([
                'success' => true,
                'message' => 'Configuration looks valid. Run pre-flight checks to verify connection.',
            ]);

        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Execute a console command and return output
     */
    private function executeConsoleCommand(string $command, array $args = []): array
    {
        // Build argument string - only include truthy values
        $argString = '';
        foreach ($args as $key => $value) {
            // Skip false, empty string, '0', and 0 values
            if ($value === false || $value === '' || $value === '0' || $value === 0) {
                continue;
            }

            // For boolean true, just add the flag without a value
            if ($value === true || $value === '1' || $value === 1) {
                $argString .= " --{$key}";
            } else {
                // For other values, add key=value
                $argString .= " --{$key}=" . escapeshellarg($value);
            }
        }

        // Execute command
        $craftPath = Craft::getAlias('@root/craft');
        $fullCommand = "{$craftPath} {$command}{$argString} 2>&1";

        // Log the command being executed
        Craft::info("Executing console command: {$fullCommand}", __METHOD__);

        exec($fullCommand, $output, $exitCode);

        // Log the result
        Craft::info("Command exit code: {$exitCode}", __METHOD__);
        if ($exitCode !== 0) {
            Craft::warning("Command failed with output: " . implode("\n", $output), __METHOD__);
        }

        return [
            'output' => implode("\n", $output),
            'exitCode' => $exitCode,
        ];
    }

    /**
     * Get the current migration state
     */
    private function getMigrationState(): array
    {
        // Check for checkpoints
        $checkpointDir = Craft::getAlias('@storage/migration-checkpoints');
        $hasCheckpoint = is_dir($checkpointDir) && count(glob($checkpointDir . '/checkpoint-*.json')) > 0;

        // Check for changelogs
        $logDir = Craft::getAlias('@storage/migration-logs');
        $hasChangelog = is_dir($logDir) && count(glob($logDir . '/changelog-*.json')) > 0;

        // Determine current phase based on completed actions
        $currentPhase = 0;
        $completedModules = [];

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
            'currentPhase' => $currentPhase,
            'completedModules' => $completedModules,
            'canResume' => $hasCheckpoint,
        ];
    }

    /**
     * Get configuration status
     */
    private function getConfigurationStatus(): array
    {
        try {
            $config = MigrationConfig::getInstance();

            $doConfig = $config->get('digitalocean');
            $awsConfig = $config->get('aws');

            return [
                'isConfigured' => true,
                'hasDoCredentials' => !empty($doConfig['accessKey']) && !empty($doConfig['secretKey']),
                'hasDoUrl' => !empty($doConfig['baseUrl']),
                'hasDoBucket' => !empty($doConfig['bucket']),
                'hasAwsConfig' => !empty($awsConfig['bucket']),
                'doRegion' => $doConfig['region'] ?? 'tor1',
                'doBucket' => $doConfig['bucket'] ?? '',
                'awsBucket' => $awsConfig['bucket'] ?? '',
                'awsRegion' => $awsConfig['region'] ?? '',
            ];
        } catch (\Exception $e) {
            return [
                'isConfigured' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get module definitions for the dashboard
     */
    private function getModuleDefinitions(): array
    {
        return [
            [
                'id' => 'setup',
                'title' => 'Setup & Configuration',
                'phase' => 0,
                'icon' => 'settings',
                'modules' => [
                    [
                        'id' => 'filesystem',
                        'title' => 'Create DO Filesystems',
                        'description' => 'Create new DigitalOcean Spaces filesystem configurations',
                        'command' => 'filesystem/create',
                        'duration' => '15-30 min',
                        'critical' => true,
                    ],
                    [
                        'id' => 'volume-config',
                        'title' => 'Configure Volumes',
                        'description' => 'Configure transform filesystem and create quarantine volume',
                        'command' => 'volume-config/configure-all',
                        'duration' => '5-10 min',
                        'critical' => true,
                    ],
                ]
            ],
            [
                'id' => 'preflight',
                'title' => 'Pre-Flight Checks',
                'phase' => 1,
                'icon' => 'check',
                'modules' => [
                    [
                        'id' => 'migration-check',
                        'title' => 'Run Pre-Flight Checks',
                        'description' => 'Validate configuration and environment (10 automated checks)',
                        'command' => 'migration-check/check',
                        'duration' => '5-10 min',
                        'critical' => true,
                    ],
                ]
            ],
            [
                'id' => 'url-replacement',
                'title' => 'URL Replacement',
                'phase' => 2,
                'icon' => 'refresh',
                'modules' => [
                    [
                        'id' => 'url-replacement',
                        'title' => 'Replace Database URLs',
                        'description' => 'Replace AWS URLs in content tables with DO URLs',
                        'command' => 'url-replacement/replace-s3-urls',
                        'duration' => '10-60 min',
                        'critical' => true,
                        'supportsDryRun' => true,
                    ],
                    [
                        'id' => 'extended-url',
                        'title' => 'Extended URL Replacement',
                        'description' => 'Replace URLs in additional tables and JSON fields',
                        'command' => 'extended-url/replace-additional',
                        'duration' => '10-30 min',
                        'critical' => false,
                        'supportsDryRun' => true,
                    ],
                ]
            ],
            [
                'id' => 'templates',
                'title' => 'Template Updates',
                'phase' => 3,
                'icon' => 'code',
                'modules' => [
                    [
                        'id' => 'template-scan',
                        'title' => 'Scan Templates',
                        'description' => 'Scan Twig templates for hardcoded AWS URLs',
                        'command' => 'template-url-replacement/scan',
                        'duration' => '5-10 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'template-replace',
                        'title' => 'Replace Template URLs',
                        'description' => 'Replace hardcoded URLs with environment variables',
                        'command' => 'template-url-replacement/replace',
                        'duration' => '5-15 min',
                        'critical' => false,
                        'supportsDryRun' => true,
                    ],
                ]
            ],
            [
                'id' => 'migration',
                'title' => 'File Migration',
                'phase' => 4,
                'icon' => 'upload',
                'modules' => [
                    [
                        'id' => 'image-migration',
                        'title' => 'Migrate Files',
                        'description' => 'Copy all files from AWS S3 to DigitalOcean Spaces',
                        'command' => 'image-migration/migrate',
                        'duration' => '1-48 hours',
                        'critical' => true,
                        'supportsDryRun' => true,
                        'supportsResume' => true,
                    ],
                ]
            ],
            [
                'id' => 'switch',
                'title' => 'Filesystem Switch',
                'phase' => 5,
                'icon' => 'transfer',
                'modules' => [
                    [
                        'id' => 'switch-preview',
                        'title' => 'Preview Switch',
                        'description' => 'Preview filesystem switch operations',
                        'command' => 'filesystem-switch/preview',
                        'duration' => '1-2 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'switch-to-do',
                        'title' => 'Switch to DO',
                        'description' => 'Switch all volumes to use DigitalOcean Spaces',
                        'command' => 'filesystem-switch/to-do',
                        'duration' => '2-5 min',
                        'critical' => true,
                    ],
                ]
            ],
            [
                'id' => 'validation',
                'title' => 'Validation',
                'phase' => 6,
                'icon' => 'check-circle',
                'modules' => [
                    [
                        'id' => 'migration-diag',
                        'title' => 'Verify Migration',
                        'description' => 'Validate migration success and asset integrity',
                        'command' => 'migration-diag/analyze',
                        'duration' => '10-30 min',
                        'critical' => true,
                    ],
                ]
            ],
            [
                'id' => 'transforms',
                'title' => 'Image Transforms',
                'phase' => 7,
                'icon' => 'image',
                'modules' => [
                    [
                        'id' => 'transform-discovery',
                        'title' => 'Discover Transforms',
                        'description' => 'Find all image transformations in database and templates',
                        'command' => 'transform-discovery/discover',
                        'duration' => '10-30 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'transform-pregeneration',
                        'title' => 'Pre-Generate Transforms',
                        'description' => 'Generate transforms on DO to prevent broken images',
                        'command' => 'transform-pregeneration/generate',
                        'duration' => '30 min - 6 hours',
                        'critical' => false,
                    ],
                ]
            ],
            [
                'id' => 'audit',
                'title' => 'Audit & Diagnostics',
                'phase' => 8,
                'icon' => 'search',
                'modules' => [
                    [
                        'id' => 'plugin-audit',
                        'title' => 'Audit Plugins',
                        'description' => 'Scan plugin configurations for hardcoded AWS URLs',
                        'command' => 'plugin-audit/scan',
                        'duration' => '5-15 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'static-asset',
                        'title' => 'Scan Static Assets',
                        'description' => 'Scan JS/CSS/SCSS for hardcoded AWS URLs',
                        'command' => 'static-asset/scan',
                        'duration' => '5-15 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'fs-diag',
                        'title' => 'Filesystem Diagnostics',
                        'description' => 'Compare and analyze filesystems directly',
                        'command' => 'fs-diag/list-fs',
                        'duration' => '5-10 min',
                        'critical' => false,
                        'requiresArgs' => true,
                    ],
                ]
            ],
        ];
    }

    /**
     * Get allowed console commands
     */
    private function getAllowedCommands(): array
    {
        return [
            'filesystem/create',
            'filesystem/delete',
            'volume-config/set-transform-filesystem',
            'volume-config/configure-all',
            'migration-check/check',
            'url-replacement/replace-s3-urls',
            'extended-url/replace-additional',
            'extended-url/replace-json',
            'template-url-replacement/scan',
            'template-url-replacement/replace',
            'image-migration/migrate',
            'image-migration/rollback',
            'filesystem-switch/preview',
            'filesystem-switch/to-do',
            'filesystem-switch/to-aws',
            'migration-diag/analyze',
            'transform-discovery/discover',
            'transform-pregeneration/generate',
            'plugin-audit/scan',
            'static-asset/scan',
            'fs-diag/list-fs',
        ];
    }

    /**
     * Get last N lines of a file
     */
    private function getTailOfFile(string $filepath, int $lines = 100): array
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
}
