<?php

namespace csabourin\craftS3SpacesMigration\adapters;

use Craft;
use craft\fs\Local as LocalFilesystem;
use League\Flysystem\FilesystemOperator;
use csabourin\craftS3SpacesMigration\interfaces\StorageProviderInterface;
use csabourin\craftS3SpacesMigration\models\ProviderCapabilities;
use csabourin\craftS3SpacesMigration\models\ConnectionTestResult;
use csabourin\craftS3SpacesMigration\models\ObjectMetadata;
use csabourin\craftS3SpacesMigration\models\ObjectIterator;
use csabourin\craftS3SpacesMigration\models\LocalObjectIterator;

/**
 * Local Filesystem Storage Adapter
 *
 * Provides access to local filesystem storage.
 * Useful for:
 * - Filesystem reorganization (untangling nested folders)
 * - Backing up cloud assets locally
 * - Hybrid cloud setups
 * - Testing migrations
 *
 * @package csabourin\craftS3SpacesMigration\adapters
 * @since 2.0.0
 */
class LocalFilesystemAdapter implements StorageProviderInterface
{
    /**
     * @var LocalFilesystem Craft local filesystem
     */
    protected LocalFilesystem $filesystem;

    /**
     * @var FilesystemOperator Flysystem adapter
     */
    protected FilesystemOperator $adapter;

    /**
     * @var string Base path on filesystem
     */
    protected string $basePath;

    /**
     * @var string URL prefix for public access (if applicable)
     */
    protected ?string $baseUrl = null;

    /**
     * Constructor
     *
     * @param array $config Configuration array:
     *   - basePath: string (required) - Absolute path to directory
     *   - baseUrl: string (optional) - URL prefix for public access
     *   - visibility: string (optional) - 'public' or 'private' (default: 'public')
     * @throws \InvalidArgumentException If required config missing or path invalid
     */
    public function __construct(array $config)
    {
        // Validate required config
        if (empty($config['basePath'])) {
            throw new \InvalidArgumentException("Missing required config: basePath");
        }

        $this->basePath = rtrim($config['basePath'], '/');
        $this->baseUrl = $config['baseUrl'] ?? null;

        // Ensure directory exists
        if (!is_dir($this->basePath)) {
            if (!mkdir($this->basePath, 0755, true)) {
                throw new \InvalidArgumentException("Cannot create directory: {$this->basePath}");
            }
        }

        // Ensure directory is writable
        if (!is_writable($this->basePath)) {
            throw new \InvalidArgumentException("Directory is not writable: {$this->basePath}");
        }

        // Create Craft local filesystem
        $this->filesystem = new LocalFilesystem([
            'path' => $this->basePath,
            'visibility' => $config['visibility'] ?? 'public',
        ]);

        // Get Flysystem adapter
        $this->adapter = $this->filesystem->getAdapter();
    }

    /**
     * @inheritDoc
     */
    public function getProviderName(): string
    {
        return 'local';
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities(): ProviderCapabilities
    {
        $caps = new ProviderCapabilities();

        $caps->supportsVersioning = false;
        $caps->supportsACLs = false;
        $caps->supportsServerSideCopy = true; // Filesystem copy/rename
        $caps->supportsMultipartUpload = false;
        $caps->supportsMetadata = false; // Limited metadata on local FS
        $caps->supportsPublicUrls = ($this->baseUrl !== null);
        $caps->supportsStreaming = true;
        $caps->supportsMultiRegion = false;
        $caps->supportsPresignedUrls = false;

        $caps->maxFileSize = PHP_INT_MAX; // Limited by disk space
        $caps->optimalBatchSize = 500; // Local ops are fast

        $caps->supportedMetadataKeys = ['Content-Type'];

        return $caps;
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): ConnectionTestResult
    {
        $startTime = microtime(true);

        try {
            // Check if directory exists and is writable
            if (!is_dir($this->basePath)) {
                throw new \RuntimeException("Directory does not exist: {$this->basePath}");
            }

            if (!is_writable($this->basePath)) {
                throw new \RuntimeException("Directory is not writable: {$this->basePath}");
            }

            // Try to list contents
            $this->adapter->listContents('', false)->toArray();

            $responseTime = microtime(true) - $startTime;

            $result = ConnectionTestResult::success(
                "Local filesystem accessible: {$this->basePath}",
                [
                    'base_path' => $this->basePath,
                    'readable' => is_readable($this->basePath),
                    'writable' => is_writable($this->basePath),
                    'disk_free' => $this->formatBytes(disk_free_space($this->basePath)),
                    'disk_total' => $this->formatBytes(disk_total_space($this->basePath)),
                ]
            );
            $result->responseTime = $responseTime;

            return $result;

        } catch (\Exception $e) {
            $responseTime = microtime(true) - $startTime;

            $result = ConnectionTestResult::failure(
                "Local filesystem error: {$e->getMessage()}",
                $e,
                [
                    'base_path' => $this->basePath,
                ]
            );
            $result->responseTime = $responseTime;

            return $result;
        }
    }

    /**
     * @inheritDoc
     */
    public function listObjects(string $path = '', array $options = []): ObjectIterator
    {
        return new LocalObjectIterator($this->adapter, $path, $options);
    }

    /**
     * @inheritDoc
     */
    public function readObject(string $path): string|resource
    {
        try {
            return $this->adapter->read($path);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to read file '{$path}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function writeObject(string $path, string|resource $content, array $metadata = []): bool
    {
        try {
            // Ensure parent directory exists
            $directory = dirname($path);
            if ($directory !== '.' && $directory !== '' && !$this->adapter->directoryExists($directory)) {
                $this->adapter->createDirectory($directory);
            }

            if (is_resource($content)) {
                $this->adapter->writeStream($path, $content);
            } else {
                $this->adapter->write($path, $content);
            }

            return true;

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to write file '{$path}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function copyObject(string $sourcePath, StorageProviderInterface $targetProvider, string $targetPath): bool
    {
        try {
            // If target is also local, we can use filesystem copy (much faster)
            if ($targetProvider instanceof self) {
                $sourceFullPath = $this->basePath . '/' . ltrim($sourcePath, '/');
                $targetFullPath = $targetProvider->basePath . '/' . ltrim($targetPath, '/');

                // Ensure target directory exists
                $targetDir = dirname($targetFullPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                return copy($sourceFullPath, $targetFullPath);
            }

            // Otherwise use stream copy
            $stream = $this->adapter->readStream($sourcePath);
            $targetProvider->writeObject($targetPath, $stream, [
                'contentType' => $this->getObjectMetadata($sourcePath)->contentType,
            ]);

            if (is_resource($stream)) {
                fclose($stream);
            }

            return true;

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to copy file '{$sourcePath}' to '{$targetPath}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteObject(string $path): bool
    {
        try {
            if ($this->objectExists($path)) {
                $this->adapter->delete($path);
            }
            return true;

        } catch (\Exception $e) {
            // Log but don't throw - deletion is idempotent
            Craft::warning("Failed to delete file '{$path}': {$e->getMessage()}", 'spaghetti-migrator');
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getObjectMetadata(string $path): ObjectMetadata
    {
        try {
            $fullPath = $this->basePath . '/' . ltrim($path, '/');

            if (!file_exists($fullPath)) {
                throw new \RuntimeException("File does not exist: {$path}");
            }

            $size = filesize($fullPath);
            $lastModified = filemtime($fullPath);

            $metadata = new ObjectMetadata(
                $path,
                $size,
                new \DateTime('@' . $lastModified)
            );

            // Get MIME type
            $metadata->contentType = $this->getMimeType($fullPath);

            return $metadata;

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to get metadata for file '{$path}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getPublicUrl(string $path): string
    {
        if ($this->baseUrl !== null) {
            return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        }

        // Return file:// URL as fallback
        return 'file://' . $this->basePath . '/' . ltrim($path, '/');
    }

    /**
     * @inheritDoc
     */
    public function objectExists(string $path): bool
    {
        try {
            return $this->adapter->fileExists($path);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getUrlPattern(): string
    {
        if ($this->baseUrl !== null) {
            return rtrim($this->baseUrl, '/') . '/{path}';
        }

        return 'file://' . $this->basePath . '/{path}';
    }

    /**
     * @inheritDoc
     */
    public function getRegion(): ?string
    {
        return null; // Not applicable for local filesystem
    }

    /**
     * @inheritDoc
     */
    public function getBucket(): string
    {
        return $this->basePath;
    }

    /**
     * Get MIME type of a file
     *
     * @param string $fullPath
     * @return string|null
     */
    private function getMimeType(string $fullPath): ?string
    {
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($fullPath);
            if ($mimeType !== false) {
                return $mimeType;
            }
        }

        // Fallback to extension-based detection
        return $this->getMimeTypeFromExtension($fullPath);
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

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Format bytes to human-readable string
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Get the underlying Craft filesystem
     *
     * @return LocalFilesystem
     */
    public function getFilesystem(): LocalFilesystem
    {
        return $this->filesystem;
    }

    /**
     * Get the underlying Flysystem adapter
     *
     * @return FilesystemOperator
     */
    public function getAdapter(): FilesystemOperator
    {
        return $this->adapter;
    }

    /**
     * Get the base path
     *
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
