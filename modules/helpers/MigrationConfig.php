<?php
namespace csabourin\spaghettiMigrator\helpers;

use Craft;
use craft\helpers\App;
use csabourin\spaghettiMigrator\Plugin;
use csabourin\spaghettiMigrator\models\Settings;

/**
 * Migration Configuration Helper
 *
 * Provides easy access to centralized migration configuration.
 * All controllers should use this class to get configuration values.
 *
 * Usage in controllers:
 *   $config = MigrationConfig::getInstance();
 *   $awsUrls = $config->getAwsUrls();
 *   $doBaseUrl = $config->getDoBaseUrl();
 */
class MigrationConfig
{
    /**
     * @var array Configuration data from PHP file
     */
    private static $config = null;

    /**
     * @var Settings|null Plugin settings from database
     */
    private static $settings = null;

    /**
     * @var self Singleton instance
     */
    private static $instance = null;

    /**
     * @var bool Whether to use plugin settings (database) or config file
     */
    private static $usePluginSettings = false;

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - loads config
     */
    private function __construct()
    {
        $this->loadSettings();
        $this->loadConfig();
    }

    /**
     * Load plugin settings from database
     * If plugin settings exist and have required values, use them instead of config file
     */
    private function loadSettings(): void
    {
        if (self::$settings !== null) {
            return;
        }

        try {
            $plugin = Plugin::getInstance();
            if ($plugin) {
                self::$settings = $plugin->getSettings();

                // Check if plugin settings have been configured (awsBucket is required)
                // If not configured, fall back to config file
                if (self::$settings && !empty(App::parseEnv(self::$settings->awsBucket))) {
                    self::$usePluginSettings = true;
                }
            }
        } catch (\Exception $e) {
            // Plugin not loaded or settings not available, will use config file
            self::$usePluginSettings = false;
        }
    }

    /**
     * Load configuration from file
     * Only required if plugin settings are not available
     */
    private function loadConfig(): void
    {
        if (self::$config !== null) {
            return;
        }

        // If using plugin settings, config file is optional
        if (self::$usePluginSettings) {
            self::$config = []; // Empty array as fallback
            return;
        }

        // Try to load from Craft config directory first
        $configPath = Craft::getAlias('@config/migration-config.php');

        if (!file_exists($configPath)) {
            // Fallback to module directory (composer-installed default)
            $configPath = Craft::getAlias('@modules/config/migration-config.php');
        }

        if (!file_exists($configPath)) {
            throw new \Exception(
                "Migration config file not found. Expected at: @config/migration-config.php\n" .
                "Please copy config/migration-config.php to your Craft config/ directory.\n\n" .
                "Alternatively, configure the plugin settings in the Craft Control Panel:\n" .
                "Settings → Plugins → S3 Spaces Migration → Settings"
            );
        }

        self::$config = require $configPath;
    }

    /**
     * Get raw config value by path (dot notation)
     *
     * Priority: Plugin Settings (database) > Config File
     *
     * @param string $path Dot-notation path (e.g., 'aws.bucket', 'migration.batchSize')
     * @param mixed $default Default value if not found
     * @param string|null $settingsProperty If using plugin settings, the property name to read
     * @return mixed
     */
    public function get(string $path, $default = null, ?string $settingsProperty = null)
    {
        // Try plugin settings first
        if (self::$usePluginSettings && self::$settings && $settingsProperty !== null) {
            $value = self::$settings->{$settingsProperty};

            // Parse environment variables if string starts with $
            if (is_string($value) && strpos($value, '$') === 0) {
                $value = App::parseEnv($value);
            }

            // For array properties stored as comma-separated strings in form
            // they're already arrays in the model, so just return them
            return $value ?? $default;
        }

        // Fall back to config file
        $keys = explode('.', $path);
        $value = self::$config;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Get entire config array
     */
    public function getAll(): array
    {
        return self::$config;
    }

    /**
     * Get current environment (dev, staging, prod)
     */
    public function getEnvironment(): string
    {
        return $this->get('environment', 'dev');
    }

    // ============================================================================
    // Multi-Provider Configuration (v2.0)
    // ============================================================================

    /**
     * Get source provider configuration
     *
     * Returns provider type and configuration for the source storage.
     * Defaults to S3 for backward compatibility.
     *
     * @return array ['type' => 'provider-name', 'config' => [...]]
     */
    public function getSourceProvider(): array
    {
        // Check for new v2.0 config format first
        $providerType = $this->get('sourceProvider.type', 's3');
        $providerConfig = $this->get('sourceProvider.config', []);

        // If new config exists, use it
        if (!empty($providerConfig)) {
            return [
                'type' => $providerType,
                'config' => $this->parseEnvInArray($providerConfig),
            ];
        }

        // Fall back to legacy AWS S3 config
        return [
            'type' => 's3',
            'config' => [
                'bucket' => $this->getAwsBucket(),
                'region' => $this->getAwsRegion(),
                'accessKey' => getenv('AWS_ACCESS_KEY_ID') ?: '',
                'secretKey' => getenv('AWS_SECRET_ACCESS_KEY') ?: '',
                'baseUrl' => $this->getAwsUrls()[0] ?? '',
            ],
        ];
    }

    /**
     * Get target provider configuration
     *
     * Returns provider type and configuration for the target storage.
     * Defaults to DO Spaces for backward compatibility.
     *
     * @return array ['type' => 'provider-name', 'config' => [...]]
     */
    public function getTargetProvider(): array
    {
        // Check for new v2.0 config format first
        $providerType = $this->get('targetProvider.type', 'do-spaces');
        $providerConfig = $this->get('targetProvider.config', []);

        // If new config exists, use it
        if (!empty($providerConfig)) {
            return [
                'type' => $providerType,
                'config' => $this->parseEnvInArray($providerConfig),
            ];
        }

        // Fall back to legacy DO Spaces config
        return [
            'type' => 'do-spaces',
            'config' => [
                'bucket' => $this->getDoBucket(),
                'region' => $this->getDoRegion(),
                'accessKey' => $this->getDoAccessKey(),
                'secretKey' => $this->getDoSecretKey(),
                'baseUrl' => $this->getDoBaseUrl(),
                'endpoint' => $this->getDoEndpoint(),
            ],
        ];
    }

    /**
     * Get migration mode
     *
     * Determines the type of migration being performed:
     * - 'cloud-to-cloud': Migrate between cloud providers (default)
     * - 'local-reorganize': Reorganize local filesystem
     * - 'cloud-to-local': Backup cloud to local
     * - 'local-to-cloud': Upload local to cloud
     *
     * @return string
     */
    public function getMigrationMode(): string
    {
        return $this->get('migrationMode', 'cloud-to-cloud');
    }

    /**
     * Parse environment variables in configuration array
     *
     * Recursively processes config arrays and parses any string values
     * that reference environment variables.
     *
     * @param array $config Configuration array
     * @return array Parsed configuration
     */
    private function parseEnvInArray(array $config): array
    {
        $result = [];

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->parseEnvInArray($value);
            } elseif (is_string($value)) {
                // Parse environment variables via Craft's App::parseEnv()
                // Supports both getenv() and Craft's $VAR_NAME format
                $result[$key] = getenv($value) ?: App::parseEnv($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    // ============================================================================
    // AWS S3 Configuration
    // ============================================================================

    /**
     * Get AWS S3 bucket name
     */
    public function getAwsBucket(): string
    {
        $value = $this->get('aws.bucket', '', 'awsBucket');
        return App::parseEnv($value);
    }

    /**
     * Get AWS S3 region
     */
    public function getAwsRegion(): string
    {
        $value = $this->get('aws.region', 'us-east-1', 'awsRegion');
        return App::parseEnv($value);
    }

    /**
     * Get AWS access key
     */
    public function getAwsAccessKey(): string
    {
        return $this->get('aws.accessKey', '');
    }

    /**
     * Get AWS secret key
     */
    public function getAwsSecretKey(): string
    {
        return $this->get('aws.secretKey', '');
    }

    /**
     * Get all AWS S3 URL patterns
     * Auto-generates URLs from bucket and region if not explicitly set
     */
    public function getAwsUrls(): array
    {
        // Try to get from config first
        $urls = $this->get('aws.urls', []);

        // If empty and using plugin settings, auto-generate from bucket/region
        if (empty($urls) && self::$usePluginSettings) {
            $bucket = $this->getAwsBucket();
            $region = $this->getAwsRegion();

            if (!empty($bucket)) {
                $urls = [
                    "https://{$bucket}.s3.amazonaws.com",
                    "http://{$bucket}.s3.amazonaws.com",
                    "https://s3.{$region}.amazonaws.com/{$bucket}",
                    "http://s3.{$region}.amazonaws.com/{$bucket}",
                    "https://s3.amazonaws.com/{$bucket}",
                    "http://s3.amazonaws.com/{$bucket}",
                ];
            }
        }

        return $urls;
    }

    // ============================================================================
    // DigitalOcean Spaces Configuration
    // ============================================================================

    /**
     * Get DO Spaces bucket name
     * Always loaded from environment variable (DO_S3_BUCKET)
     */
    public function getDoBucket(): string
    {
        // DO credentials are always from env vars (not stored in DB for security)
        if (self::$usePluginSettings) {
            return App::parseEnv('$DO_S3_BUCKET') ?? '';
        }
        return $this->get('digitalocean.bucket', '');
    }

    /**
     * Get DO Spaces region
     */
    public function getDoRegion(): string
    {
        return $this->get('digitalocean.region', 'tor1', 'doRegion');
    }

    /**
     * Get DO Spaces base URL
     * Always loaded from environment variable (DO_S3_BASE_URL)
     */
    public function getDoBaseUrl(): string
    {
        // DO credentials are always from env vars (not stored in DB for security)
        if (self::$usePluginSettings) {
            return App::parseEnv('$DO_S3_BASE_URL') ?? '';
        }
        return $this->get('digitalocean.baseUrl', '');
    }

    /**
     * Get DO Spaces access key
     * Always loaded from environment variable (DO_S3_ACCESS_KEY)
     */
    public function getDoAccessKey(): string
    {
        // DO credentials are always from env vars (not stored in DB for security)
        if (self::$usePluginSettings) {
            return App::parseEnv('$DO_S3_ACCESS_KEY') ?? '';
        }
        return $this->get('digitalocean.accessKey', '');
    }

    /**
     * Get DO Spaces secret key
     * Always loaded from environment variable (DO_S3_SECRET_KEY)
     */
    public function getDoSecretKey(): string
    {
        // DO credentials are always from env vars (not stored in DB for security)
        if (self::$usePluginSettings) {
            return App::parseEnv('$DO_S3_SECRET_KEY') ?? '';
        }
        return $this->get('digitalocean.secretKey', '');
    }

    /**
     * Get DO Spaces endpoint (region-only, without bucket name)
     * This is different from baseUrl - endpoint is for SDK configuration
     *
     * Example: https://tor1.digitaloceanspaces.com
     * NOT: https://bucket-name.tor1.digitaloceanspaces.com
     *
     * Always loaded from environment variable (DO_S3_BASE_ENDPOINT)
     */
    public function getDoEndpoint(): string
    {
        // DO credentials are always from env vars (not stored in DB for security)
        if (self::$usePluginSettings) {
            return App::parseEnv('$DO_S3_BASE_ENDPOINT') ?? '';
        }
        return $this->get('digitalocean.endpoint', '');
    }

    // ============================================================================
    // Environment Variable References
    // ============================================================================

    /**
     * Get environment variable reference for DO Spaces access key
     * Returns the full env var reference with $ prefix (e.g., "$DO_S3_ACCESS_KEY")
     * This can be stored directly in the database and Craft will resolve it at runtime
     */
    public function getDoEnvVarAccessKey(): string
    {
        return $this->get('digitalocean.envVars.accessKey', '$DO_S3_ACCESS_KEY');
    }

    /**
     * Get environment variable reference for DO Spaces secret key
     * Returns the full env var reference with $ prefix (e.g., "$DO_S3_SECRET_KEY")
     */
    public function getDoEnvVarSecretKey(): string
    {
        return $this->get('digitalocean.envVars.secretKey', '$DO_S3_SECRET_KEY');
    }

    /**
     * Get environment variable reference for DO Spaces bucket
     * Returns the full env var reference with $ prefix (e.g., "$DO_S3_BUCKET")
     */
    public function getDoEnvVarBucket(): string
    {
        return $this->get('digitalocean.envVars.bucket', '$DO_S3_BUCKET');
    }

    /**
     * Get environment variable reference for DO Spaces base URL
     * Returns the full env var reference with $ prefix (e.g., "$DO_S3_BASE_URL")
     */
    public function getDoEnvVarBaseUrl(): string
    {
        return $this->get('digitalocean.envVars.baseUrl', '$DO_S3_BASE_URL');
    }

    /**
     * Get environment variable reference for DO Spaces endpoint
     * Returns the full env var reference with $ prefix (e.g., "$DO_S3_BASE_ENDPOINT")
     * This is critical for avoiding SSL certificate errors - endpoint must be region-only
     */
    public function getDoEnvVarEndpoint(): string
    {
        return $this->get('digitalocean.envVars.endpoint', '$DO_S3_BASE_ENDPOINT');
    }

    /**
     * Get environment variable reference for AWS access key
     * Returns the full env var reference with $ prefix (e.g., "$AWS_SOURCE_ACCESS_KEY")
     * This can be used in rclone commands and will resolve at runtime
     */
    public function getAwsEnvVarAccessKeyRef(): string
    {
        $varName = $this->get('envVars.awsAccessKey', 'AWS_SOURCE_ACCESS_KEY');
        return '$' . $varName;
    }

    /**
     * Get environment variable reference for AWS secret key
     * Returns the full env var reference with $ prefix (e.g., "$AWS_SOURCE_SECRET_KEY")
     */
    public function getAwsEnvVarSecretKeyRef(): string
    {
        $varName = $this->get('envVars.awsSecretKey', 'AWS_SOURCE_SECRET_KEY');
        return '$' . $varName;
    }

    /**
     * Get environment variable reference for AWS bucket
     * Returns the full env var reference with $ prefix (e.g., "$AWS_SOURCE_BUCKET")
     */
    public function getAwsEnvVarBucketRef(): string
    {
        $varName = $this->get('envVars.awsBucket', 'AWS_SOURCE_BUCKET');
        return '$' . $varName;
    }

    /**
     * Get environment variable reference for AWS region
     * Returns the full env var reference with $ prefix (e.g., "$AWS_SOURCE_REGION")
     */
    public function getAwsEnvVarRegionRef(): string
    {
        $varName = $this->get('envVars.awsRegion', 'AWS_SOURCE_REGION');
        return '$' . $varName;
    }

    /**
     * Get environment variable name for AWS access key (without $ prefix)
     */
    public function getAwsEnvVarAccessKey(): string
    {
        return $this->get('envVars.awsAccessKey', 'AWS_SOURCE_ACCESS_KEY');
    }

    /**
     * Get environment variable name for AWS secret key (without $ prefix)
     */
    public function getAwsEnvVarSecretKey(): string
    {
        return $this->get('envVars.awsSecretKey', 'AWS_SOURCE_SECRET_KEY');
    }

    /**
     * Get environment variable name for AWS bucket (without $ prefix)
     */
    public function getAwsEnvVarBucket(): string
    {
        return $this->get('envVars.awsBucket', 'AWS_SOURCE_BUCKET');
    }

    /**
     * Get environment variable name for AWS region (without $ prefix)
     */
    public function getAwsEnvVarRegion(): string
    {
        return $this->get('envVars.awsRegion', 'AWS_SOURCE_REGION');
    }

    // ============================================================================
    // URL Mappings
    // ============================================================================

    /**
     * Get URL mappings (old AWS URL => new DO URL)
     *
     * Generates a mapping array used by URL replacement controllers to convert
     * all AWS S3 URLs to DigitalOcean Spaces URLs.
     *
     * This method automatically handles multiple AWS URL formats:
     * - Virtual-hosted style: https://bucket.s3.amazonaws.com
     * - Path style: https://s3.region.amazonaws.com/bucket
     * - Legacy format: https://s3.amazonaws.com/bucket
     * - Both HTTP and HTTPS variants
     *
     * All variants are mapped to a single DO Spaces URL.
     *
     * EXAMPLE OUTPUT:
     * [
     *     'https://my-bucket.s3.amazonaws.com' => 'https://my-bucket.tor1.digitaloceanspaces.com',
     *     'http://my-bucket.s3.amazonaws.com' => 'https://my-bucket.tor1.digitaloceanspaces.com',
     *     'https://s3.ca-central-1.amazonaws.com/my-bucket' => 'https://my-bucket.tor1.digitaloceanspaces.com',
     *     // ... more variants
     * ]
     *
     * USAGE IN CONTROLLERS:
     * ```php
     * $mappings = $this->config->getUrlMappings();
     * foreach ($mappings as $oldUrl => $newUrl) {
     *     // Replace $oldUrl with $newUrl in database
     * }
     * ```
     *
     * @param string|null $customNewUrl Optional: Override the target DO URL
     *                                  (useful for testing or custom destinations)
     * @return array<string, string> Associative array mapping old URLs to new URL
     *
     * @see getAwsUrls() Source AWS URL patterns
     * @see getDoBaseUrl() Target DO Spaces URL
     * @see UrlReplacementController For usage example
     */
    public function getUrlMappings(?string $customNewUrl = null): array
    {
        $newUrl = $customNewUrl ?? $this->getDoBaseUrl();
        $oldUrls = $this->getAwsUrls();

        $mappings = [];
        foreach ($oldUrls as $oldUrl) {
            $mappings[$oldUrl] = $newUrl;
        }

        return $mappings;
    }

    // ============================================================================
    // Filesystem Configuration
    // ============================================================================

    /**
     * Get filesystem handle mappings (AWS => DO)
     */
    public function getFilesystemMappings(): array
    {
        return $this->get('filesystemMappings', [], 'filesystemMappings');
    }

    /**
     * Get filesystem definitions for DigitalOcean Spaces
     */
    public function getFilesystemDefinitions(): array
    {
        return $this->get('filesystems', [], 'filesystemDefinitions');
    }

    /**
     * Get filesystem definition by handle
     */
    public function getFilesystemDefinition(string $handle): ?array
    {
        $definitions = $this->getFilesystemDefinitions();
        foreach ($definitions as $def) {
            if ($def['handle'] === $handle) {
                return $def;
            }
        }
        return null;
    }

    /**
     * Get transform filesystem handle
     * Used for storing image transforms in DO Spaces
     */
    public function getTransformFilesystemHandle(): string
    {
        return $this->get('filesystems.transformHandle', 'imageTransforms_do');
    }

    /**
     * Get quarantine filesystem handle
     * Note: This is the FILESYSTEM handle, not the volume handle
     */
    public function getQuarantineFilesystemHandle(): string
    {
        return $this->get('filesystems.quarantineHandle', 'quarantine');
    }

    // ============================================================================
    // Volume Configuration
    // ============================================================================

    /**
     * Get source volume handles for migration
     */
    public function getSourceVolumeHandles(): array
    {
        return $this->get('volumes.source', ['images', 'optimisedImages'], 'sourceVolumeHandles');
    }

    /**
     * Get target volume handle
     */
    public function getTargetVolumeHandle(): string
    {
        return $this->get('volumes.target', 'images', 'targetVolumeHandle');
    }

    /**
     * Get quarantine volume handle
     */
    public function getQuarantineVolumeHandle(): string
    {
        return $this->get('volumes.quarantine', 'quarantine', 'quarantineVolumeHandle');
    }

    /**
     * Get root-level volume handles (DEPRECATED - use more specific methods)
     * @deprecated Use getVolumesAtBucketRoot(), getVolumesWithSubfolders(), or getFlatStructureVolumes()
     */
    public function getRootLevelVolumeHandles(): array
    {
        // Backward compatibility: return flat structure volumes
        return $this->get('volumes.flatStructure', $this->get('volumes.rootLevel', ['chartData']));
    }

    /**
     * Get volumes that exist at bucket root (vs in DO Spaces subfolders)
     * These volumes are located at the root level of the S3/Spaces bucket,
     * but may still contain internal subfolder structures.
     */
    public function getVolumesAtBucketRoot(): array
    {
        return $this->get('volumes.atBucketRoot', ['optimisedImages', 'chartData'], 'volumesAtBucketRoot');
    }

    /**
     * Get volumes with internal subfolder structure
     * These volumes contain organized subfolders with files.
     * Example: optimisedImages has /images, /optimizedImages, /images-Winter
     */
    public function getVolumesWithSubfolders(): array
    {
        return $this->get('volumes.withSubfolders', ['images', 'optimisedImages'], 'volumesWithSubfolders');
    }

    /**
     * Get volumes with flat structure (no subfolders)
     * These volumes have files directly at root with no folder organization.
     * Example: chartData has CSV/JSON files at root level only.
     */
    public function getFlatStructureVolumes(): array
    {
        return $this->get('volumes.flatStructure', ['chartData'], 'volumesFlatStructure');
    }

    /**
     * Check if volume has internal subfolder structure
     *
     * This method determines whether a volume organizes its files into subfolders
     * or keeps all files at the root level. This information is critical for
     * migration path calculations.
     *
     * UNDERSTANDING VOLUME STRUCTURES:
     *
     * WITH SUBFOLDERS (returns true):
     * ```
     * volume-root/
     * ├── images/
     * │   ├── photo1.jpg
     * │   └── photo2.jpg
     * ├── optimizedImages/
     * │   └── optimized.jpg
     * └── images-Winter/
     *     └── winter.jpg
     * ```
     *
     * FLAT STRUCTURE (returns false):
     * ```
     * volume-root/
     * ├── file1.csv
     * ├── file2.json
     * └── file3.xml
     * (no subfolders, all files at root)
     * ```
     *
     * MIGRATION IMPACT:
     * - Subfolder volumes: Preserve folder structure during migration
     * - Flat volumes: All files remain at root, no folder nesting
     *
     * CONFIGURATION:
     * Set in migration-config.php:
     * ```php
     * 'volumes' => [
     *     'withSubfolders' => ['images', 'optimisedImages'],
     *     'flatStructure' => ['chartData']
     * ]
     * ```
     *
     * @param string $volumeHandle Volume handle to check (e.g., 'images', 'documents')
     * @return bool True if volume contains subfolders, false if flat structure
     *
     * @see getVolumesWithSubfolders() Get all volumes with subfolder structure
     * @see getFlatStructureVolumes() Get all volumes with flat structure
     * @see ImageMigrationController Path calculation logic uses this
     */
    public function volumeHasSubfolders(string $volumeHandle): bool
    {
        return in_array($volumeHandle, $this->getVolumesWithSubfolders());
    }

    /**
     * Check if volume exists at bucket root level
     *
     * This method determines whether a volume is stored at the root of the S3/Spaces
     * bucket or within a subfolder. This is NOT about internal folder structure
     * (see volumeHasSubfolders for that), but about the bucket-level location.
     *
     * UNDERSTANDING BUCKET-LEVEL LOCATIONS:
     *
     * AT BUCKET ROOT (returns true):
     * ```
     * my-bucket/
     * ├── file1.jpg          ← Volume files at bucket root
     * ├── file2.jpg
     * └── subfolder/         ← Volume may have internal subfolders
     *     └── file3.jpg
     * ```
     *
     * IN SUBFOLDER (returns false):
     * ```
     * my-bucket/
     * └── my-volume-folder/  ← Volume in DO Spaces subfolder
     *     ├── file1.jpg
     *     └── file2.jpg
     * ```
     *
     * MIGRATION IMPACT:
     * - Bucket root volumes: Files copied to DO bucket root (unless subfolder configured)
     * - Subfolder volumes: Files copied into DO Spaces subfolder (if configured)
     *
     * DIGITALOCEAN SPACES SUBFOLDERS:
     * Configure DO subfolders in .env:
     * ```
     * DO_S3_SUBFOLDER_IMAGES=images-folder
     * DO_S3_SUBFOLDER_DOCUMENTS=docs-folder
     * ```
     *
     * CONFIGURATION:
     * Set in migration-config.php:
     * ```php
     * 'volumes' => [
     *     'atBucketRoot' => ['optimisedImages', 'chartData']
     * ]
     * ```
     *
     * @param string $volumeHandle Volume handle to check (e.g., 'optimisedImages')
     * @return bool True if volume is at bucket root level, false if in subfolder
     *
     * @see getVolumesAtBucketRoot() Get all volumes at bucket root
     * @see volumeHasSubfolders() Check internal folder structure (different concept)
     */
    public function volumeIsAtBucketRoot(string $volumeHandle): bool
    {
        return in_array($volumeHandle, $this->getVolumesAtBucketRoot());
    }

    // ============================================================================
    // Migration Settings
    // ============================================================================

    /**
     * Get migration batch size
     */
    public function getBatchSize(): int
    {
        return (int) $this->get('migration.batchSize', 100, 'batchSize');
    }

    /**
     * Get checkpoint frequency
     */
    public function getCheckpointEveryBatches(): int
    {
        return (int) $this->get('migration.checkpointEveryBatches', 1, 'checkpointEveryBatches');
    }

    /**
     * Get changelog flush frequency
     */
    public function getChangelogFlushEvery(): int
    {
        return (int) $this->get('migration.changelogFlushEvery', 5, 'changelogFlushEvery');
    }

    /**
     * Get max retries for operations
     */
    public function getMaxRetries(): int
    {
        return (int) $this->get('migration.maxRetries', 3, 'maxRetries');
    }

    /**
     * Get retry delay in milliseconds
     */
    public function getRetryDelayMs(): int
    {
        return (int) $this->get('migration.retryDelayMs', 1000, 'retryDelayMs');
    }

    /**
     * Get checkpoint retention hours
     */
    public function getCheckpointRetentionHours(): int
    {
        return (int) $this->get('migration.checkpointRetentionHours', 72, 'checkpointRetentionHours');
    }

    /**
     * Get max repeated errors before stopping
     */
    public function getMaxRepeatedErrors(): int
    {
        return (int) $this->get('migration.maxRepeatedErrors', 10, 'maxRepeatedErrors');
    }

    /**
     * Get error threshold before stopping migration
     * Maximum number of errors before halting the migration process
     */
    public function getErrorThreshold(): int
    {
        return (int) $this->get('migration.errorThreshold', 50, 'errorThreshold');
    }

    /**
     * Get critical error threshold
     * Threshold for critical errors (write failures, permissions, etc.)
     */
    public function getCriticalErrorThreshold(): int
    {
        return (int) $this->get('migration.criticalErrorThreshold', 20, 'criticalErrorThreshold');
    }

    /**
     * Get migration lock timeout in seconds
     * How long a migration lock is valid before it expires
     */
    public function getLockTimeoutSeconds(): int
    {
        return (int) $this->get('migration.lockTimeoutSeconds', 43200, 'lockTimeoutSeconds'); // 12 hours
    }

    /**
     * Get lock acquisition timeout in seconds
     * How long to wait when trying to acquire a migration lock
     */
    public function getLockAcquireTimeoutSeconds(): int
    {
        return (int) $this->get('migration.lockAcquireTimeoutSeconds', 3, 'lockAcquireTimeoutSeconds');
    }

    // ============================================================================
    // Field Configuration
    // ============================================================================

    /**
     * Get optimized images field handle
     * The ImageOptimize field used for storing optimized image variants
     */
    public function getOptimizedImagesFieldHandle(): string
    {
        return $this->get('fields.optimizedImages', 'optimizedImagesField', 'optimizedImagesFieldHandle');
    }

    // ============================================================================
    // Transform Settings
    // ============================================================================

    /**
     * Get maximum concurrent transform generations
     * How many transforms can be generated in parallel
     */
    public function getMaxConcurrentTransforms(): int
    {
        return (int) $this->get('transforms.maxConcurrent', 5, 'maxConcurrentTransforms');
    }

    /**
     * Get warmup timeout for transform crawling
     * HTTP timeout when warming up transforms via URL crawling
     */
    public function getWarmupTimeout(): int
    {
        return (int) $this->get('transforms.warmupTimeout', 10, 'warmupTimeout');
    }

    // ============================================================================
    // Template Settings
    // ============================================================================

    /**
     * Get template file extensions to scan
     */
    public function getTemplateExtensions(): array
    {
        return $this->get('templates.extensions', ['twig'], 'templateExtensions');
    }

    /**
     * Get template backup suffix pattern
     */
    public function getTemplateBackupSuffix(): string
    {
        $pattern = $this->get('templates.backupSuffix', '.backup-{timestamp}', 'templateBackupSuffix');
        return str_replace('{timestamp}', date('YmdHis'), $pattern);
    }

    /**
     * Get template environment variable name for URLs
     */
    public function getTemplateEnvVarName(): string
    {
        return $this->get('templates.envVarName', 'DO_S3_BASE_URL', 'templateEnvVarName');
    }

    // ============================================================================
    // Database Settings
    // ============================================================================

    /**
     * Get content table patterns to scan
     */
    public function getContentTablePatterns(): array
    {
        return $this->get('database.contentTables', ['content', 'matrixcontent_%'], 'contentTablePatterns');
    }

    /**
     * Get additional tables to scan (beyond content tables)
     */
    public function getAdditionalTables(): array
    {
        return $this->get('database.additionalTables', [], 'additionalTables');
    }

    /**
     * Get database column types to scan
     */
    public function getColumnTypes(): array
    {
        return $this->get('database.columnTypes', ['text', 'mediumtext', 'longtext'], 'columnTypes');
    }

    /**
     * Get field column pattern for database queries
     * Pattern for identifying Craft field columns (e.g., field_*)
     */
    public function getFieldColumnPattern(): string
    {
        return $this->get('database.fieldColumnPattern', 'field_%', 'fieldColumnPattern');
    }

    // ============================================================================
    // URL Replacement Settings
    // ============================================================================

    /**
     * Get sample URL limit for URL replacement preview
     * How many sample URLs to show when previewing replacements
     */
    public function getSampleUrlLimit(): int
    {
        return (int) $this->get('urlReplacement.sampleUrlLimit', 5, 'sampleUrlLimit');
    }

    // ============================================================================
    // Diagnostics Settings
    // ============================================================================

    /**
     * Get file list limit for diagnostics
     * Maximum number of files to show when listing filesystem contents
     */
    public function getFileListLimit(): int
    {
        return (int) $this->get('diagnostics.fileListLimit', 50, 'fileListLimit');
    }

    // ============================================================================
    // Dashboard Settings
    // ============================================================================

    /**
     * Get default number of log lines to display
     * How many log lines to show by default in the dashboard
     */
    public function getDashboardLogLinesDefault(): int
    {
        return (int) $this->get('dashboard.logLinesDefault', 100, 'dashboardLogLinesDefault');
    }

    /**
     * Get log file name for dashboard display
     * Which log file to show in the dashboard
     */
    public function getDashboardLogFileName(): string
    {
        return $this->get('dashboard.logFileName', 'web.log', 'dashboardLogFileName');
    }

    // ============================================================================
    // Progress Reporting Settings
    // ============================================================================

    /**
     * Get progress reporting interval
     * How often to report progress during batch operations
     */
    public function getProgressReportInterval(): int
    {
        return (int) $this->get('migration.progressReportInterval', 50, 'progressReportInterval');
    }

    // ============================================================================
    // Lock Management Settings
    // ============================================================================

    /**
     * Get lock refresh interval in seconds
     * How often to refresh migration lock during long operations
     */
    public function getLockRefreshIntervalSeconds(): int
    {
        return (int) $this->get('migration.lockRefreshIntervalSeconds', 60, 'lockRefreshIntervalSeconds');
    }

    // ============================================================================
    // Verification Settings
    // ============================================================================

    /**
     * Get verification sample size
     * Number of assets to sample when doing quick verification
     */
    public function getVerificationSampleSize(): int
    {
        return (int) $this->get('migration.verificationSampleSize', 100, 'verificationSampleSize');
    }

    // ============================================================================
    // Link Repair / Fuzzy Matching Settings
    // ============================================================================

    /**
     * Get minimum confidence for fuzzy matching (0.0 to 1.0)
     * Matches below this threshold are rejected
     */
    public function getFuzzyMatchMinConfidence(): float
    {
        return (float) $this->get('migration.fuzzyMatchMinConfidence', 0.60, 'fuzzyMatchMinConfidence');
    }

    /**
     * Get confidence threshold for warnings (0.0 to 1.0)
     * Matches below this show warnings but are still accepted
     */
    public function getFuzzyMatchWarnConfidence(): float
    {
        return (float) $this->get('migration.fuzzyMatchWarnConfidence', 0.90, 'fuzzyMatchWarnConfidence');
    }

    /**
     * Get priority folder patterns
     * Folder patterns that receive priority during duplicate resolution
     */
    public function getPriorityFolderPatterns(): array
    {
        return $this->get('migration.priorityFolderPatterns', ['originals'], 'priorityFolderPatterns');
    }

    // ============================================================================
    // Controller / UI Settings
    // ============================================================================

    /**
     * Get polling delay in milliseconds
     * Delay for AJAX/UI operations
     */
    public function getPollDelayMs(): int
    {
        return (int) $this->get('migration.pollDelayMs', 100, 'pollDelayMs');
    }

    /**
     * Get process cache duration in seconds
     * How long to cache process information
     */
    public function getProcessCacheDurationSeconds(): int
    {
        return (int) $this->get('migration.processCacheDurationSeconds', 3600, 'processCacheDurationSeconds');
    }

    // ============================================================================
    // Performance Estimation Settings
    // ============================================================================

    /**
     * Get estimated database scan performance (rows per second)
     * Used for progress estimation during inline linking detection
     */
    public function getDbScanEstimateRowsPerSecond(): int
    {
        return (int) $this->get('migration.dbScanEstimateRowsPerSecond', 1000, 'dbScanEstimateRowsPerSecond');
    }

    // ============================================================================
    // State Management Settings
    // ============================================================================

    /**
     * Get state retention in days
     * How many days to keep old migration state records
     */
    public function getStateRetentionDays(): int
    {
        return (int) $this->get('migration.stateRetentionDays', 7, 'stateRetentionDays');
    }

    // ============================================================================
    // Paths
    // ============================================================================

    /**
     * Get templates path
     */
    public function getTemplatesPath(): string
    {
        return Craft::getAlias($this->get('paths.templates', '@templates'));
    }

    /**
     * Get storage path
     */
    public function getStoragePath(): string
    {
        return Craft::getAlias($this->get('paths.storage', '@storage'));
    }

    /**
     * Get logs path
     */
    public function getLogsPath(): string
    {
        return Craft::getAlias($this->get('paths.logs', '@storage/logs'));
    }

    /**
     * Get backups path
     */
    public function getBackupsPath(): string
    {
        return Craft::getAlias($this->get('paths.backups', '@storage/backups'));
    }

    // ============================================================================
    // Validation & Display
    // ============================================================================

    /**
     * Validate that all required configuration values are set
     *
     * This method performs comprehensive validation of the migration configuration
     * before any migration operations begin. It's the first line of defense against
     * configuration errors that could cause migration failures.
     *
     * VALIDATION CATEGORIES:
     *
     * 1. AWS SOURCE VALIDATION
     *    - Bucket name is set
     *    - URL patterns are generated
     *
     * 2. DIGITALOCEAN TARGET VALIDATION
     *    - Bucket name is set (from DO_S3_BUCKET env var)
     *    - Base URL is set (from DO_S3_BASE_URL env var)
     *    - Access key is set (from DO_S3_ACCESS_KEY env var)
     *    - Secret key is set (from DO_S3_SECRET_KEY env var)
     *
     * 3. FILESYSTEM MAPPINGS VALIDATION
     *    - At least one filesystem mapping exists
     *
     * USAGE PATTERN:
     * ```php
     * $config = MigrationConfig::getInstance();
     * $errors = $config->validate();
     *
     * if (!empty($errors)) {
     *     // Display errors and exit
     *     foreach ($errors as $error) {
     *         echo "• $error\n";
     *     }
     *     return ExitCode::CONFIG;
     * }
     *
     * // Proceed with migration
     * ```
     *
     * WHEN TO CALL:
     * - At the start of EVERY controller action
     * - Before any database modifications
     * - Before any file operations
     * - In pre-migration diagnostic checks
     *
     * WHAT IT DOESN'T VALIDATE:
     * - Network connectivity to AWS/DO (use MigrationCheckController)
     * - Filesystem permissions (use MigrationCheckController)
     * - Volume existence in Craft (use MigrationCheckController)
     * - Actual file accessibility (use connectivity tests)
     *
     * COMMON ERRORS & SOLUTIONS:
     *
     * Error: "DigitalOcean bucket name is not configured"
     * → Solution: Add DO_S3_BUCKET=your-bucket to .env
     *
     * Error: "AWS bucket name is not configured"
     * → Solution: Set aws.bucket in migration-config.php
     *
     * Error: "DigitalOcean access key is not configured"
     * → Solution: Add DO_S3_ACCESS_KEY=your-key to .env
     *
     * Error: "Filesystem mappings are not configured"
     * → Solution: Define filesystemMappings in migration-config.php
     *
     * @return array<string> Array of validation error messages (empty if valid)
     *
     * @see MigrationCheckController::actionCheck() For comprehensive pre-flight checks
     * @see displaySummary() Show configuration overview
     */
    public function validate(): array
    {
        $errors = [];

        // ─────────────────────────────────────────────────────────────────
        // CATEGORY 1: AWS Source Configuration
        // ─────────────────────────────────────────────────────────────────

        if (empty($this->getAwsBucket())) {
            $errors[] = "AWS bucket name is not configured (set aws.bucket in migration-config.php)";
        }

        if (empty($this->getAwsUrls())) {
            $errors[] = "AWS URLs are not configured (auto-generated from aws.bucket and aws.region)";
        }

        // ─────────────────────────────────────────────────────────────────
        // CATEGORY 2: DigitalOcean Target Configuration
        // ─────────────────────────────────────────────────────────────────

        if (empty($this->getDoBucket())) {
            $errors[] = "DigitalOcean bucket name is not configured (set DO_S3_BUCKET in .env)";
        }

        if (empty($this->getDoBaseUrl())) {
            $errors[] = "DigitalOcean base URL is not configured (set DO_S3_BASE_URL in .env)";
        }

        if (empty($this->getDoAccessKey())) {
            $errors[] = "DigitalOcean access key is not configured (set DO_S3_ACCESS_KEY in .env)";
        }

        if (empty($this->getDoSecretKey())) {
            $errors[] = "DigitalOcean secret key is not configured (set DO_S3_SECRET_KEY in .env)";
        }

        // ─────────────────────────────────────────────────────────────────
        // CATEGORY 3: Filesystem Mappings
        // ─────────────────────────────────────────────────────────────────

        if (empty($this->getFilesystemMappings())) {
            $errors[] = "Filesystem mappings are not configured (set filesystemMappings in migration-config.php)";
        }

        return $errors;
    }

    /**
     * Display configuration summary
     */
    public function displaySummary(): string
    {
        $summary = [];
        $summary[] = "Environment: " . $this->getEnvironment();
        $summary[] = "";
        $summary[] = "AWS S3:";
        $summary[] = "  Bucket: " . $this->getAwsBucket();
        $summary[] = "  Region: " . $this->getAwsRegion();
        $summary[] = "  URL Patterns: " . count($this->getAwsUrls());
        $summary[] = "";
        $summary[] = "DigitalOcean Spaces:";
        $summary[] = "  Bucket: " . $this->getDoBucket();
        $summary[] = "  Region: " . $this->getDoRegion();
        $summary[] = "  Base URL: " . $this->getDoBaseUrl();
        $summary[] = "  Access Key: " . (empty($this->getDoAccessKey()) ? '(not set)' : '***');
        $summary[] = "";
        $summary[] = "Filesystems: " . count($this->getFilesystemDefinitions());
        $summary[] = "Mappings: " . count($this->getFilesystemMappings());
        $summary[] = "";
        $summary[] = "Volumes:";
        $summary[] = "  Source: " . implode(', ', $this->getSourceVolumeHandles());
        $summary[] = "  Target: " . $this->getTargetVolumeHandle();
        $summary[] = "  Quarantine: " . $this->getQuarantineVolumeHandle();
        $summary[] = "  At Bucket Root: " . implode(', ', $this->getVolumesAtBucketRoot());
        $summary[] = "  With Subfolders: " . implode(', ', $this->getVolumesWithSubfolders());
        $summary[] = "  Flat Structure: " . implode(', ', $this->getFlatStructureVolumes());

        return implode("\n", $summary);
    }
}
