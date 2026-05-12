<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\DuplicateResolver;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\ChangeLogManager;
use csabourin\spaghettiMigrator\services\migration\FilesystemNestingDetector;
use csabourin\spaghettiMigrator\services\migration\InventoryBuilder;
use csabourin\spaghettiMigrator\services\migration\MigrationReporter;

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
     * @var MigrationConfig
     */
    private $config;

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
        $this->config = MigrationConfig::getInstance();
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
        $optimisedHandle = $this->config->getOptimisedImagesVolumeHandle();
        $targetHandle = $targetVolume->handle;
        $quarantineHandle = $quarantineVolume ? $quarantineVolume->handle : $this->config->getQuarantineVolumeHandle();

        $this->reporter->printPhaseHeader("PHASE 0.5: OPTIMISED IMAGES → IMAGES MIGRATION");

        $this->controller->stdout("  STRATEGY: Process ALL assets with volumeId={$optimisedVolume->id}\n", Console::FG_CYAN);
        $this->controller->stdout("  - Updates volumeId FIRST (database before files)\n", Console::FG_CYAN);
        $this->controller->stdout("  - Searches in multiple locations ({$optimisedHandle}, {$targetHandle}, {$quarantineHandle})\n", Console::FG_CYAN);
        $this->controller->stdout("  - Handles missing files gracefully (updates volumeId anyway)\n", Console::FG_CYAN);
        $this->controller->stdout("  - Uses DuplicateResolver for collision handling\n\n", Console::FG_CYAN);

        $volumesService = Craft::$app->getVolumes();
        $optimisedVolume = $volumesService->getVolumeByHandle($optimisedHandle);

        if (!$optimisedVolume) {
            $this->controller->stdout("  Skipping - {$optimisedHandle} volume not found\n\n");
            return ['total' => 0];
        }

        $this->controller->stdout("  Source: {$optimisedHandle} (Volume ID: {$optimisedVolume->id})\n");
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
            $this->controller->stdout("  This will migrate {$totalAssets} assets from {$optimisedHandle} to {$targetHandle}.\n", Console::FG_YELLOW);
            $this->controller->stdout("  All assets will have their volumeId updated, even if files are missing.\n\n", Console::FG_YELLOW);

            if (!$this->controller->confirm("Continue with migration?", true)) {
                $this->controller->stdout("  ⚠ Migration cancelled by user\n\n");
                return ['total' => 0, 'cancelled' => true];
            }
            $this->controller->stdout("\n");
        }

        // Build file index for quick lookup
        $fileIndex = $this->buildFileIndexForOptimisedMigration($optimisedVolume, $targetVolume, $quarantineVolume);

        // Safety check: if the file index is completely empty for all assets that need migrating,
        // this almost certainly means rclone has not yet copied the files to DO Spaces.
        // Proceeding would silently update volumeId for every asset without copying any file,
        // leaving them with broken references. Abort and require the operator to fix the sync first.
        if (!$this->dryRun) {
            $matchCount = 0;
            foreach ($optimisedAssets as $assetData) {
                if (isset($fileIndex[$assetData['filename']])) {
                    $matchCount++;
                }
            }

            if ($matchCount === 0) {
                $this->controller->stdout("\n", Console::FG_RED);
                $this->controller->stdout("  ✗ ABORTED: Phase 0.5 found 0 physical files for {$totalAssets} assets.\n", Console::FG_RED);
                $this->controller->stdout("  This almost certainly means the filesystem switch (Phase 2) ran before rclone\n", Console::FG_RED);
                $this->controller->stdout("  copied the '{$optimisedHandle}' files to DO Spaces.\n\n", Console::FG_RED);
                $this->controller->stdout("  TO FIX:\n", Console::FG_YELLOW);
                $this->controller->stdout("  1. Verify the rclone copy covered ALL files (check for flat files in bucket root)\n", Console::FG_YELLOW);
                $this->controller->stdout("  2. Re-run the rclone sync: check the 'Prerequisites' section of the dashboard\n", Console::FG_YELLOW);
                $this->controller->stdout("  3. Re-run this migration once the files are confirmed present in DO Spaces\n\n", Console::FG_YELLOW);
                throw new \RuntimeException(
                    "Phase 0.5 aborted: no physical files found for {$totalAssets} optimisedImages assets. " .
                    "Run rclone sync first (see dashboard Prerequisites), then retry."
                );
            }

            if ($matchCount < $totalAssets) {
                $missing = $totalAssets - $matchCount;
                $pct = round(($missing / $totalAssets) * 100);
                $this->controller->stdout("  ⚠ Warning: {$missing}/{$totalAssets} ({$pct}%) assets have no matching physical file.\n", Console::FG_YELLOW);
                $this->controller->stdout("  These will have their volumeId updated but no file will be copied.\n", Console::FG_YELLOW);
                $this->controller->stdout("  Consider running rclone check to verify DO Spaces is fully synced.\n\n", Console::FG_YELLOW);
            }
        }

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

                // Check for duplicate filename collision.
                // skipFileCopy=true: Phase 0.5 moves the winner's file itself via
                // migrateOptimisedAsset, so mergeAssets must not write to the winner's
                // (source) filesystem — which may be disconnected or wrong-direction.
                $resolution = DuplicateResolver::resolveFilenameCollision(
                    $asset,
                    $targetVolume->id,
                    $targetRootFolder->id,
                    $this->dryRun,
                    false,
                    null,
                    true
                );

                if ($resolution['action'] === 'merge_into_existing') {
                    $this->reporter->safeStdout("m", Console::FG_CYAN);
                    $stats['merged']++;

                    if (!$this->dryRun) {
                        // Remove the source root file via fileIndex if found there
                        $this->cleanupOptimisedFile($optimisedVolume, $filename, $fileIndex);
                        // Also attempt a direct delete from the optimisedImages filesystem
                        // by filename alone, covering cases where the fileIndex scan missed
                        // the root copy (e.g. disconnected S3 scan, partial previous run)
                        $this->deleteFromOptimisedFs($optimisedVolume, $filename);
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
            $processed = $stats['moved_with_file'] + $stats['volumeId_updated_missing_file'] + $stats['merged'] + $stats['duplicates_overwritten'] + $stats['errors'];
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
        $optimisedHandle = $optimisedVolume->handle;
        $targetHandle = $targetVolume->handle;
        $quarantineHandle = $quarantineVolume ? $quarantineVolume->handle : $this->config->getQuarantineVolumeHandle();

        $this->controller->stdout("  Building file index (scanning {$optimisedHandle}, {$targetHandle}, {$quarantineHandle})...\n");

        $fileIndex = [];
        $volumesToScan = [
            $optimisedHandle => $optimisedVolume,
            $targetHandle => $targetVolume,
            $quarantineHandle => $quarantineVolume
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

                    // Store first occurrence (priority: source optimised-images volume > target volume > quarantine)
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
            if ($sourceLocation === $targetVolume->handle && $targetFs->fileExists($newPath)) {
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
                if ($sourceLocation !== $targetVolume->handle) {
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

        if (!$fileInfo || $fileInfo['volume'] !== $optimisedVolume->handle) {
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
                Craft::info("Cleaned up file from {$optimisedVolume->handle} after merge: {$path}", __METHOD__);
            }
        } catch (\Exception $e) {
            Craft::warning("Failed to cleanup file {$filename} from {$optimisedVolume->handle}: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Delete a file directly from the optimisedImages volume filesystem by filename.
     *
     * Used as a fallback after cleanupOptimisedFile to handle root copies that the
     * fileIndex missed (disconnected original filesystem, partial previous run, etc.)
     *
     * @param $optimisedVolume Optimised volume instance
     * @param string $filename Bare filename to delete
     */
    private function deleteFromOptimisedFs($optimisedVolume, string $filename): void
    {
        try {
            $fs = $optimisedVolume->getFs();
            if ($fs->fileExists($filename)) {
                $fs->deleteFile($filename);
                Craft::info("Deleted root copy from {$optimisedVolume->handle}: {$filename}", __METHOD__);
            }
        } catch (\Exception $e) {
            Craft::warning(
                "Could not delete root copy {$filename} from {$optimisedVolume->handle}: " . $e->getMessage(),
                __METHOD__
            );
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
