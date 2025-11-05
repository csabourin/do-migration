<?php
namespace modules\helpers;

use Craft;

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
     * @var array Configuration data
     */
    private static $config = null;

    /**
     * @var self Singleton instance
     */
    private static $instance = null;

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
        $this->loadConfig();
    }

    /**
     * Load configuration from file
     */
    private function loadConfig(): void
    {
        if (self::$config !== null) {
            return;
        }

        // Try to load from Craft config directory first
        $configPath = Craft::getAlias('@config/migration-config.php');

        if (!file_exists($configPath)) {
            // Fallback to module directory
            $configPath = dirname(__DIR__) . '/config/migration-config.php';
        }

        if (!file_exists($configPath)) {
            throw new \Exception(
                "Migration config file not found. Expected at: @config/migration-config.php\n" .
                "Please copy config/migration-config.php to your Craft config/ directory."
            );
        }

        self::$config = require $configPath;
    }

    /**
     * Get raw config value by path (dot notation)
     *
     * @param string $path Dot-notation path (e.g., 'aws.bucket', 'migration.batchSize')
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function get(string $path, $default = null)
    {
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
    // AWS S3 Configuration
    // ============================================================================

    /**
     * Get AWS S3 bucket name
     */
    public function getAwsBucket(): string
    {
        return $this->get('aws.bucket', 'ncc-website-2');
    }

    /**
     * Get AWS S3 region
     */
    public function getAwsRegion(): string
    {
        return $this->get('aws.region', 'ca-central-1');
    }

    /**
     * Get all AWS S3 URL patterns
     */
    public function getAwsUrls(): array
    {
        return $this->get('aws.urls', []);
    }

    // ============================================================================
    // DigitalOcean Spaces Configuration
    // ============================================================================

    /**
     * Get DO Spaces bucket name
     */
    public function getDoBucket(): string
    {
        return $this->get('digitalocean.bucket', '');
    }

    /**
     * Get DO Spaces region
     */
    public function getDoRegion(): string
    {
        return $this->get('digitalocean.region', 'tor1');
    }

    /**
     * Get DO Spaces base URL
     */
    public function getDoBaseUrl(): string
    {
        return $this->get('digitalocean.baseUrl', '');
    }

    /**
     * Get DO Spaces access key
     */
    public function getDoAccessKey(): string
    {
        return $this->get('digitalocean.accessKey', '');
    }

    /**
     * Get DO Spaces secret key
     */
    public function getDoSecretKey(): string
    {
        return $this->get('digitalocean.secretKey', '');
    }

    // ============================================================================
    // URL Mappings
    // ============================================================================

    /**
     * Get URL mappings (old AWS URL => new DO URL)
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
        return $this->get('filesystemMappings', []);
    }

    /**
     * Get filesystem definitions for DigitalOcean Spaces
     */
    public function getFilesystemDefinitions(): array
    {
        return $this->get('filesystems', []);
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

    // ============================================================================
    // Volume Configuration
    // ============================================================================

    /**
     * Get source volume handles for migration
     */
    public function getSourceVolumeHandles(): array
    {
        return $this->get('volumes.source', ['images', 'optimisedImages']);
    }

    /**
     * Get target volume handle
     */
    public function getTargetVolumeHandle(): string
    {
        return $this->get('volumes.target', 'images');
    }

    /**
     * Get quarantine volume handle
     */
    public function getQuarantineVolumeHandle(): string
    {
        return $this->get('volumes.quarantine', 'quarantine');
    }

    /**
     * Get root-level volume handles
     */
    public function getRootLevelVolumeHandles(): array
    {
        return $this->get('volumes.rootLevel', ['optimisedImages', 'chartData']);
    }

    // ============================================================================
    // Migration Settings
    // ============================================================================

    /**
     * Get migration batch size
     */
    public function getBatchSize(): int
    {
        return (int) $this->get('migration.batchSize', 100);
    }

    /**
     * Get checkpoint frequency
     */
    public function getCheckpointEveryBatches(): int
    {
        return (int) $this->get('migration.checkpointEveryBatches', 1);
    }

    /**
     * Get changelog flush frequency
     */
    public function getChangelogFlushEvery(): int
    {
        return (int) $this->get('migration.changelogFlushEvery', 5);
    }

    /**
     * Get max retries for operations
     */
    public function getMaxRetries(): int
    {
        return (int) $this->get('migration.maxRetries', 3);
    }

    /**
     * Get checkpoint retention hours
     */
    public function getCheckpointRetentionHours(): int
    {
        return (int) $this->get('migration.checkpointRetentionHours', 72);
    }

    /**
     * Get max repeated errors before stopping
     */
    public function getMaxRepeatedErrors(): int
    {
        return (int) $this->get('migration.maxRepeatedErrors', 10);
    }

    // ============================================================================
    // Template Settings
    // ============================================================================

    /**
     * Get template file extensions to scan
     */
    public function getTemplateExtensions(): array
    {
        return $this->get('templates.extensions', ['twig']);
    }

    /**
     * Get template backup suffix pattern
     */
    public function getTemplateBackupSuffix(): string
    {
        $pattern = $this->get('templates.backupSuffix', '.backup-{timestamp}');
        return str_replace('{timestamp}', date('YmdHis'), $pattern);
    }

    /**
     * Get template environment variable name for URLs
     */
    public function getTemplateEnvVarName(): string
    {
        return $this->get('templates.envVarName', 'DO_S3_BASE_URL');
    }

    // ============================================================================
    // Database Settings
    // ============================================================================

    /**
     * Get content table patterns to scan
     */
    public function getContentTablePatterns(): array
    {
        return $this->get('database.contentTables', ['content', 'matrixcontent_%']);
    }

    /**
     * Get additional tables to scan (beyond content tables)
     */
    public function getAdditionalTables(): array
    {
        return $this->get('database.additionalTables', []);
    }

    /**
     * Get database column types to scan
     */
    public function getColumnTypes(): array
    {
        return $this->get('database.columnTypes', ['text', 'mediumtext', 'longtext']);
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
     * Validate that all required config values are set
     *
     * @return array Array of validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        // Check required AWS config
        if (empty($this->getAwsBucket())) {
            $errors[] = "AWS bucket name is not configured";
        }
        if (empty($this->getAwsUrls())) {
            $errors[] = "AWS URLs are not configured";
        }

        // Check required DO config
        if (empty($this->getDoBucket())) {
            $errors[] = "DigitalOcean bucket name is not configured";
        }
        if (empty($this->getDoBaseUrl())) {
            $errors[] = "DigitalOcean base URL is not configured";
        }
        if (empty($this->getDoAccessKey())) {
            $errors[] = "DigitalOcean access key is not configured (check DO_S3_ACCESS_KEY in .env)";
        }
        if (empty($this->getDoSecretKey())) {
            $errors[] = "DigitalOcean secret key is not configured (check DO_S3_SECRET_KEY in .env)";
        }

        // Check filesystem mappings
        if (empty($this->getFilesystemMappings())) {
            $errors[] = "Filesystem mappings are not configured";
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

        return implode("\n", $summary);
    }
}
