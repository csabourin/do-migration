<?php

namespace csabourin\spaghettiMigrator\adapters;

use Craft;
use craft\fs\GoogleCloud as GoogleCloudFilesystem;
use League\Flysystem\FilesystemOperator;
use csabourin\spaghettiMigrator\interfaces\StorageProviderInterface;
use csabourin\spaghettiMigrator\models\ProviderCapabilities;
use csabourin\spaghettiMigrator\models\ConnectionTestResult;
use csabourin\spaghettiMigrator\models\ObjectMetadata;
use csabourin\spaghettiMigrator\models\ObjectIterator;
use csabourin\spaghettiMigrator\models\S3ObjectIterator;

/**
 * Google Cloud Storage Adapter
 *
 * Provides access to Google Cloud Storage (GCS).
 * Requires Craft CMS's Google Cloud Storage filesystem plugin.
 *
 * Installation:
 *   composer require craftcms/google-cloud
 *
 * Configuration:
 *   - bucket: GCS bucket name
 *   - projectId: GCP project ID
 *   - keyFilePath: Path to service account JSON key file
 *   - subfolder: Optional subfolder within bucket
 *
 * @package csabourin\spaghettiMigrator\adapters
 * @since 2.0.0
 */
class GCSStorageAdapter implements StorageProviderInterface
{
    /**
     * @var GoogleCloudFilesystem Craft GCS filesystem
     */
    protected GoogleCloudFilesystem $filesystem;

    /**
     * @var FilesystemOperator Flysystem adapter
     */
    protected FilesystemOperator $adapter;

    /**
     * @var string Bucket name
     */
    protected string $bucket;

    /**
     * @var string Project ID
     */
    protected string $projectId;

    /**
     * @var string Base URL for public access
     */
    protected string $baseUrl;

    /**
     * @var string Subfolder path (if any)
     */
    protected string $subfolder = '';

    /**
     * Constructor
     *
     * @param array $config Configuration array:
     *   - bucket: string (required) - GCS bucket name
     *   - projectId: string (required) - GCP project ID
     *   - keyFilePath: string (required) - Path to service account JSON
     *   - baseUrl: string (optional) - Base URL for public access
     *   - subfolder: string (optional) - Subfolder within bucket
     * @throws \InvalidArgumentException If required config missing
     */
    public function __construct(array $config)
    {
        // Validate required config
        $required = ['bucket', 'projectId', 'keyFilePath'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Missing required config: {$key}");
            }
        }

        $this->bucket = $config['bucket'];
        $this->projectId = $config['projectId'];
        $this->subfolder = $config['subfolder'] ?? '';

        // Validate key file exists
        if (!file_exists($config['keyFilePath'])) {
            throw new \InvalidArgumentException("GCS key file not found: {$config['keyFilePath']}");
        }

        // Create Craft GCS filesystem
        $this->filesystem = new GoogleCloudFilesystem([
            'bucket' => $this->bucket,
            'projectId' => $this->projectId,
            'keyFileContents' => file_get_contents($config['keyFilePath']),
            'subfolder' => $this->subfolder,
        ]);

        // Get Flysystem adapter
        $this->adapter = $this->filesystem->getAdapter();

        // Determine base URL
        if (!empty($config['baseUrl'])) {
            $this->baseUrl = rtrim($config['baseUrl'], '/');
        } else {
            // Default GCS URL format
            $this->baseUrl = "https://storage.googleapis.com/{$this->bucket}";
            if ($this->subfolder) {
                $this->baseUrl .= '/' . trim($this->subfolder, '/');
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getProviderName(): string
    {
        return 'gcs';
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities(): ProviderCapabilities
    {
        $caps = new ProviderCapabilities();

        $caps->supportsVersioning = true;
        $caps->supportsACLs = true;
        $caps->supportsServerSideCopy = true;
        $caps->supportsMultipartUpload = true;
        $caps->supportsMetadata = true;
        $caps->supportsPublicUrls = true;
        $caps->supportsStreaming = true;
        $caps->supportsMultiRegion = true;
        $caps->supportsPresignedUrls = true;

        $caps->maxFileSize = 5 * 1024 * 1024 * 1024 * 1024; // 5TB
        $caps->maxPartSize = 5 * 1024 * 1024 * 1024; // 5GB
        $caps->minPartSize = 256 * 1024; // 256KB

        $caps->optimalBatchSize = 100;

        $caps->supportedMetadataKeys = [
            'Content-Type',
            'Cache-Control',
            'Content-Disposition',
            'Content-Encoding',
            'Content-Language',
        ];

        $caps->availableRegions = [
            'us-central1', 'us-east1', 'us-east4', 'us-west1', 'us-west2', 'us-west3', 'us-west4',
            'europe-west1', 'europe-west2', 'europe-west3', 'europe-west4', 'europe-north1',
            'asia-east1', 'asia-east2', 'asia-northeast1', 'asia-northeast2', 'asia-northeast3',
            'asia-south1', 'asia-southeast1', 'australia-southeast1',
        ];

        return $caps;
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): ConnectionTestResult
    {
        $startTime = microtime(true);

        try {
            // Try to list files (limited to 1 to be fast)
            $this->adapter->listContents('', false)->toArray();

            $responseTime = microtime(true) - $startTime;

            $result = ConnectionTestResult::success(
                "GCS connection successful: {$this->bucket} (project: {$this->projectId})",
                [
                    'bucket' => $this->bucket,
                    'project_id' => $this->projectId,
                    'subfolder' => $this->subfolder ?: '(root)',
                ]
            );
            $result->responseTime = $responseTime;

            return $result;

        } catch (\Exception $e) {
            $responseTime = microtime(true) - $startTime;

            $result = ConnectionTestResult::failure(
                "GCS connection failed: {$e->getMessage()}",
                $e,
                [
                    'bucket' => $this->bucket,
                    'project_id' => $this->projectId,
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
        return new S3ObjectIterator($this->adapter, $path, $options);
    }

    /**
     * @inheritDoc
     */
    public function readObject(string $path): mixed
    {
        try {
            return $this->adapter->read($path);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to read object '{$path}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function writeObject(string $path, mixed $content, array $metadata = []): bool
    {
        try {
            $config = [];

            // Handle metadata
            if (!empty($metadata['contentType'])) {
                $config['ContentType'] = $metadata['contentType'];
            }
            if (!empty($metadata['cacheControl'])) {
                $config['CacheControl'] = $metadata['cacheControl'];
            }

            if (is_resource($content)) {
                $this->adapter->writeStream($path, $content, $config);
            } else {
                $this->adapter->write($path, $content, $config);
            }

            return true;

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to write object '{$path}': {$e->getMessage()}",
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
            // Stream copy for reliability
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
                "Failed to copy object '{$sourcePath}' to '{$targetPath}': {$e->getMessage()}",
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
            Craft::warning("Failed to delete object '{$path}': {$e->getMessage()}", 'spaghetti-migrator');
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getObjectMetadata(string $path): ObjectMetadata
    {
        try {
            $mimeType = $this->adapter->mimeType($path);
            $size = $this->adapter->fileSize($path);
            $lastModified = $this->adapter->lastModified($path);

            $metadata = new ObjectMetadata(
                $path,
                $size,
                new \DateTime('@' . $lastModified)
            );

            $metadata->contentType = $mimeType;

            return $metadata;

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to get metadata for object '{$path}': {$e->getMessage()}",
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
        return $this->baseUrl . '/' . ltrim($path, '/');
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
        return $this->baseUrl . '/{path}';
    }

    /**
     * @inheritDoc
     */
    public function getRegion(): ?string
    {
        return null; // GCS uses multi-regional buckets, no single region
    }

    /**
     * @inheritDoc
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * Get the project ID
     *
     * @return string
     */
    public function getProjectId(): string
    {
        return $this->projectId;
    }

    /**
     * Get the underlying Craft filesystem
     *
     * @return GoogleCloudFilesystem
     */
    public function getFilesystem(): GoogleCloudFilesystem
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
}
