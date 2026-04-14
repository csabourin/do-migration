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
 *   AWS_SOURCE_BUCKET=your-aws-bucket    # Your AWS S3 source bucket
 *   AWS_SOURCE_REGION=us-east-1          # Your AWS S3 source region
 *   DO_S3_ACCESS_KEY=your_key_here       # From DigitalOcean Spaces API
 *   DO_S3_SECRET_KEY=your_secret_here    # From DigitalOcean Spaces API
 *   DO_S3_BUCKET=your-bucket-name        # Your DO Spaces bucket
 *   DO_S3_BASE_URL=https://your-bucket.tor1.digitaloceanspaces.com
 *
 * STEP 3: Update Section 1 below (AWS Source Settings) if needed:
 *   - aws.bucket / AWS_SOURCE_BUCKET controls the AWS bucket name
 *   - aws.region / AWS_SOURCE_REGION controls the AWS region
 *
 * STEP 4: Update Section 3 below (Filesystem Mappings) - Optional:
 *   - Only if your Craft filesystem handles have different names
 *
 * That's it! 🎉 The rest has sensible defaults.
 * Run: ./craft spaghetti-migrator/migration-check/check
 *
 * ═══════════════════════════════════════════════════════════════════════
 * 💡 TIP: Start with 'dev' environment, test thoroughly, then do staging/prod
 * ═══════════════════════════════════════════════════════════════════════
 */

use craft\helpers\App;

$awsAccessKeyEnv = App::env('AWS_SOURCE_ACCESS_KEY') !== null
    ? 'AWS_SOURCE_ACCESS_KEY'
    : 'AWS_ACCESS_KEY_ID';
$awsSecretKeyEnv = App::env('AWS_SOURCE_SECRET_KEY') !== null
    ? 'AWS_SOURCE_SECRET_KEY'
    : 'AWS_SECRET_ACCESS_KEY';

// ═══════════════════════════════════════════════════════════════════════════
// CURRENT ENVIRONMENT (Loaded from .env)
// ═══════════════════════════════════════════════════════════════════════════

$env = App::env('MIGRATION_ENV') ?? 'dev';

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 1: AWS SOURCE CONFIGURATION                                  ┃
// ┃  🔧 CHANGE THIS: Update to match your current AWS S3 setup            ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$awsSource = [
    // ⚠️ REQUIRED: Your current AWS S3 bucket name
    // 📍 Find this in: AWS Console → S3 → Buckets
    // Example: 'my-craft-assets', 'production-s3-bucket', 'website-assets'
    'bucket' => App::env('AWS_SOURCE_BUCKET') ?: 'your-aws-bucket-name',

    // ⚠️ REQUIRED: Your AWS region
    // 📍 Common values: us-east-1, us-west-2, ca-central-1, eu-west-1
    'region' => App::env('AWS_SOURCE_REGION') ?: 'us-east-1',

    // ✅ Loaded from .env: AWS access key (if available)
    'accessKey' => App::env($awsAccessKeyEnv),

    // ✅ Loaded from .env: AWS secret key (if available)
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
        'region' => '$DO_S3_REGION',
    ],
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 3: FILESYSTEM MAPPINGS                                       ┃
// ┃  🔧 CHANGE THIS: Only if your Craft filesystem handles are different  ┃
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

// Maps AWS filesystem handles → New DigitalOcean filesystem handles
// 📍 Find your filesystem handles in: Craft CP → Settings → Assets → Filesystems
// 💡 Convention: Add "_do" suffix to new handles to distinguish them
// ⚠️ NOTE: This switches which filesystem your volumes use, not volume names

$filesystemMappings = [
    // AWS Filesystem   →  DO Filesystem (will be created)
    // ⚠️ CHANGE THIS: Update to match YOUR Craft filesystem handles
    // 📍 Find your handles in: Craft CP → Settings → Assets → Filesystems
    // Example mappings (replace with your actual filesystem handles):
    'images'            => 'images_do',
    'optimisedImages'   => 'optimisedImages_do',
    'documents'         => 'documents_do',
    'videos'            => 'videos_do',
    // Add more mappings as needed for your project
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 4: VOLUME BEHAVIOR                                           ┃
// ┃  🔧 OPTIONAL: Describes your volume structure (affects migration)     ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$volumeConfig = [
    // Which volumes to migrate FROM
    // ⚠️ CHANGE THIS: Update to match YOUR Craft volume handles
    // 💡 Usually your main asset volumes
    'source' => ['images', 'documents'],

    // Where to consolidate assets TO
    // 💡 Best practice: Consolidate into one main volume
    'target' => 'images',

    // Documents volume handle used by repair and validation commands
    // 💡 Change this if your document assets live in a differently named volume
    'documentsHandle' => 'documents',

    // Where to move unused/orphaned assets
    // 💡 Create this volume before migration
    'quarantine' => 'quarantine',

    // Safety threshold: warn if more than N% of assets would be quarantined
    // If exceeded, auto-confirm (--yes) is blocked and manual confirmation required
    // 💡 A high quarantine percentage may indicate missing reference detection
    'quarantineSafetyThresholdPercent' => 25,

    // ─────────────────────────────────────────────────────────────────────
    // Advanced: Volume Structure Hints (helps migration optimize paths)
    // ⚠️ OPTIONAL: Customize these based on your volume structure
    // ─────────────────────────────────────────────────────────────────────

    // Volumes at bucket root (not in a subfolder)
    // ℹ️ These volumes exist at S3 bucket root, not inside a subfolder
    'atBucketRoot' => ['images'],

    // Volumes with internal subfolders
    // ℹ️ These volumes contain organized subfolders with files
    // Example: images has /products, /blog, /team subfolders
    'withSubfolders' => ['images', 'documents'],

    // Volumes with flat structure (no subfolders)
    // ℹ️ All files directly at root level with no folder organization
    'flatStructure' => [],

    // Handle of the optimised/transformed images volume
    // ℹ️ Used to derive flattenable volume defaults. Change if your volume uses a different handle.
    'optimisedImagesHandle' => 'optimisedImages',

    // Volumes permitted to be flattened to root by the flatten-to-root command
    // ℹ️ Leave empty to auto-derive from optimisedImagesHandle above.
    //    Set explicitly to allow additional volumes (use with caution — flattening
    //    destroys folder structure and breaks Craft-generated asset URLs).
    'flattenable' => [],
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 5: FILESYSTEM DEFINITIONS                                    ┃
// ┃  ✅ AUTO-GENERATED: These will be created automatically               ┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

// These filesystem configurations will be created in Craft automatically
// 💡 Add corresponding .env variables for subfolders (see .env.example)

$filesystemDefinitions = [
    [
        'handle' => 'images_do',
        'name' => 'Images (DO Spaces)',
        'subfolder' => '$DO_S3_SUBFOLDER_IMAGES',           // Optional: .env variable
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
    [
        'handle' => 'documents_do',
        'name' => 'Documents (DO Spaces)',
        'subfolder' => '$DO_S3_SUBFOLDER_DOCUMENTS',        // Optional: .env variable
        'hasUrls' => true,
    ],
    [
        'handle' => 'videos_do',
        'name' => 'Videos (DO Spaces)',
        'subfolder' => '$DO_S3_SUBFOLDER_VIDEOS',           // Optional: .env variable
        'hasUrls' => true,
    ],
    [
        'handle' => 'imageTransforms_do',
        'name' => 'Image Transforms (DO Spaces)',
        'subfolder' => '$DO_S3_SUBFOLDER_IMAGETRANSFORMS',  // Optional: .env variable
        'hasUrls' => true,
    ],
    [
        'handle' => 'quarantine',
        'name' => 'Quarantined Assets (DO Spaces)',
        'subfolder' => '$DO_S3_SUBFOLDER_QUARANTINE',       // Optional: .env variable
        'hasUrls' => false,
    ],
    // Command helpers can reference these handles directly when they differ per project.
    'transformHandle' => 'imageTransforms_do',
    'quarantineHandle' => 'quarantine',
    // ⚠️ Add more filesystem definitions as needed to match your filesystemMappings above
];

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 6: MIGRATION PERFORMANCE SETTINGS                            ┃
// ┃  ✅ GOOD DEFAULTS: Only change if you know what you're doing          ┃
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
    // 💡 Safety threshold to prevent runaway migrations
    'errorThreshold' => 50,

    // How long a migration lock is valid before it expires (seconds)
    // 💡 12 hours = 43200 seconds (prevents stale locks)
    'lockTimeoutSeconds' => 43200,

    // How long to wait when trying to acquire a migration lock (seconds)
    // 💡 Prevents race conditions when multiple processes try to migrate
    'lockAcquireTimeoutSeconds' => 3,

    // Sample size for the volumeId integrity pre-flight check (#11)
    // ℹ️ How many assets to check per source volume. Also capped at 5% of total.
    'integrityCheckSampleSize' => 20,
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

// ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
// ┃  SECTION 13: DASHBOARD COMMAND DEFAULTS                              ┃
// ┃  ✅ GOOD DEFAULTS: Controls the rclone examples shown in the dashboard┃
// ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛

$rcloneSettings = [
    'awsRemoteName' => 'aws-s3',
    'doRemoteName' => 'prod-medias',
    'targetPath' => 'medias',
    'copyOptions' => '--exclude "_*/**" --fast-list --transfers=32 --checkers=16 --use-mmap --s3-acl=public-read -P',
    'checkOptions' => '--one-way',
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

    // Dashboard command defaults
    'rclone' => $rcloneSettings,

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
 * □ AWS bucket name matches your current S3 bucket
 * □ AWS region matches your current S3 region
 * □ Filesystem handles match your Craft filesystems (Check: Settings → Assets → Filesystems)
 * □ DigitalOcean Spaces bucket exists and is accessible
 * □ Access keys have read/write permissions
 *
 * Run validation:
 *   ./craft spaghetti-migrator/migration-check/check
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * 💡 COMMON ISSUES & SOLUTIONS
 *
 * Issue: "DigitalOcean bucket name is not configured"
 * → Add DO_S3_BUCKET to your .env file
 *
 * Issue: "AWS URLs are not configured"
 * → Set aws.bucket in Section 1 above
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
