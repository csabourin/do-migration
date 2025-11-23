<?php

namespace csabourin\craftS3SpacesMigration\adapters;

use csabourin\craftS3SpacesMigration\models\ProviderCapabilities;

/**
 * Wasabi Storage Adapter
 *
 * Wasabi is S3-compatible, so this extends S3StorageAdapter
 * with Wasabi-specific configuration and capability overrides.
 *
 * Installation:
 *   No additional packages needed - uses Craft's built-in S3 support
 *
 * Configuration:
 *   - bucket: Wasabi bucket name
 *   - region: Wasabi region (e.g., 'us-east-1', 'eu-central-1')
 *   - accessKey: Wasabi access key
 *   - secretKey: Wasabi secret key
 *   - endpoint: Wasabi endpoint URL
 *
 * @package csabourin\craftS3SpacesMigration\adapters
 * @since 2.0.0
 */
class WasabiStorageAdapter extends S3StorageAdapter
{
    /**
     * Constructor
     *
     * @param array $config Configuration array
     * @throws \InvalidArgumentException If required config missing
     */
    public function __construct(array $config)
    {
        $region = $config['region'] ?? 'us-east-1';

        // Set Wasabi endpoint if not provided
        if (empty($config['endpoint'])) {
            // Wasabi uses s3.{region}.wasabisys.com format
            $config['endpoint'] = "https://s3.{$region}.wasabisys.com";
        }

        // Set Wasabi base URL if not provided
        if (empty($config['baseUrl'])) {
            $bucket = $config['bucket'] ?? '';
            $config['baseUrl'] = "https://{$bucket}.s3.{$region}.wasabisys.com";

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
        return 'wasabi';
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities(): ProviderCapabilities
    {
        // Start with S3 capabilities
        $caps = parent::getCapabilities();

        // Override Wasabi-specific differences
        $caps->supportsVersioning = false; // Wasabi doesn't support versioning
        $caps->supportsPresignedUrls = true;

        // Wasabi regions
        $caps->availableRegions = [
            'us-east-1',    // Virginia
            'us-east-2',    // N. Virginia
            'us-central-1', // Texas
            'us-west-1',    // Oregon
            'eu-central-1', // Amsterdam
            'eu-west-1',    // London
            'eu-west-2',    // Paris
            'ap-northeast-1', // Tokyo
            'ap-northeast-2', // Osaka
            'ap-southeast-1', // Singapore
            'ap-southeast-2', // Sydney
        ];

        // Wasabi file size limits
        $caps->maxFileSize = 5 * 1024 * 1024 * 1024 * 1024; // 5TB
        $caps->maxPartSize = 5 * 1024 * 1024 * 1024; // 5GB

        return $caps;
    }
}
