<?php

namespace csabourin\craftS3SpacesMigration\models;

/**
 * Provider Capabilities
 *
 * Describes what features and limitations a storage provider has.
 * Controllers query these capabilities to optimize operations and adapt behavior.
 *
 * @package csabourin\craftS3SpacesMigration\models
 * @since 2.0.0
 */
class ProviderCapabilities
{
    // Feature Support

    /**
     * @var bool Supports object versioning
     */
    public bool $supportsVersioning = false;

    /**
     * @var bool Supports access control lists (ACLs)
     */
    public bool $supportsACLs = false;

    /**
     * @var bool Supports server-side copy between buckets/containers
     */
    public bool $supportsServerSideCopy = false;

    /**
     * @var bool Supports multipart uploads for large files
     */
    public bool $supportsMultipartUpload = false;

    /**
     * @var bool Supports custom metadata on objects
     */
    public bool $supportsMetadata = true;

    /**
     * @var bool Supports generating public URLs
     */
    public bool $supportsPublicUrls = true;

    /**
     * @var bool Supports streaming large files
     */
    public bool $supportsStreaming = true;

    /**
     * @var bool Supports multi-region buckets/containers
     */
    public bool $supportsMultiRegion = false;

    /**
     * @var bool Supports signed/presigned URLs with expiration
     */
    public bool $supportsPresignedUrls = false;

    // Size Limits

    /**
     * @var int Maximum file size in bytes (PHP_INT_MAX = no limit)
     */
    public int $maxFileSize = PHP_INT_MAX;

    /**
     * @var int Maximum part size for multipart uploads in bytes
     */
    public int $maxPartSize = 5 * 1024 * 1024 * 1024; // 5GB default

    /**
     * @var int Minimum part size for multipart uploads in bytes
     */
    public int $minPartSize = 5 * 1024 * 1024; // 5MB default

    // Performance Tuning

    /**
     * @var int Optimal batch size for bulk operations
     */
    public int $optimalBatchSize = 100;

    /**
     * @var int|null Maximum requests per second (null = no limit)
     */
    public ?int $maxRequestsPerSecond = null;

    /**
     * @var int|null Maximum concurrent connections (null = no limit)
     */
    public ?int $maxConcurrentConnections = null;

    // Metadata Support

    /**
     * @var array Supported metadata header keys
     */
    public array $supportedMetadataKeys = ['Content-Type', 'Cache-Control'];

    // Regional Capabilities

    /**
     * @var array Available regions for this provider
     */
    public array $availableRegions = [];

    /**
     * Check if a specific capability is supported
     *
     * @param string $capability Capability name (e.g., 'supportsVersioning')
     * @return bool
     */
    public function supports(string $capability): bool
    {
        if (!property_exists($this, $capability)) {
            return false;
        }

        $value = $this->$capability;

        // Handle boolean properties
        if (is_bool($value)) {
            return $value;
        }

        // Handle array properties (e.g., availableRegions)
        if (is_array($value)) {
            return !empty($value);
        }

        // Handle numeric properties
        if (is_numeric($value)) {
            return $value > 0;
        }

        return false;
    }

    /**
     * Get a human-readable summary of capabilities
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'features' => [
                'versioning' => $this->supportsVersioning,
                'acls' => $this->supportsACLs,
                'server_side_copy' => $this->supportsServerSideCopy,
                'multipart_upload' => $this->supportsMultipartUpload,
                'metadata' => $this->supportsMetadata,
                'public_urls' => $this->supportsPublicUrls,
                'streaming' => $this->supportsStreaming,
                'multi_region' => $this->supportsMultiRegion,
                'presigned_urls' => $this->supportsPresignedUrls,
            ],
            'limits' => [
                'max_file_size' => $this->formatBytes($this->maxFileSize),
                'max_part_size' => $this->formatBytes($this->maxPartSize),
                'min_part_size' => $this->formatBytes($this->minPartSize),
                'optimal_batch_size' => $this->optimalBatchSize,
                'max_requests_per_second' => $this->maxRequestsPerSecond ?? 'unlimited',
                'max_concurrent_connections' => $this->maxConcurrentConnections ?? 'unlimited',
            ],
            'metadata' => [
                'supported_keys' => $this->supportedMetadataKeys,
            ],
            'regions' => [
                'available' => $this->availableRegions,
            ],
        ];
    }

    /**
     * Format bytes to human-readable string
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === PHP_INT_MAX) {
            return 'unlimited';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }
}
