<?php

namespace csabourin\craftS3SpacesMigration\models;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * S3 Object Iterator
 *
 * Iterates through objects in an S3 bucket using Flysystem.
 *
 * @package csabourin\craftS3SpacesMigration\models
 * @since 2.0.0
 */
class S3ObjectIterator extends ObjectIterator
{
    /**
     * @var FilesystemOperator Flysystem adapter
     */
    private FilesystemOperator $adapter;

    /**
     * @var \Iterator|null Flysystem listing iterator
     */
    private ?\Iterator $listing = null;

    /**
     * Constructor
     *
     * @param FilesystemOperator $adapter
     * @param string $prefix
     * @param array $options
     */
    public function __construct(FilesystemOperator $adapter, string $prefix = '', array $options = [])
    {
        parent::__construct($prefix, $options);
        $this->adapter = $adapter;
    }

    /**
     * @inheritDoc
     */
    protected function fetchNextBatch(): void
    {
        if ($this->complete) {
            return;
        }

        // Initialize listing on first call
        if ($this->listing === null) {
            $recursive = $this->options['recursive'] ?? true;
            $this->listing = $this->adapter->listContents($this->prefix, $recursive)->getIterator();
        }

        // Fetch next batch
        $this->objects = [];
        $batchSize = $this->options['maxKeys'] ?? 1000;

        while ($this->listing->valid() && count($this->objects) < $batchSize) {
            /** @var StorageAttributes $item */
            $item = $this->listing->current();

            // Skip directories, we only want files
            if ($item->isFile()) {
                $this->objects[] = $this->convertToObjectMetadata($item);
            }

            $this->listing->next();
        }

        // Mark complete if no more items
        if (!$this->listing->valid()) {
            $this->complete = true;
        }
    }

    /**
     * Convert Flysystem StorageAttributes to ObjectMetadata
     *
     * @param StorageAttributes $item
     * @return ObjectMetadata
     */
    private function convertToObjectMetadata(StorageAttributes $item): ObjectMetadata
    {
        $path = $item->path();
        $size = $item->fileSize() ?? 0;
        $lastModified = $item->lastModified() ?? time();

        $metadata = new ObjectMetadata(
            $path,
            $size,
            new \DateTime('@' . $lastModified)
        );

        // Try to get MIME type if available
        if (method_exists($item, 'mimeType')) {
            $metadata->contentType = $item->mimeType();
        } else {
            // Infer from extension
            $metadata->contentType = $this->getMimeTypeFromExtension($path);
        }

        // Try to get additional attributes if available
        if ($item->extraMetadata()) {
            $extra = $item->extraMetadata();

            if (isset($extra['ETag'])) {
                $metadata->etag = trim($extra['ETag'], '"');
            }

            if (isset($extra['ContentType'])) {
                $metadata->contentType = $extra['ContentType'];
            }

            if (isset($extra['CacheControl'])) {
                $metadata->cacheControl = $extra['CacheControl'];
            }

            if (isset($extra['StorageClass'])) {
                $metadata->storageClass = $extra['StorageClass'];
            }
        }

        return $metadata;
    }

    /**
     * Get MIME type from file extension
     *
     * @param string $path
     * @return string|null
     */
    private function getMimeTypeFromExtension(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            'txt' => 'text/plain',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
        ];

        return $mimeTypes[$extension] ?? null;
    }
}
