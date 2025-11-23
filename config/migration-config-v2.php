<?php
/**
 * Spaghetti Migrator v2.0 Configuration
 *
 * This configuration file demonstrates the new multi-provider architecture.
 * Copy this to your Craft config/ directory as migration-config.php
 *
 * New in v2.0:
 * - Multi-cloud provider support (S3, DO Spaces, GCS, Azure, Local)
 * - Flexible URL replacement strategies
 * - Local filesystem reorganization mode
 * - Provider-agnostic configuration
 */

return [
    /**
     * ========================================================================
     * MULTI-PROVIDER CONFIGURATION (v2.0)
     * ========================================================================
     */

    /**
     * Source Provider
     *
     * Where to migrate FROM.
     * Supported types: 's3', 'do-spaces', 'gcs', 'azure-blob', 'local'
     */
    'sourceProvider' => [
        'type' => 's3', // AWS S3

        'config' => [
            'bucket' => getenv('AWS_BUCKET'),
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
            'accessKey' => getenv('AWS_ACCESS_KEY_ID'),
            'secretKey' => getenv('AWS_SECRET_ACCESS_KEY'),
            'baseUrl' => getenv('AWS_BASE_URL'), // Optional custom URL
        ],
    ],

    /**
     * Target Provider
     *
     * Where to migrate TO.
     */
    'targetProvider' => [
        'type' => 'do-spaces', // DigitalOcean Spaces

        'config' => [
            'bucket' => getenv('DO_SPACES_BUCKET'),
            'region' => getenv('DO_SPACES_REGION') ?: 'nyc3',
            'accessKey' => getenv('DO_SPACES_KEY'),
            'secretKey' => getenv('DO_SPACES_SECRET'),
            'baseUrl' => getenv('DO_SPACES_BASE_URL'),
            'endpoint' => getenv('DO_SPACES_ENDPOINT'), // Region-only endpoint
        ],
    ],

    /**
     * Migration Mode
     *
     * - 'cloud-to-cloud': Migrate between cloud providers (default)
     * - 'local-reorganize': Reorganize local filesystem
     * - 'cloud-to-local': Backup cloud assets locally
     * - 'local-to-cloud': Upload local files to cloud
     */
    'migrationMode' => 'cloud-to-cloud',

    /**
     * ========================================================================
     * EXAMPLE CONFIGURATIONS FOR DIFFERENT SCENARIOS
     * ========================================================================
     */

    /**
     * EXAMPLE 1: S3 → Google Cloud Storage
     */
    /*
    'sourceProvider' => [
        'type' => 's3',
        'config' => [
            'bucket' => getenv('AWS_BUCKET'),
            'region' => 'us-east-1',
            'accessKey' => getenv('AWS_ACCESS_KEY_ID'),
            'secretKey' => getenv('AWS_SECRET_ACCESS_KEY'),
        ],
    ],

    'targetProvider' => [
        'type' => 'gcs',
        'config' => [
            'bucket' => getenv('GCS_BUCKET'),
            'projectId' => getenv('GCS_PROJECT_ID'),
            'keyFilePath' => getenv('GCS_KEY_FILE'), // Path to service account JSON
        ],
    ],
    */

    /**
     * EXAMPLE 2: Local Filesystem Reorganization
     */
    /*
    'migrationMode' => 'local-reorganize',

    'sourceProvider' => [
        'type' => 'local',
        'config' => [
            'basePath' => '/path/to/messy/photos',
            'baseUrl' => null, // Optional public URL
        ],
    ],

    'targetProvider' => [
        'type' => 'local',
        'config' => [
            'basePath' => '/path/to/organized/photos',
        ],
    ],

    'localReorganization' => [
        'strategy' => 'flatten', // or 'organize-by-date', 'organize-by-type'
        'namingPattern' => '{date}-{original}', // {date}, {type}, {size}, {hash}, {original}
        'handleDuplicates' => 'rename', // 'skip', 'overwrite', 'rename', 'deduplicate'
    ],
    */

    /**
     * EXAMPLE 3: S3 → Azure Blob Storage
     */
    /*
    'targetProvider' => [
        'type' => 'azure-blob',
        'config' => [
            'container' => getenv('AZURE_CONTAINER'),
            'accountName' => getenv('AZURE_ACCOUNT_NAME'),
            'accountKey' => getenv('AZURE_ACCOUNT_KEY'),
        ],
    ],
    */

    /**
     * EXAMPLE 4: Cloud → Local Backup
     */
    /*
    'migrationMode' => 'cloud-to-local',

    'sourceProvider' => [
        'type' => 's3',
        'config' => [...],
    ],

    'targetProvider' => [
        'type' => 'local',
        'config' => [
            'basePath' => '/backups/cloud-assets',
        ],
    ],
    */

    /**
     * ========================================================================
     * URL REPLACEMENT STRATEGIES (v2.0)
     * ========================================================================
     */

    /**
     * URL Replacement Strategies
     *
     * Multiple strategies can be defined. They run in priority order.
     */
    'urlReplacementStrategies' => [
        // Simple string replacement (most common)
        [
            'type' => 'simple',
            'search' => 'https://my-bucket.s3.amazonaws.com',
            'replace' => 'https://my-space.nyc3.digitaloceanspaces.com',
            'priority' => 0,
        ],

        // Regex replacement for complex patterns
        [
            'type' => 'regex',
            'pattern' => '#https://([^.]+)\.s3\.amazonaws\.com#',
            'replacement' => 'https://$1.nyc3.digitaloceanspaces.com',
            'priority' => 10, // Higher priority = runs first
        ],

        // Multiple domain mappings
        [
            'type' => 'multi-mapping',
            'mappings' => [
                'cdn1.example.com' => 'cdn-new.example.com',
                'cdn2.example.com' => 'cdn-new.example.com',
                'cdn3.example.com' => 'cdn-new.example.com',
            ],
            'priority' => 5,
        ],
    ],

    /**
     * ========================================================================
     * LEGACY CONFIGURATION (Backward Compatibility)
     * ========================================================================
     */

    /**
     * Legacy AWS S3 Configuration
     *
     * These settings are still supported for backward compatibility.
     * If sourceProvider is not defined, these values are used automatically.
     */
    'aws' => [
        'bucket' => getenv('AWS_BUCKET'),
        'region' => getenv('AWS_REGION') ?: 'us-east-1',
        'urls' => [
            'https://' . getenv('AWS_BUCKET') . '.s3.amazonaws.com',
            'http://' . getenv('AWS_BUCKET') . '.s3.amazonaws.com',
        ],
    ],

    /**
     * Legacy DigitalOcean Spaces Configuration
     *
     * These settings are still supported for backward compatibility.
     */
    'digitalocean' => [
        'bucket' => getenv('DO_SPACES_BUCKET'),
        'region' => getenv('DO_SPACES_REGION') ?: 'nyc3',
        'baseUrl' => getenv('DO_SPACES_BASE_URL'),
        'endpoint' => getenv('DO_SPACES_ENDPOINT'),
    ],

    /**
     * ========================================================================
     * MIGRATION SETTINGS
     * ========================================================================
     */

    'migration' => [
        'batchSize' => 100,
        'checkpointEveryBatches' => 1,
        'changelogFlushEvery' => 5,
        'maxRetries' => 3,
        'retryDelayMs' => 1000,
        'checkpointRetentionHours' => 72,
        'maxRepeatedErrors' => 10,
        'errorThreshold' => 50,
        'criticalErrorThreshold' => 20,
        'lockTimeoutSeconds' => 43200, // 12 hours
        'lockAcquireTimeoutSeconds' => 3,
        'progressReportInterval' => 50,
        'lockRefreshIntervalSeconds' => 60,
        'verificationSampleSize' => 100,
        'fuzzyMatchMinConfidence' => 0.60,
        'fuzzyMatchWarnConfidence' => 0.90,
        'priorityFolderPatterns' => ['originals'],
    ],

    /**
     * ========================================================================
     * VOLUME CONFIGURATION
     * ========================================================================
     */

    'volumes' => [
        'source' => ['images', 'optimisedImages'],
        'target' => 'images',
        'quarantine' => 'quarantine',
        'atBucketRoot' => ['optimisedImages', 'chartData'],
        'withSubfolders' => ['images', 'optimisedImages'],
        'flatStructure' => ['chartData'],
    ],

    /**
     * ========================================================================
     * FILESYSTEM CONFIGURATION
     * ========================================================================
     */

    'filesystemMappings' => [
        'awsImages' => 'doImages',
        'awsOptimisedImages' => 'doOptimisedImages',
    ],

    'filesystems' => [
        [
            'handle' => 'doImages',
            'type' => 'vaersaagod\\dospaces\\Fs',
            'hasUrls' => true,
            'url' => getenv('DO_SPACES_BASE_URL'),
            'bucket' => getenv('DO_SPACES_BUCKET'),
            'region' => getenv('DO_SPACES_REGION'),
            'subfolder' => '',
        ],
    ],

    /**
     * ========================================================================
     * OTHER SETTINGS
     * ========================================================================
     */

    'environment' => getenv('CRAFT_ENVIRONMENT') ?: 'dev',

    'fields' => [
        'optimizedImages' => 'optimizedImagesField',
    ],

    'transforms' => [
        'maxConcurrent' => 5,
        'warmupTimeout' => 10,
    ],

    'templates' => [
        'extensions' => ['twig'],
        'backupSuffix' => '.backup-{timestamp}',
        'envVarName' => 'DO_S3_BASE_URL',
    ],

    'database' => [
        'contentTables' => ['content', 'matrixcontent_%'],
        'additionalTables' => [],
        'columnTypes' => ['text', 'mediumtext', 'longtext'],
        'fieldColumnPattern' => 'field_%',
    ],

    'urlReplacement' => [
        'sampleUrlLimit' => 5,
    ],

    'diagnostics' => [
        'fileListLimit' => 50,
    ],

    'dashboard' => [
        'logLinesDefault' => 100,
        'logFileName' => 'web.log',
    ],

    'paths' => [
        'templates' => '@templates',
        'storage' => '@storage',
        'logs' => '@storage/logs',
        'backups' => '@storage/backups',
    ],
];
