<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\base\FsInterface;

/**
 * Migration Strategy Selector
 *
 * Automatically selects the optimal migration strategy based on filesystem analysis.
 *
 * STRATEGIES:
 * 1. Direct Cloud Copy - Fastest, for same provider non-nested filesystems
 * 2. Temporary Local Files - Safest, for nested filesystems (prevents data corruption)
 * 3. Stream Copy - For cross-provider migrations (AWS → DigitalOcean)
 *
 * The selector analyzes:
 * - Filesystem providers (S3, GCS, Azure, etc.)
 * - Bucket/container relationships
 * - Path nesting (parent/child relationships)
 * - Network topology
 *
 * @author Christian Sabourin
 * @version 2.0.0
 */
class MigrationStrategySelector
{
    /**
     * @var FilesystemNestingDetector Nesting detector
     */
    private $nestingDetector;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->nestingDetector = new FilesystemNestingDetector();
    }

    /**
     * Select the optimal migration strategy
     *
     * @param FsInterface $sourceFs Source filesystem
     * @param FsInterface $targetFs Target filesystem
     * @return string Strategy name ('temp_file', 'direct', 'stream')
     */
    public function selectStrategy(FsInterface $sourceFs, FsInterface $targetFs): string
    {
        // Strategy 1: Check for nested filesystems first (highest priority)
        // This prevents data corruption from same-location copies
        if ($this->nestingDetector->isNestedFilesystem($sourceFs, $targetFs)) {
            return 'temp_file';
        }

        // Strategy 2: Check if same provider and same bucket
        // Direct copy is fastest for same-provider, same-bucket scenarios
        if ($this->isSameProvider($sourceFs, $targetFs) &&
            $this->isSameBucket($sourceFs, $targetFs)) {
            return 'direct';
        }

        // Strategy 3: Cross-provider or different buckets
        // Use stream copy or temp file approach
        if (!$this->isSameProvider($sourceFs, $targetFs)) {
            // Cross-provider migration - currently use temp file
            // In future, could use stream copy for large files
            return 'temp_file';
        }

        // Default: Temp file (safest)
        return 'temp_file';
    }

    /**
     * Get detailed strategy recommendation with reasoning
     *
     * @param FsInterface $sourceFs Source filesystem
     * @param FsInterface $targetFs Target filesystem
     * @return array Strategy information
     */
    public function getStrategyRecommendation(FsInterface $sourceFs, FsInterface $targetFs): array
    {
        $strategy = $this->selectStrategy($sourceFs, $targetFs);
        $isNested = $this->nestingDetector->isNestedFilesystem($sourceFs, $targetFs);
        $sameProvider = $this->isSameProvider($sourceFs, $targetFs);
        $sameBucket = $this->isSameBucket($sourceFs, $targetFs);

        $reasoning = [];

        if ($isNested) {
            $reasoning[] = 'Filesystems are nested (one is parent of other)';
            $reasoning[] = 'Direct copy would cause data corruption';
            $reasoning[] = 'Using local temp files as intermediary';
        }

        if ($sameProvider && $sameBucket && !$isNested) {
            $reasoning[] = 'Same provider and bucket';
            $reasoning[] = 'No nesting detected';
            $reasoning[] = 'Direct cloud-to-cloud copy is safe and fast';
        }

        if (!$sameProvider) {
            $reasoning[] = 'Different cloud providers detected';
            $reasoning[] = 'Cross-provider migration requires download/upload';
        }

        return [
            'strategy' => $strategy,
            'is_nested' => $isNested,
            'same_provider' => $sameProvider,
            'same_bucket' => $sameBucket,
            'source_type' => get_class($sourceFs),
            'target_type' => get_class($targetFs),
            'reasoning' => $reasoning,
            'performance' => $this->getPerformanceEstimate($strategy),
        ];
    }

    /**
     * Check if two filesystems are from the same provider
     *
     * @param FsInterface $fs1 First filesystem
     * @param FsInterface $fs2 Second filesystem
     * @return bool True if same provider
     */
    private function isSameProvider(FsInterface $fs1, FsInterface $fs2): bool
    {
        return get_class($fs1) === get_class($fs2);
    }

    /**
     * Check if two filesystems use the same bucket/container
     *
     * @param FsInterface $fs1 First filesystem
     * @param FsInterface $fs2 Second filesystem
     * @return bool True if same bucket
     */
    private function isSameBucket(FsInterface $fs1, FsInterface $fs2): bool
    {
        // Use the nesting detector's logic
        $diagnostics = $this->nestingDetector->getDiagnosticInfo($fs1, $fs2);
        return $diagnostics['same_bucket'] ?? false;
    }

    /**
     * Get performance estimate for a strategy
     *
     * @param string $strategy Strategy name
     * @return array Performance information
     */
    private function getPerformanceEstimate(string $strategy): array
    {
        $estimates = [
            'direct' => [
                'speed' => 'fastest',
                'network_hops' => 0,
                'disk_usage' => 'none',
                'description' => 'Cloud-to-cloud copy within same bucket',
            ],
            'temp_file' => [
                'speed' => 'moderate',
                'network_hops' => 2,
                'disk_usage' => 'temporary',
                'description' => 'Download to local temp, then upload to target',
            ],
            'stream' => [
                'speed' => 'moderate',
                'network_hops' => 2,
                'disk_usage' => 'minimal',
                'description' => 'Stream from source to target (future)',
            ],
        ];

        return $estimates[$strategy] ?? $estimates['temp_file'];
    }

    /**
     * Validate that a strategy is safe for the given filesystems
     *
     * @param string $strategy Strategy to validate
     * @param FsInterface $sourceFs Source filesystem
     * @param FsInterface $targetFs Target filesystem
     * @return array Validation result
     */
    public function validateStrategy(string $strategy, FsInterface $sourceFs, FsInterface $targetFs): array
    {
        $isNested = $this->nestingDetector->isNestedFilesystem($sourceFs, $targetFs);

        // CRITICAL: Never allow direct copy for nested filesystems
        if ($strategy === 'direct' && $isNested) {
            return [
                'valid' => false,
                'reason' => 'Direct copy not allowed for nested filesystems',
                'risk' => 'high',
                'recommendation' => 'Use temp_file strategy instead',
            ];
        }

        // Temp file is always safe
        if ($strategy === 'temp_file') {
            return [
                'valid' => true,
                'reason' => 'Temp file approach is universally safe',
                'risk' => 'none',
            ];
        }

        // Direct copy is safe if not nested
        if ($strategy === 'direct' && !$isNested) {
            return [
                'valid' => true,
                'reason' => 'Direct copy safe for non-nested filesystems',
                'risk' => 'none',
            ];
        }

        return [
            'valid' => true,
            'reason' => 'Strategy is acceptable',
            'risk' => 'low',
        ];
    }

    /**
     * Get all available strategies with descriptions
     *
     * @return array Strategy descriptions
     */
    public function getAvailableStrategies(): array
    {
        return [
            'temp_file' => [
                'name' => 'Temporary Local Files',
                'description' => 'Download to local temp, then upload to target',
                'use_case' => 'Nested filesystems, cross-provider migrations',
                'safety' => 'highest',
                'speed' => 'moderate',
            ],
            'direct' => [
                'name' => 'Direct Cloud Copy',
                'description' => 'Copy directly within cloud provider',
                'use_case' => 'Same bucket, non-nested paths',
                'safety' => 'high',
                'speed' => 'fastest',
            ],
            'stream' => [
                'name' => 'Stream Copy',
                'description' => 'Stream from source to target (future)',
                'use_case' => 'Large files, limited disk space',
                'safety' => 'high',
                'speed' => 'moderate',
                'status' => 'planned',
            ],
        ];
    }

    /**
     * Determine if manual strategy override is recommended
     *
     * @param FsInterface $sourceFs Source filesystem
     * @param FsInterface $targetFs Target filesystem
     * @param string $manualStrategy User-specified strategy
     * @return array Override recommendation
     */
    public function evaluateManualOverride(
        FsInterface $sourceFs,
        FsInterface $targetFs,
        string $manualStrategy
    ): array {
        $recommended = $this->selectStrategy($sourceFs, $targetFs);
        $validation = $this->validateStrategy($manualStrategy, $sourceFs, $targetFs);

        if (!$validation['valid']) {
            return [
                'allowed' => false,
                'reason' => $validation['reason'],
                'risk' => $validation['risk'],
                'force_recommended' => true,
            ];
        }

        if ($manualStrategy === $recommended) {
            return [
                'allowed' => true,
                'reason' => 'Manual strategy matches recommendation',
                'risk' => 'none',
            ];
        }

        return [
            'allowed' => true,
            'reason' => "Manual override: {$manualStrategy} instead of {$recommended}",
            'risk' => 'low',
            'warning' => "Recommended strategy is {$recommended} for optimal performance",
        ];
    }
}
