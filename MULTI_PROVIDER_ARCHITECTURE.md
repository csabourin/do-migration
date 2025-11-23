# Multi-Provider Migration Architecture

## Vision
Transform Spaghetti Migrator from a Craft CMS S3→DigitalOcean tool into a **universal, provider-agnostic asset migration platform** while maintaining 100% backward compatibility.

## Core Principles
1. **Provider Agnostic**: Any source → Any target (cloud or local)
2. **Pluggable**: New providers add via adapters, no core changes
3. **Backward Compatible**: Existing S3→DO migrations work unchanged
4. **Capability-Aware**: Controllers adapt to provider capabilities
5. **Zero Vendor Lock-in**: Switch providers without code changes

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                  Migration Controllers                   │
│  (Provider-agnostic, use interfaces only)               │
└───────────────────┬─────────────────────────────────────┘
                    │
┌───────────────────▼─────────────────────────────────────┐
│              Provider Registry & Config                  │
│  - Resolve adapters by name                             │
│  - Query capabilities                                   │
│  - Manage credentials                                   │
└───────────────────┬─────────────────────────────────────┘
                    │
        ┌───────────┴───────────┐
        │                       │
┌───────▼──────┐       ┌───────▼──────┐
│   Source     │       │    Target    │
│   Adapter    │       │    Adapter   │
└──────┬───────┘       └───────┬──────┘
       │                       │
┌──────▼───────────────────────▼──────┐
│        Storage Implementations       │
│  S3 | DO | GCS | Azure | LocalFS    │
└─────────────────────────────────────┘
```

---

## 1. Storage Provider Abstraction

### Core Interface: `StorageProviderInterface`

```php
namespace csabourin\spaghettiMigrator\interfaces;

interface StorageProviderInterface
{
    /**
     * Get provider identifier (e.g., 's3', 'do-spaces', 'gcs', 'local')
     */
    public function getProviderName(): string;

    /**
     * Get provider capabilities
     */
    public function getCapabilities(): ProviderCapabilities;

    /**
     * Test connection and credentials
     */
    public function testConnection(): ConnectionTestResult;

    /**
     * List objects in a path (with pagination)
     */
    public function listObjects(string $path = '', array $options = []): ObjectIterator;

    /**
     * Read object content
     */
    public function readObject(string $path): string|resource;

    /**
     * Write object content
     */
    public function writeObject(string $path, string|resource $content, array $metadata = []): bool;

    /**
     * Copy object (server-to-server if supported)
     */
    public function copyObject(string $sourcePath, StorageProviderInterface $targetProvider, string $targetPath): bool;

    /**
     * Delete object
     */
    public function deleteObject(string $path): bool;

    /**
     * Get object metadata (size, modified time, etag, etc.)
     */
    public function getObjectMetadata(string $path): ObjectMetadata;

    /**
     * Generate public URL for object
     */
    public function getPublicUrl(string $path): string;

    /**
     * Check if object exists
     */
    public function objectExists(string $path): bool;

    /**
     * Get base URL pattern for URL replacement
     */
    public function getUrlPattern(): string;
}
```

### Capability System

```php
namespace csabourin\spaghettiMigrator\models;

class ProviderCapabilities
{
    public bool $supportsVersioning = false;
    public bool $supportsACLs = false;
    public bool $supportsServerSideCopy = false;
    public bool $supportsMultipartUpload = false;
    public bool $supportsMetadata = true;
    public bool $supportsPublicUrls = true;
    public bool $supportsStreaming = true;

    public int $maxFileSize = PHP_INT_MAX;
    public int $maxPartSize = 5 * 1024 * 1024 * 1024; // 5GB
    public int $optimalBatchSize = 100;

    public array $supportedMetadataKeys = ['Content-Type', 'Cache-Control'];

    // Rate limiting
    public ?int $maxRequestsPerSecond = null;
    public ?int $maxConcurrentConnections = null;

    // Regional capabilities
    public bool $supportsMultiRegion = false;
    public array $availableRegions = [];

    /**
     * Check if a specific capability is supported
     */
    public function supports(string $capability): bool
    {
        return $this->$capability ?? false;
    }
}
```

---

## 2. Provider Implementations

### AWS S3 Adapter

```php
namespace csabourin\spaghettiMigrator\adapters;

use Aws\S3\S3Client;
use csabourin\spaghettiMigrator\interfaces\StorageProviderInterface;

class S3StorageAdapter implements StorageProviderInterface
{
    private S3Client $client;
    private string $bucket;
    private string $region;
    private string $baseUrl;

    public function __construct(array $config)
    {
        $this->bucket = $config['bucket'];
        $this->region = $config['region'] ?? 'us-east-1';
        $this->baseUrl = $config['baseUrl'] ?? "https://{$this->bucket}.s3.{$this->region}.amazonaws.com";

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => [
                'key' => $config['accessKey'],
                'secret' => $config['secretKey'],
            ],
        ]);
    }

    public function getProviderName(): string
    {
        return 's3';
    }

    public function getCapabilities(): ProviderCapabilities
    {
        $caps = new ProviderCapabilities();
        $caps->supportsVersioning = true;
        $caps->supportsACLs = true;
        $caps->supportsServerSideCopy = true;
        $caps->supportsMultipartUpload = true;
        $caps->maxFileSize = 5 * 1024 * 1024 * 1024 * 1024; // 5TB
        $caps->maxPartSize = 5 * 1024 * 1024 * 1024; // 5GB
        $caps->supportsMultiRegion = true;

        return $caps;
    }

    public function testConnection(): ConnectionTestResult
    {
        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
            return new ConnectionTestResult(true, 'S3 connection successful');
        } catch (\Exception $e) {
            return new ConnectionTestResult(false, $e->getMessage());
        }
    }

    public function listObjects(string $path = '', array $options = []): ObjectIterator
    {
        return new S3ObjectIterator($this->client, $this->bucket, $path, $options);
    }

    public function copyObject(string $sourcePath, StorageProviderInterface $targetProvider, string $targetPath): bool
    {
        // Server-to-server copy if target is also S3-compatible
        if ($targetProvider->getCapabilities()->supportsServerSideCopy
            && $targetProvider instanceof S3CompatibleInterface) {
            return $this->serverSideCopy($sourcePath, $targetProvider, $targetPath);
        }

        // Fall back to stream copy
        return $this->streamCopy($sourcePath, $targetProvider, $targetPath);
    }

    public function getUrlPattern(): string
    {
        return $this->baseUrl . '/{path}';
    }

    // ... implement remaining interface methods
}
```

### DigitalOcean Spaces Adapter

```php
namespace csabourin\spaghettiMigrator\adapters;

/**
 * DigitalOcean Spaces is S3-compatible, so extend S3 adapter
 */
class DOSpacesStorageAdapter extends S3StorageAdapter
{
    public function __construct(array $config)
    {
        // DO Spaces uses S3-compatible API with custom endpoint
        $config['endpoint'] = $config['endpoint'] ?? "https://{$config['region']}.digitaloceanspaces.com";
        parent::__construct($config);
    }

    public function getProviderName(): string
    {
        return 'do-spaces';
    }

    public function getCapabilities(): ProviderCapabilities
    {
        $caps = parent::getCapabilities();
        $caps->supportsVersioning = false; // DO Spaces doesn't support versioning yet
        $caps->availableRegions = ['nyc3', 'sfo2', 'sfo3', 'ams3', 'sgp1', 'fra1'];

        return $caps;
    }
}
```

### Google Cloud Storage Adapter

```php
namespace csabourin\spaghettiMigrator\adapters;

use Google\Cloud\Storage\StorageClient;

class GCSStorageAdapter implements StorageProviderInterface
{
    private StorageClient $client;
    private string $bucket;

    public function __construct(array $config)
    {
        $this->bucket = $config['bucket'];

        $this->client = new StorageClient([
            'projectId' => $config['projectId'],
            'keyFilePath' => $config['keyFilePath'] ?? null,
        ]);
    }

    public function getProviderName(): string
    {
        return 'gcs';
    }

    public function getCapabilities(): ProviderCapabilities
    {
        $caps = new ProviderCapabilities();
        $caps->supportsVersioning = true;
        $caps->supportsACLs = true;
        $caps->supportsServerSideCopy = true;
        $caps->maxFileSize = 5 * 1024 * 1024 * 1024 * 1024; // 5TB
        $caps->supportsMultiRegion = true;

        return $caps;
    }

    // ... implement interface methods
}
```

### Azure Blob Storage Adapter

```php
namespace csabourin\spaghettiMigrator\adapters;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class AzureBlobStorageAdapter implements StorageProviderInterface
{
    private BlobRestProxy $client;
    private string $container;

    public function __construct(array $config)
    {
        $this->container = $config['container'];

        $connectionString = sprintf(
            'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s',
            $config['accountName'],
            $config['accountKey']
        );

        $this->client = BlobRestProxy::createBlobService($connectionString);
    }

    public function getProviderName(): string
    {
        return 'azure-blob';
    }

    // ... implement interface methods
}
```

### Local Filesystem Adapter

```php
namespace csabourin\spaghettiMigrator\adapters;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

class LocalFilesystemAdapter implements StorageProviderInterface
{
    private Filesystem $filesystem;
    private string $basePath;

    public function __construct(array $config)
    {
        $this->basePath = $config['basePath'];

        $adapter = new LocalFilesystemAdapter($this->basePath);
        $this->filesystem = new Filesystem($adapter);
    }

    public function getProviderName(): string
    {
        return 'local';
    }

    public function getCapabilities(): ProviderCapabilities
    {
        $caps = new ProviderCapabilities();
        $caps->supportsPublicUrls = false;
        $caps->supportsServerSideCopy = true; // Filesystem copy/rename
        $caps->maxFileSize = PHP_INT_MAX;

        return $caps;
    }

    public function getPublicUrl(string $path): string
    {
        // Local files don't have public URLs
        return 'file://' . $this->basePath . '/' . $path;
    }

    // ... implement interface methods
}
```

---

## 3. Provider Registry

```php
namespace csabourin\spaghettiMigrator\services;

use csabourin\spaghettiMigrator\interfaces\StorageProviderInterface;

class ProviderRegistry extends Component
{
    private array $providers = [];
    private array $adapters = [];

    public function init(): void
    {
        parent::init();

        // Register built-in adapters
        $this->registerAdapter('s3', S3StorageAdapter::class);
        $this->registerAdapter('do-spaces', DOSpacesStorageAdapter::class);
        $this->registerAdapter('gcs', GCSStorageAdapter::class);
        $this->registerAdapter('azure-blob', AzureBlobStorageAdapter::class);
        $this->registerAdapter('local', LocalFilesystemAdapter::class);
        $this->registerAdapter('backblaze-b2', BackblazeB2StorageAdapter::class);
        $this->registerAdapter('wasabi', WasabiStorageAdapter::class);
        $this->registerAdapter('cloudflare-r2', CloudflareR2StorageAdapter::class);
    }

    /**
     * Register a provider adapter class
     */
    public function registerAdapter(string $name, string $adapterClass): void
    {
        if (!is_subclass_of($adapterClass, StorageProviderInterface::class)) {
            throw new \InvalidArgumentException("Adapter must implement StorageProviderInterface");
        }

        $this->adapters[$name] = $adapterClass;
    }

    /**
     * Create and configure a provider instance
     */
    public function createProvider(string $name, array $config): StorageProviderInterface
    {
        if (!isset($this->adapters[$name])) {
            throw new \InvalidArgumentException("Unknown provider: {$name}");
        }

        $adapterClass = $this->adapters[$name];
        $provider = new $adapterClass($config);

        // Cache the instance
        $cacheKey = md5($name . serialize($config));
        $this->providers[$cacheKey] = $provider;

        return $provider;
    }

    /**
     * Get available provider types
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Check if a provider is registered
     */
    public function hasProvider(string $name): bool
    {
        return isset($this->adapters[$name]);
    }
}
```

---

## 4. Enhanced MigrationConfig

```php
namespace csabourin\spaghettiMigrator\helpers;

class MigrationConfig
{
    /**
     * Get source provider configuration
     */
    public static function getSourceProvider(): array
    {
        return [
            'type' => self::get('sourceProviderType', 's3'),
            'config' => self::get('sourceProviderConfig', [
                'bucket' => getenv('AWS_BUCKET'),
                'region' => getenv('AWS_REGION'),
                'accessKey' => getenv('AWS_ACCESS_KEY_ID'),
                'secretKey' => getenv('AWS_SECRET_ACCESS_KEY'),
            ]),
        ];
    }

    /**
     * Get target provider configuration
     */
    public static function getTargetProvider(): array
    {
        return [
            'type' => self::get('targetProviderType', 'do-spaces'),
            'config' => self::get('targetProviderConfig', [
                'bucket' => getenv('DO_SPACES_BUCKET'),
                'region' => getenv('DO_SPACES_REGION'),
                'accessKey' => getenv('DO_SPACES_KEY'),
                'secretKey' => getenv('DO_SPACES_SECRET'),
            ]),
        ];
    }

    /**
     * Get URL replacement strategies
     */
    public static function getUrlReplacementStrategies(): array
    {
        return self::get('urlReplacementStrategies', [
            [
                'type' => 'simple',
                'search' => self::getSourceBaseUrl(),
                'replace' => self::getTargetBaseUrl(),
            ],
        ]);
    }
}
```

---

## 5. Configurable URL Replacement

### URL Strategy Interface

```php
namespace csabourin\spaghettiMigrator\interfaces;

interface UrlReplacementStrategyInterface
{
    /**
     * Replace URLs according to strategy
     */
    public function replace(string $content): string;

    /**
     * Test if this strategy applies to given content
     */
    public function applies(string $content): bool;

    /**
     * Get description of what this strategy does
     */
    public function getDescription(): string;
}
```

### Built-in Strategies

```php
namespace csabourin\spaghettiMigrator\strategies;

class SimpleUrlReplacementStrategy implements UrlReplacementStrategyInterface
{
    private string $search;
    private string $replace;

    public function __construct(string $search, string $replace)
    {
        $this->search = $search;
        $this->replace = $replace;
    }

    public function replace(string $content): string
    {
        return str_replace($this->search, $this->replace, $content);
    }

    public function applies(string $content): bool
    {
        return str_contains($content, $this->search);
    }

    public function getDescription(): string
    {
        return "Replace '{$this->search}' with '{$this->replace}'";
    }
}

class RegexUrlReplacementStrategy implements UrlReplacementStrategyInterface
{
    private string $pattern;
    private string $replacement;

    public function __construct(string $pattern, string $replacement)
    {
        $this->pattern = $pattern;
        $this->replacement = $replacement;
    }

    public function replace(string $content): string
    {
        return preg_replace($this->pattern, $this->replacement, $content);
    }

    public function applies(string $content): bool
    {
        return preg_match($this->pattern, $content) === 1;
    }

    public function getDescription(): string
    {
        return "Regex replace: {$this->pattern} → {$this->replacement}";
    }
}

class TemplatedUrlReplacementStrategy implements UrlReplacementStrategyInterface
{
    private array $mappings;

    public function __construct(array $mappings)
    {
        // Example: ['cdn1.example.com' => 'cdn2.example.com', 'cdn3.example.com' => 'cdn4.example.com']
        $this->mappings = $mappings;
    }

    public function replace(string $content): string
    {
        foreach ($this->mappings as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }
        return $content;
    }

    public function applies(string $content): bool
    {
        foreach ($this->mappings as $search => $replace) {
            if (str_contains($content, $search)) {
                return true;
            }
        }
        return false;
    }

    public function getDescription(): string
    {
        $count = count($this->mappings);
        return "Apply {$count} domain mappings";
    }
}
```

---

## 6. Pluggable Migration Phases

### Phase Registry

```php
namespace csabourin\spaghettiMigrator\services;

class MigrationPhaseRegistry extends Component
{
    private array $phases = [];

    public function init(): void
    {
        parent::init();

        // Register default phases
        $this->registerPhase('setup', SetupPhase::class, 0);
        $this->registerPhase('preflight', PreflightPhase::class, 1);
        $this->registerPhase('url-replacement', UrlReplacementPhase::class, 2);
        $this->registerPhase('template-replacement', TemplateReplacementPhase::class, 3);
        $this->registerPhase('filesystem-switch', FilesystemSwitchPhase::class, 4);
        $this->registerPhase('file-migration', FileMigrationPhase::class, 5);
        $this->registerPhase('validation', ValidationPhase::class, 6);
        $this->registerPhase('transforms', TransformsPhase::class, 7);
    }

    /**
     * Register a custom migration phase
     */
    public function registerPhase(string $id, string $phaseClass, int $order, array $dependencies = []): void
    {
        if (!is_subclass_of($phaseClass, MigrationPhaseInterface::class)) {
            throw new \InvalidArgumentException("Phase must implement MigrationPhaseInterface");
        }

        $this->phases[$id] = [
            'class' => $phaseClass,
            'order' => $order,
            'dependencies' => $dependencies,
        ];
    }

    /**
     * Get phases in execution order
     */
    public function getPhases(): array
    {
        $phases = $this->phases;
        uasort($phases, fn($a, $b) => $a['order'] <=> $b['order']);
        return $phases;
    }

    /**
     * Validate phase dependencies
     */
    public function validateDependencies(): array
    {
        $errors = [];
        foreach ($this->phases as $id => $phase) {
            foreach ($phase['dependencies'] as $dep) {
                if (!isset($this->phases[$dep])) {
                    $errors[] = "Phase '{$id}' depends on missing phase '{$dep}'";
                }
            }
        }
        return $errors;
    }
}
```

### Phase Interface

```php
namespace csabourin\spaghettiMigrator\interfaces;

interface MigrationPhaseInterface
{
    /**
     * Get phase name
     */
    public function getName(): string;

    /**
     * Get phase description
     */
    public function getDescription(): string;

    /**
     * Execute the phase
     */
    public function execute(MigrationContext $context): PhaseResult;

    /**
     * Validate prerequisites for this phase
     */
    public function validate(MigrationContext $context): ValidationResult;

    /**
     * Estimate duration
     */
    public function estimateDuration(MigrationContext $context): ?int;
}
```

---

## 7. Extensible Diagnostics

```php
namespace csabourin\spaghettiMigrator\services;

class DiagnosticsRegistry extends Component
{
    private array $checks = [];

    public function init(): void
    {
        parent::init();

        // Register core checks
        $this->registerCheck('connection', ConnectionDiagnosticCheck::class);
        $this->registerCheck('permissions', PermissionsDiagnosticCheck::class);
        $this->registerCheck('configuration', ConfigurationDiagnosticCheck::class);
    }

    /**
     * Register a diagnostic check
     */
    public function registerCheck(string $id, string $checkClass, array $providers = []): void
    {
        if (!is_subclass_of($checkClass, DiagnosticCheckInterface::class)) {
            throw new \InvalidArgumentException("Check must implement DiagnosticCheckInterface");
        }

        $this->checks[$id] = [
            'class' => $checkClass,
            'providers' => $providers, // Empty = all providers, or specific list
        ];
    }

    /**
     * Run all applicable checks for a provider
     */
    public function runChecks(StorageProviderInterface $provider): array
    {
        $results = [];

        foreach ($this->checks as $id => $check) {
            // Skip if check is provider-specific and doesn't match
            if (!empty($check['providers']) && !in_array($provider->getProviderName(), $check['providers'])) {
                continue;
            }

            $checkInstance = new $check['class']();
            $results[$id] = $checkInstance->execute($provider);
        }

        return $results;
    }
}
```

---

## 8. Configuration Structure

### New config/migration-config.php

```php
<?php

return [
    /**
     * Source Provider Configuration
     */
    'sourceProvider' => [
        'type' => 's3', // s3, do-spaces, gcs, azure-blob, local
        'config' => [
            'bucket' => getenv('AWS_BUCKET'),
            'region' => getenv('AWS_REGION'),
            'accessKey' => getenv('AWS_ACCESS_KEY_ID'),
            'secretKey' => getenv('AWS_SECRET_ACCESS_KEY'),
            'baseUrl' => getenv('AWS_BASE_URL'), // Optional
        ],
    ],

    /**
     * Target Provider Configuration
     */
    'targetProvider' => [
        'type' => 'do-spaces', // s3, do-spaces, gcs, azure-blob, local
        'config' => [
            'bucket' => getenv('DO_SPACES_BUCKET'),
            'region' => getenv('DO_SPACES_REGION'),
            'accessKey' => getenv('DO_SPACES_KEY'),
            'secretKey' => getenv('DO_SPACES_SECRET'),
            'baseUrl' => getenv('DO_SPACES_BASE_URL'),
        ],
    ],

    /**
     * URL Replacement Strategies
     */
    'urlReplacementStrategies' => [
        // Simple string replacement
        [
            'type' => 'simple',
            'search' => 'https://my-bucket.s3.amazonaws.com',
            'replace' => 'https://my-space.nyc3.digitaloceanspaces.com',
        ],

        // Regex replacement
        [
            'type' => 'regex',
            'pattern' => '#https://([^.]+)\.s3\.amazonaws\.com#',
            'replacement' => 'https://$1.nyc3.digitaloceanspaces.com',
        ],

        // Multiple domain mappings
        [
            'type' => 'templated',
            'mappings' => [
                'cdn1.example.com' => 'cdn-new.example.com',
                'cdn2.example.com' => 'cdn-new.example.com',
            ],
        ],
    ],

    /**
     * Migration Mode
     */
    'migrationMode' => 'cloud-to-cloud', // cloud-to-cloud, local-reorganize, hybrid

    /**
     * Local Filesystem Reorganization (when migrationMode = 'local-reorganize')
     */
    'localReorganization' => [
        'enabled' => false,
        'sourcePath' => '/path/to/messy/files',
        'targetPath' => '/path/to/organized/files',
        'strategy' => 'flatten', // flatten, organize-by-date, organize-by-type
        'namingPattern' => '{date}-{original}', // {date}, {type}, {size}, {hash}, {original}
        'handleDuplicates' => 'rename', // skip, overwrite, rename, deduplicate
    ],

    /**
     * Custom Migration Phases (optional)
     */
    'customPhases' => [
        // Example: Add a CDN purge phase after file migration
        'cdn-purge' => [
            'class' => 'mymodule\\migrations\\CdnPurgePhase',
            'order' => 6.5, // Between file-migration (6) and validation (7)
            'dependencies' => ['file-migration'],
        ],
    ],

    /**
     * Custom Diagnostic Checks (optional)
     */
    'customDiagnostics' => [
        'cdn-latency' => [
            'class' => 'mymodule\\diagnostics\\CdnLatencyCheck',
            'providers' => ['do-spaces'], // Only run for DO Spaces
        ],
    ],
];
```

---

## 9. Migration Presets

### Dashboard Presets

```php
namespace csabourin\spaghettiMigrator\models;

class MigrationPreset
{
    public string $id;
    public string $name;
    public string $description;
    public string $icon;
    public array $config;
    public array $estimatedDuration;

    public static function getPresets(): array
    {
        return [
            new self([
                'id' => 's3-to-do-spaces',
                'name' => 'AWS S3 → DigitalOcean Spaces',
                'description' => 'Migrate assets from AWS S3 to DigitalOcean Spaces with URL replacement',
                'icon' => 'cloud-arrow-right',
                'config' => [
                    'sourceProvider' => ['type' => 's3'],
                    'targetProvider' => ['type' => 'do-spaces'],
                ],
                'estimatedDuration' => ['small' => '30 minutes', 'medium' => '2-4 hours', 'large' => '8-24 hours'],
            ]),

            new self([
                'id' => 's3-to-gcs',
                'name' => 'AWS S3 → Google Cloud Storage',
                'description' => 'Migrate assets from AWS S3 to Google Cloud Storage',
                'icon' => 'cloud-arrow-right',
                'config' => [
                    'sourceProvider' => ['type' => 's3'],
                    'targetProvider' => ['type' => 'gcs'],
                ],
            ]),

            new self([
                'id' => 'cloud-to-local',
                'name' => 'Cloud → Local Storage',
                'description' => 'Download cloud assets to local filesystem (backup or hybrid setup)',
                'icon' => 'cloud-arrow-down',
                'config' => [
                    'sourceProvider' => ['type' => 's3'], // User selects
                    'targetProvider' => ['type' => 'local'],
                ],
            ]),

            new self([
                'id' => 'local-reorganize',
                'name' => 'Local Filesystem Reorganization',
                'description' => 'Untangle nested folders, flatten structure, deduplicate files',
                'icon' => 'folder-tree',
                'config' => [
                    'migrationMode' => 'local-reorganize',
                    'sourceProvider' => ['type' => 'local'],
                    'targetProvider' => ['type' => 'local'],
                ],
            ]),

            new self([
                'id' => 'multi-cloud-sync',
                'name' => 'Multi-Cloud Replication',
                'description' => 'Replicate assets to multiple cloud providers for redundancy',
                'icon' => 'cloud-sync',
                'config' => [
                    'sourceProvider' => ['type' => 's3'],
                    'targetProvider' => ['type' => 'multiple'], // Special handling
                ],
            ]),
        ];
    }
}
```

---

## 10. Controller Refactoring Example

### Before (Provider-Specific)

```php
// Old: Hardcoded to S3/DO
class ImageMigrationController extends Console
{
    public function actionMigrate()
    {
        $sourceFs = $this->getAwsFilesystem();
        $targetFs = $this->getDoSpacesFilesystem();

        // ... migration logic
    }
}
```

### After (Provider-Agnostic)

```php
namespace csabourin\spaghettiMigrator\console\controllers;

class ImageMigrationController extends Console
{
    public function actionMigrate()
    {
        // Get providers from registry
        $registry = Plugin::getInstance()->providerRegistry;

        $sourceConfig = MigrationConfig::getSourceProvider();
        $targetConfig = MigrationConfig::getTargetProvider();

        $sourceProvider = $registry->createProvider($sourceConfig['type'], $sourceConfig['config']);
        $targetProvider = $registry->createProvider($targetConfig['type'], $targetConfig['config']);

        // Test connections
        $this->stdout("Testing source connection...\n");
        $sourceTest = $sourceProvider->testConnection();
        if (!$sourceTest->success) {
            $this->stderr("Source connection failed: {$sourceTest->message}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Testing target connection...\n");
        $targetTest = $targetProvider->testConnection();
        if (!$targetTest->success) {
            $this->stderr("Target connection failed: {$targetTest->message}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Get capabilities for optimization
        $sourceCaps = $sourceProvider->getCapabilities();
        $targetCaps = $targetProvider->getCapabilities();

        $batchSize = min($sourceCaps->optimalBatchSize, $targetCaps->optimalBatchSize);

        // Use server-to-server copy if both support it
        $useServerSideCopy = $sourceCaps->supportsServerSideCopy && $targetCaps->supportsServerSideCopy;

        $this->stdout("Migration strategy: " . ($useServerSideCopy ? "Server-to-server" : "Streamed") . "\n");
        $this->stdout("Batch size: {$batchSize}\n");

        // Delegate to migration service (already provider-agnostic)
        $migrationService = Plugin::getInstance()->migration;
        $result = $migrationService->migrateFiles($sourceProvider, $targetProvider, [
            'batchSize' => $batchSize,
            'useServerSideCopy' => $useServerSideCopy,
        ]);

        return $result->success ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
```

---

## 11. Implementation Roadmap

### Phase 1: Foundation (Week 1-2)
1. ✅ Create interface definitions (`StorageProviderInterface`, `ProviderCapabilities`)
2. ✅ Build provider registry and capability system
3. ✅ Implement S3 adapter (extract from existing code)
4. ✅ Implement DO Spaces adapter (extract from existing code)
5. ✅ Add backward compatibility layer (existing configs still work)
6. ✅ Unit tests for registry and adapters

### Phase 2: Core Providers (Week 3)
7. ✅ Implement Local Filesystem adapter
8. ✅ Implement Google Cloud Storage adapter
9. ✅ Implement Azure Blob adapter
10. ✅ Integration tests with real cloud accounts (optional, manual)

### Phase 3: Controller Refactoring (Week 4-5)
11. ✅ Refactor `ImageMigrationController` to use providers
12. ✅ Refactor `FilesystemSwitchController` to be provider-agnostic
13. ✅ Update URL replacement to use strategies
14. ✅ Test existing S3→DO migrations (ensure backward compatibility)

### Phase 4: Advanced Features (Week 6)
15. ✅ Implement pluggable phase registry
16. ✅ Implement extensible diagnostics
17. ✅ Build dashboard presets UI
18. ✅ Add local reorganization mode

### Phase 5: Documentation & Polish (Week 7)
19. ✅ Write "Adding a New Provider" guide
20. ✅ Write "Multi-Cloud Migrations" guide
21. ✅ Create sample adapter stubs
22. ✅ Update README with new capabilities
23. ✅ Create migration examples for each preset

---

## 12. Backward Compatibility Strategy

### Existing Configurations Work Unchanged

```php
// OLD config (still works)
return [
    'sourceVolume' => 'awsImages',
    'targetVolume' => 'doImages',
    // ... existing keys
];

// System automatically detects and converts to:
return [
    'sourceProvider' => [
        'type' => 's3',
        'config' => [/* derived from sourceVolume */],
    ],
    'targetProvider' => [
        'type' => 'do-spaces',
        'config' => [/* derived from targetVolume */],
    ],
];
```

### Deprecation Notices (Not Errors)

```php
if (isset($config['sourceVolume'])) {
    Craft::warning(
        'sourceVolume config is deprecated. Use sourceProvider instead. See docs/migration-guide.md',
        'spaghetti-migrator'
    );
    // Convert automatically, don't break
}
```

---

## 13. Success Metrics

### Technical Goals
- [ ] Zero breaking changes for existing users
- [ ] Support 5+ cloud providers
- [ ] Support local filesystem reorganization
- [ ] 100% test coverage for adapters
- [ ] < 5% performance degradation vs current version

### Adoption Goals
- [ ] 10+ community-contributed provider adapters within 6 months
- [ ] 5+ documented use cases beyond Craft CMS
- [ ] Featured in cloud provider documentation (DO, GCP, Azure)
- [ ] 1,000+ stars on GitHub (currently ~?)
- [ ] 10,000+ downloads via Packagist

---

## 14. Future Enhancements (Post-MVP)

- **Bi-directional sync**: Keep two clouds in sync
- **Multi-target replication**: One source → multiple targets
- **Bandwidth optimization**: Compression, parallel transfers
- **Cost calculator**: Pre-migration cost estimates
- **AI-powered insights**: Duplicate detection, organization suggestions
- **Standalone CLI**: `spaghetti-migrator` command (no Craft required)
- **Docker container**: Migration as a service
- **WordPress plugin**: Port to WordPress
- **Drupal module**: Port to Drupal

---

## Questions for Decision

1. **Naming**: Keep "Spaghetti Migrator" or rename to "Universal Asset Migrator"?
2. **Versioning**: Release as 2.0.0 (breaking) or 1.x.0 (backward compatible)?
3. **Licensing**: Keep MIT or consider dual licensing (MIT for open-source, commercial for enterprise features)?
4. **Monetization**: Keep 100% free or offer "Pro" features (advanced providers, support)?
5. **Standalone CLI**: Priority high or low?

---

## Next Steps

Ready to implement? Let's start with Phase 1:

```bash
# Create branch
git checkout -b feature/multi-provider-architecture

# Start with interfaces
mkdir -p modules/interfaces
touch modules/interfaces/StorageProviderInterface.php
touch modules/models/ProviderCapabilities.php

# Then adapters
mkdir -p modules/adapters
touch modules/adapters/S3StorageAdapter.php
touch modules/adapters/DOSpacesStorageAdapter.php

# Finally registry
touch modules/services/ProviderRegistry.php
```

**Shall I proceed with implementation?**
