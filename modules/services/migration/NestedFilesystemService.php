<?php

namespace csabourin\craftS3SpacesMigration\services\migration;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\craftS3SpacesMigration\helpers\DuplicateResolver;
use csabourin\craftS3SpacesMigration\services\ChangeLogManager;
use csabourin\craftS3SpacesMigration\services\migration\FilesystemNestingDetector;
use csabourin\craftS3SpacesMigration\services\migration\InventoryBuilder;
use csabourin\craftS3SpacesMigration\services\migration\MigrationReporter;

/**
 * Nested Filesystem Service
 *
 * Handles migration scenarios where source and target filesystems share the same
 * bucket but different subfolders, creating a parent-child relationship.
 *
 * PROBLEM:
 * When a volume exists at the bucket root while another volume is in a subfolder,
 * direct copying can cause:
 * - Source and destination being the same physical location
 * - Infinite loops during indexing
 * - Duplicate files and transforms
 *
 * SOLUTION: Two-step process using local temporary files as intermediary:
 * 1. Download from remote source to LOCAL temp folder
 * 2. Upload from LOCAL temp folder to remote destination
 *
 * EXAMPLE SCENARIOS:
 * - Volume at bucket root → subfolder consolidation (e.g., optimisedImages)
 * - Subfolder → deeper subfolder reorganization
 * - Any nested filesystem configuration
 *
 * @author Christian Sabourin
 * @version 2.0.0
 */
class NestedFilesystemService
{
    /**
     * @var Controller Controller instance
     */
    private $controller;

    /**
     * @var ChangeLogManager Change log manager
     */
    private $changeLogManager;

    /**
     * @var InventoryBuilder Inventory builder
     */
    private $inventoryBuilder;

    /**
     * @var MigrationReporter Reporter
     */
    private $reporter;

    /**
     * @var bool Dry run mode
     */
    private $dryRun;

    /**
     * @var bool Auto-confirm mode
     */
    private $yes;

    /**
     * @var array Transform patterns for detection
     */
    private $transformPatterns = [
        '/_\d+x\d+_crop_center-center/',  // _800x600_crop_center-center
        '/_\d+x\d+_/',                      // _800x600_
        '/_[a-zA-Z]+\d*x\d*/',              // _thumb200x200
    ];

    /**
     * @var FilesystemNestingDetector Filesystem nesting detector
     */
    private $nestingDetector;

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param ChangeLogManager $changeLogManager Change log manager
     * @param InventoryBuilder $inventoryBuilder Inventory builder
     * @param MigrationReporter $reporter Reporter
     * @param bool $dryRun Dry run mode
     * @param bool $yes Auto-confirm mode
     */
    public function __construct(
        Controller $controller,
        ChangeLogManager $changeLogManager,
        InventoryBuilder $inventoryBuilder,
        MigrationReporter $reporter,
        bool $dryRun = false,
        bool $yes = false
    ) {
        $this->controller = $controller;
        $this->changeLogManager = $changeLogManager;
        $this->inventoryBuilder = $inventoryBuilder;
        $this->reporter = $reporter;
        $this->dryRun = $dryRun;
        $this->yes = $yes;
        $this->nestingDetector = new FilesystemNestingDetector();
    }

    /**
     * Handle optimised images at root migration
     *
     * @param array $assetInventory Asset inventory
     * @param array $fileInventory File inventory
     * @param $targetVolume Target volume instance
     * @param $quarantineVolume Quarantine volume instance
     * @return array Migration statistics
     */
    public function handleOptimisedImagesAtRoot(
        array $assetInventory,
        array $fileInventory,
        $targetVolume,
        $quarantineVolume
    ): array {
        $this->reporter->printPhaseHeader("PHASE 0.5: OPTIMISED IMAGES → IMAGES MIGRATION");

        $this->controller->stdout("  STRATEGY: Process ALL assets with volumeId=4\n", Console::FG_CYAN);
        $this->controller->stdout("  - Updates volumeId FIRST (database before files)\n", Console::FG_CYAN);
        $this->controller->stdout("  - Searches in multiple locations (optimisedImages, images, quarantine)\n", Console::FG_CYAN);
        $this->controller->stdout("  - Handles missing files gracefully (updates volumeId anyway)\n", Console::FG_CYAN);
        $this->controller->stdout("  - Uses DuplicateResolver for collision handling\n\n", Console::FG_CYAN);

        $volumesService = Craft::$app->getVolumes();
        $optimisedVolume = $volumesService->getVolumeByHandle('optimisedImages');

        if (!$optimisedVolume) {
            $this->controller->stdout("  Skipping - optimisedImages volume not found\n\n");
            return ['total' => 0];
        }

        $this->controller->stdout("  Source: optimisedImages (Volume ID: {$optimisedVolume->id})\n");
        $this->controller->stdout("  Target: {$targetVolume->name} (Volume ID: {$targetVolume->id})\n\n");

        // Filter assets by volumeId
        $optimisedAssets = array_filter($assetInventory, function ($asset) use ($optimisedVolume) {
            return $asset['volumeId'] == $optimisedVolume->id;
        });

        $totalAssets = count($optimisedAssets);
        $this->controller->stdout("  Found {$totalAssets} assets with volumeId={$optimisedVolume->id}\n\n");

        if ($totalAssets === 0) {
            $this->controller->stdout("  ✓ No assets to migrate\n\n", Console::FG_GREEN);
            return ['total' => 0];
        }

        // Confirm before proceeding
        if (!$this->dryRun && !$this->yes) {
            $this->controller->stdout("  This will migrate {$totalAssets} assets from optimisedImages to images.\n", Console::FG_YELLOW);
            $this->controller->stdout("  All assets will have their volumeId updated, even if files are missing.\n\n", Console::FG_YELLOW);

            if (!$this->controller->confirm("Continue with migration?", true)) {
                $this->controller->stdout("  ⚠ Migration cancelled by user\n\n");
                return ['total' => 0, 'cancelled' => true];
            }
            $this->controller->stdout("\n");
        }

        // Build file index for quick lookup
        $fileIndex = $this->buildFileIndexForOptimisedMigration($optimisedVolume, $targetVolume, $quarantineVolume);

        // Process all assets
        $this->controller->stdout("  Processing assets...\n");
        $this->reporter->printProgressLegend();
        $this->controller->stdout("  Legend: . = moved with file, w = volumeId updated (file missing), m = merged, d = duplicate, x = error\n");
        $this->controller->stdout("  Progress: ");

        $stats = [
            'moved_with_file' => 0,
            'volumeId_updated_missing_file' => 0,
            'merged' => 0,
            'duplicates_overwritten' => 0,
            'errors' => 0
        ];

        $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);

        foreach ($optimisedAssets as $assetData) {
            $assetId = $assetData['id'];
            $filename = $assetData['filename'];

            try {
                $asset = Asset::findOne($assetId);
                if (!$asset) {
                    $this->reporter->safeStdout("?", Console::FG_GREY);
                    continue;
                }

                // Check for duplicate filename collision
                $resolution = DuplicateResolver::resolveFilenameCollision(
                    $asset,
                    $targetVolume->id,
                    $targetRootFolder->id,
                    $this->dryRun
                );

                if ($resolution['action'] === 'merge_into_existing') {
                    $this->reporter->safeStdout("m", Console::FG_CYAN);
                    $stats['merged']++;

                    if (!$this->dryRun) {
                        $this->cleanupOptimisedFile($optimisedVolume, $filename, $fileIndex);
                    }
                    continue;
                } elseif ($resolution['action'] === 'overwrite') {
                    $this->reporter->safeStdout("d", Console::FG_YELLOW);
                    $stats['duplicates_overwritten']++;
                }

                if (!$this->dryRun) {
                    $result = $this->migrateOptimisedAsset(
                        $asset,
                        $optimisedVolume,
                        $targetVolume,
                        $targetRootFolder,
                        $fileIndex
                    );

                    if ($result['success']) {
                        if ($result['file_found']) {
                            $this->reporter->safeStdout(".", Console::FG_GREEN);
                            $stats['moved_with_file']++;
                        } else {
                            $this->reporter->safeStdout("w", Console::FG_YELLOW);
                            $stats['volumeId_updated_missing_file']++;
                        }
                    } else {
                        $this->reporter->safeStdout("x", Console::FG_RED);
                        $stats['errors']++;
                        Craft::warning("Failed to migrate asset {$assetId}: {$result['error']}", __METHOD__);
                    }
                } else {
                    $this->reporter->safeStdout(".", Console::FG_GREY);
                }

            } catch (\Exception $e) {
                $this->reporter->safeStdout("x", Console::FG_RED);
                $stats['errors']++;
                Craft::error("Error migrating asset {$assetId}: " . $e->getMessage(), __METHOD__);
            }

            // Progress reporting
            $processed = $stats['moved_with_file'] + $stats['volumeId_updated_missing_file'] + $stats['merged'] + $stats['errors'];
            if ($processed % 50 === 0 && $processed > 0) {
                $this->reporter->safeStdout(" [{$processed}/{$totalAssets}]\n  ");
            }
        }

        $this->reporter->safeStdout("\n\n");
        $this->controller->stdout("  Summary:\n");
        $this->controller->stdout("    Moved with file:          {$stats['moved_with_file']}\n", Console::FG_GREEN);
        $this->controller->stdout("    VolumeId updated (no file): {$stats['volumeId_updated_missing_file']}\n", Console::FG_YELLOW);
        $this->controller->stdout("    Merged into existing:     {$stats['merged']}\n", Console::FG_CYAN);
        $this->controller->stdout("    Duplicates overwritten:   {$stats['duplicates_overwritten']}\n", Console::FG_YELLOW);

        if ($stats['errors'] > 0) {
            $this->controller->stdout("    Errors:                   {$stats['errors']}\n", Console::FG_RED);
        }

        $guaranteed = $stats['moved_with_file'] + $stats['volumeId_updated_missing_file'] + $stats['merged'];
        $this->controller->stdout("\n  ✓ GUARANTEED: {$guaranteed}/{$totalAssets} assets now point to volume {$targetVolume->id}\n", Console::FG_GREEN);

        if ($stats['volumeId_updated_missing_file'] > 0) {
            $this->controller->stdout("  ⓘ {$stats['volumeId_updated_missing_file']} assets have volumeId updated but files were not found\n", Console::FG_CYAN);
            $this->controller->stdout("    These can be recovered later if files are located\n\n", Console::FG_GREY);
        }

        $stats['total'] = $totalAssets;
        return $stats;
    }

    /**
     * Build file index for optimised migration
     *
     * Scans multiple volumes to locate files (optimisedImages, images, quarantine)
     *
     * @param $optimisedVolume Optimised volume instance
     * @param $targetVolume Target volume instance
     * @param $quarantineVolume Quarantine volume instance
     * @return array File index keyed by filename
     */
    private function buildFileIndexForOptimisedMigration($optimisedVolume, $targetVolume, $quarantineVolume): array
    {
        $this->controller->stdout("  Building file index (scanning optimisedImages, images, quarantine)...\n");

        $fileIndex = [];
        $volumesToScan = [
            'optimisedImages' => $optimisedVolume,
            'images' => $targetVolume,
            'quarantine' => $quarantineVolume
        ];

        foreach ($volumesToScan as $volumeName => $volume) {
            if (!$volume) {
                continue;
            }

            $this->controller->stdout("    Scanning {$volumeName}... ");

            try {
                $fs = $volume->getFs();
                $scan = $this->inventoryBuilder->scanFilesystem($fs, '', true, null);

                $fileCount = 0;
                foreach ($scan['all'] as $entry) {
                    if (($entry['type'] ?? null) !== 'file') {
                        continue;
                    }

                    $filename = basename($entry['path']);

                    // Skip transform files
                    if ($this->isTransformFile($filename, $entry['path'])) {
                        continue;
                    }

                    // Store first occurrence (priority: optimisedImages > images > quarantine)
                    if (!isset($fileIndex[$filename])) {
                        $fileIndex[$filename] = [
                            'volume' => $volumeName,
                            'volumeId' => $volume->id,
                            'path' => $entry['path'],
                            'fs' => $fs,
                            'size' => $entry['size'] ?? 0
                        ];
                        $fileCount++;
                    }
                }

                $this->controller->stdout("found {$fileCount} files\n", Console::FG_GREEN);

            } catch (\Exception $e) {
                $this->controller->stdout("error: " . $e->getMessage() . "\n", Console::FG_RED);
                Craft::warning("Failed to scan {$volumeName}: " . $e->getMessage(), __METHOD__);
            }
        }

        $totalFiles = count($fileIndex);
        $this->controller->stdout("  ✓ File index built: {$totalFiles} unique files\n\n");

        return $fileIndex;
    }

    /**
     * Migrate single asset from optimisedImages to target
     *
     * STRATEGY:
     * 1. Update volumeId FIRST (database before file operations)
     * 2. Search for file in multiple locations
     * 3. Move file if found, or continue with just volumeId update
     * 4. Return success even if file missing (with warning)
     *
     * @param $asset Asset instance
     * @param $sourceVolume Source volume instance
     * @param $targetVolume Target volume instance
     * @param $targetRootFolder Target root folder instance
     * @param array $fileIndex File index
     * @return array Result with success, file_found, error keys
     */
    private function migrateOptimisedAsset($asset, $sourceVolume, $targetVolume, $targetRootFolder, array $fileIndex): array
    {
        $filename = $asset->filename;

        try {
            // STEP 1: Update volumeId FIRST
            $asset->volumeId = $targetVolume->id;
            $asset->folderId = $targetRootFolder->id;

            if (!Craft::$app->getElements()->saveElement($asset, false)) {
                $errorSummary = $asset->getErrorSummary(true);
                return [
                    'success' => false,
                    'error' => 'Failed to save asset record: ' . implode(', ', $errorSummary),
                    'file_found' => false
                ];
            }

            // STEP 2: Handle file operations
            $newPath = $filename; // Root folder = just filename
            $fileInfo = $fileIndex[$filename] ?? null;

            if (!$fileInfo) {
                // File not found but volumeId updated
                Craft::info(
                    "VolumeId updated for asset {$asset->id} ({$filename}) but file not found",
                    __METHOD__
                );

                $this->changeLogManager->logChange([
                    'type' => 'volumeId_updated_missing_file',
                    'assetId' => $asset->id,
                    'filename' => $filename,
                    'fromVolume' => $sourceVolume->id,
                    'toVolume' => $targetVolume->id,
                    'note' => 'File not found but volumeId updated'
                ]);

                return [
                    'success' => true,
                    'file_found' => false,
                    'warning' => "File not found: {$filename}"
                ];
            }

            // File found - move it
            $sourceLocation = $fileInfo['volume'] ?? 'unknown';
            $sourcePath = $fileInfo['path'] ?? '';
            $sourceFs = $fileInfo['fs'] ?? null;

            if (!$sourcePath || !$sourceFs) {
                return [
                    'success' => true,
                    'file_found' => false,
                    'warning' => "Invalid file info"
                ];
            }

            $targetFs = $targetVolume->getFs();

            // Check if file already in target location
            if ($sourceLocation === 'images' && $targetFs->fileExists($newPath)) {
                $this->changeLogManager->logChange([
                    'type' => 'volumeId_updated_file_already_in_target',
                    'assetId' => $asset->id,
                    'filename' => $filename,
                    'fromVolume' => $sourceVolume->id,
                    'toVolume' => $targetVolume->id,
                    'path' => $newPath
                ]);

                return [
                    'success' => true,
                    'file_found' => true,
                    'note' => 'File already in target location'
                ];
            }

            // CRITICAL: Use temp file approach for nested filesystem
            // The nesting detector automatically identifies this scenario
            $tempPath = tempnam(sys_get_temp_dir(), 'asset_');

            if (!$tempPath || strpos($tempPath, sys_get_temp_dir()) !== 0) {
                throw new \Exception("Failed to create local temp file");
            }

            try {
                // Read from source
                $content = $sourceFs->read($sourcePath);
                if ($content === false) {
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                    throw new \Exception("Failed to read source file from {$sourceLocation}: {$sourcePath}");
                }

                // Write to local temp
                file_put_contents($tempPath, $content);

                // Write to target
                $targetFs->write($newPath, $content, []);

                // Verify target file
                if (!$targetFs->fileExists($newPath)) {
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                    throw new \Exception("Failed to write target file: {$newPath}");
                }

                // Delete from source (if different from target)
                if ($sourceLocation !== 'images') {
                    try {
                        $sourceFs->deleteFile($sourcePath);
                    } catch (\Exception $e) {
                        Craft::warning("Failed to delete source file {$sourcePath}: " . $e->getMessage(), __METHOD__);
                    }
                }

                $this->changeLogManager->logChange([
                    'type' => 'moved_from_optimised',
                    'assetId' => $asset->id,
                    'filename' => $filename,
                    'fromVolume' => $sourceVolume->id,
                    'fromLocation' => $sourceLocation,
                    'fromPath' => $sourcePath,
                    'toVolume' => $targetVolume->id,
                    'toPath' => $newPath
                ]);

                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }

                return [
                    'success' => true,
                    'file_found' => true,
                    'moved_from' => $sourceLocation
                ];

            } catch (\Exception $e) {
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
                throw $e;
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'file_found' => isset($fileInfo)
            ];
        }
    }

    /**
     * Cleanup physical file from optimisedImages after asset merge
     *
     * @param $optimisedVolume Optimised volume instance
     * @param string $filename Filename to cleanup
     * @param array $fileIndex File index
     */
    private function cleanupOptimisedFile($optimisedVolume, string $filename, array $fileIndex): void
    {
        $fileInfo = $fileIndex[$filename] ?? null;

        if (!$fileInfo || $fileInfo['volume'] !== 'optimisedImages') {
            return;
        }

        try {
            $fs = $fileInfo['fs'] ?? null;
            $path = $fileInfo['path'] ?? null;

            if (!$fs || !$path) {
                Craft::warning("Invalid file info for cleanup: missing fs or path", __METHOD__);
                return;
            }

            if ($fs->fileExists($path)) {
                $fs->deleteFile($path);
                Craft::info("Cleaned up file from optimisedImages after merge: {$path}", __METHOD__);
            }
        } catch (\Exception $e) {
            Craft::warning("Failed to cleanup file {$filename} from optimisedImages: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Detect if file is a transform
     *
     * Craft generates transforms in subdirectories with patterns like:
     * - _485x275_crop_center-center_none
     * - _34x17_crop_center-center
     * - _thumbnail
     *
     * @param string $filename Filename
     * @param string $path Full path
     * @return bool True if transform file
     */
    public function isTransformFile(string $filename, string $path): bool
    {
        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if (strpos($segment, '_') === 0) {
                foreach ($this->transformPatterns as $pattern) {
                    if (preg_match($pattern, '/' . $segment . '/')) {
                        return true;
                    }
                }

                if (strpos($segment, '_transforms') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if two filesystems create a nested scenario
     *
     * Uses the FilesystemNestingDetector to automatically determine if
     * filesystems share the same bucket with one being a parent of the other.
     *
     * @param \craft\base\FsInterface $sourceFs Source filesystem
     * @param \craft\base\FsInterface $targetFs Target filesystem
     * @return bool True if nested (requires temp file approach)
     */
    public function requiresTempFileApproach($sourceFs, $targetFs): bool
    {
        return $this->nestingDetector->isNestedFilesystem($sourceFs, $targetFs);
    }

    /**
     * Get diagnostic information about filesystem nesting
     *
     * Useful for debugging and understanding why temp file approach is needed
     *
     * @param \craft\base\FsInterface $sourceFs Source filesystem
     * @param \craft\base\FsInterface $targetFs Target filesystem
     * @return array Diagnostic information
     */
    public function getNestingDiagnostics($sourceFs, $targetFs): array
    {
        return $this->nestingDetector->getDiagnosticInfo($sourceFs, $targetFs);
    }
}
