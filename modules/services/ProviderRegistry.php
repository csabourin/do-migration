<?php

namespace csabourin\craftS3SpacesMigration\services;

use Craft;
use craft\base\Component;
use csabourin\craftS3SpacesMigration\interfaces\StorageProviderInterface;

/**
 * Provider Registry Service
 *
 * Central registry for storage provider adapters.
 * Manages registration, instantiation, and caching of provider instances.
 *
 * @package csabourin\craftS3SpacesMigration\services
 * @since 2.0.0
 */
class ProviderRegistry extends Component
{
    /**
     * @var array Registered adapter classes by provider name
     */
    private array $adapters = [];

    /**
     * @var array Cached provider instances by cache key
     */
    private array $instances = [];

    /**
     * Initialize and register built-in adapters
     *
     * @return void
     */
    public function init(): void
    {
        parent::init();

        // Register built-in adapters
        // These will be implemented next
        $this->registerAdapter('s3', \csabourin\craftS3SpacesMigration\adapters\S3StorageAdapter::class);
        $this->registerAdapter('do-spaces', \csabourin\craftS3SpacesMigration\adapters\DOSpacesStorageAdapter::class);
        $this->registerAdapter('local', \csabourin\craftS3SpacesMigration\adapters\LocalFilesystemAdapter::class);

        // Future providers (to be implemented in Phase 2)
        // $this->registerAdapter('gcs', \csabourin\craftS3SpacesMigration\adapters\GCSStorageAdapter::class);
        // $this->registerAdapter('azure-blob', \csabourin\craftS3SpacesMigration\adapters\AzureBlobStorageAdapter::class);
        // $this->registerAdapter('backblaze-b2', \csabourin\craftS3SpacesMigration\adapters\BackblazeB2StorageAdapter::class);
        // $this->registerAdapter('wasabi', \csabourin\craftS3SpacesMigration\adapters\WasabiStorageAdapter::class);
        // $this->registerAdapter('cloudflare-r2', \csabourin\craftS3SpacesMigration\adapters\CloudflareR2StorageAdapter::class);
    }

    /**
     * Register a provider adapter class
     *
     * @param string $name Provider identifier (e.g., 's3', 'gcs', 'local')
     * @param string $adapterClass Fully qualified class name
     * @return void
     * @throws \InvalidArgumentException If adapter doesn't implement StorageProviderInterface
     */
    public function registerAdapter(string $name, string $adapterClass): void
    {
        if (!class_exists($adapterClass)) {
            throw new \InvalidArgumentException("Adapter class does not exist: {$adapterClass}");
        }

        if (!is_subclass_of($adapterClass, StorageProviderInterface::class)) {
            throw new \InvalidArgumentException(
                "Adapter '{$adapterClass}' must implement StorageProviderInterface"
            );
        }

        $this->adapters[$name] = $adapterClass;

        Craft::info(
            "Registered storage provider adapter: {$name} => {$adapterClass}",
            'spaghetti-migrator'
        );
    }

    /**
     * Unregister a provider adapter
     *
     * @param string $name Provider identifier
     * @return void
     */
    public function unregisterAdapter(string $name): void
    {
        unset($this->adapters[$name]);

        // Clear any cached instances for this provider type
        $this->instances = array_filter(
            $this->instances,
            fn($key) => !str_starts_with($key, $name . ':'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Create a provider instance
     *
     * Instances are cached based on provider name and config hash.
     *
     * @param string $name Provider identifier
     * @param array $config Provider-specific configuration
     * @param bool $useCache Whether to use cached instance (default: true)
     * @return StorageProviderInterface
     * @throws \InvalidArgumentException If provider is not registered
     */
    public function createProvider(string $name, array $config, bool $useCache = true): StorageProviderInterface
    {
        if (!$this->hasProvider($name)) {
            throw new \InvalidArgumentException(
                "Unknown provider: '{$name}'. Available providers: " . implode(', ', $this->getAvailableProviders())
            );
        }

        // Generate cache key from provider name and config
        $cacheKey = $this->generateCacheKey($name, $config);

        // Return cached instance if available
        if ($useCache && isset($this->instances[$cacheKey])) {
            Craft::debug("Using cached provider instance: {$name}", 'spaghetti-migrator');
            return $this->instances[$cacheKey];
        }

        // Create new instance
        $adapterClass = $this->adapters[$name];

        try {
            /** @var StorageProviderInterface $provider */
            $provider = new $adapterClass($config);

            // Validate that the provider returns correct name
            if ($provider->getProviderName() !== $name) {
                Craft::warning(
                    "Provider adapter '{$adapterClass}' returns name '{$provider->getProviderName()}' but registered as '{$name}'",
                    'spaghetti-migrator'
                );
            }

            // Cache the instance
            if ($useCache) {
                $this->instances[$cacheKey] = $provider;
            }

            Craft::info(
                "Created new provider instance: {$name} ({$adapterClass})",
                'spaghetti-migrator'
            );

            return $provider;

        } catch (\Exception $e) {
            Craft::error(
                "Failed to create provider instance '{$name}': {$e->getMessage()}",
                'spaghetti-migrator'
            );
            throw new \RuntimeException(
                "Failed to create provider '{$name}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get all available provider identifiers
     *
     * @return array Array of provider names
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Check if a provider is registered
     *
     * @param string $name Provider identifier
     * @return bool
     */
    public function hasProvider(string $name): bool
    {
        return isset($this->adapters[$name]);
    }

    /**
     * Get registered adapter class for a provider
     *
     * @param string $name Provider identifier
     * @return string|null Adapter class name or null if not registered
     */
    public function getAdapterClass(string $name): ?string
    {
        return $this->adapters[$name] ?? null;
    }

    /**
     * Get all registered adapters
     *
     * @return array Map of provider name => adapter class
     */
    public function getAllAdapters(): array
    {
        return $this->adapters;
    }

    /**
     * Clear all cached provider instances
     *
     * Useful for testing or when credentials change.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $count = count($this->instances);
        $this->instances = [];

        Craft::info(
            "Cleared {$count} cached provider instances",
            'spaghetti-migrator'
        );
    }

    /**
     * Get provider information and capabilities
     *
     * @param string $name Provider identifier
     * @param array $config Provider configuration
     * @return array Provider info including capabilities
     * @throws \InvalidArgumentException If provider not registered
     */
    public function getProviderInfo(string $name, array $config = []): array
    {
        if (!$this->hasProvider($name)) {
            throw new \InvalidArgumentException("Unknown provider: {$name}");
        }

        $adapterClass = $this->adapters[$name];

        $info = [
            'name' => $name,
            'adapter_class' => $adapterClass,
            'registered' => true,
        ];

        // If config provided, create instance and get capabilities
        if (!empty($config)) {
            try {
                $provider = $this->createProvider($name, $config, false);
                $info['capabilities'] = $provider->getCapabilities()->toArray();
                $info['region'] = $provider->getRegion();
                $info['bucket'] = $provider->getBucket();
            } catch (\Exception $e) {
                $info['error'] = $e->getMessage();
            }
        }

        return $info;
    }

    /**
     * Generate cache key for provider instance
     *
     * @param string $name Provider name
     * @param array $config Provider configuration
     * @return string
     */
    private function generateCacheKey(string $name, array $config): string
    {
        // Sort config for consistent hashing
        ksort($config);

        // Create hash of config (excluding sensitive data for logging)
        $configHash = md5(serialize($config));

        return "{$name}:{$configHash}";
    }

    /**
     * Get statistics about cached providers
     *
     * @return array
     */
    public function getCacheStats(): array
    {
        $stats = [
            'total_cached' => count($this->instances),
            'by_provider' => [],
        ];

        foreach ($this->instances as $key => $instance) {
            $providerName = $instance->getProviderName();
            if (!isset($stats['by_provider'][$providerName])) {
                $stats['by_provider'][$providerName] = 0;
            }
            $stats['by_provider'][$providerName]++;
        }

        return $stats;
    }
}
