<?php
namespace csabourin\craftS3SpacesMigration\models;

use craft\base\Model;

/**
 * S3 Spaces Migration Settings Model
 *
 * Stores all plugin configuration in the Craft database.
 * Settings are organized into two categories:
 * - Frequently Changed: Settings users adjust often (AWS/DO config, volumes, performance)
 * - Good Defaults: Advanced settings that rarely need changes
 *
 * @package csabourin\craftS3SpacesMigration\models
 */
class Settings extends Model
{
    // ============================================================================
    // FREQUENTLY CHANGED SETTINGS
    // ============================================================================

    // ──────────────────────────────────────────────────────────────────────────
    // AWS Source Configuration
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var string AWS S3 bucket name
     * Environment variable: AWS_SOURCE_BUCKET
     */
    public string $awsBucket = '';

    /**
     * @var string AWS S3 region
     * Environment variable: AWS_SOURCE_REGION
     * Common values: us-east-1, us-west-2, ca-central-1, eu-west-1
     */
    public string $awsRegion = 'us-east-1';

    // ──────────────────────────────────────────────────────────────────────────
    // DigitalOcean Target Configuration
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var string DO Spaces region
     * Available regions: nyc3, ams3, sgp1, sfo3, fra1, tor1
     */
    public string $doRegion = 'tor1';

    // ──────────────────────────────────────────────────────────────────────────
    // Filesystem Mappings
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var array Filesystem handle mappings (AWS => DO)
     * Example: ['images' => 'images_do', 'documents' => 'documents_do']
     * JSON encoded in database
     */
    public array $filesystemMappings = [
        'images' => 'images_do',
        'documents' => 'documents_do',
        'videos' => 'videos_do',
    ];

    // ──────────────────────────────────────────────────────────────────────────
    // Volume Configuration
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var array Source volume handles for migration
     * JSON encoded in database
     */
    public array $sourceVolumeHandles = ['images', 'documents'];

    /**
     * @var string Target volume handle for consolidation
     */
    public string $targetVolumeHandle = 'images';

    /**
     * @var string Quarantine volume handle for unused assets
     */
    public string $quarantineVolumeHandle = 'quarantine';

    /**
     * @var array Volumes at bucket root (not in subfolder)
     * JSON encoded in database
     */
    public array $volumesAtBucketRoot = ['images'];

    /**
     * @var array Volumes with internal subfolder structure
     * JSON encoded in database
     */
    public array $volumesWithSubfolders = ['images', 'documents'];

    /**
     * @var array Volumes with flat structure (no subfolders)
     * JSON encoded in database
     */
    public array $volumesFlatStructure = [];

    // ──────────────────────────────────────────────────────────────────────────
    // Filesystem Definitions
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var array Filesystem definitions for DO Spaces
     * JSON encoded in database
     */
    public array $filesystemDefinitions = [
        [
            'handle' => 'images_do',
            'name' => 'Images (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_IMAGES',
            'hasUrls' => true,
        ],
        [
            'handle' => 'documents_do',
            'name' => 'Documents (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_DOCUMENTS',
            'hasUrls' => true,
        ],
        [
            'handle' => 'videos_do',
            'name' => 'Videos (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_VIDEOS',
            'hasUrls' => true,
        ],
        [
            'handle' => 'imageTransforms_do',
            'name' => 'Image Transforms (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_IMAGETRANSFORMS',
            'hasUrls' => true,
        ],
        [
            'handle' => 'quarantine',
            'name' => 'Quarantined Assets (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_QUARANTINE',
            'hasUrls' => false,
        ],
    ];

    // ──────────────────────────────────────────────────────────────────────────
    // Migration Performance Settings (Frequently Adjusted)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var int How many assets to process in each batch
     * Higher = faster but more memory. Lower = slower but safer.
     * Recommended: 50-200 depending on server
     */
    public int $batchSize = 100;

    /**
     * @var int Maximum number of errors before halting migration
     * Safety threshold to prevent runaway migrations
     */
    public int $errorThreshold = 50;

    /**
     * @var int Critical error threshold for unexpected errors
     * Write failures, permissions, etc.
     */
    public int $criticalErrorThreshold = 20;

    /**
     * @var int Maximum concurrent transform generations
     * Higher = faster but more CPU/memory usage
     */
    public int $maxConcurrentTransforms = 5;

    // ============================================================================
    // GOOD DEFAULTS (Advanced Settings)
    // ============================================================================

    // ──────────────────────────────────────────────────────────────────────────
    // Migration Settings
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var int How often to create checkpoints (allows resume if interrupted)
     * 1 = checkpoint after every batch (safest)
     */
    public int $checkpointEveryBatches = 1;

    /**
     * @var int How often to flush the change log to disk
     * Lower = safer but slower. Higher = faster but riskier.
     */
    public int $changelogFlushEvery = 5;

    /**
     * @var int Maximum retry attempts for failed operations
     * Network issues are common, retries help
     */
    public int $maxRetries = 3;

    /**
     * @var int Retry delay in milliseconds
     */
    public int $retryDelayMs = 1000;

    /**
     * @var int How long to keep old checkpoints (hours)
     * 72 hours = 3 days (enough time to resume or debug)
     */
    public int $checkpointRetentionHours = 72;

    /**
     * @var int Stop migration if this many repeated errors occur
     * Prevents runaway loops on systemic issues
     */
    public int $maxRepeatedErrors = 10;

    /**
     * @var int Migration lock timeout in seconds
     * 12 hours = 43200 seconds (prevents stale locks)
     */
    public int $lockTimeoutSeconds = 43200;

    /**
     * @var int Lock acquisition timeout in seconds
     * Prevents race conditions when multiple processes try to migrate
     */
    public int $lockAcquireTimeoutSeconds = 3;

    // ──────────────────────────────────────────────────────────────────────────
    // Field Configuration
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var string ImageOptimize field handle
     * Used for storing optimized image variants
     */
    public string $optimizedImagesFieldHandle = 'optimizedImagesField';

    // ──────────────────────────────────────────────────────────────────────────
    // Transform Settings
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var int HTTP timeout when warming up transforms via URL crawling (seconds)
     * Increase if transforms take long to generate
     */
    public int $warmupTimeout = 10;

    // ──────────────────────────────────────────────────────────────────────────
    // Template & Database Scanning
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var array Template file extensions to scan
     * JSON encoded in database
     */
    public array $templateExtensions = ['twig'];

    /**
     * @var string Template backup suffix pattern
     * {timestamp} will be replaced with current date/time
     */
    public string $templateBackupSuffix = '.backup-{timestamp}';

    /**
     * @var string Environment variable name for URLs in templates
     */
    public string $templateEnvVarName = 'DO_S3_BASE_URL';

    /**
     * @var array Database content table patterns to scan
     * JSON encoded in database
     */
    public array $contentTablePatterns = ['content', 'matrixcontent_%'];

    /**
     * @var array Additional database tables to scan
     * JSON encoded in database
     */
    public array $additionalTables = [
        ['table' => 'projectconfig', 'column' => 'value'],
        ['table' => 'elements_sites', 'column' => 'metadata'],
        ['table' => 'revisions', 'column' => 'data'],
    ];

    /**
     * @var array Database column types to search
     * JSON encoded in database
     */
    public array $columnTypes = ['text', 'mediumtext', 'longtext'];

    /**
     * @var string Field column pattern for database queries
     * Pattern for identifying Craft field columns (e.g., field_*)
     */
    public string $fieldColumnPattern = 'field_%';

    // ──────────────────────────────────────────────────────────────────────────
    // URL Replacement Settings
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var int How many sample URLs to show when previewing replacements
     * Shows examples before performing actual replacement
     */
    public int $sampleUrlLimit = 5;

    // ──────────────────────────────────────────────────────────────────────────
    // Diagnostics Settings
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var int Maximum number of files to show when listing filesystem contents
     * Prevents overwhelming output in diagnostic commands
     */
    public int $fileListLimit = 50;

    // ──────────────────────────────────────────────────────────────────────────
    // Dashboard Settings
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var int How many log lines to show by default in the dashboard
     * Balance between useful context and readability
     */
    public int $dashboardLogLinesDefault = 100;

    /**
     * @var string Which log file to show in the dashboard
     * Typically 'web.log' for web requests
     */
    public string $dashboardLogFileName = 'web.log';

    // ============================================================================
    // VALIDATION RULES
    // ============================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            // AWS Configuration
            [['awsBucket'], 'required', 'message' => 'AWS bucket name is required. Set AWS_SOURCE_BUCKET in your .env file.'],
            [['awsRegion'], 'required', 'message' => 'AWS region is required.'],
            [['awsRegion'], 'string', 'max' => 50],

            // DO Configuration
            [['doRegion'], 'required', 'message' => 'DigitalOcean region is required.'],
            [['doRegion'], 'string', 'max' => 50],
            [['doRegion'], 'in', 'range' => ['nyc3', 'ams3', 'sgp1', 'sfo3', 'fra1', 'tor1']],

            // Filesystem mappings
            [['filesystemMappings'], 'required', 'message' => 'At least one filesystem mapping is required.'],

            // Volume configuration
            [['sourceVolumeHandles'], 'required', 'message' => 'Source volume handles are required.'],
            [['targetVolumeHandle'], 'required', 'message' => 'Target volume handle is required.'],
            [['quarantineVolumeHandle'], 'required', 'message' => 'Quarantine volume handle is required.'],
            [['targetVolumeHandle', 'quarantineVolumeHandle', 'optimizedImagesFieldHandle'], 'string', 'max' => 255],

            // Integer values with ranges
            [['batchSize'], 'integer', 'min' => 1, 'max' => 1000],
            [['errorThreshold'], 'integer', 'min' => 1, 'max' => 1000],
            [['criticalErrorThreshold'], 'integer', 'min' => 1, 'max' => 1000],
            [['maxConcurrentTransforms'], 'integer', 'min' => 1, 'max' => 50],
            [['checkpointEveryBatches'], 'integer', 'min' => 1, 'max' => 100],
            [['changelogFlushEvery'], 'integer', 'min' => 1, 'max' => 100],
            [['maxRetries'], 'integer', 'min' => 0, 'max' => 10],
            [['retryDelayMs'], 'integer', 'min' => 0, 'max' => 10000],
            [['checkpointRetentionHours'], 'integer', 'min' => 1, 'max' => 720], // Max 30 days
            [['maxRepeatedErrors'], 'integer', 'min' => 1, 'max' => 100],
            [['lockTimeoutSeconds'], 'integer', 'min' => 60, 'max' => 86400], // 1 min to 24 hours
            [['lockAcquireTimeoutSeconds'], 'integer', 'min' => 1, 'max' => 60],
            [['warmupTimeout'], 'integer', 'min' => 1, 'max' => 300],
            [['sampleUrlLimit'], 'integer', 'min' => 1, 'max' => 50],
            [['fileListLimit'], 'integer', 'min' => 1, 'max' => 500],
            [['dashboardLogLinesDefault'], 'integer', 'min' => 10, 'max' => 10000],

            // String fields
            [['templateBackupSuffix', 'templateEnvVarName', 'fieldColumnPattern', 'dashboardLogFileName'], 'string'],

            // Array fields (will be JSON encoded)
            [['filesystemMappings', 'sourceVolumeHandles', 'volumesAtBucketRoot', 'volumesWithSubfolders', 'volumesFlatStructure', 'filesystemDefinitions', 'templateExtensions', 'contentTablePatterns', 'additionalTables', 'columnTypes'], 'safe'],
        ];
    }

    /**
     * Returns attribute labels
     *
     * @return array
     */
    public function attributeLabels(): array
    {
        return [
            // AWS Configuration
            'awsBucket' => 'AWS S3 Bucket Name',
            'awsRegion' => 'AWS S3 Region',

            // DO Configuration
            'doRegion' => 'DigitalOcean Region',

            // Filesystem Mappings
            'filesystemMappings' => 'Filesystem Mappings',

            // Volume Configuration
            'sourceVolumeHandles' => 'Source Volume Handles',
            'targetVolumeHandle' => 'Target Volume Handle',
            'quarantineVolumeHandle' => 'Quarantine Volume Handle',
            'volumesAtBucketRoot' => 'Volumes at Bucket Root',
            'volumesWithSubfolders' => 'Volumes with Subfolders',
            'volumesFlatStructure' => 'Flat Structure Volumes',

            // Filesystem Definitions
            'filesystemDefinitions' => 'Filesystem Definitions',

            // Migration Performance
            'batchSize' => 'Batch Size',
            'errorThreshold' => 'Error Threshold',
            'criticalErrorThreshold' => 'Critical Error Threshold',
            'maxConcurrentTransforms' => 'Max Concurrent Transforms',

            // Migration Settings
            'checkpointEveryBatches' => 'Checkpoint Frequency',
            'changelogFlushEvery' => 'Changelog Flush Frequency',
            'maxRetries' => 'Max Retries',
            'retryDelayMs' => 'Retry Delay (ms)',
            'checkpointRetentionHours' => 'Checkpoint Retention (hours)',
            'maxRepeatedErrors' => 'Max Repeated Errors',
            'lockTimeoutSeconds' => 'Lock Timeout (seconds)',
            'lockAcquireTimeoutSeconds' => 'Lock Acquire Timeout (seconds)',

            // Field Configuration
            'optimizedImagesFieldHandle' => 'Optimized Images Field Handle',

            // Transform Settings
            'warmupTimeout' => 'Warmup Timeout (seconds)',

            // Template Settings
            'templateExtensions' => 'Template File Extensions',
            'templateBackupSuffix' => 'Template Backup Suffix',
            'templateEnvVarName' => 'Template Environment Variable',

            // Database Settings
            'contentTablePatterns' => 'Content Table Patterns',
            'additionalTables' => 'Additional Tables',
            'columnTypes' => 'Column Types',
            'fieldColumnPattern' => 'Field Column Pattern',

            // URL Replacement
            'sampleUrlLimit' => 'Sample URL Limit',

            // Diagnostics
            'fileListLimit' => 'File List Limit',

            // Dashboard
            'dashboardLogLinesDefault' => 'Dashboard Log Lines',
            'dashboardLogFileName' => 'Dashboard Log File',
        ];
    }

    /**
     * Handles setting attributes from form data
     * Converts comma-separated strings and JSON strings to arrays
     *
     * @param array $values
     * @param bool $safeOnly
     * @return void
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        // Convert comma-separated strings to arrays
        $arrayFields = [
            'sourceVolumeHandles',
            'volumesAtBucketRoot',
            'volumesWithSubfolders',
            'volumesFlatStructure',
            'templateExtensions',
            'contentTablePatterns',
            'columnTypes',
        ];

        foreach ($arrayFields as $field) {
            if (isset($values[$field]) && is_string($values[$field])) {
                $values[$field] = array_filter(array_map('trim', explode(',', $values[$field])));
            }
        }

        // Convert JSON strings to arrays
        $jsonFields = [
            'filesystemMappings',
            'filesystemDefinitions',
            'additionalTables',
        ];

        foreach ($jsonFields as $field) {
            if (isset($values[$field]) && is_string($values[$field])) {
                $decoded = json_decode($values[$field], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $values[$field] = $decoded;
                }
            }
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * Returns attribute hints (help text)
     *
     * @return array
     */
    public function attributeHints(): array
    {
        return [
            // AWS Configuration
            'awsBucket' => 'Your current AWS S3 bucket name. Set via AWS_SOURCE_BUCKET environment variable.',
            'awsRegion' => 'AWS region where your S3 bucket is located (e.g., us-east-1, ca-central-1).',

            // DO Configuration
            'doRegion' => 'DigitalOcean Spaces region. Must match your DO Spaces bucket region.',

            // Filesystem Mappings
            'filesystemMappings' => 'Maps AWS filesystem handles to new DigitalOcean filesystem handles. Format: {"aws_handle": "do_handle"}',

            // Volume Configuration
            'sourceVolumeHandles' => 'List of source volume handles to migrate from.',
            'targetVolumeHandle' => 'Target volume handle for asset consolidation.',
            'quarantineVolumeHandle' => 'Volume for storing unused/orphaned assets.',
            'volumesAtBucketRoot' => 'Volumes located at bucket root level (not in a subfolder).',
            'volumesWithSubfolders' => 'Volumes containing internal subfolder structures.',
            'volumesFlatStructure' => 'Volumes with flat structure (all files at root, no subfolders).',

            // Migration Performance
            'batchSize' => 'Number of assets to process in each batch. Higher = faster but more memory. Recommended: 50-200.',
            'errorThreshold' => 'Maximum total errors before halting migration. Prevents runaway migrations.',
            'criticalErrorThreshold' => 'Threshold for critical errors (write failures, permissions). Lower than general threshold.',
            'maxConcurrentTransforms' => 'Maximum parallel transform generations. Higher = faster but more CPU/memory.',

            // Migration Settings
            'checkpointEveryBatches' => 'Create checkpoint after this many batches. Lower = safer resume points.',
            'changelogFlushEvery' => 'Flush changelog to disk every N operations. Lower = safer but slower.',
            'maxRetries' => 'Retry failed operations this many times. Helps with transient network issues.',
            'retryDelayMs' => 'Wait this many milliseconds between retries.',
            'checkpointRetentionHours' => 'Keep checkpoints for this many hours before cleanup.',
            'maxRepeatedErrors' => 'Stop if same error repeats this many times. Prevents infinite loops.',
            'lockTimeoutSeconds' => 'Migration lock expires after this many seconds. Prevents stale locks.',
            'lockAcquireTimeoutSeconds' => 'Wait this long when trying to acquire migration lock.',

            // Field Configuration
            'optimizedImagesFieldHandle' => 'Handle of ImageOptimize field used for optimized image variants.',

            // Transform Settings
            'warmupTimeout' => 'HTTP timeout for transform URL crawling (seconds).',

            // Template Settings
            'templateExtensions' => 'File extensions to scan in templates directory.',
            'templateBackupSuffix' => 'Suffix pattern for template backups. Use {timestamp} for dynamic timestamps.',
            'templateEnvVarName' => 'Environment variable name used in templates for DO Spaces URL.',

            // Database Settings
            'contentTablePatterns' => 'Database table patterns to scan for content (supports % wildcard).',
            'additionalTables' => 'Additional tables beyond content tables. Format: [{"table": "name", "column": "column"}]',
            'columnTypes' => 'Database column types to search for URLs.',
            'fieldColumnPattern' => 'Pattern for identifying Craft field columns (e.g., field_%).',

            // URL Replacement
            'sampleUrlLimit' => 'Number of sample URLs to show in replacement previews.',

            // Diagnostics
            'fileListLimit' => 'Maximum files to show in diagnostic listings.',

            // Dashboard
            'dashboardLogLinesDefault' => 'Default number of log lines to display in dashboard.',
            'dashboardLogFileName' => 'Log file to display in dashboard.',
        ];
    }
}
