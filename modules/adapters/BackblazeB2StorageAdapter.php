<?php

namespace csabourin\craftS3SpacesMigration\adapters;

use csabourin\craftS3SpacesMigration\models\ProviderCapabilities;

/**
 * Backblaze B2 Storage Adapter
 *
 * Backblaze B2 is S3-compatible, so this extends S3StorageAdapter
 * with B2-specific configuration and capability overrides.
 *
 * Installation:
 *   No additional packages needed - uses Craft's built-in S3 support
 *
 * Configuration:
 *   - bucket: B2 bucket name
 *   - region: B2 region (e.g., 'us-west-002')
 *   - accessKey: B2 application key ID
 *   - secretKey: B2 application key
 *   - endpoint: B2 endpoint URL
 *
 * @package csabourin\craftS3SpacesMigration\adapters
 * @since 2.0.0
 */
class BackblazeB2StorageAdapter extends S3StorageAdapter
{
    /**
     * Constructor
     *
     * @param array $config Configuration array
     * @throws \InvalidArgumentException If required config missing
     */
    public function __construct(array $config)
    {
        // Set B2 endpoint if not provided
        if (empty($config['endpoint'])) {
            $region = $config['region'] ?? 'us-west-002';
            // B2 uses s3.{region}.backblazeb2.com format
            $config['endpoint'] = "https://s3.{$region}.backblazeb2.com";
        }

        // Set B2 base URL if not provided
        if (empty($config['baseUrl'])) {
            $bucket = $config['bucket'] ?? '';
            $region = $config['region'] ?? 'us-west-002';
            $config['baseUrl'] = "https://{$bucket}.s3.{$region}.backblazeb2.com";

            if (!empty($config['subfolder'])) {
                $config['baseUrl'] .= '/' . trim($config['subfolder'], '/');
            }
        }

        // Call parent constructor
        parent::__construct($config);
    }

    /**
     * @inheritDoc
     */
    public function getProviderName(): string
    {
        return 'backblaze-b2';
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities(): ProviderCapabilities
    {
        // Start with S3 capabilities
        $caps = parent::getCapabilities();

        // Override B2-specific differences
        $caps->supportsVersioning = false; // B2 has different versioning model
        $caps->supportsACLs = false; // B2 uses different authorization model
        $caps->supportsPresignedUrls = true; // B2 supports presigned URLs

        // B2 regions
        $caps->availableRegions = [
            'us-west-001',
            'us-west-002',
            'us-west-004',
            'eu-central-003',
        ];

        // B2 file size limits (as of 2024)
        $caps->maxFileSize = 10 * 1024 * 1024 * 1024 * 1024; // 10TB
        $caps->maxPartSize = 5 * 1024 * 1024 * 1024; // 5GB
        $caps->minPartSize = 5 * 1024 * 1024; // 5MB

        return $caps;
    }
}
