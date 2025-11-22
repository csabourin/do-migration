<?php
/**
 * SCENARIO: Simple One-to-One Migration
 *
 * USE CASE:
 * - Single volume migration
 * - Same structure on source and target
 * - Straightforward migration with no consolidation
 *
 * PERFECT FOR:
 * - Testing the migration process
 * - Simple Craft setups with one volume
 * - Learning how the migration works
 *
 * EXAMPLE:
 * Before: images → s3://bucket-aws/images/
 * After:  images → s3://bucket-do/images/
 *
 * HOW IT WORKS:
 * 1. Direct migration from AWS to DigitalOcean
 * 2. Same folder structure maintained
 * 3. Minimal configuration required
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
        'source' => ['images'],
        'target' => 'images',
        'quarantine' => 'quarantine',

        'atBucketRoot' => [],
        'withSubfolders' => ['images'],
        'flatStructure' => [],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // VOLUME MAPPINGS
    // ═══════════════════════════════════════════════════════════════════════
    'volumeMappings' => [
        'images' => 'images_do',
        'quarantine' => 'quarantine_do',
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // FILESYSTEM DEFINITIONS
    // ═══════════════════════════════════════════════════════════════════════
    'filesystemDefinitions' => [
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
