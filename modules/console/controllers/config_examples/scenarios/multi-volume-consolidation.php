<?php
/**
 * SCENARIO: Multi-Volume Consolidation
 *
 * USE CASE:
 * - Multiple source volumes (images, documents, videos)
 * - Consolidate everything into ONE target volume
 * - Each volume in its own subfolder
 *
 * PERFECT FOR:
 * - Simplifying volume management
 * - Reducing S3 bucket complexity
 * - Centralizing asset storage
 *
 * EXAMPLE:
 * Before:
 *   - images → s3://bucket-aws/images/
 *   - documents → s3://bucket-aws/documents/
 *   - videos → s3://bucket-aws/videos/
 * After:
 *   - all assets → s3://bucket-do/assets/ (with subfolders preserved)
 *
 * HOW IT WORKS:
 * 1. All source volumes migrate to single target volume
 * 2. Folder structure preserved within target
 * 3. Duplicate assets resolved by usage count
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
        // Multiple source volumes
        'source' => ['images', 'documents', 'videos'],

        // Single target volume
        'target' => 'assets',

        // Quarantine
        'quarantine' => 'quarantine',

        // All volumes in subfolders
        'atBucketRoot' => [],
        'withSubfolders' => ['images', 'documents', 'videos', 'assets'],
        'flatStructure' => [],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // VOLUME MAPPINGS
    // ═══════════════════════════════════════════════════════════════════════
    'volumeMappings' => [
        'images' => 'assets_do',
        'documents' => 'assets_do',
        'videos' => 'assets_do',
        'assets' => 'assets_do',
        'quarantine' => 'quarantine_do',
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // FILESYSTEM DEFINITIONS
    // ═══════════════════════════════════════════════════════════════════════
    'filesystemDefinitions' => [
        [
            'handle' => 'assets_do',
            'name' => 'Assets (DO Spaces)',
            'subfolder' => '$DO_S3_SUBFOLDER_ASSETS',
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
