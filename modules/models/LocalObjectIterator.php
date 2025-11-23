<?php

namespace csabourin\spaghettiMigrator\models;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * Local Object Iterator
 *
 * Iterates through objects in a local filesystem using Flysystem.
 *
 * @package csabourin\spaghettiMigrator\models
 * @since 2.0.0
 */
class LocalObjectIterator extends ObjectIterator
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

        // Local files don't have cloud-specific metadata like ETag or storage class
        // But we can use file modification time hash as a pseudo-ETag
        $metadata->etag = md5($path . $lastModified);

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
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'avi' => 'video/x-msvideo',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'txt' => 'text/plain',
            'html' => 'text/html',
            'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
