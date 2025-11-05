<?php
/**
 * Migration Configuration - Single Source of Truth
 *
 * This file contains ALL configuration for the AWS S3 to DigitalOcean Spaces migration.
 *
 * Usage:
 *   1. Copy this file to your Craft config/ directory
 *   2. Set MIGRATION_ENV in .env file (dev, staging, prod)
 *   3. All controllers will automatically use these settings
 *
 * Environment Variables Required in .env:
 *   MIGRATION_ENV=dev|staging|prod
 *   DO_S3_ACCESS_KEY=your_key
 *   DO_S3_SECRET_KEY=your_secret
 *   DO_S3_BUCKET=your_bucket_name
 */

use craft\helpers\App;

// Get current environment (default to 'dev' if not set)
$env = App::env('MIGRATION_ENV') ?? 'dev';

// ============================================================================
// ENVIRONMENT-SPECIFIC SETTINGS
// ============================================================================

$environments = [
    // Development Environment
    'dev' => [
        'aws' => [
            'bucket' => 'ncc-website-2',
            'region' => 'ca-central-1',
            'urls' => [
                'https://ncc-website-2.s3.amazonaws.com',
                'http://ncc-website-2.s3.amazonaws.com',
                'https://s3.ca-central-1.amazonaws.com/ncc-website-2',
                'http://s3.ca-central-1.amazonaws.com/ncc-website-2',
                'https://s3.amazonaws.com/ncc-website-2',
                'http://s3.amazonaws.com/ncc-website-2',
            ],
        ],
        'digitalocean' => [
            'region' => 'tor1',
            'bucket' => App::env('DO_S3_BUCKET') ?? 'your-dev-bucket',
            'baseUrl' => App::env('DO_S3_BASE_URL') ?? 'https://dev-medias-test.tor1.digitaloceanspaces.com',
            'accessKey' => App::env('DO_S3_ACCESS_KEY'),
            'secretKey' => App::env('DO_S3_SECRET_KEY'),
        ],
    ],

    // Staging Environment
    'staging' => [
        'aws' => [
            'bucket' => 'ncc-website-2',
            'region' => 'ca-central-1',
            'urls' => [
                'https://ncc-website-2.s3.amazonaws.com',
                'http://ncc-website-2.s3.amazonaws.com',
                'https://s3.ca-central-1.amazonaws.com/ncc-website-2',
                'http://s3.ca-central-1.amazonaws.com/ncc-website-2',
                'https://s3.amazonaws.com/ncc-website-2',
                'http://s3.amazonaws.com/ncc-website-2',
            ],
        ],
        'digitalocean' => [
            'region' => 'tor1',
            'bucket' => App::env('DO_S3_BUCKET') ?? 'your-staging-bucket',
            'baseUrl' => App::env('DO_S3_BASE_URL') ?? 'https://staging-medias.tor1.digitaloceanspaces.com',
            'accessKey' => App::env('DO_S3_ACCESS_KEY'),
            'secretKey' => App::env('DO_S3_SECRET_KEY'),
        ],
    ],

    // Production Environment
    'prod' => [
        'aws' => [
            'bucket' => 'ncc-website-2',
            'region' => 'ca-central-1',
            'urls' => [
                'https://ncc-website-2.s3.amazonaws.com',
                'http://ncc-website-2.s3.amazonaws.com',
                'https://s3.ca-central-1.amazonaws.com/ncc-website-2',
                'http://s3.ca-central-1.amazonaws.com/ncc-website-2',
                'https://s3.amazonaws.com/ncc-website-2',
                'http://s3.amazonaws.com/ncc-website-2',
            ],
        ],
        'digitalocean' => [
            'region' => 'tor1',
            'bucket' => App::env('DO_S3_BUCKET') ?? 'your-prod-bucket',
            'baseUrl' => App::env('DO_S3_BASE_URL') ?? 'https://medias.tor1.digitaloceanspaces.com',
            'accessKey' => App::env('DO_S3_ACCESS_KEY'),
            'secretKey' => App::env('DO_S3_SECRET_KEY'),
        ],
    ],
];

// ============================================================================
// COMMON SETTINGS (Shared across all environments)
// ============================================================================

$commonConfig = [
    // Current environment
    'environment' => $env,

    // Filesystem mappings (AWS handle => DO handle)
    'filesystemMappings' => [
        'images'           => 'images_do',
        'optimisedImages'  => 'optimisedImages_do',
        'documents'        => 'documents_do',
        'videos'           => 'videos_do',
        'formDocuments'    => 'formDocuments_do',
        'chartData'        => 'chartData_do',
    ],

    // Volume configurations
    'volumes' => [
        // Source volumes for migration
        'source' => ['images', 'optimisedImages'],

        // Target volume (consolidated location)
        'target' => 'images',

        // Quarantine volume for unused/orphaned assets
        'quarantine' => 'quarantine',

        // Root-level volumes (no subfolders)
        'rootLevel' => ['optimisedImages', 'chartData'],
    ],

    // DigitalOcean Spaces filesystem definitions
    'filesystems' => [
        [
            'handle' => 'images_do',
            'name' => 'Images (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_IMAGES',
            'hasUrls' => true,
        ],
        [
            'handle' => 'optimisedImages_do',
            'name' => 'Optimised Images (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_OPTIMISEDIMAGES',
            'hasUrls' => true,
        ],
        [
            'handle' => 'imageTransforms_do',
            'name' => 'Image Transforms (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_IMAGETRANSFORMS',
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
            'handle' => 'formDocuments_do',
            'name' => 'Form Documents (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_FORMDOCUMENTS',
            'hasUrls' => true,
        ],
        [
            'handle' => 'chartData_do',
            'name' => 'Chart Data (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_CHARTDATA',
            'hasUrls' => true,
        ],
        [
            'handle' => 'quarantine',
            'name' => 'Quarantined Assets (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_QUARANTINE',
            'hasUrls' => false,
        ],
    ],

    // Environment variable names
    'envVars' => [
        'doAccessKey' => 'DO_S3_ACCESS_KEY',
        'doSecretKey' => 'DO_S3_SECRET_KEY',
        'doBucket' => 'DO_S3_BUCKET',
        'doBaseUrl' => 'DO_S3_BASE_URL',
        'doRegion' => 'DO_S3_REGION',
    ],

    // Migration batch settings
    'migration' => [
        'batchSize' => 100,
        'checkpointEveryBatches' => 1,
        'changelogFlushEvery' => 5,
        'maxRetries' => 3,
        'checkpointRetentionHours' => 72,
        'maxRepeatedErrors' => 10,
    ],

    // Template scanning settings
    'templates' => [
        'extensions' => ['twig'],
        'backupSuffix' => '.backup-{timestamp}',
        'envVarName' => 'DO_S3_BASE_URL',
    ],

    // Database scanning settings
    'database' => [
        'contentTables' => [
            'content',
            'matrixcontent_%',
        ],
        'additionalTables' => [
            ['table' => 'projectconfig', 'column' => 'config'],
            ['table' => 'elements_sites', 'column' => 'metadata'],
            ['table' => 'revisions', 'column' => 'data'],
        ],
        'columnTypes' => ['text', 'mediumtext', 'longtext'],
    ],

    // Paths
    'paths' => [
        'templates' => '@templates',
        'storage' => '@storage',
        'logs' => '@storage/logs',
        'backups' => '@storage/backups',
    ],
];

// ============================================================================
// MERGE ENVIRONMENT-SPECIFIC WITH COMMON CONFIG
// ============================================================================

$envConfig = $environments[$env] ?? $environments['dev'];

return array_merge($commonConfig, [
    'aws' => $envConfig['aws'],
    'digitalocean' => $envConfig['digitalocean'],
]);
