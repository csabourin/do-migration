<?php

namespace csabourin\craftS3SpacesMigration\interfaces;

use csabourin\craftS3SpacesMigration\models\ProviderCapabilities;
use csabourin\craftS3SpacesMigration\models\ConnectionTestResult;
use csabourin\craftS3SpacesMigration\models\ObjectMetadata;
use csabourin\craftS3SpacesMigration\models\ObjectIterator;

/**
 * Storage Provider Interface
 *
 * Defines the contract for all storage provider adapters (S3, GCS, Azure, DO Spaces, Local FS, etc.)
 * This abstraction allows the migration system to work with any storage backend.
 *
 * @package csabourin\craftS3SpacesMigration\interfaces
 * @since 2.0.0
 */
interface StorageProviderInterface
{
    /**
     * Get provider identifier
     *
     * @return string Unique identifier (e.g., 's3', 'do-spaces', 'gcs', 'azure-blob', 'local')
     */
    public function getProviderName(): string;

    /**
     * Get provider capabilities
     *
     * Returns a capability object describing what features this provider supports.
     * Controllers use this to optimize operations and adapt to provider limitations.
     *
     * @return ProviderCapabilities
     */
    public function getCapabilities(): ProviderCapabilities;

    /**
     * Test connection and credentials
     *
     * Verifies that the provider is accessible and credentials are valid.
     * Used in pre-flight checks before starting migrations.
     *
     * @return ConnectionTestResult
     */
    public function testConnection(): ConnectionTestResult;

    /**
     * List objects in a path
     *
     * Returns an iterator for all objects under the given path.
     * Supports pagination automatically for large result sets.
     *
     * @param string $path Path prefix to list (empty string for root)
     * @param array $options Optional parameters:
     *   - recursive: bool - Include subdirectories (default: true)
     *   - maxKeys: int - Maximum results per page (default: 1000)
     *   - startAfter: string - Pagination cursor
     * @return ObjectIterator Iterator of objects
     */
    public function listObjects(string $path = '', array $options = []): ObjectIterator;

    /**
     * Read object content
     *
     * @param string $path Object path/key
     * @return string|resource Content as string or stream resource
     * @throws \Exception If object doesn't exist or read fails
     */
    public function readObject(string $path): string|resource;

    /**
     * Write object content
     *
     * @param string $path Object path/key
     * @param string|resource $content Content to write
     * @param array $metadata Optional metadata:
     *   - contentType: string - MIME type
     *   - cacheControl: string - Cache-Control header
     *   - acl: string - Access control (public-read, private, etc.)
     *   - metadata: array - Custom key-value metadata
     * @return bool Success
     * @throws \Exception If write fails
     */
    public function writeObject(string $path, string|resource $content, array $metadata = []): bool;

    /**
     * Copy object from this provider to another provider
     *
     * Handles both server-to-server copy (when supported) and streamed copy.
     * Controllers should use this instead of read+write for efficiency.
     *
     * @param string $sourcePath Source object path
     * @param StorageProviderInterface $targetProvider Target provider
     * @param string $targetPath Target object path
     * @return bool Success
     * @throws \Exception If copy fails
     */
    public function copyObject(string $sourcePath, StorageProviderInterface $targetProvider, string $targetPath): bool;

    /**
     * Delete object
     *
     * @param string $path Object path/key
     * @return bool Success (returns true even if object doesn't exist - idempotent)
     * @throws \Exception If deletion fails for reasons other than "not found"
     */
    public function deleteObject(string $path): bool;

    /**
     * Get object metadata
     *
     * Retrieves metadata without downloading the full object.
     *
     * @param string $path Object path/key
     * @return ObjectMetadata
     * @throws \Exception If object doesn't exist or metadata fetch fails
     */
    public function getObjectMetadata(string $path): ObjectMetadata;

    /**
     * Generate public URL for object
     *
     * @param string $path Object path/key
     * @return string Public URL
     */
    public function getPublicUrl(string $path): string;

    /**
     * Check if object exists
     *
     * More efficient than catching exceptions from getObjectMetadata()
     *
     * @param string $path Object path/key
     * @return bool True if exists
     */
    public function objectExists(string $path): bool;

    /**
     * Get base URL pattern for URL replacement
     *
     * Returns a template pattern where {path} is replaced with object path.
     * Example: "https://bucket.s3.amazonaws.com/{path}"
     *
     * @return string URL pattern with {path} placeholder
     */
    public function getUrlPattern(): string;

    /**
     * Get storage region/location
     *
     * @return string|null Region identifier (e.g., 'us-east-1', 'nyc3') or null if not applicable
     */
    public function getRegion(): ?string;

    /**
     * Get bucket/container name
     *
     * @return string Bucket, container, or root path name
     */
    public function getBucket(): string;
}
