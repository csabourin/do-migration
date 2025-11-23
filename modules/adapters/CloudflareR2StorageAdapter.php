<?php

namespace csabourin\craftS3SpacesMigration\adapters;

use csabourin\craftS3SpacesMigration\models\ProviderCapabilities;

/**
 * Cloudflare R2 Storage Adapter
 *
 * Cloudflare R2 is S3-compatible, so this extends S3StorageAdapter
 * with R2-specific configuration and capability overrides.
 *
 * Installation:
 *   No additional packages needed - uses Craft's built-in S3 support
 *
 * Configuration:
 *   - bucket: R2 bucket name
 *   - accountId: Cloudflare account ID
 *   - accessKey: R2 access key ID
 *   - secretKey: R2 secret access key
 *   - endpoint: R2 endpoint URL (auto-generated from accountId if not provided)
 *
 * @package csabourin\craftS3SpacesMigration\adapters
 * @since 2.0.0
 */
class CloudflareR2StorageAdapter extends S3StorageAdapter
{
    /**
     * Constructor
     *
     * @param array $config Configuration array
     * @throws \InvalidArgumentException If required config missing
     */
    public function __construct(array $config)
    {
        // Validate R2-specific requirements
        if (empty($config['accountId']) && empty($config['endpoint'])) {
            throw new \InvalidArgumentException(
                "Either 'accountId' or 'endpoint' must be provided for Cloudflare R2"
            );
        }

        // Set R2 endpoint if not provided
        if (empty($config['endpoint']) && !empty($config['accountId'])) {
            // R2 uses {accountId}.r2.cloudflarestorage.com format
            $config['endpoint'] = "https://{$config['accountId']}.r2.cloudflarestorage.com";
        }

        // Set R2 base URL if not provided
        if (empty($config['baseUrl'])) {
            $bucket = $config['bucket'] ?? '';
            $accountId = $config['accountId'] ?? 'account';

            // R2 public URL format (if custom domain not configured)
            $config['baseUrl'] = "https://{$bucket}.{$accountId}.r2.cloudflarestorage.com";

            if (!empty($config['subfolder'])) {
                $config['baseUrl'] .= '/' . trim($config['subfolder'], '/');
            }
        }

        // R2 doesn't use traditional regions
        if (!isset($config['region'])) {
            $config['region'] = 'auto';
        }

        // Call parent constructor
        parent::__construct($config);
    }

    /**
     * @inheritDoc
     */
    public function getProviderName(): string
    {
        return 'cloudflare-r2';
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities(): ProviderCapabilities
    {
        // Start with S3 capabilities
        $caps = parent::getCapabilities();

        // Override R2-specific differences
        $caps->supportsVersioning = false; // R2 doesn't support versioning yet
        $caps->supportsACLs = false; // R2 uses different authorization model
        $caps->supportsPresignedUrls = true;
        $caps->supportsMultiRegion = true; // R2 is globally distributed

        // R2 doesn't use traditional regions - it's automatically distributed
        $caps->availableRegions = ['auto']; // Cloudflare's global network

        // R2 file size limits (as of 2024)
        $caps->maxFileSize = 5 * 1024 * 1024 * 1024 * 1024; // 5TB
        $caps->maxPartSize = 5 * 1024 * 1024 * 1024; // 5GB
        $caps->minPartSize = 5 * 1024 * 1024; // 5MB

        // R2 has no egress fees - highlight this as a feature
        $caps->supportedMetadataKeys = [
            'Content-Type',
            'Cache-Control',
            'Content-Disposition',
            'Content-Encoding',
            'Content-Language',
            'x-amz-meta-*', // Custom metadata
        ];

        return $caps;
    }
}
