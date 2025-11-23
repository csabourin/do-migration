<?php
/**
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃  AWS S3 → DigitalOcean Spaces Migration Configuration                 ┃
 * ┃  Single Source of Truth for All Migration Settings                    ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 *
 * ╔═══════════════════════════════════════════════════════════════════════╗
 * ║                        🚀 QUICK START GUIDE                           ║
 * ╚═══════════════════════════════════════════════════════════════════════╝
 *
 * STEP 1: Copy this file
 *   cp migration-config.php /path/to/your-craft-project/config/
 *
 * STEP 2: Update your .env file with these REQUIRED variables:
 *   MIGRATION_ENV=dev                    # Or: staging, prod
 *   AWS_SOURCE_BUCKET=ncc-website-2      # Your AWS S3 source bucket
 *   AWS_SOURCE_REGION=ca-central-1       # Your AWS S3 source region
 *   DO_S3_ACCESS_KEY=your_key_here       # From DigitalOcean Spaces API
 *   DO_S3_SECRET_KEY=your_secret_here    # From DigitalOcean Spaces API
 *   DO_S3_BUCKET=your-bucket-name        # Your DO Spaces bucket
 *   DO_S3_BASE_URL=https://your-bucket.tor1.digitaloceanspaces.com
 *
 * STEP 3: Optional - Add AWS credentials to .env (if needed for direct access):
 *   AWS_SOURCE_ACCESS_KEY=your_aws_key   # Optional: AWS access key
 *   AWS_SOURCE_SECRET_KEY=your_aws_secret # Optional: AWS secret key
 *   # OR use default AWS env vars:
 *   AWS_ACCESS_KEY_ID=your_aws_key
 *   AWS_SECRET_ACCESS_KEY=your_aws_secret
 *
 * That's it! 🎉 The rest has sensible defaults.
 * Run: ./craft spaghetti-migrator/migration-check/check
 *
 * ═══════════════════════════════════════════════════════════════════════
 * 💡 TIP: Start with 'dev' environment, test thoroughly, then do staging/prod
 * ═══════════════════════════════════════════════════════════════════════
 */

use craft\helpers\App;

// ═══════════════════════════════════════════════════════════════════════════
// CURRENT ENVIRONMENT (Loaded from .env)
// ═══════════════════════════════════════════════════════════════════════════

$env = App::env('MIGRATION_ENV') ?? 'dev';

// Auto-detect which AWS environment variable names to use
// Supports both AWS_SOURCE_* (migration-specific) and AWS_* (standard AWS SDK)
$awsAccessKeyEnv = App::env('AWS_SOURCE_ACCESS_KEY') !== null
    ? 'AWS_SOURCE_ACCESS_KEY'
    : 'AWS_ACCESS_KEY_ID';
$awsSecretKeyEnv = App::env('AWS_SOURCE_SECRET_KEY') !== null
    ? 'AWS_SOURCE_SECRET_KEY'
    : 'AWS_SECRET_ACCESS_KEY';

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 1: AWS SOURCE CONFIGURATION                                  ┃
// ┃  🔧 CHANGE THIS: Update to match your current AWS S3 setup            ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$awsSource = [
    // ⚠️ REQUIRED: Your current AWS S3 bucket name
    // 📍 Find this in: AWS Console → S3 → Buckets
    // Can be overridden by AWS_SOURCE_BUCKET environment variable
    'bucket' => App::env('AWS_SOURCE_BUCKET') ?: 'ncc-website-2',

    // ⚠️ REQUIRED: Your AWS region
    // 📍 Common values: us-east-1, us-west-2, ca-central-1, eu-west-1
    'region' => App::env('AWS_SOURCE_REGION') ?: 'ca-central-1',

    // ✅ OPTIONAL: Loaded from .env (if direct AWS access is needed)
    // The system auto-detects AWS_SOURCE_ACCESS_KEY or AWS_ACCESS_KEY_ID
    'accessKey' => App::env($awsAccessKeyEnv),

    // ✅ OPTIONAL: Loaded from .env (if direct AWS access is needed)
    // The system auto-detects AWS_SOURCE_SECRET_KEY or AWS_SECRET_ACCESS_KEY
    'secretKey' => App::env($awsSecretKeyEnv),

    // ✅ AUTO-GENERATED: All possible URL formats (leave as-is)
    // The system will search for all these URL patterns in your database
    'urls' => null,  // Auto-generated from bucket name (see bottom of file)
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 2: DIGITALOCEAN TARGET CONFIGURATION                         ┃
// ┃  ✅ OPTIONAL: Loaded from .env (recommended)                          ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$doTarget = [
    // Region where your DO Spaces is located
    // 📍 Available regions: nyc3, ams3, sgp1, sfo3, fra1, tor1
    'region' => 'tor1',

    // ✅ Loaded from .env: DO_S3_BUCKET
    'bucket' => App::env('DO_S3_BUCKET'),

    // ✅ Loaded from .env: DO_S3_BASE_URL
    // Format: https://your-bucket.tor1.digitaloceanspaces.com
    'baseUrl' => App::env('DO_S3_BASE_URL'),

    // ✅ Loaded from .env: DO_S3_ACCESS_KEY
    'accessKey' => App::env('DO_S3_ACCESS_KEY'),

    // ✅ Loaded from .env: DO_S3_SECRET_KEY
    'secretKey' => App::env('DO_S3_SECRET_KEY'),

    // ✅ Loaded from .env: DO_S3_BASE_ENDPOINT
    // Format: https://tor1.digitaloceanspaces.com (region-only, no bucket name)
    // This is different from baseUrl - endpoint is for SDK configuration
    'endpoint' => App::env('DO_S3_BASE_ENDPOINT'),

    // Environment variable references (stored in Craft config with $ prefix)
    // These are used when storing config in the database
    'envVars' => [
        'accessKey' => '$DO_S3_ACCESS_KEY',
        'secretKey' => '$DO_S3_SECRET_KEY',
        'bucket' => '$DO_S3_BUCKET',
        'baseUrl' => '$DO_S3_BASE_URL',
        'endpoint' => '$DO_S3_BASE_ENDPOINT',
    ],
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 3: FILESYSTEM MAPPINGS                                       ┃
// ┃  🔧 YOUR CONFIGURATION: Customized for your project                   ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

// IMPORTANT CONCEPT:
// ═══════════════════════════════════════════════════════════════════════
// In Craft CMS:
//   • FILESYSTEMS = Storage backends (AWS S3, DO Spaces) - where files live
//   • VOLUMES = Logical containers that USE filesystems + metadata
//
// During migration:
//   • Volumes KEEP their same name and are NOT transferred
//   • Volumes SWITCH which filesystem they use (via fsHandle property)
//   • Filesystems have SEPARATE names:
//       - AWS filesystems keep their original name (e.g., 'images')
//       - DO filesystems get '_do' suffix (e.g., 'images_do')
// ═══════════════════════════════════════════════════════════════════════

$filesystemMappings = [
    // AWS Filesystem   →  DO Filesystem (will be created)
    'images'            => 'images_do',
    'documents'         => 'documents_do',
    'videos'            => 'videos_do',
    'optimisedImages'   => 'optimisedImages_do',
    'chartData'         => 'chartData_do',
    'formDocuments'     => 'formDocuments_do',
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 4: VOLUME BEHAVIOR                                           ┃
// ┃  🔧 YOUR CONFIGURATION: Customized for your project                   ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$volumeConfig = [
    // Which volumes to migrate FROM
    'source' => ['images', 'optimisedImages'],

    // Where to consolidate assets TO
    'target' => 'images',

    // Where to move unused/orphaned assets
    'quarantine' => 'quarantine',

    // ─────────────────────────────────────────────────────────────────────
    // Advanced: Volume Structure Hints (helps migration optimize paths)
    // ─────────────────────────────────────────────────────────────────────

    // Volumes at bucket root (not in a subfolder)
    'atBucketRoot' => ['optimisedImages'],

    // Volumes with internal subfolders
    'withSubfolders' => ['images', 'optimisedImages'],

    // Volumes with flat structure (no subfolders)
    'flatStructure' => [],
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 5: FILESYSTEM DEFINITIONS                                    ┃
// ┃  🔧 YOUR CONFIGURATION: Customized for your project                   ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$filesystemDefinitions = [
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
        'subfolder' => '$DO_S3_SUBFOLDER_IMAGETRANSFORMS',  // Updated from ALLTRANSFORMS
        'hasUrls' => true,
    ],
    [
        'handle' => 'quarantine',
        'name' => 'Quarantined Assets (DO Spaces)',
        'subfolder' => '$DO_S3_SUBFOLDER_QUARANTINE',
        'hasUrls' => false,
    ],
    [
        'handle' => 'chartData_do',
        'name' => 'Chart Data (DO Spaces)',
        'subfolder' => '$DO_S3_SUBFOLDER_CHARTDATA',
        'hasUrls' => true,
    ],
    [
        'handle' => 'formDocuments_do',
        'name' => 'Form Documents (DO Spaces)',
        'subfolder' => '$DO_S3_SUBFOLDER_FORMDOCUMENTS',
        'hasUrls' => true,
    ],
    [
        'handle' => 'optimisedImages_do',
        'name' => 'Optimised Images (DO Spaces)',
        // NOTE: Initially created without subfolder (at root)
        // After migration, this will be updated to use the target subfolder from ENV
        'subfolder' => '',
        // Target subfolder to apply after migration completes (from ENV variable)
        'targetSubfolder' => '$DO_S3_SUBFOLDER_OPTIMISEDIMAGES',
        'hasUrls' => true,
    ],
];

// REMOVED: Filesystem handles are now part of the volumes config
// The methods getTransformFilesystemHandle() and getQuarantineFilesystemHandle()
// will use the default values defined in MigrationConfig.php

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 6: MIGRATION PERFORMANCE SETTINGS                            ┃
// ┃  🔧 YOUR CONFIGURATION: Custom settings                               ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$migrationSettings = [
    // How many assets to process in each batch
    // 💡 Higher = faster but more memory. Lower = slower but safer.
    // Recommended: 50-200 depending on your server
    'batchSize' => 100,

    // How often to create checkpoints (allows resume if interrupted)
    // 💡 1 = checkpoint after every batch (safest)
    'checkpointEveryBatches' => 1,

    // How often to flush the change log to disk
    // 💡 Lower = safer but slower. Higher = faster but riskier.
    'changelogFlushEvery' => 5,

    // Maximum retry attempts for failed operations
    // 💡 Network issues are common, retries help
    'maxRetries' => 3,

    // How long to keep old checkpoints (hours)
    // 💡 72 hours = 3 days (enough time to resume or debug)
    'checkpointRetentionHours' => 72,

    // Stop migration if this many repeated errors occur
    // 💡 Prevents runaway loops on systemic issues
    'maxRepeatedErrors' => 10,

    // Maximum number of errors before halting the migration process
    // 💡 YOUR CUSTOM VALUE: Set to 500 for higher tolerance
    'errorThreshold' => 500,

    // How long a migration lock is valid before it expires (seconds)
    // 💡 12 hours = 43200 seconds (prevents stale locks)
    'lockTimeoutSeconds' => 43200,

    // How long to wait when trying to acquire a migration lock (seconds)
    // 💡 Prevents race conditions when multiple processes try to migrate
    'lockAcquireTimeoutSeconds' => 3,
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 7: FIELD CONFIGURATION                                       ┃
// ┃  ✅ GOOD DEFAULTS: Only change if your field handles differ           ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$fieldSettings = [
    // The ImageOptimize field handle used for storing optimized image variants
    // 💡 Find in: Craft CP → Settings → Fields
    'optimizedImages' => 'optimizedImagesField',
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 8: TRANSFORM SETTINGS                                        ┃
// ┃  ✅ GOOD DEFAULTS: Controls image transform generation                ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$transformSettings = [
    // How many transforms can be generated in parallel
    // 💡 Higher = faster but more CPU/memory usage
    'maxConcurrent' => 5,

    // HTTP timeout when warming up transforms via URL crawling (seconds)
    // 💡 Increase if transforms take long to generate
    'warmupTimeout' => 10,
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 9: TEMPLATE & DATABASE SCANNING                              ┃
// ┃  ✅ GOOD DEFAULTS: Rarely needs changes                               ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$scanSettings = [
    'templates' => [
        // File extensions to scan for URLs
        'extensions' => ['twig'],

        // Backup file suffix pattern
        'backupSuffix' => '.backup-{timestamp}',

        // Environment variable to use in templates
        'envVarName' => 'DO_S3_BASE_URL',
    ],

    'database' => [
        // Database tables to scan for URLs
        'contentTables' => [
            'content',
            'matrixcontent_%',
        ],

        // Additional tables beyond content
        'additionalTables' => [
            ['table' => 'projectconfig', 'column' => 'value'],
            ['table' => 'elements_sites', 'column' => 'metadata'],
            ['table' => 'revisions', 'column' => 'data'],
        ],

        // Column types to search
        'columnTypes' => ['text', 'mediumtext', 'longtext'],

        // Pattern for identifying Craft field columns (e.g., field_*)
        'fieldColumnPattern' => 'field_%',
    ],

    'paths' => [
        'templates' => '@templates',
        'storage' => '@storage',
        'logs' => '@storage/logs',
        'backups' => '@storage/backups',
    ],
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 10: URL REPLACEMENT SETTINGS                                 ┃
// ┃  ✅ GOOD DEFAULTS: Controls URL replacement preview behavior          ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$urlReplacementSettings = [
    // How many sample URLs to show when previewing replacements
    // 💡 Shows examples before performing actual replacement
    'sampleUrlLimit' => 5,
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 11: DIAGNOSTICS SETTINGS                                     ┃
// ┃  ✅ GOOD DEFAULTS: Controls diagnostic output limits                  ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$diagnosticsSettings = [
    // Maximum number of files to show when listing filesystem contents
    // 💡 Prevents overwhelming output in diagnostic commands
    'fileListLimit' => 50,
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 12: DASHBOARD SETTINGS                                       ┃
// ┃  ✅ GOOD DEFAULTS: Controls dashboard display                         ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$dashboardSettings = [
    // How many log lines to show by default in the dashboard
    // 💡 Balance between useful context and readability
    'logLinesDefault' => 100,

    // Which log file to show in the dashboard
    // 💡 Typically 'web.log' for web requests
    'logFileName' => 'web.log',
];

// ═══════════════════════════════════════════════════════════════════════════
// 🔄 AUTO-GENERATION & ASSEMBLY
// ═══════════════════════════════════════════════════════════════════════════

// Auto-generate all possible AWS S3 URL patterns from bucket name
if ($awsSource['urls'] === null) {
    $bucket = $awsSource['bucket'];
    $region = $awsSource['region'];
    $awsSource['urls'] = [
        "https://{$bucket}.s3.amazonaws.com",
        "http://{$bucket}.s3.amazonaws.com",
        "https://s3.{$region}.amazonaws.com/{$bucket}",
        "http://s3.{$region}.amazonaws.com/{$bucket}",
        "https://s3.amazonaws.com/{$bucket}",
        "http://s3.amazonaws.com/{$bucket}",
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// 📦 FINAL CONFIGURATION EXPORT
// ═══════════════════════════════════════════════════════════════════════════

return [
    // Environment
    'environment' => $env,

    // AWS Source
    'aws' => $awsSource,

    // DigitalOcean Target
    'digitalocean' => $doTarget,

    // Volume & Filesystem Configuration
    'filesystemMappings' => $filesystemMappings,
    'volumes' => $volumeConfig,
    'filesystems' => $filesystemDefinitions,

    // Migration Performance
    'migration' => $migrationSettings,

    // Field Configuration
    'fields' => $fieldSettings,

    // Transform Settings
    'transforms' => $transformSettings,

    // Template & Database Scanning
    'templates' => $scanSettings['templates'],
    'database' => $scanSettings['database'],
    'paths' => $scanSettings['paths'],

    // URL Replacement Settings
    'urlReplacement' => $urlReplacementSettings,

    // Diagnostics Settings
    'diagnostics' => $diagnosticsSettings,

    // Dashboard Settings
    'dashboard' => $dashboardSettings,

    // Environment variable names (for reference)
    'envVars' => [
        'awsBucket' => 'AWS_SOURCE_BUCKET',
        'awsRegion' => 'AWS_SOURCE_REGION',
        'awsAccessKey' => $awsAccessKeyEnv,
        'awsSecretKey' => $awsSecretKeyEnv,
        'doAccessKey' => 'DO_S3_ACCESS_KEY',
        'doSecretKey' => 'DO_S3_SECRET_KEY',
        'doBucket' => 'DO_S3_BUCKET',
        'doBaseUrl' => 'DO_S3_BASE_URL',
        'doRegion' => 'DO_S3_REGION',
        'doEndpoint' => 'DO_S3_BASE_ENDPOINT',
    ],
];

/**
 * ╔═══════════════════════════════════════════════════════════════════════╗
 * ║                      ✅ VALIDATION CHECKLIST                          ║
 * ╚═══════════════════════════════════════════════════════════════════════╝
 *
 * Before running migration, verify:
 *
 * □ .env file has all required DO_S3_* variables
 * □ AWS bucket name matches your current S3 bucket (ncc-website-2)
 * □ AWS region matches your current S3 region (ca-central-1)
 * □ Filesystem handles match your Craft filesystems
 * □ DigitalOcean Spaces bucket exists and is accessible
 * □ Access keys have read/write permissions
 *
 * Run validation:
 *   ./craft spaghetti-migrator/migration-check/check
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * 💡 WHAT'S NEW IN THIS VERSION:
 *
 * ✨ AWS Credentials Support:
 *    - Added optional AWS access key and secret key configuration
 *    - Auto-detects AWS_SOURCE_* or standard AWS_* environment variables
 *    - Useful for direct AWS access if needed during migration
 *
 * ✨ Updated Environment Variables:
 *    - DO_S3_SUBFOLDER_IMAGETRANSFORMS (was: DO_S3_SUBFOLDER_ALLTRANSFORMS)
 *    - Added AWS_SOURCE_ACCESS_KEY and AWS_SOURCE_SECRET_KEY support
 *
 * ✅ Preserved Your Custom Settings:
 *    - AWS bucket: ncc-website-2
 *    - AWS region: ca-central-1
 *    - Error threshold: 500 (your preference)
 *    - All your filesystem mappings and definitions
 *    - Your volume configuration (source, target, quarantine)
 *    - Your optimisedImages configuration with targetSubfolder
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * 💡 COMMON ISSUES & SOLUTIONS
 *
 * Issue: "DigitalOcean bucket name is not configured"
 * → Add DO_S3_BUCKET to your .env file
 *
 * Issue: "AWS URLs are not configured"
 * → Set aws.bucket in Section 1 above (already set to ncc-website-2)
 *
 * Issue: "Volume 'images' not found"
 * → Check your Craft filesystem handles in Settings → Assets → Filesystems
 * → Update filesystemMappings in Section 3 to match your handles
 *
 * Issue: Migration runs out of memory
 * → Reduce batchSize in Section 6 (try 50 or 25)
 *
 * 📚 Full documentation:
 * → README_FR.md (French)
 * → README.md (English)
 * → CONFIG_QUICK_REFERENCE.md (Configuration reference)
 *
 * ═══════════════════════════════════════════════════════════════════════
 */
