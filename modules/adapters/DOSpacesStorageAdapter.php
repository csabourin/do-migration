<?php

namespace csabourin\craftS3SpacesMigration\adapters;

use csabourin\craftS3SpacesMigration\models\ProviderCapabilities;

/**
 * DigitalOcean Spaces Storage Adapter
 *
 * DigitalOcean Spaces is S3-compatible, so this extends S3StorageAdapter
 * with DO-specific configuration and capability overrides.
 *
 * @package csabourin\craftS3SpacesMigration\adapters
 * @since 2.0.0
 */
class DOSpacesStorageAdapter extends S3StorageAdapter
{
    /**
     * Constructor
     *
     * @param array $config Configuration array:
     *   - bucket: string (required) - Spaces bucket name
     *   - region: string (required) - DO region (nyc3, sfo2, ams3, sgp1, fra1, sfo3)
     *   - accessKey: string (required) - DO Spaces access key
     *   - secretKey: string (required) - DO Spaces secret key
     *   - baseUrl: string (optional) - Base URL for public access
     *   - subfolder: string (optional) - Subfolder within bucket
     * @throws \InvalidArgumentException If required config missing
     */
    public function __construct(array $config)
    {
        // Set DO Spaces endpoint if not provided
        if (empty($config['endpoint'])) {
            $region = $config['region'] ?? 'nyc3';
            $config['endpoint'] = "https://{$region}.digitaloceanspaces.com";
        }

        // Set DO Spaces base URL if not provided
        if (empty($config['baseUrl'])) {
            $region = $config['region'] ?? 'nyc3';
            $bucket = $config['bucket'] ?? '';
            $config['baseUrl'] = "https://{$bucket}.{$region}.digitaloceanspaces.com";

            if (!empty($config['subfolder'])) {
                $config['baseUrl'] .= '/' . trim($config['subfolder'], '/');
            }
        }

        // Call parent constructor which handles the rest
        parent::__construct($config);
    }

    /**
     * @inheritDoc
     */
    public function getProviderName(): string
    {
        return 'do-spaces';
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities(): ProviderCapabilities
    {
        // Start with S3 capabilities
        $caps = parent::getCapabilities();

        // Override DO Spaces-specific differences
        $caps->supportsVersioning = false; // DO Spaces doesn't support versioning yet
        $caps->supportsPresignedUrls = true; // DO Spaces supports presigned URLs via S3 API

        // DO Spaces specific regions
        $caps->availableRegions = [
            'nyc3',  // New York 3
            'sfo2',  // San Francisco 2
            'sfo3',  // San Francisco 3
            'ams3',  // Amsterdam 3
            'sgp1',  // Singapore 1
            'fra1',  // Frankfurt 1
        ];

        // DO Spaces has same file size limits as S3
        $caps->maxFileSize = 5 * 1024 * 1024 * 1024 * 1024; // 5TB
        $caps->maxPartSize = 5 * 1024 * 1024 * 1024; // 5GB

        return $caps;
    }
}
