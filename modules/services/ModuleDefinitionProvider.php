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
    private ?MigrationConfig $config = null;

    public function __construct(?MigrationConfig $config = null)
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
            $this->getSetupPhase(),
            $this->getPreflightPhase(),
            $this->getUrlReplacementPhase(),
            $this->getTemplatesPhase(),
            $this->getSwitchPhase($configData),
            $this->getMigrationPhase(),
            $this->getValidationPhase(),
            $this->getTransformsPhase(),
            $this->getAuditPhase(),
        ];

        // Ensure all modules have consistent keys
        foreach ($definitions as &$phase) {
            if (isset($phase['modules'])) {
                foreach ($phase['modules'] as &$module) {
                    // Set default values for optional keys
                    $module['supportsDryRun'] = $module['supportsDryRun'] ?? false;
                    $module['supportsResume'] = $module['supportsResume'] ?? false;
                    $module['requiresArgs'] = $module['requiresArgs'] ?? false;
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
        $doAccessKey = '';
        $doSecretKey = '';
        $doRegion = '';
        $doEndpoint = '';

        $envVarNames = [
            'awsBucket' => 'AWS_SOURCE_BUCKET',
            'awsRegion' => 'AWS_SOURCE_REGION',
            'awsAccessKey' => 'AWS_SOURCE_ACCESS_KEY',
            'awsSecretKey' => 'AWS_SOURCE_SECRET_KEY',
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
            $doAccessKey = $this->config->getDoAccessKey();
            $doSecretKey = $this->config->getDoSecretKey();
            $doRegion = $this->config->getDoRegion();
            $doEndpoint = $this->config->getDoEndpoint();

            $envVarNames['awsBucket'] = $this->config->getAwsEnvVarBucket();
            $envVarNames['awsRegion'] = $this->config->getAwsEnvVarRegion();
            $envVarNames['awsAccessKey'] = $this->config->getAwsEnvVarAccessKey();
            $envVarNames['awsSecretKey'] = $this->config->getAwsEnvVarSecretKey();
            $envVarNames['doAccessKey'] = $this->config->get('envVars.doAccessKey', $envVarNames['doAccessKey']);
            $envVarNames['doSecretKey'] = $this->config->get('envVars.doSecretKey', $envVarNames['doSecretKey']);
            $envVarNames['doRegion'] = $this->config->get('envVars.doRegion', $envVarNames['doRegion']);
            $envVarNames['doEndpoint'] = $this->config->get('envVars.doEndpoint', $envVarNames['doEndpoint']);
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
                'accessKey' => $doAccessKey,
                'secretKey' => $doSecretKey,
                'region' => $doRegion,
                'endpoint' => $doEndpoint,
            ],
            'envVars' => $envVarNames,
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

        $rcloneAwsConfigCommand = sprintf(
            'rclone config create aws-s3 s3 provider AWS access_key_id %s secret_access_key %s region %s acl public-read',
            $awsAccessKeyRef,
            $awsSecretKeyRef,
            $awsRegionRef
        );

        $rcloneDoConfigCommand = sprintf(
            'rclone config create prod-medias s3 provider DigitalOcean access_key_id %s secret_access_key %s endpoint %s acl public-read',
            $doAccessKeyPlaceholder,
            $doSecretKeyPlaceholder,
            $doEndpointPlaceholder
        );

        $rcloneCopyCommand = sprintf(
            'rclone copy aws-s3:%s prod-medias:medias --exclude "_*/**" --fast-list --transfers=32 --checkers=16 --use-mmap --s3-acl=public-read -P',
            $awsBucketPlaceholder
        );

        $rcloneCheckCommand = sprintf(
            'rclone check aws-s3:%s prod-medias:medias --one-way',
            $awsBucketPlaceholder
        );

        return [
            'awsConfig' => $rcloneAwsConfigCommand,
            'doConfig' => $rcloneDoConfigCommand,
            'copy' => $rcloneCopyCommand,
            'check' => $rcloneCheckCommand,
        ];
    }

    /**
     * Prerequisites phase definition
     */
    private function getPrerequisitesPhase(array $configData): array
    {
        $rclone = $this->getRcloneCommands($configData);

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
                    'description' => 'CRITICAL: Configure plugin settings via the Control Panel BEFORE rclone setup.<br><br>Go to: <strong>Settings → Plugins → S3 Spaces Migration → Plugin Settings</strong><br><br>Configure the following:<br>• AWS Source Bucket<br>• AWS Source Region<br>• AWS Access Key<br>• AWS Secret Key<br>• DO Access Key<br>• DO Secret Key<br>• DO Bucket<br>• DO Base URL (e.g., https://your-bucket.tor1.digitaloceanspaces.com)<br>• DO Base Endpoint (e.g., tor1.digitaloceanspaces.com)<br>• DO Region (e.g., tor1)<br><br>All settings are stored in the Craft database and can be imported/exported via the plugin settings page.<br><br>⚠️ This MUST be done before the next steps!',
                    'command' => null,
                    'duration' => '5 min',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'install-rclone',
                    'title' => '3. Install & Configure rclone (REQUIRED)',
                    'description' => 'CRITICAL: Install rclone for efficient file synchronization.<br><br>Install: Visit https://rclone.org/install/<br>Verify: <code>which rclone</code><br><br>Configure AWS remote:<br><code>' . $rclone['awsConfig'] . '</code><br><br>Configure DO remote:<br><code>' . $rclone['doConfig'] . '</code><br><br>⚠️ The commands above use environment variables from step 2!',
                    'command' => null,
                    'duration' => '10-15 min',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'sync-files',
                    'title' => '4. Sync AWS → DO Files (REQUIRED)',
                    'description' => '📦 <strong>THIS IS THE ACTUAL DATA TRANSFER</strong> - Bulk copy ALL files from AWS to DO using rclone.<br><br>Initial sync (run this now):<br><code>' . $rclone['copy'] . '</code><br><br>Verify sync completed:<br><code>' . $rclone['check'] . '</code><br><br>⚠️ <strong>IMPORTANT:</strong> You will run a SECOND sync just before the filesystem switch in Phase 4 to catch any new files uploaded during URL replacement phases.<br><br>The "File Migration" phase (Phase 5) will NOT copy files - it just organizes the files already on DO.',
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
                    'description' => 'CRITICAL: Disable ALL asset management and image processing plugins to prevent asset transformation during migration.<br><br>Go to: <strong>Settings → Plugins</strong> and disable these if installed:<br>• <strong>Image Optimize</strong> - optimizes/transforms images on save<br>• <strong>ImageResizer</strong> - auto-resizes images on upload<br>• <strong>Imager-X</strong> - generates image transforms<br>• <strong>Image Toolbox</strong> - processes images automatically<br>• <strong>Transcoder</strong> - transforms media files<br>• <strong>TinyImage</strong> - compresses images<br>• <strong>Focal Point Field</strong> - may trigger image processing<br>• Any other plugins that automatically process, optimize, resize, or transform assets<br><br>⚠️ These plugins MUST remain disabled until AFTER the migration is complete to ensure assets are migrated without modification.<br><br>Re-enable them only after Phase 7 (Image Transforms) is complete.',
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
    private function getSetupPhase(): array
    {
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
                    'description' => 'CRITICAL: Configure transform filesystem for ALL volumes. This prevents transform pollution and ensures proper file organization.<br><br>This will set the transform filesystem for all volumes to use the dedicated transform volume.',
                    'command' => 'volume-config/configure-all',
                    'duration' => '5-10 min',
                    'critical' => true,
                    'supportsDryRun' => true,
                ],
                [
                    'id' => 'volume-config-quarantine',
                    'title' => 'Create Quarantine Volume',
                    'description' => 'Create quarantine volume for problematic assets.',
                    'command' => 'volume-config/create-quarantine-volume',
                    'duration' => '2-5 min',
                    'critical' => false,
                    'supportsDryRun' => true,
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
            'phase' => 2,
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
    private function getTemplatesPhase(): array
    {
        return [
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
            'phase' => 4,
            'icon' => 'transfer',
            'description' => '🔒 <strong>BEFORE STARTING THIS PHASE:</strong> Run a SECOND rclone sync to catch any new files uploaded during URL replacement:<br><code>' . $rclone['copy'] . '</code><br><br>Then switch volumes to DigitalOcean to:<br><br>1️⃣ <strong>FREEZE AWS STATE</strong> - Prevents new writes to AWS S3 (preserves backup)<br>2️⃣ <strong>ENABLE INSTANT ROLLBACK</strong> - If migration fails, switch back to unchanged AWS<br>3️⃣ <strong>POINT TO DO SPACES</strong> - Next phase will organize files WITHIN DO (already synced via rclone)<br><br>⚠️ This is NOT the data transfer (rclone already copied files). This switches Craft CMS to read from DO Spaces.',
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
                ],
            ]
        ];
    }

    /**
     * Migration phase definition
     */
    private function getMigrationPhase(): array
    {
        return [
            'id' => 'migration',
            'title' => 'File Organization & Cleanup',
            'phase' => 5,
            'icon' => 'upload',
            'description' => '🧹 <strong>DO-to-DO CLEANUP (NOT data transfer)</strong><br><br>Files are already on DigitalOcean Spaces via rclone sync. This phase:<br><br>1️⃣ <strong>Links inline images</strong> - Creates asset relations for RTE images<br>2️⃣ <strong>Fixes broken links</strong> - Updates asset paths to match actual files<br>3️⃣ <strong>Consolidates files</strong> - Moves files to correct folder structure within DO<br>4️⃣ <strong>Quarantines unused</strong> - Safely archives orphaned files for review<br>5️⃣ <strong>Resolves duplicates</strong> - Merges duplicate asset records<br><br>✅ All operations happen WITHIN DigitalOcean Spaces (reorganization, not copying)',
            'modules' => [
                [
                    'id' => 'transform-cleanup',
                    'title' => 'Clean OptimisedImages Transforms',
                    'description' => 'Remove cached transforms stored in underscore-prefixed folders inside the Optimised Images volume (ID 4) so the migration only copies source assets. Run in dry run mode first to review the files that will be deleted.',
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
                ],
                [
                    'id' => 'image-migration-force-cleanup',
                    'title' => 'Force Cleanup',
                    'description' => 'Force cleanup - removes ALL locks and old data. Use with caution!',
                    'command' => 'image-migration/force-cleanup',
                    'duration' => '2-5 min',
                    'critical' => false,
                ],
            ]
        ];
    }

    /**
     * Validation phase definition
     */
    private function getValidationPhase(): array
    {
        return [
            'id' => 'validation',
            'title' => 'Post-Migration Validation',
            'phase' => 6,
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
                    'id' => 'migration-diag-move',
                    'title' => 'Move Originals to Images',
                    'description' => 'Move assets from /originals to /images folder.',
                    'command' => 'migration-diag/move-originals',
                    'duration' => '10-30 min',
                    'critical' => false,
                    'supportsDryRun' => true,
                ],
                [
                    'id' => 'volume-consolidation-status',
                    'title' => 'Check Consolidation Status',
                    'description' => 'Check if volume consolidation is needed (OptimisedImages → Images, subfolders → root).',
                    'command' => 'volume-consolidation/status',
                    'duration' => '1-2 min',
                    'critical' => false,
                ],
                [
                    'id' => 'volume-consolidation-merge',
                    'title' => 'Merge OptimisedImages → Images',
                    'description' => 'Move ALL assets from OptimisedImages volume to Images volume. Handles the edge case where OptimisedImages is at bucket root. Automatically renames duplicate filenames.',
                    'command' => 'volume-consolidation/merge-optimized-to-images',
                    'duration' => '10-60 min',
                    'critical' => false,
                    'supportsDryRun' => true,
                ],
                [
                    'id' => 'volume-consolidation-flatten',
                    'title' => 'Flatten Subfolders → Root',
                    'description' => 'Move ALL assets from subfolders (including /originals/) to root folder in Images volume. Handles duplicate filenames automatically.',
                    'command' => 'volume-consolidation/flatten-to-root',
                    'duration' => '10-60 min',
                    'critical' => false,
                    'supportsDryRun' => true,
                ],
                [
                    'id' => 'post-migration-commands',
                    'title' => 'Post-Migration Commands (REQUIRED)',
                    'description' => 'CRITICAL: Run these commands IN ORDER after migration:<br><br>1. Rebuild asset indexes:<br><code>./craft index-assets/all</code><br><br>2. Rebuild search indexes:<br><code>./craft resave/entries --update-search-index=1</code><br><br>3. Resave all assets:<br><code>./craft resave/assets</code><br><br>4. Clear all Craft caches:<br><code>./craft clear-caches/all</code><br><code>./craft invalidate-tags/all</code><br><code>./craft clear-caches/template-caches</code><br><br>5. Purge CDN cache manually:<br>• CloudFlare: Dashboard → Caching → Purge Everything<br>• Fastly: Dashboard → Purge → Purge All<br><br>These steps are ESSENTIAL for proper site functionality!',
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
    private function getTransformsPhase(): array
    {
        return [
            'id' => 'transforms',
            'title' => 'Image Transforms',
            'phase' => 7,
            'icon' => 'image',
            'modules' => [
                [
                    'id' => 'add-optimised-field',
                    'title' => 'Add optimisedImagesField (REQUIRED FIRST)',
                    'description' => 'CRITICAL: Add optimisedImagesField to Images (DO) volume BEFORE generating transforms.<br><br>Run in terminal:<br><code>./craft s3-spaces-migration/volume-config/add-optimised-field images</code><br><br>Or add manually via CP:<br>1. Settings → Assets → Volumes<br>2. Click "Images (DO)"<br>3. Go to "Field Layout" tab<br>4. In "Content" tab, click "+ Add field"<br>5. Select "optimisedImagesField"<br>6. Save<br><br>This ensures transforms are correctly generated and prevents errors.',
                    'command' => null,
                    'duration' => '2-5 min',
                    'critical' => true,
                    'requiresArgs' => true,
                ],
                [
                    'id' => 'transform-discovery-all',
                    'title' => 'Discover ALL Transforms',
                    'description' => 'Discover all transforms in both database and templates.',
                    'command' => 'transform-discovery/discover',
                    'duration' => '10-30 min',
                    'critical' => false,
                ],
                [
                    'id' => 'transform-discovery-db',
                    'title' => 'Scan Database Only',
                    'description' => 'Scan only database for transforms.',
                    'command' => 'transform-discovery/scan-database',
                    'duration' => '5-15 min',
                    'critical' => false,
                ],
                [
                    'id' => 'transform-discovery-templates',
                    'title' => 'Scan Templates Only',
                    'description' => 'Scan only Twig templates for transforms.',
                    'command' => 'transform-discovery/scan-templates',
                    'duration' => '5-15 min',
                    'critical' => false,
                ],
                [
                    'id' => 'transform-pregeneration-discover',
                    'title' => 'Discover Image Transforms',
                    'description' => 'Discover all image transforms being used in the database.',
                    'command' => 'transform-pre-generation/discover',
                    'duration' => '10-30 min',
                    'critical' => false,
                ],
                [
                    'id' => 'transform-pregeneration',
                    'title' => 'Generate Transforms',
                    'description' => 'Generate transforms based on discovery report.',
                    'command' => 'transform-pre-generation/generate',
                    'duration' => '30 min - 6 hours',
                    'critical' => false,
                ],
                [
                    'id' => 'transform-pregeneration-verify',
                    'title' => 'Verify Transforms',
                    'description' => 'Verify that transforms exist for all discovered references.',
                    'command' => 'transform-pre-generation/verify',
                    'duration' => '10-30 min',
                    'critical' => false,
                ],
                [
                    'id' => 'transform-pregeneration-warmup',
                    'title' => 'Warmup Transforms',
                    'description' => 'Warm up transforms by visiting pages (simulates real traffic).',
                    'command' => 'transform-pre-generation/warmup',
                    'duration' => '30 min - 2 hours',
                    'critical' => false,
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
            'phase' => 8,
            'icon' => 'search',
            'modules' => [
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
