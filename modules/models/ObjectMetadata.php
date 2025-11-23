<?php

namespace csabourin\craftS3SpacesMigration\models;

/**
 * Object Metadata
 *
 * Represents metadata about a storage object without loading its content.
 *
 * @package csabourin\craftS3SpacesMigration\models
 * @since 2.0.0
 */
class ObjectMetadata
{
    /**
     * @var string Object path/key
     */
    public string $path;

    /**
     * @var int Size in bytes
     */
    public int $size;

    /**
     * @var \DateTime Last modified timestamp
     */
    public \DateTime $lastModified;

    /**
     * @var string|null ETag or content hash
     */
    public ?string $etag = null;

    /**
     * @var string|null Content type (MIME type)
     */
    public ?string $contentType = null;

    /**
     * @var string|null Cache-Control header
     */
    public ?string $cacheControl = null;

    /**
     * @var string|null Content encoding (e.g., 'gzip')
     */
    public ?string $contentEncoding = null;

    /**
     * @var array Custom metadata key-value pairs
     */
    public array $metadata = [];

    /**
     * @var string|null Storage class (e.g., 'STANDARD', 'GLACIER')
     */
    public ?string $storageClass = null;

    /**
     * @var string|null ACL (e.g., 'public-read', 'private')
     */
    public ?string $acl = null;

    /**
     * Constructor
     *
     * @param string $path
     * @param int $size
     * @param \DateTime $lastModified
     */
    public function __construct(string $path, int $size, \DateTime $lastModified)
    {
        $this->path = $path;
        $this->size = $size;
        $this->lastModified = $lastModified;
    }

    /**
     * Get human-readable file size
     *
     * @return string
     */
    public function getFormattedSize(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Get extension from path
     *
     * @return string|null
     */
    public function getExtension(): ?string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION) ?: null;
    }

    /**
     * Get filename from path
     *
     * @return string
     */
    public function getFilename(): string
    {
        return pathinfo($this->path, PATHINFO_BASENAME);
    }

    /**
     * Get directory from path
     *
     * @return string
     */
    public function getDirectory(): string
    {
        $dir = pathinfo($this->path, PATHINFO_DIRNAME);
        return $dir === '.' ? '' : $dir;
    }

    /**
     * Check if this is an image based on content type or extension
     *
     * @return bool
     */
    public function isImage(): bool
    {
        // Check content type first
        if ($this->contentType && str_starts_with($this->contentType, 'image/')) {
            return true;
        }

        // Fall back to extension
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
        return in_array(strtolower($this->getExtension() ?? ''), $imageExtensions);
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'size' => $this->size,
            'size_formatted' => $this->getFormattedSize(),
            'last_modified' => $this->lastModified->format('Y-m-d H:i:s'),
            'etag' => $this->etag,
            'content_type' => $this->contentType,
            'cache_control' => $this->cacheControl,
            'content_encoding' => $this->contentEncoding,
            'metadata' => $this->metadata,
            'storage_class' => $this->storageClass,
            'acl' => $this->acl,
            'extension' => $this->getExtension(),
            'filename' => $this->getFilename(),
            'directory' => $this->getDirectory(),
            'is_image' => $this->isImage(),
        ];
    }
}
