<?php

namespace csabourin\craftS3SpacesMigration\adapters;

use Craft;
use craft\fs\AwsS3 as AwsS3Filesystem;
use League\Flysystem\FilesystemOperator;
use csabourin\craftS3SpacesMigration\interfaces\StorageProviderInterface;
use csabourin\craftS3SpacesMigration\models\ProviderCapabilities;
use csabourin\craftS3SpacesMigration\models\ConnectionTestResult;
use csabourin\craftS3SpacesMigration\models\ObjectMetadata;
use csabourin\craftS3SpacesMigration\models\ObjectIterator;
use csabourin\craftS3SpacesMigration\models\S3ObjectIterator;

/**
 * AWS S3 Storage Adapter
 *
 * Provides access to AWS S3 storage using Craft's native S3 filesystem.
 *
 * @package csabourin\craftS3SpacesMigration\adapters
 * @since 2.0.0
 */
class S3StorageAdapter implements StorageProviderInterface
{
    /**
     * @var AwsS3Filesystem Craft S3 filesystem
     */
    protected AwsS3Filesystem $filesystem;

    /**
     * @var FilesystemOperator Flysystem adapter
     */
    protected FilesystemOperator $adapter;

    /**
     * @var string Bucket name
     */
    protected string $bucket;

    /**
     * @var string Region
     */
    protected string $region;

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
     *   - bucket: string (required) - S3 bucket name
     *   - region: string (required) - AWS region
     *   - accessKey: string (required) - AWS access key ID
     *   - secretKey: string (required) - AWS secret access key
     *   - baseUrl: string (optional) - Base URL for public access
     *   - subfolder: string (optional) - Subfolder within bucket
     *   - endpoint: string (optional) - Custom endpoint URL
     * @throws \InvalidArgumentException If required config missing
     */
    public function __construct(array $config)
    {
        // Validate required config
        $required = ['bucket', 'region', 'accessKey', 'secretKey'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Missing required config: {$key}");
            }
        }

        $this->bucket = $config['bucket'];
        $this->region = $config['region'];
        $this->subfolder = $config['subfolder'] ?? '';

        // Create Craft S3 filesystem
        $this->filesystem = new AwsS3Filesystem([
            'bucket' => $this->bucket,
            'region' => $this->region,
            'keyId' => $config['accessKey'],
            'secret' => $config['secretKey'],
            'subfolder' => $this->subfolder,
            'endpoint' => $config['endpoint'] ?? null,
        ]);

        // Get Flysystem adapter
        $this->adapter = $this->filesystem->getAdapter();

        // Determine base URL
        if (!empty($config['baseUrl'])) {
            $this->baseUrl = rtrim($config['baseUrl'], '/');
        } else {
            // Default S3 URL format
            $this->baseUrl = "https://{$this->bucket}.s3.{$this->region}.amazonaws.com";
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
        return 's3';
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
        $caps->minPartSize = 5 * 1024 * 1024; // 5MB

        $caps->optimalBatchSize = 100;

        $caps->supportedMetadataKeys = [
            'Content-Type',
            'Cache-Control',
            'Content-Disposition',
            'Content-Encoding',
            'Content-Language',
        ];

        $caps->availableRegions = [
            'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2',
            'eu-west-1', 'eu-west-2', 'eu-west-3', 'eu-central-1', 'eu-north-1',
            'ap-south-1', 'ap-southeast-1', 'ap-southeast-2', 'ap-northeast-1', 'ap-northeast-2',
            'sa-east-1', 'ca-central-1',
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
                "S3 connection successful: {$this->bucket} ({$this->region})",
                [
                    'bucket' => $this->bucket,
                    'region' => $this->region,
                    'subfolder' => $this->subfolder ?: '(root)',
                ]
            );
            $result->responseTime = $responseTime;

            return $result;

        } catch (\Exception $e) {
            $responseTime = microtime(true) - $startTime;

            $result = ConnectionTestResult::failure(
                "S3 connection failed: {$e->getMessage()}",
                $e,
                [
                    'bucket' => $this->bucket,
                    'region' => $this->region,
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
    public function readObject(string $path): string|resource
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
    public function writeObject(string $path, string|resource $content, array $metadata = []): bool
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
            if (!empty($metadata['acl'])) {
                $config['ACL'] = $metadata['acl'];
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
            // If target is also S3, we can potentially use server-side copy
            // For now, use stream copy for reliability
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
            // Log but don't throw - deletion is idempotent
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
            $flysystemMetadata = $this->adapter->mimeType($path);
            $size = $this->adapter->fileSize($path);
            $lastModified = $this->adapter->lastModified($path);

            $metadata = new ObjectMetadata(
                $path,
                $size,
                new \DateTime('@' . $lastModified)
            );

            $metadata->contentType = $flysystemMetadata;

            // Try to get additional metadata if available
            try {
                $metadata->etag = $this->adapter->checksum($path);
            } catch (\Exception $e) {
                // Checksum might not be available
            }

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
        return $this->region;
    }

    /**
     * @inheritDoc
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * Get the underlying Craft filesystem
     *
     * @return AwsS3Filesystem
     */
    public function getFilesystem(): AwsS3Filesystem
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
