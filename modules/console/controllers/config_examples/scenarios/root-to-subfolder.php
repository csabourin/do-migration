<?php
/**
 * SCENARIO: Volume at Bucket Root → Subfolder Consolidation
 *
 * USE CASE:
 * - Source volume exists at bucket ROOT (no subfolder)
 * - Target volume is in a SUBFOLDER
 * - Prevents double indexing and transform loops using targetSubfolder mechanism
 *
 * PERFECT FOR:
 * - Legacy Craft setups with root-level volumes (e.g., optimisedImages)
 * - Consolidating multiple volumes into organized subfolders
 * - Migrating from flat structure to hierarchical organization
 *
 * EXAMPLE:
 * Before: optimisedImages → s3://bucket/ (root)
 * After:  images → s3://bucket/images/ (subfolder)
 *
 * HOW IT WORKS:
 * 1. optimisedImages filesystem starts with subfolder='' (root)
 * 2. Files migrated using temp file approach (prevents nesting issues)
 * 3. After migration, filesystem updated to targetSubfolder
 * 4. This prevents Craft from re-indexing assets twice
 */

return [
    // ═══════════════════════════════════════════════════════════════════════
    // CREDENTIALS (Required)
    // ═══════════════════════════════════════════════════════════════════════
    'credentials' => [
        'aws' => [
            'key' => '$S3_KEY_AWS',
            'secret' => '$S3_SECRET_AWS',
            'region' => '$S3_REGION_AWS',
            'bucket' => '$S3_BUCKET_AWS',
        ],
        'digitalocean' => [
            'key' => '$DO_SPACES_KEY',
            'secret' => '$DO_SPACES_SECRET',
            'region' => '$DO_SPACES_REGION',
            'endpoint' => '$DO_SPACES_ENDPOINT',
            'bucket' => '$DO_SPACES_BUCKET',
        ],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // VOLUME CONFIGURATION
    // ═══════════════════════════════════════════════════════════════════════
    'volumes' => [
        // Source: Volume at bucket root
        'source' => ['optimisedImages'],

        // Target: Consolidated volume in subfolder
        'target' => 'images',

        // Quarantine for unused files
        'quarantine' => 'quarantine',

        // IMPORTANT: Mark root-level volumes
        'atBucketRoot' => ['optimisedImages'],

        // Volumes with subfolders
        'withSubfolders' => ['images'],

        // Flat structure volumes
        'flatStructure' => [],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // VOLUME MAPPINGS (How volumes map between AWS and DO)
    // ═══════════════════════════════════════════════════════════════════════
    'volumeMappings' => [
        'optimisedImages' => 'optimisedImages_do',
        'images' => 'images_do',
        'quarantine' => 'quarantine_do',
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // FILESYSTEM DEFINITIONS (Auto-created in Craft)
    // ═══════════════════════════════════════════════════════════════════════
    'filesystemDefinitions' => [
        [
            'handle' => 'optimisedImages_do',
            'name' => 'Optimised Images (DO Spaces)',
            'subfolder' => '',  // Start at root during migration
            'targetSubfolder' => '$DO_S3_SUBFOLDER_IMAGES',  // Switch after migration
            'hasUrls' => true,
        ],
        [
            'handle' => 'images_do',
            'name' => 'Images (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_IMAGES',
            'hasUrls' => true,
        ],
        [
            'handle' => 'quarantine_do',
            'name' => 'Quarantine (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_QUARANTINE',
            'hasUrls' => false,
        ],
    ],
];
