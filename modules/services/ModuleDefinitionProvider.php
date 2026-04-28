<?php

namespace csabourin\spaghettiMigrator\services;

use csabourin\spaghettiMigrator\helpers\MigrationConfig;

/**
 * Module Definition Provider
 *
 * Provides module definitions for the migration dashboard.
 * This class encapsulates the large 741-line getModuleDefinitions() method
 * that was previously in MigrationController.
 */
class ModuleDefinitionProvider
{
    /**
     * @var MigrationConfig|null
     */
    private $config = null;

    /**
     * @param MigrationConfig|object|null $config Configuration object (accepts test doubles)
     */
    public function __construct($config = null)
    {
        $this->config = $config ?? MigrationConfig::getInstance();
    }

    /**
     * Get module definitions for the dashboard
     */
    public function getModuleDefinitions(): array
    {
        $configData = $this->getConfigurationData();

        $definitions = [
            $this->getPrerequisitesPhase($configData),
            $this->getSetupPhase($configData),
            $this->getPreflightPhase(),
            $this->getSwitchPhase($configData),
            $this->getMigrationPhase($configData),
            $this->getConsolidationPhase($configData),
            $this->getUrlReplacementPhase(),
            $this->getTemplatesPhase($configData),
            $this->getValidationPhase(),
            $this->getTransformsPhase($configData),
            $this->getAuditPhase(),
        ];

        // Ensure all phases and modules have consistent keys
        foreach ($definitions as &$phase) {
            $phase['description'] = $phase['description'] ?? null;

            if (isset($phase['modules'])) {
                foreach ($phase['modules'] as &$module) {
                    // Set default values for optional keys
                    $module['supportsDryRun'] = $module['supportsDryRun'] ?? false;
                    $module['supportsResume'] = $module['supportsResume'] ?? false;
                    $module['requiresArgs'] = $module['requiresArgs'] ?? false;
                    $module['requiresYes'] = $module['requiresYes'] ?? false;
                }
            }
        }

        return $definitions;
    }

    /**
     * Get configuration data for placeholders
     */
    private function getConfigurationData(): array
    {
        $awsBucket = '';
        $awsRegion = '';
        $awsAccessKey = '';
        $awsSecretKey = '';
        $doBucket = '';
        $doBaseUrl = '';
        $doAccessKey = '';
        $doSecretKey = '';
        $doRegion = '';
        $doEndpoint = '';
        $targetVolumeHandle = 'images';
        $documentsVolumeHandle = 'documents';
        $quarantineVolumeHandle = 'quarantine';
        $optimisedImagesVolumeHandle = 'optimisedImages';
        $optimisedImagesFilesystemHandle = 'optimisedImages_do';
        $transformFilesystemHandle = 'imageTransforms_do';
        $optimizedImagesFieldHandle = 'optimizedImagesField';
        $templateEnvVarName = 'DO_S3_BASE_URL';
        $rcloneAwsRemoteName = 'aws-s3';
        $rcloneDoRemoteName = 'prod-medias';
        $rcloneTargetPath = 'medias';
        $rcloneCopyOptions = '--exclude "_*/**" --fast-list --transfers=32 --checkers=16 --use-mmap --s3-acl=public-read -P';
        $rcloneCheckOptions = '--one-way';

        $envVarNames = [
            'awsBucket' => 'AWS_SOURCE_BUCKET',
            'awsRegion' => 'AWS_SOURCE_REGION',
            'awsAccessKey' => 'AWS_SOURCE_ACCESS_KEY',
            'awsSecretKey' => 'AWS_SOURCE_SECRET_KEY',
            'doBucket' => 'DO_S3_BUCKET',
            'doBaseUrl' => 'DO_S3_BASE_URL',
            'doAccessKey' => 'DO_S3_ACCESS_KEY',
            'doSecretKey' => 'DO_S3_SECRET_KEY',
            'doRegion' => 'DO_S3_REGION',
            'doEndpoint' => 'DO_S3_BASE_ENDPOINT',
        ];

        try {
            $awsBucket = $this->config->getAwsBucket();
            $awsRegion = $this->config->getAwsRegion();
            $awsAccessKey = $this->config->getAwsAccessKey();
            $awsSecretKey = $this->config->getAwsSecretKey();
            $doBucket = $this->config->getDoBucket();
            $doBaseUrl = $this->config->getDoBaseUrl();
            $doAccessKey = $this->config->getDoAccessKey();
            $doSecretKey = $this->config->getDoSecretKey();
            $doRegion = $this->config->getDoRegion();
            $doEndpoint = $this->config->getDoEndpoint();
            $targetVolumeHandle = $this->config->getTargetVolumeHandle();
            $documentsVolumeHandle = $this->config->getDocumentsVolumeHandle();
            $quarantineVolumeHandle = $this->config->getQuarantineVolumeHandle();
            $optimisedImagesVolumeHandle = $this->config->getOptimisedImagesVolumeHandle();
            $optimisedImagesFilesystemHandle = $this->config->getOptimisedImagesFilesystemHandle();
            $transformFilesystemHandle = $this->config->getTransformFilesystemHandle();
            $optimizedImagesFieldHandle = $this->config->getOptimizedImagesFieldHandle();
            $templateEnvVarName = $this->config->getTemplateEnvVarName();
            $rcloneAwsRemoteName = $this->config->getRcloneAwsRemoteName();
            $rcloneDoRemoteName = $this->config->getRcloneDoRemoteName();
            $rcloneTargetPath = $this->config->getRcloneTargetPath();
            $rcloneCopyOptions = $this->config->getRcloneCopyOptions();
            $rcloneCheckOptions = $this->config->getRcloneCheckOptions();

            $envVarNames['awsBucket'] = $this->config->getAwsEnvVarBucket();
            $envVarNames['awsRegion'] = $this->config->getAwsEnvVarRegion();
            $envVarNames['awsAccessKey'] = $this->config->getAwsEnvVarAccessKey();
            $envVarNames['awsSecretKey'] = $this->config->getAwsEnvVarSecretKey();
            $envVarNames['doBucket'] = $this->config->getDoEnvVarBucketName();
            $envVarNames['doBaseUrl'] = $this->config->getDoEnvVarBaseUrlName();
            $envVarNames['doAccessKey'] = $this->config->getDoEnvVarAccessKeyName();
            $envVarNames['doSecretKey'] = $this->config->getDoEnvVarSecretKeyName();
            $envVarNames['doRegion'] = $this->config->getDoEnvVarRegionName();
            $envVarNames['doEndpoint'] = $this->config->getDoEnvVarEndpointName();
        } catch (\Throwable $e) {
            // Use defaults when configuration is unavailable
        }

        return [
            'aws' => [
                'bucket' => $awsBucket,
                'region' => $awsRegion,
                'accessKey' => $awsAccessKey,
                'secretKey' => $awsSecretKey,
            ],
            'do' => [
                'bucket' => $doBucket,
                'baseUrl' => $doBaseUrl,
                'accessKey' => $doAccessKey,
                'secretKey' => $doSecretKey,
                'region' => $doRegion,
                'endpoint' => $doEndpoint,
            ],
            'envVars' => $envVarNames,
            'handles' => [
                'targetVolume' => $targetVolumeHandle,
                'documentsVolume' => $documentsVolumeHandle,
                'quarantineVolume' => $quarantineVolumeHandle,
                'optimisedImagesVolume' => $optimisedImagesVolumeHandle,
                'optimisedImagesFilesystem' => $optimisedImagesFilesystemHandle,
                'transformFilesystem' => $transformFilesystemHandle,
                'optimizedImagesField' => $optimizedImagesFieldHandle,
            ],
            'templates' => [
                'envVarName' => $templateEnvVarName,
            ],
            'rclone' => [
                'awsRemoteName' => $rcloneAwsRemoteName,
                'doRemoteName' => $rcloneDoRemoteName,
                'targetPath' => $rcloneTargetPath,
                'copyOptions' => $rcloneCopyOptions,
                'checkOptions' => $rcloneCheckOptions,
            ],
        ];
    }

    /**
     * Generate placeholder value for display
     */
    private function placeholder(?string $value, ?string $envVarName, string $defaultPlaceholder): string
    {
        $value = $value !== null ? trim((string) $value) : '';
        if ($value !== '') {
            return $value;
        }

        $envVarName = $envVarName !== null ? trim((string) $envVarName) : '';
        if ($envVarName !== '') {
            return '${' . $envVarName . '}';
        }

        return $defaultPlaceholder;
    }

    /**
     * Escape values embedded into HTML descriptions.
     */
    private function html(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Build a short status label for env-backed configuration.
     */
    private function envStatus(?string $value): string
    {
        return trim((string) $value) !== '' ? 'resolved' : 'not resolved';
    }

    /**
     * Normalize endpoint URL
     */
    private function normalizeEndpoint(?string $endpoint): string
    {
        if ($endpoint === null) {
            return '';
        }

        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }

        if (stripos($endpoint, 'http://') === 0 || stripos($endpoint, 'https://') === 0) {
            $endpoint = preg_replace('#^https?://#i', '', $endpoint);
        }

        return rtrim($endpoint, '/');
    }

    /**
     * Get rclone configuration commands
     */
    private function getRcloneCommands(array $configData): array
    {
        // Generate AWS rclone command - use env var references for credentials
        $awsAccessKeyRef = '$AWS_SOURCE_ACCESS_KEY';
        $awsSecretKeyRef = '$AWS_SOURCE_SECRET_KEY';
        $awsRegionRef = '$AWS_SOURCE_REGION';
        $awsRemoteName = $configData['rclone']['awsRemoteName'] ?? 'aws-s3';
        $doRemoteName = $configData['rclone']['doRemoteName'] ?? 'prod-medias';
        $targetPath = trim((string) ($configData['rclone']['targetPath'] ?? 'medias'), '/');
        $copyOptions = trim((string) ($configData['rclone']['copyOptions'] ?? ''));
        $checkOptions = trim((string) ($configData['rclone']['checkOptions'] ?? ''));

        // Try to get from config if available
        try {
            $awsAccessKeyRef = $this->config->getAwsEnvVarAccessKeyRef();
            $awsSecretKeyRef = $this->config->getAwsEnvVarSecretKeyRef();
            $awsRegionRef = $this->config->getAwsEnvVarRegionRef();
        } catch (\Throwable $e) {
            // Use defaults
        }

        $doAccessKeyPlaceholder = $this->placeholder(
            $configData['do']['accessKey'],
            $configData['envVars']['doAccessKey'],
            '${DO_S3_ACCESS_KEY}'
        );
        $doSecretKeyPlaceholder = $this->placeholder(
            $configData['do']['secretKey'],
            $configData['envVars']['doSecretKey'],
            '${DO_S3_SECRET_KEY}'
        );

        $doEndpointHost = $this->normalizeEndpoint($configData['do']['endpoint']);
        $doEndpointPlaceholder = $doEndpointHost !== '' ? $doEndpointHost : '';

        if ($doEndpointPlaceholder === '') {
            $endpointEnvVar = $configData['envVars']['doEndpoint'] ?? '';
            if ($endpointEnvVar !== '') {
                $doEndpointPlaceholder = '${' . $endpointEnvVar . '}';
            }
        }

        $doRegionPlaceholder = $this->placeholder(
            $configData['do']['region'],
            $configData['envVars']['doRegion'],
            'tor1'
        );

        if ($doEndpointPlaceholder === '' && $doRegionPlaceholder !== '') {
            $candidate = $doRegionPlaceholder;
            if (stripos($candidate, 'digitaloceanspaces.com') === false) {
                $candidate = rtrim($candidate, '.') . '.digitaloceanspaces.com';
            }
            $doEndpointPlaceholder = $candidate;
        }

        if ($doEndpointPlaceholder === '') {
            $doEndpointPlaceholder = 'tor1.digitaloceanspaces.com';
        }

        $awsBucketPlaceholder = $this->placeholder(
            $configData['aws']['bucket'],
            $configData['envVars']['awsBucket'],
            '${AWS_SOURCE_BUCKET}'
        );

        $targetSpec = $doRemoteName . ':';
        if ($targetPath !== '') {
            $targetSpec .= $targetPath;
        }

        $rcloneAwsConfigCommand = sprintf(
            'rclone config create %s s3 provider AWS access_key_id %s secret_access_key %s region %s acl public-read',
            $awsRemoteName,
            $awsAccessKeyRef,
            $awsSecretKeyRef,
            $awsRegionRef
        );

        $rcloneDoConfigCommand = sprintf(
            'rclone config create %s s3 provider DigitalOcean access_key_id %s secret_access_key %s endpoint %s acl public-read',
            $doRemoteName,
            $doAccessKeyPlaceholder,
            $doSecretKeyPlaceholder,
            $doEndpointPlaceholder
        );

        $rcloneCopyCommand = sprintf('rclone copy %s:%s %s', $awsRemoteName, $awsBucketPlaceholder, $targetSpec);
        if ($copyOptions !== '') {
            $rcloneCopyCommand .= ' ' . $copyOptions;
        }

        $rcloneCheckCommand = sprintf('rclone check %s:%s %s', $awsRemoteName, $awsBucketPlaceholder, $targetSpec);
        if ($checkOptions !== '') {
            $rcloneCheckCommand .= ' ' . $checkOptions;
        }

        return [
            'awsConfig' => $rcloneAwsConfigCommand,
            'doConfig' => $rcloneDoConfigCommand,
            'copy' => $rcloneCopyCommand,
            'awsRemoteName' => $awsRemoteName,
            'doRemoteName' => $doRemoteName,
            'targetSpec' => $targetSpec,
            'check' => $rcloneCheckCommand,
        ];
    }

    /**
     * Prerequisites phase definition
     */
    private function getPrerequisitesPhase(array $configData): array
    {
        $rclone = $this->getRcloneCommands($configData);
        $awsBucket = $this->placeholder($configData['aws']['bucket'] ?? '', $configData['envVars']['awsBucket'] ?? '', 'Not set');
        $awsRegion = $this->placeholder($configData['aws']['region'] ?? '', $configData['envVars']['awsRegion'] ?? '', 'Not set');
        $doBucket = $this->placeholder($configData['do']['bucket'] ?? '', $configData['envVars']['doBucket'] ?? '', 'Not set');
        $doBaseUrl = $this->placeholder($configData['do']['baseUrl'] ?? '', $configData['envVars']['doBaseUrl'] ?? '', 'Not set');
        $doEndpoint = $this->placeholder($configData['do']['endpoint'] ?? '', $configData['envVars']['doEndpoint'] ?? '', 'Not set');
        $doRegion = $this->placeholder($configData['do']['region'] ?? '', $configData['envVars']['doRegion'] ?? '', 'tor1');
        $settingsSummary = sprintf(
            'Current resolved values:<br>• AWS Source Bucket: <code>%s</code><br>• AWS Source Region: <code>%s</code><br>• DO Bucket: <code>%s</code><br>• DO Base URL: <code>%s</code><br>• DO Base Endpoint: <code>%s</code><br>• DO Region: <code>%s</code><br><br>Current env references:<br>• AWS Access Key: <code>%s</code> (%s)<br>• AWS Secret Key: <code>%s</code> (%s)<br>• DO Access Key: <code>%s</code> (%s)<br>• DO Secret Key: <code>%s</code> (%s)',
            $this->html($awsBucket),
            $this->html($awsRegion),
            $this->html($doBucket),
            $this->html($doBaseUrl),
            $this->html($doEndpoint),
            $this->html($doRegion),
            $this->html($configData['envVars']['awsAccessKey'] ?? 'AWS_SOURCE_ACCESS_KEY'),
            $this->envStatus($configData['aws']['accessKey'] ?? ''),
            $this->html($configData['envVars']['awsSecretKey'] ?? 'AWS_SOURCE_SECRET_KEY'),
            $this->envStatus($configData['aws']['secretKey'] ?? ''),
            $this->html($configData['envVars']['doAccessKey'] ?? 'DO_S3_ACCESS_KEY'),
            $this->envStatus($configData['do']['accessKey'] ?? ''),
            $this->html($configData['envVars']['doSecretKey'] ?? 'DO_S3_SECRET_KEY'),
            $this->envStatus($configData['do']['secretKey'] ?? '')
        );

        return [
            'id' => 'prerequisites',
            'title' => '⚠️ Prerequisites (Complete BEFORE Migration)',
            'phase' => -1,
            'icon' => 'warning',
            'modules' => [
                [
                    'id' => 'install-plugin',
                    'title' => '1. Install DO Spaces Plugin (REQUIRED)',
                    'description' => 'CRITICAL: Install the DigitalOcean Spaces plugin FIRST.<br><br>Run these commands in your terminal:<br><code>composer require vaersaagod/dospaces<br>./craft plugin/install dospaces</code><br><br>Verify installation: Check that the plugin appears in Settings → Plugins',
                    'command' => null,
                    'duration' => '5-10 min',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'env-config',
                    'title' => '2. Configure Plugin Settings (REQUIRED)',
                    'description' => 'CRITICAL: Configure plugin settings via the Control Panel BEFORE rclone setup.<br><br>Go to: <strong>Settings → Plugins → S3 Spaces Migration → Plugin Settings</strong><br><br>Use the settings page to define bucket/region values, env-variable references, filesystem roles, and dashboard workflow commands.<br><br>' . $settingsSummary . '<br><br>All settings are stored in the Craft database and can be imported/exported via the plugin settings page.<br><br>⚠️ This MUST be done before the next steps!',
                    'command' => null,
                    'duration' => '5 min',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'install-rclone',
                    'title' => '3. Install & Configure rclone (REQUIRED)',
                    'description' => 'CRITICAL: Install rclone for efficient file synchronization.<br><br>Install: Visit https://rclone.org/install/<br>Verify: <code>which rclone</code><br><br>Configured remotes from settings:<br>• AWS remote: <code>' . $this->html($rclone['awsRemoteName']) . '</code><br>• DO remote: <code>' . $this->html($rclone['doRemoteName']) . '</code><br>• DO target: <code>' . $this->html($rclone['targetSpec']) . '</code><br><br>Configure AWS remote:<br><code>' . $rclone['awsConfig'] . '</code><br><br>Configure DO remote:<br><code>' . $rclone['doConfig'] . '</code><br><br>⚠️ The commands above use environment variables from step 2!',
                    'command' => null,
                    'duration' => '10-15 min',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'sync-files',
                    'title' => '4. Sync AWS → DO Files (REQUIRED)',
                    'description' => '📦 <strong>THIS IS THE ACTUAL DATA TRANSFER</strong> - Bulk copy ALL files from AWS to DO using rclone.<br><br>Initial sync (run this now):<br><code>' . $rclone['copy'] . '</code><br><br>Verify sync completed:<br><code>' . $rclone['check'] . '</code><br><br>⚠️ <strong>IMPORTANT:</strong> You will run a SECOND sync just before the filesystem switch in Phase 2 to catch any new files uploaded after the initial sync.<br><br>The "File Organization & Cleanup" phase (Phase 3) will NOT copy files - it just organizes the files already on DO.',
                    'command' => null,
                    'duration' => '1-4 hours',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'backup',
                    'title' => '5. Create Database Backup (REQUIRED)',
                    'description' => 'CRITICAL: Create a complete database backup before proceeding.<br><br>Run one of these commands:<br><code>./craft db/backup</code><br>Or with DDEV:<br><code>ddev export-db --file=backup-before-migration.sql.gz</code><br><br>Also backup config files:<br><code>tar -czf backup-files.tar.gz templates/ config/ modules/</code>',
                    'command' => null,
                    'duration' => '5-10 min',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'disable-asset-plugins',
                    'title' => '6. Disable Asset Management Plugins (REQUIRED)',
                    'description' => 'CRITICAL: Disable ALL asset management and image processing plugins to prevent asset transformation during migration.<br><br>Go to: <strong>Settings → Plugins</strong> and disable these if installed:<br>• <strong>Image Optimize</strong> - optimizes/transforms images on save<br>• <strong>ImageResizer</strong> - auto-resizes images on upload<br>• <strong>Imager-X</strong> - generates image transforms<br>• <strong>Image Toolbox</strong> - processes images automatically<br>• <strong>Transcoder</strong> - transforms media files<br>• <strong>TinyImage</strong> - compresses images<br>• <strong>Focal Point Field</strong> - may trigger image processing<br>• Any other plugins that automatically process, optimize, resize, or transform assets<br><br>⚠️ These plugins MUST remain disabled until AFTER the migration is complete to ensure assets are migrated without modification.<br><br>Re-enable them only after Phase 8 (Image Transforms) is complete.',
                    'command' => null,
                    'duration' => '5 min',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
            ]
        ];
    }

    /**
     * Setup phase definition
     */
    private function getSetupPhase(array $configData): array
    {
        $transformFs = $this->html($configData['handles']['transformFilesystem'] ?? 'imageTransforms_do');
        $quarantineVolume = $this->html($configData['handles']['quarantineVolume'] ?? 'quarantine');

        return [
            'id' => 'setup',
            'title' => 'Setup & Configuration',
            'phase' => 0,
            'icon' => 'settings',
            'modules' => [
                [
                    'id' => 'filesystem',
                    'title' => 'Create DO Filesystems',
                    'description' => 'Create new DigitalOcean Spaces filesystem configurations in Craft CMS.',
                    'command' => 'filesystem/create',
                    'duration' => '15-30 min',
                    'critical' => true,
                ],
                [
                    'id' => 'filesystem-list',
                    'title' => 'List Filesystems',
                    'description' => 'View all configured filesystems in the system.',
                    'command' => 'filesystem/list',
                    'duration' => '1-2 min',
                    'critical' => false,
                ],
                [
                    'id' => 'filesystem-fix',
                    'title' => 'Fix DO Spaces Endpoints',
                    'description' => 'Fix endpoint configurations for DigitalOcean Spaces filesystems.',
                    'command' => 'filesystem-fix/fix-endpoints',
                    'duration' => '2-5 min',
                    'critical' => false,
                ],
                [
                    'id' => 'filesystem-show',
                    'title' => 'Show Filesystem Config',
                    'description' => 'Display current filesystem configurations.',
                    'command' => 'filesystem-fix/show',
                    'duration' => '1-2 min',
                    'critical' => false,
                ],
                [
                    'id' => 'volume-config-status',
                    'title' => 'Volume Configuration Status',
                    'description' => 'Show current volume configuration status.',
                    'command' => 'volume-config/status',
                    'duration' => '1-2 min',
                    'critical' => false,
                ],
                [
                    'id' => 'volume-config',
                    'title' => 'Configure All Volumes',
                    'description' => 'CRITICAL: Configure transform filesystem for ALL volumes. This prevents transform pollution and ensures proper file organization.<br><br>This will set the transform filesystem for all volumes to use the dedicated transform filesystem <code>' . $transformFs . '</code>.',
                    'command' => 'volume-config/configure-all',
                    'duration' => '5-10 min',
                    'critical' => true,
                    'supportsDryRun' => true,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'volume-config-quarantine',
                    'title' => 'Create Quarantine Volume',
                    'description' => 'Create the quarantine volume <code>' . $quarantineVolume . '</code> for problematic assets.',
                    'command' => 'volume-config/create-quarantine-volume',
                    'duration' => '2-5 min',
                    'critical' => false,
                    'supportsDryRun' => true,
                    'requiresYes' => true,
                ],
            ]
        ];
    }

    /**
     * Pre-flight phase definition
     */
    private function getPreflightPhase(): array
    {
        return [
            'id' => 'preflight',
            'title' => 'Pre-Flight Checks',
            'phase' => 1,
            'icon' => 'check',
            'modules' => [
                [
                    'id' => 'migration-check',
                    'title' => 'Run Pre-Flight Checks',
                    'description' => 'Validate configuration and environment with 10 automated checks:<br>• DO Spaces plugin installed<br>• rclone available<br>• Fresh AWS → DO sync completed<br>• Transform filesystem configured<br>• Volume field layouts<br>• DO credentials valid<br>• AWS connectivity<br>• Database schema<br>• PHP environment<br>• File permissions',
                    'command' => 'migration-check/check',
                    'duration' => '5-10 min',
                    'critical' => true,
                ],
                [
                    'id' => 'migration-check-analyze',
                    'title' => 'Detailed Asset Analysis',
                    'description' => 'Show detailed analysis of assets before migration.',
                    'command' => 'migration-check/analyze',
                    'duration' => '5-10 min',
                    'critical' => false,
                ],
            ]
        ];
    }

    /**
     * URL replacement phase definition
     */
    private function getUrlReplacementPhase(): array
    {
        return [
            'id' => 'url-replacement',
            'title' => 'URL Replacement',
            'phase' => 5,
            'icon' => 'refresh',
            'modules' => [
                [
                    'id' => 'url-replacement-config',
                    'title' => 'Show URL Replacement Config',
                    'description' => 'Display current URL replacement configuration.',
                    'command' => 'url-replacement/show-config',
                    'duration' => '1-2 min',
                    'critical' => false,
                ],
                [
                    'id' => 'url-replacement',
                    'title' => 'Replace Database URLs',
                    'description' => 'Replace AWS URLs in content tables with DO URLs',
                    'command' => 'url-replacement/replace-s3-urls',
                    'duration' => '10-60 min',
                    'critical' => true,
                    'supportsDryRun' => true,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'url-replacement-verify',
                    'title' => 'Verify URL Replacement',
                    'description' => 'Verify that no AWS S3 URLs remain in the database.',
                    'command' => 'url-replacement/verify',
                    'duration' => '5-10 min',
                    'critical' => false,
                ],
                [
                    'id' => 'extended-url-scan',
                    'title' => 'Scan Additional Tables',
                    'description' => 'Scan additional database tables for AWS S3 URLs.',
                    'command' => 'extended-url-replacement/scan-additional',
                    'duration' => '5-10 min',
                    'critical' => false,
                ],
                [
                    'id' => 'extended-url',
                    'title' => 'Replace URLs in Additional Tables',
                    'description' => 'Replace URLs in additional tables.',
                    'command' => 'extended-url-replacement/replace-additional',
                    'duration' => '10-30 min',
                    'critical' => false,
                    'supportsDryRun' => true,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'extended-url-json',
                    'title' => 'Replace URLs in JSON Fields',
                    'description' => 'Replace URLs in JSON fields.',
                    'command' => 'extended-url-replacement/replace-json',
                    'duration' => '10-30 min',
                    'critical' => false,
                    'supportsDryRun' => true,
                ],
            ]
        ];
    }

    /**
     * Templates phase definition
     */
    private function getTemplatesPhase(array $configData): array
    {
        $templateEnvVar = $this->html($configData['templates']['envVarName'] ?? 'DO_S3_BASE_URL');

        return [
            'id' => 'templates',
            'title' => 'Template Updates',
            'phase' => 6,
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
                    'description' => 'Replace hardcoded URLs with the configured template environment variable <code>' . $templateEnvVar . '</code>.',
                    'command' => 'template-url-replacement/replace',
                    'duration' => '5-15 min',
                    'critical' => false,
                    'supportsDryRun' => true,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'template-verify',
                    'title' => 'Verify Template Updates',
                    'description' => 'Verify that no AWS URLs remain in templates.',
                    'command' => 'template-url-replacement/verify',
                    'duration' => '2-5 min',
                    'critical' => false,
                ],
                [
                    'id' => 'template-restore',
                    'title' => 'Restore Template Backups',
                    'description' => 'Restore templates from backups if needed.',
                    'command' => 'template-url-replacement/restore-backups',
                    'duration' => '2-5 min',
                    'critical' => false,
                    'requiresYes' => true,
                ],
            ]
        ];
    }

    /**
     * Filesystem switch phase definition
     */
    private function getSwitchPhase(array $configData): array
    {
        $rclone = $this->getRcloneCommands($configData);

        return [
            'id' => 'switch',
            'title' => 'Filesystem Switch',
            'phase' => 2,
            'icon' => 'transfer',
            'description' => '🔒 <strong>BEFORE STARTING THIS PHASE:</strong> Run a SECOND rclone sync to catch any files uploaded since the initial sync:<br><code>' . $rclone['copy'] . '</code><br><br>Then switch volumes to DigitalOcean to:<br><br>1️⃣ <strong>FREEZE AWS STATE</strong> - Prevents new writes to AWS S3 (preserves backup)<br>2️⃣ <strong>ENABLE INSTANT ROLLBACK</strong> - If migration fails, switch back to unchanged AWS<br>3️⃣ <strong>POINT TO DO SPACES</strong> - Next phases will organize files WITHIN DO (already synced via rclone) and then update URL references once files are in their final locations<br><br>⚠️ This is NOT the data transfer (rclone already copied files). This switches Craft CMS to read from DO Spaces.',
            'modules' => [
                [
                    'id' => 'switch-list',
                    'title' => 'List Filesystems',
                    'description' => 'List all filesystems defined in Project Config.',
                    'command' => 'filesystem-switch/list-filesystems',
                    'duration' => '1-2 min',
                    'critical' => false,
                ],
                [
                    'id' => 'switch-test',
                    'title' => 'Test Connectivity',
                    'description' => 'Test connectivity to all filesystems defined in Project Config.',
                    'command' => 'filesystem-switch/test-connectivity',
                    'duration' => '2-5 min',
                    'critical' => false,
                ],
                [
                    'id' => 'switch-preview',
                    'title' => 'Preview Switch',
                    'description' => 'Preview what will be changed (dry run).',
                    'command' => 'filesystem-switch/preview',
                    'duration' => '1-2 min',
                    'critical' => false,
                ],
                [
                    'id' => 'switch-to-do',
                    'title' => 'Switch to DO Spaces',
                    'description' => '🔒 CRITICAL: Switch all Craft CMS volumes to point to DigitalOcean Spaces.<br><br><strong>WHY THIS HAPPENS FIRST:</strong><br>• Freezes AWS S3 (no new files written = pristine backup)<br>• Enables instant rollback if migration fails<br>• Files are ALREADY on DO via rclone sync<br>• Next phase just cleans up/organizes within DO<br><br>⚠️ This is a database-only operation - changes volume configs to point to DO filesystem.',
                    'command' => 'filesystem-switch/to-do',
                    'duration' => '2-5 min',
                    'critical' => true,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'switch-verify',
                    'title' => 'Verify Filesystem Setup',
                    'description' => 'Verify current filesystem setup after switching.',
                    'command' => 'filesystem-switch/verify',
                    'duration' => '2-5 min',
                    'critical' => false,
                ],
                [
                    'id' => 'switch-to-aws',
                    'title' => '🔙 Emergency Rollback to AWS',
                    'description' => '⚠️ <strong>EMERGENCY USE ONLY</strong> - Instantly switches volumes back to AWS S3.<br><br>Use this if:<br>• File migration fails and cannot be fixed<br>• Need to restore service immediately<br>• AWS is still intact (frozen during migration)<br><br><strong>WARNING:</strong> Any new files uploaded to DO AFTER the switch will be lost when rolling back to AWS!',
                    'command' => 'filesystem-switch/to-aws',
                    'duration' => '2-5 min',
                    'critical' => false,
                    'requiresYes' => true,
                ],
            ]
        ];
    }

    /**
     * Migration phase definition
     */
    private function getMigrationPhase(array $configData): array
    {
        $optimisedVolume = $this->html($configData['handles']['optimisedImagesVolume'] ?? 'optimisedImages');

        return [
            'id' => 'migration',
            'title' => 'File Organization & Cleanup',
            'phase' => 3,
            'icon' => 'upload',
            'description' => '🧹 <strong>DO-to-DO CLEANUP (NOT data transfer)</strong><br><br>Files are already on DigitalOcean Spaces via rclone sync. This phase:<br><br>1️⃣ <strong>Links inline images</strong> - Creates asset relations for RTE images<br>2️⃣ <strong>Fixes broken links</strong> - Updates asset paths to match actual files<br>3️⃣ <strong>Consolidates files</strong> - Moves files to correct folder structure within DO<br>4️⃣ <strong>Quarantines unused</strong> - Safely archives orphaned files for review<br>5️⃣ <strong>Resolves duplicates</strong> - Merges duplicate asset records<br><br>✅ All operations happen WITHIN DigitalOcean Spaces (reorganization, not copying)',
            'modules' => [
                [
                    'id' => 'transform-cleanup',
                    'title' => 'Clean OptimisedImages Transforms',
                    'description' => 'Remove cached transforms stored in underscore-prefixed folders inside the configured optimised-images volume <code>' . $optimisedVolume . '</code> so the migration only copies source assets. Run in dry run mode first to review the files that will be deleted.',
                    'command' => 'transform-cleanup/clean',
                    'duration' => '5-20 min',
                    'critical' => true,
                    'supportsDryRun' => true,
                ],
                [
                    'id' => 'image-migration-status',
                    'title' => 'Migration Status',
                    'description' => 'List available checkpoints and migrations.',
                    'command' => 'image-migration/status',
                    'duration' => '1-2 min',
                    'critical' => false,
                ],
                [
                    'id' => 'image-migration',
                    'title' => 'Organize & Clean Files (DO-to-DO)',
                    'description' => '🧹 <strong>CLEANUP WITHIN DO SPACES (NOT AWS-to-DO transfer)</strong><br><br>Files are already on DO via rclone. This command:<br>• Links inline RTE images to assets<br>• Fixes broken asset-file paths<br>• Consolidates files to proper locations<br>• Quarantines unused/orphaned files<br>• Resolves duplicate asset records<br><br>✅ All operations within DO Spaces<br>✅ Checkpoint/resume support<br>✅ Full rollback capability<br><br>Duration: 1-48 hours (depends on asset count)',
                    'command' => 'image-migration/migrate',
                    'duration' => '1-48 hours',
                    'critical' => true,
                    'supportsDryRun' => true,
                    'supportsResume' => true,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'image-migration-monitor',
                    'title' => 'Monitor Migration',
                    'description' => 'Monitor migration progress in real-time.',
                    'command' => 'image-migration/monitor',
                    'duration' => 'Continuous',
                    'critical' => false,
                ],
                [
                    'id' => 'image-migration-cleanup',
                    'title' => 'Cleanup Checkpoints',
                    'description' => 'Cleanup old checkpoints and logs after successful migration.',
                    'command' => 'image-migration/cleanup',
                    'duration' => '2-5 min',
                    'critical' => false,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'image-migration-force-cleanup',
                    'title' => 'Force Cleanup',
                    'description' => 'Force cleanup - removes ALL locks and old data. Use with caution!',
                    'command' => 'image-migration/force-cleanup',
                    'duration' => '2-5 min',
                    'critical' => false,
                    'requiresYes' => true,
                ],
            ]
        ];
    }

    /**
     * File consolidation phase definition (must run before URL replacement)
     */
    private function getConsolidationPhase(array $configData): array
    {
        $optimisedVolume = $this->html($configData['handles']['optimisedImagesVolume'] ?? 'optimisedImages');
        $targetVolume = $this->html($configData['handles']['targetVolume'] ?? 'images');

        return [
            'id' => 'consolidation',
            'title' => 'Volume Consolidation',
            'phase' => 4,
            'icon' => 'layers',
            'description' => '📦 <strong>FINALIZE FILE LOCATIONS BEFORE URL REPLACEMENT</strong><br><br>These operations physically move files and update asset records. They must complete before URL replacement so that database and template URLs reflect the correct final paths.<br><br>1️⃣ Check consolidation status to identify what needs to move<br>2️⃣ Merge <code>' . $optimisedVolume . '</code> → <code>' . $targetVolume . '</code> (if optimised-image assets remain)<br>3️⃣ Flatten subfolders → root (if volumes require a flat structure)<br>4️⃣ Move any user assets misplaced in /originals (edge case)',
            'modules' => [
                [
                    'id' => 'volume-consolidation-status',
                    'title' => 'Check Consolidation Status',
                    'description' => 'Check if volume consolidation is needed (<code>' . $optimisedVolume . '</code> → <code>' . $targetVolume . '</code>, subfolders → root). Run this first to decide which of the steps below are required.',
                    'command' => 'volume-consolidation/status',
                    'duration' => '1-2 min',
                    'critical' => true,
                ],
                [
                    'id' => 'volume-consolidation-merge',
                    'title' => 'Merge OptimisedImages → Images',
                    'description' => 'Move ALL assets from <code>' . $optimisedVolume . '</code> to <code>' . $targetVolume . '</code> (database + physical files). Required when the optimised-images volume was not included in the initial rclone source config or when its assets remain after Phase 3. Automatically resolves duplicate filenames.',
                    'command' => 'volume-consolidation/merge-optimized-to-images',
                    'duration' => '10-60 min',
                    'critical' => false,
                    'supportsDryRun' => true,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'volume-consolidation-flatten',
                    'title' => 'Flatten Subfolders → Root',
                    'description' => 'Move ALL assets from subfolders to the root folder in <code>' . $targetVolume . '</code> (database + physical files). Required for volumes configured as flat-structure. Handles duplicate filenames automatically.',
                    'command' => 'volume-consolidation/flatten-to-root',
                    'duration' => '10-60 min',
                    'critical' => false,
                    'supportsDryRun' => true,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'migration-diag-move',
                    'title' => 'Move Originals to Images',
                    'description' => '⚠️ <strong>Edge case only</strong> — Run only if user assets were accidentally placed in the <code>/originals/</code> subfolder during migration instead of at the volume root. The <code>/originals/</code> folder is normally reserved by Craft for transform originals and should not be emptied under normal circumstances.',
                    'command' => 'migration-diag/move-originals',
                    'duration' => '10-30 min',
                    'critical' => false,
                    'supportsDryRun' => true,
                ],
            ]
        ];
    }

    /**
     * Validation phase definition (diagnostics only — runs after URL replacement)
     */
    private function getValidationPhase(): array
    {
        return [
            'id' => 'validation',
            'title' => 'Post-Migration Validation',
            'phase' => 7,
            'icon' => 'check-circle',
            'modules' => [
                [
                    'id' => 'migration-diag',
                    'title' => 'Analyze Migration State',
                    'description' => 'Analyze current state after migration.',
                    'command' => 'migration-diag/analyze',
                    'duration' => '10-30 min',
                    'critical' => true,
                ],
                [
                    'id' => 'migration-diag-missing',
                    'title' => 'Check Missing Files',
                    'description' => 'Check for missing files that caused errors during migration.',
                    'command' => 'migration-diag/check-missing-files',
                    'duration' => '5-15 min',
                    'critical' => false,
                ],
                [
                    'id' => 'post-migration-commands',
                    'title' => 'Post-Migration Commands (REQUIRED)',
                    'description' => 'CRITICAL: Run these commands IN ORDER after migration:<br><br>1. Rebuild asset indexes:<br><code>./craft index-assets/all</code><br><br>2. Rebuild search indexes:<br><code>./craft resave/entries --update-search-index=1</code><br><br>3. Resave all assets:<br><code>./craft resave/assets</code><br><br>4. Clear all Craft caches:<br><code>./craft clear-caches/all</code><br><code>./craft invalidate-tags/all</code><br><br>5. Purge CDN cache manually:<br>• CloudFlare: Dashboard → Caching → Purge Everything<br>• Fastly: Dashboard → Purge → Purge All<br><br>These steps are ESSENTIAL for proper site functionality!',
                    'command' => null,
                    'duration' => '15-30 min',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
            ]
        ];
    }

    /**
     * Transforms phase definition
     */
    private function getTransformsPhase(array $configData): array
    {
        $targetVolume = $this->html($configData['handles']['targetVolume'] ?? 'images');
        $fieldHandle = $this->html($configData['handles']['optimizedImagesField'] ?? 'optimizedImagesField');

        return [
            'id' => 'transforms',
            'title' => 'Image Transforms',
            'phase' => 8,
            'icon' => 'image',
            'description' => '📸 <strong>TRANSFORM WORKFLOW:</strong><br><br>1️⃣ <strong>Discovery</strong> - Scan database AND templates for all transform usage<br>2️⃣ <strong>Generation</strong> - Pre-generate all discovered transforms<br>3️⃣ <strong>Verification</strong> - Confirm all transforms exist<br>4️⃣ <strong>Optional: Warmup</strong> - Crawl pages to trigger additional transforms<br><br>This prevents broken images during migration.',
            'modules' => [
                [
                    'id' => 'add-optimised-field',
                    'title' => 'Add optimisedImagesField (REQUIRED FIRST)',
                    'description' => 'CRITICAL: Add the configured optimized-images field <code>' . $fieldHandle . '</code> to the target volume <code>' . $targetVolume . '</code> BEFORE generating transforms.<br><br>Run in terminal:<br><code>./craft spaghetti-migrator/volume-config/add-optimised-field ' . $targetVolume . '</code><br><br>Or add it manually to the target volume field layout before continuing.<br><br>This ensures transforms are correctly generated and prevents errors.',
                    'command' => null,
                    'duration' => '2-5 min',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'transform-discovery-all',
                    'title' => '1️⃣ Discover ALL Transforms (Database + Templates)',
                    'description' => '🔍 <strong>COMPREHENSIVE DISCOVERY</strong> - Scans BOTH database content AND Twig templates for transform usage.<br><br><strong>Scans for:</strong><br>• Background-image URLs in database fields<br>• ImageOptimize transform references<br>• Inline img src with transform parameters<br>• Twig .getUrl() calls<br>• srcset() references<br>• Named transform handles<br><br>Generates a report for the next step.<br><br>⚠️ Run this ONCE to get complete coverage.',
                    'command' => 'transform-discovery/discover',
                    'duration' => '10-30 min',
                    'critical' => true,
                ],
                [
                    'id' => 'transform-discovery-db',
                    'title' => 'Scan Database Only (Optional)',
                    'description' => 'Scan only database for transforms. Use only if you need to isolate database-only transforms for troubleshooting.',
                    'command' => 'transform-discovery/scan-database',
                    'duration' => '5-15 min',
                    'critical' => false,
                ],
                [
                    'id' => 'transform-discovery-templates',
                    'title' => 'Scan Templates Only (Optional)',
                    'description' => 'Scan only Twig templates for transforms. Use only if you need to isolate template-only transforms for troubleshooting.',
                    'command' => 'transform-discovery/scan-templates',
                    'duration' => '5-15 min',
                    'critical' => false,
                ],
                [
                    'id' => 'transform-pregeneration',
                    'title' => '2️⃣ Generate Transforms',
                    'description' => '⚙️ <strong>PRE-GENERATE TRANSFORMS</strong> - Uses the discovery report to pre-generate all image transforms.<br><br><strong>What it does:</strong><br>• Loads latest discovery report automatically<br>• Pre-generates transforms in batches<br>• Supports checkpointing for large datasets<br>• Can be resumed if interrupted<br><br><strong>Performance:</strong><br>• Batch size configurable<br>• Concurrent generation supported<br>• Progress tracking with visual feedback<br><br>Run this AFTER discovery completes.',
                    'command' => 'transform-pre-generation/generate',
                    'duration' => '30 min - 6 hours',
                    'critical' => true,
                    'supportsDryRun' => true,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'transform-pregeneration-verify',
                    'title' => '3️⃣ Verify Transforms',
                    'description' => '✅ <strong>VERIFY ALL TRANSFORMS EXIST</strong> - Confirms that all discovered transforms have been successfully generated.<br><br>Checks:<br>• All transforms from discovery report<br>• Reports missing transforms<br>• Shows coverage percentage<br><br>Run this AFTER generation to ensure completeness.',
                    'command' => 'transform-pre-generation/verify',
                    'duration' => '10-30 min',
                    'critical' => true,
                ],
                [
                    'id' => 'transform-pregeneration-warmup',
                    'title' => '4️⃣ Warmup Transforms (Optional)',
                    'description' => '🔥 <strong>WARMUP BY PAGE CRAWLING</strong> - Visits all pages to trigger on-demand transform generation.<br><br>Use this to:<br>• Catch any transforms missed by discovery<br>• Simulate real user traffic patterns<br>• Pre-generate dynamic transforms<br><br>This is optional but recommended for comprehensive coverage.',
                    'command' => 'transform-pre-generation/warmup',
                    'duration' => '30 min - 2 hours',
                    'critical' => false,
                    'supportsDryRun' => true,
                    'requiresYes' => true,
                ],
            ]
        ];
    }

    /**
     * Audit phase definition
     */
    private function getAuditPhase(): array
    {
        return [
            'id' => 'audit',
            'title' => 'Audit & Diagnostics',
            'phase' => 9,
            'icon' => 'search',
            'modules' => [
                [
                    'id' => 'missing-file-fix-analyze',
                    'title' => '🔍 Analyze Missing Files',
                    'description' => '<strong>SCAN FOR MISSING FILES</strong> - Analyzes assets with missing physical files and searches quarantine for matches.<br><br><strong>What it does:</strong><br>• Finds all assets with missing files<br>• Searches quarantine for orphaned files<br>• Identifies files in wrong volumes<br>• Shows detailed statistics<br><br><strong>File Type Mapping:</strong><br>• PDFs, DOCX, ZIP, TXT → Documents volume<br>• Images (JPG, PNG, etc.) → Images volume<br><br>Run this FIRST to understand the scope of missing files.',
                    'command' => 'missing-file-fix/analyze',
                    'duration' => '5-10 min',
                    'critical' => true,
                ],
                [
                    'id' => 'missing-file-fix-fix',
                    'title' => '🔧 Fix Missing File Associations',
                    'description' => '<strong>RECONNECT QUARANTINED FILES</strong> - Moves files from quarantine to correct locations and updates database records.<br><br><strong>What it does:</strong><br>• Finds orphaned files in quarantine<br>• Matches them to asset records<br>• Moves files to correct volumes<br>• Updates database records<br>• Shows summary of fixed files<br><br><strong>⚠️ IMPORTANT:</strong><br>• Always run "Analyze" first<br>• Test with dry-run mode first (default)<br>• Turn off dry-run to apply changes<br><br>This fixes the link between database records and physical files.',
                    'command' => 'missing-file-fix/fix',
                    'duration' => '10-30 min',
                    'critical' => true,
                    'supportsDryRun' => true,
                    'requiresYes' => true,
                ],
                [
                    'id' => 'plugin-config-audit-list',
                    'title' => 'List Installed Plugins',
                    'description' => 'List all installed plugins in the system.',
                    'command' => 'plugin-config-audit/list-plugins',
                    'duration' => '1-2 min',
                    'critical' => false,
                ],
                [
                    'id' => 'plugin-config-audit',
                    'title' => 'Scan Plugin Configurations',
                    'description' => 'Scan plugin configurations for hardcoded AWS S3 URLs.',
                    'command' => 'plugin-config-audit/scan',
                    'duration' => '5-15 min',
                    'critical' => false,
                ],
                [
                    'id' => 'static-asset-scan',
                    'title' => 'Scan Static Assets',
                    'description' => 'Scan JS/CSS/SCSS files for hardcoded AWS S3 URLs.',
                    'command' => 'static-asset-scan/scan',
                    'duration' => '5-15 min',
                    'critical' => false,
                ],
                [
                    'id' => 'fs-diag-list',
                    'title' => 'List Filesystem Files',
                    'description' => 'List files in a filesystem by handle (NO VOLUME REQUIRED).<br>Requires filesystem handle argument.',
                    'command' => 'fs-diag/list-fs',
                    'duration' => '5-10 min',
                    'critical' => false,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'fs-diag-compare',
                    'title' => 'Compare Filesystems',
                    'description' => 'Compare two filesystems to find differences.<br>Requires two filesystem handles as arguments.',
                    'command' => 'fs-diag/compare-fs',
                    'duration' => '10-30 min',
                    'critical' => false,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'fs-diag-search',
                    'title' => 'Search Filesystem',
                    'description' => 'Search for specific files in a filesystem by handle.<br>Requires filesystem handle and search pattern.',
                    'command' => 'fs-diag/search-fs',
                    'duration' => '5-15 min',
                    'critical' => false,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'fs-diag-verify',
                    'title' => 'Verify File Exists',
                    'description' => 'Verify if specific file exists in filesystem.<br>Requires filesystem handle and file path.',
                    'command' => 'fs-diag/verify-fs',
                    'duration' => '1-5 min',
                    'critical' => false,
                    'requiresArgs' => true,
                ],
            ]
        ];
    }
}
