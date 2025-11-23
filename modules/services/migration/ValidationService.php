<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;

/**
 * Validation Service
 *
 * Handles configuration validation, health checks, and pre-migration verification.
 * Ensures all prerequisites are met before migration begins.
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class ValidationService
{
    /**
     * @var Controller The controller instance for output
     */
    private $controller;

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * @var MigrationReporter Reporter for output formatting
     */
    private $reporter;

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance for output
     * @param MigrationConfig $config Migration configuration
     * @param MigrationReporter $reporter Reporter for output
     */
    public function __construct(
        Controller $controller,
        MigrationConfig $config,
        MigrationReporter $reporter
    ) {
        $this->controller = $controller;
        $this->config = $config;
        $this->reporter = $reporter;
    }

    /**
     * Validate migration configuration
     *
     * Validates that all required volumes exist and are properly configured.
     *
     * @return array Validated volumes ['target' => Volume, 'sources' => Volume[], 'quarantine' => Volume]
     * @throws \Exception If validation fails
     */
    public function validateConfiguration(): array
    {
        $volumesService = Craft::$app->getVolumes();

        // Get target volume
        $targetVolumeHandle = $this->config->getTargetVolumeHandle();
        $targetVolume = $volumesService->getVolumeByHandle($targetVolumeHandle);
        if (!$targetVolume) {
            throw new \Exception("Target volume '{$targetVolumeHandle}' not found");
        }

        // Get source volumes
        $sourceVolumeHandles = $this->config->getSourceVolumeHandles();
        $sourceVolumes = [];
        foreach ($sourceVolumeHandles as $handle) {
            $volume = $volumesService->getVolumeByHandle($handle);
            if ($volume) {
                $sourceVolumes[] = $volume;
                $assetCount = Asset::find()->volumeId($volume->id)->count();
                $this->controller->stdout("    Source: {$volume->name} ({$handle}) - {$assetCount} assets\n", Console::FG_CYAN);
            } else {
                $this->controller->stdout("    ⚠ Source volume '{$handle}' not found - will skip\n", Console::FG_YELLOW);
            }
        }

        if (empty($sourceVolumes)) {
            throw new \Exception("No source volumes found");
        }

        // Get quarantine volume
        $quarantineVolumeHandle = $this->config->getQuarantineVolumeHandle();
        $quarantineVolume = $volumesService->getVolumeByHandle($quarantineVolumeHandle);
        if (!$quarantineVolume) {
            throw new \Exception("Quarantine volume '{$quarantineVolumeHandle}' not found");
        }

        // Ensure quarantine uses different filesystem
        if ($quarantineVolume->fsHandle === $targetVolume->fsHandle) {
            throw new \Exception("Quarantine volume must use a DIFFERENT filesystem");
        }

        $this->controller->stdout("    ✓ Quarantine: {$quarantineVolume->name} (separate filesystem)\n", Console::FG_GREEN);

        return [
            'target' => $targetVolume,
            'sources' => $sourceVolumes,
            'quarantine' => $quarantineVolume
        ];
    }

    /**
     * Perform pre-migration health check
     *
     * Verifies database connectivity, filesystem access, and write permissions.
     *
     * @param $targetVolume Target volume instance
     * @param $quarantineVolume Quarantine volume instance
     * @throws \Exception If health check fails
     */
    public function performHealthCheck($targetVolume, $quarantineVolume): void
    {
        $this->controller->stdout("    Performing health check...\n");

        // Database check
        try {
            Craft::$app->getDb()->createCommand('SELECT 1')->execute();
            $this->controller->stdout("      ✓ Database connection OK\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            throw new \Exception("CRITICAL: Database connection failed: " . $e->getMessage());
        }

        // Target FS check
        try {
            $targetFs = $targetVolume->getFs();
            $iter = $targetFs->getFileList('', false);
            $count = 0;
            foreach ($iter as $item) {
                $count++;
                if ($count >= 3) {
                    break;
                }
            }
            $this->controller->stdout("      ✓ Target filesystem accessible ({$count} items found)\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            throw new \Exception("CRITICAL: Target filesystem not accessible: " . $e->getMessage());
        }

        // Quarantine FS check
        try {
            $quarantineFs = $quarantineVolume->getFs();
            $iter = $quarantineFs->getFileList('', false);
            $count = 0;
            foreach ($iter as $item) {
                $count++;
                if ($count >= 3) {
                    break;
                }
            }
            $this->controller->stdout("      ✓ Quarantine filesystem accessible ({$count} items found)\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            throw new \Exception("CRITICAL: Quarantine filesystem not accessible: " . $e->getMessage());
        }

        // Write test - ensure we can actually write files
        try {
            $testFile = 'migration-test-' . time() . '.txt';
            $targetFs->write($testFile, 'test', []);
            $targetFs->deleteFile($testFile);
            $this->controller->stdout("      ✓ Target filesystem writable\n", Console::FG_GREEN);
        } catch (\Throwable $e) {
            throw new \Exception("CRITICAL: Cannot write to target filesystem: " . $e->getMessage());
        }

        $this->controller->stdout("    ✓ Health check passed\n\n", Console::FG_GREEN);
    }

    /**
     * Validate disk space before migration
     *
     * Estimates required space and checks if target has sufficient capacity.
     *
     * @param array $sourceVolumes Source volume instances
     * @param $targetVolume Target volume instance
     */
    public function validateDiskSpace(array $sourceVolumes, $targetVolume): void
    {
        $this->controller->stdout("    Validating disk space...\n");

        try {
            // Estimate required space by counting assets in source volumes
            $totalSize = 0;
            $assetCount = 0;

            foreach ($sourceVolumes as $volume) {
                $assets = Asset::find()
                    ->volumeId($volume->id)
                    ->all();

                foreach ($assets as $asset) {
                    if ($asset->size) {
                        $totalSize += $asset->size;
                        $assetCount++;
                    }
                }
            }

            // Add 20% buffer for transforms and temporary files
            $requiredSpace = $totalSize * 1.2;

            // Format sizes for display
            $totalSizeFormatted = $this->reporter->formatBytes($totalSize);
            $requiredSpaceFormatted = $this->reporter->formatBytes($requiredSpace);

            $this->controller->stdout("      Assets to migrate: {$assetCount} files\n");
            $this->controller->stdout("      Total size: {$totalSizeFormatted}\n");
            $this->controller->stdout("      Required space (with 20% buffer): {$requiredSpaceFormatted}\n");

            // Try to get available space (this may not work for all filesystems)
            $availableSpace = @disk_free_space(Craft::$app->getPath()->getStoragePath());

            if ($availableSpace !== false) {
                $availableSpaceFormatted = $this->reporter->formatBytes($availableSpace);
                $this->controller->stdout("      Local disk available: {$availableSpaceFormatted}\n");

                if ($availableSpace < $requiredSpace) {
                    $this->controller->stdout("      ⚠ WARNING: Local disk space may be insufficient for migration\n", Console::FG_YELLOW);
                    $this->controller->stdout("      Consider clearing temporary files or using a larger volume\n", Console::FG_YELLOW);
                } else {
                    $this->controller->stdout("      ✓ Sufficient local disk space\n", Console::FG_GREEN);
                }
            } else {
                $this->controller->stdout("      ⓘ Cannot determine available disk space (remote filesystem)\n", Console::FG_CYAN);
            }

            $this->controller->stdout("    ✓ Disk space validation complete\n\n", Console::FG_GREEN);

        } catch (\Throwable $e) {
            $this->controller->stdout("      ⚠ WARNING: Could not validate disk space: " . $e->getMessage() . "\n", Console::FG_YELLOW);
            $this->controller->stdout("      Continuing migration...\n\n", Console::FG_YELLOW);
        }
    }

    /**
     * Ensure a Craft FS can list contents (Spaces/Flysystem v3 compatible)
     *
     * @param $fs Filesystem instance
     * @param string $label Filesystem label for error messages
     * @throws \Exception If filesystem is not accessible
     */
    public function assertFsAccessible($fs, string $label = 'Filesystem'): void
    {
        try {
            // Non-recursive root listing via Craft FS API
            $iter = $fs->getFileList('', false);
            foreach ($iter as $_) {
                break; // force an actual call
            }
        } catch (\Throwable $e) {
            throw new \Exception("CRITICAL: {$label} not accessible: " . $e->getMessage());
        }
    }

    /**
     * Get Flysystem operator from Craft FS service
     *
     * Always returns a Flysystem operator for a Craft FS using Craft's FS service.
     *
     * @param $fs Filesystem instance
     * @return \League\Flysystem\FilesystemOperator
     * @throws \RuntimeException If operator cannot be created
     */
    public function getFsOperatorFromService($fs)
    {
        // Build an operator even if the FS implementation doesn't expose it
        $config = Craft::$app->fs->createFilesystemConfig($fs);
        $operator = Craft::$app->fs->createFilesystem($config);

        if (!is_object($operator) || !method_exists($operator, 'listContents')) {
            throw new \RuntimeException('Could not create a Flysystem operator from FS: ' . get_class($fs));
        }

        return $operator;
    }

    /**
     * Get the FS prefix/subfolder (parsed with env vars resolved)
     *
     * Computes the correct listing prefix (subfolder/root) for a Craft FS.
     * Returns a string WITHOUT a leading slash (what S3/Spaces expect).
     *
     * @param $fs Filesystem instance
     * @return string Parsed prefix without leading slash
     */
    public function getFsPrefix($fs): string
    {
        if (method_exists($fs, 'getRootPath')) {
            $prefix = Craft::parseEnv((string) $fs->getRootPath());
        } elseif (method_exists($fs, 'getSubfolder')) {
            $prefix = Craft::parseEnv((string) $fs->getSubfolder());
        } elseif (property_exists($fs, 'subfolder')) {
            $prefix = Craft::parseEnv((string) $fs->subfolder);
        } else {
            $prefix = '';
        }

        // Remove leading slash for S3/Spaces compatibility
        $prefix = ltrim($prefix, '/');
        return $prefix === '/' ? '' : $prefix;
    }
}
