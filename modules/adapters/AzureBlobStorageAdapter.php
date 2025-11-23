<?php

namespace csabourin\spaghettiMigrator\adapters;

use Craft;
use craft\fs\Azure as AzureFilesystem;
use League\Flysystem\FilesystemOperator;
use csabourin\spaghettiMigrator\interfaces\StorageProviderInterface;
use csabourin\spaghettiMigrator\models\ProviderCapabilities;
use csabourin\spaghettiMigrator\models\ConnectionTestResult;
use csabourin\spaghettiMigrator\models\ObjectMetadata;
use csabourin\spaghettiMigrator\models\ObjectIterator;
use csabourin\spaghettiMigrator\models\S3ObjectIterator;

/**
 * Azure Blob Storage Adapter
 *
 * Provides access to Microsoft Azure Blob Storage.
 * Requires Craft CMS's Azure filesystem plugin.
 *
 * Installation:
 *   composer require craftcms/azure-blob
 *
 * Configuration:
 *   - container: Azure container name
 *   - accountName: Azure storage account name
 *   - accountKey: Azure storage account key
 *   - subfolder: Optional subfolder within container
 *
 * @package csabourin\spaghettiMigrator\adapters
 * @since 2.0.0
 */
class AzureBlobStorageAdapter implements StorageProviderInterface
{
    /**
     * @var AzureFilesystem Craft Azure filesystem
     */
    protected AzureFilesystem $filesystem;

    /**
     * @var FilesystemOperator Flysystem adapter
     */
    protected FilesystemOperator $adapter;

    /**
     * @var string Container name
     */
    protected string $container;

    /**
     * @var string Account name
     */
    protected string $accountName;

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
     *   - container: string (required) - Azure container name
     *   - accountName: string (required) - Storage account name
     *   - accountKey: string (required) - Storage account key
     *   - baseUrl: string (optional) - Base URL for public access
     *   - subfolder: string (optional) - Subfolder within container
     * @throws \InvalidArgumentException If required config missing
     */
    public function __construct(array $config)
    {
        // Validate required config
        $required = ['container', 'accountName', 'accountKey'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Missing required config: {$key}");
            }
        }

        $this->container = $config['container'];
        $this->accountName = $config['accountName'];
        $this->subfolder = $config['subfolder'] ?? '';

        // Create Craft Azure filesystem
        $this->filesystem = new AzureFilesystem([
            'container' => $this->container,
            'accountName' => $this->accountName,
            'accountKey' => $config['accountKey'],
            'subfolder' => $this->subfolder,
        ]);

        // Get Flysystem adapter
        $this->adapter = $this->filesystem->getAdapter();

        // Determine base URL
        if (!empty($config['baseUrl'])) {
            $this->baseUrl = rtrim($config['baseUrl'], '/');
        } else {
            // Default Azure Blob URL format
            $this->baseUrl = "https://{$this->accountName}.blob.core.windows.net/{$this->container}";
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
        return 'azure-blob';
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
        $caps->supportsPresignedUrls = true; // SAS tokens

        $caps->maxFileSize = 190.7 * 1024 * 1024 * 1024; // ~190.7 GB for block blobs
        $caps->maxPartSize = 100 * 1024 * 1024; // 100MB per block
        $caps->minPartSize = 64 * 1024; // 64KB

        $caps->optimalBatchSize = 100;

        $caps->supportedMetadataKeys = [
            'Content-Type',
            'Cache-Control',
            'Content-Disposition',
            'Content-Encoding',
            'Content-Language',
        ];

        $caps->availableRegions = [
            'eastus', 'eastus2', 'westus', 'westus2', 'westus3', 'centralus', 'northcentralus', 'southcentralus',
            'westeurope', 'northeurope', 'uksouth', 'ukwest', 'francecentral', 'germanywestcentral',
            'eastasia', 'southeastasia', 'japaneast', 'japanwest', 'australiaeast', 'australiasoutheast',
            'brazilsouth', 'canadacentral', 'canadaeast', 'centralindia', 'southindia', 'westindia',
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
                "Azure Blob connection successful: {$this->container} (account: {$this->accountName})",
                [
                    'container' => $this->container,
                    'account_name' => $this->accountName,
                    'subfolder' => $this->subfolder ?: '(root)',
                ]
            );
            $result->responseTime = $responseTime;

            return $result;

        } catch (\Exception $e) {
            $responseTime = microtime(true) - $startTime;

            $result = ConnectionTestResult::failure(
                "Azure Blob connection failed: {$e->getMessage()}",
                $e,
                [
                    'container' => $this->container,
                    'account_name' => $this->accountName,
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
        return null; // Azure regions determined by account, not per-container
    }

    /**
     * @inheritDoc
     */
    public function getBucket(): string
    {
        return $this->container;
    }

    /**
     * Get the account name
     *
     * @return string
     */
    public function getAccountName(): string
    {
        return $this->accountName;
    }

    /**
     * Get the underlying Craft filesystem
     *
     * @return AzureFilesystem
     */
    public function getFilesystem(): AzureFilesystem
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
