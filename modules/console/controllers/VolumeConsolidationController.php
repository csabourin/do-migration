<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\BaseConsoleController;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\db\Query;
use yii\console\ExitCode;
use csabourin\spaghettiMigrator\helpers\DuplicateResolver;

/**
 * Volume Consolidation Controller
 *
 * Handles robust consolidation of assets from OptimisedImages → Images
 * and flattening of folder structures to root level.
 *
 * This controller addresses the edge case where OptimisedImages volume
 * points to the bucket root and contains all other volumes as subfolders.
 */
class VolumeConsolidationController extends BaseConsoleController
{
    public string $defaultAction = 'merge-optimized-to-images';

    /**
     * @var bool Dry run mode - preview changes without applying them
     */
    public $dryRun = true;

    /**
     * @var bool Skip all confirmation prompts
     */
    public $yes = false;

    /**
     * @var int Batch size for processing
     */
    public $batchSize = 100;

    /**
     * @var string Volume handle for operations (defaults to 'images')
     */
    public $volumeHandle = 'images';

    /**
     * Merge all assets from OptimisedImages volume into Images volume
     *
     * This command moves assets from OptimisedImages volume to Images volume based on
     * DATABASE ASSOCIATION (volumeId field), NOT filesystem location. This is crucial
     * because OptimisedImages may point to the bucket root containing all other volumes.
     *
     * IMPORTANT: Only assets with volumeId = OptimisedImages volume ID will be moved.
     * Files in the filesystem directory that belong to other volumes (based on their
     * volumeId in the database) will NOT be affected.
     *
     * Example usage:
     *   ./craft spaghetti-migrator/volume-consolidation/merge-optimized-to-images --dryRun=0
     */
    public function actionMergeOptimizedToImages(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("MERGE OPTIMISEDIMAGES → IMAGES\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->stdout("MODE: DRY RUN - No changes will be made\n\n", Console::FG_YELLOW);
        } else {
            $this->stdout("MODE: LIVE - Changes will be applied\n\n", Console::FG_RED);
        }

        // Get volumes
        $volumesService = Craft::$app->getVolumes();

        $sourceVolume = $volumesService->getVolumeByHandle('optimisedImages')
                     ?? $volumesService->getVolumeByHandle('optimizedImages');
        $targetVolume = $volumesService->getVolumeByHandle('images');

        if (!$sourceVolume) {
            $this->stdout("✗ Source volume 'optimisedImages' not found\n\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$targetVolume) {
            $this->stdout("✗ Target volume 'images' not found\n\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Get target root folder
        $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);
        if (!$targetRootFolder) {
            $this->stdout("✗ Could not find root folder for Images volume\n\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Count assets to move - ONLY those with volumeId matching source volume
        $totalAssets = Asset::find()
            ->volumeId($sourceVolume->id)
            ->count();

        $this->stdout("Source Volume: {$sourceVolume->name} (handle: {$sourceVolume->handle}, ID: {$sourceVolume->id})\n");
        $this->stdout("Target Volume: {$targetVolume->name} (handle: {$targetVolume->handle}, ID: {$targetVolume->id})\n");
        $this->stdout("\n");
        $this->stdout("FILTER: Only assets with volumeId = {$sourceVolume->id} will be processed\n", Console::FG_YELLOW);
        $this->stdout("        (Files in filesystem that belong to other volumes will be ignored)\n", Console::FG_YELLOW);
        $this->stdout("\n");
        $this->stdout("Assets to move: {$totalAssets}\n\n");

        if ($totalAssets === 0) {
            $this->stdout("✓ No assets to move\n\n", Console::FG_GREEN);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        // Confirm if not in --yes mode
        if (!$this->dryRun && !$this->yes) {
            $this->stdout("This will move {$totalAssets} assets from '{$sourceVolume->handle}' to '{$targetVolume->handle}'.\n", Console::FG_YELLOW);
            $this->stdout("Are you sure you want to continue? (yes/no): ");

            $handle = fopen("php://stdin", "r");
            $line = trim(fgets($handle));
            fclose($handle);

            if (strtolower($line) !== 'yes') {
                $this->stdout("\nOperation cancelled\n\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }
            $this->stdout("\n");
        }

        // Process assets in batches
        $this->stdout("Processing assets...\n");
        $this->stdout("Legend: . = moved, m = merged, d = duplicate overwrite, w = missing file (volumeId updated), x = error\n");
        $this->stdout("Progress: ");

        $moved = 0;
        $errors = 0;
        $skipped = 0;
        $missingFiles = 0;
        $duplicatesResolved = 0;
        $offset = 0;

        while ($offset < $totalAssets) {
            // CRITICAL: Only fetch assets where volumeId = source volume ID
            // This ensures we don't process files from other volumes that may exist
            // in the same filesystem directory (e.g., when OptimisedImages points to bucket root)
            $assets = Asset::find()
                ->volumeId($sourceVolume->id)
                ->limit($this->batchSize)
                ->offset($offset)
                ->all();

            if (empty($assets)) {
                break;
            }

            foreach ($assets as $asset) {
                try {
                    // Store original path before any modifications
                    $originalPath = $asset->getPath();

                    // Resolve any duplicate filename collisions
                    $resolution = DuplicateResolver::resolveFilenameCollision(
                        $asset,
                        $targetVolume->id,
                        $targetRootFolder->id,
                        $this->dryRun
                    );

                    // Track if this is a duplicate overwrite
                    $isOverwrite = ($resolution['action'] === 'overwrite');

                    if ($resolution['action'] === 'merge_into_existing') {
                        // Asset was merged into existing - the asset record has been deleted
                        // but we still need to clean up the physical file from optimisedImages
                        if (!$this->dryRun) {
                            $sourceFs = $sourceVolume->getFs();
                            if ($sourceFs->fileExists($originalPath)) {
                                try {
                                    $sourceFs->deleteFile($originalPath);
                                    Craft::info(
                                        "Cleaned up file from optimisedImages after merge: {$originalPath}",
                                        __METHOD__
                                    );
                                } catch (\Exception $e) {
                                    Craft::warning(
                                        "Failed to delete file from optimisedImages after merge: {$originalPath} - " . $e->getMessage(),
                                        __METHOD__
                                    );
                                }
                            }
                        }
                        $this->stdout("m", Console::FG_CYAN);
                        $duplicatesResolved++;
                        $skipped++;
                        continue;
                    }

                    if (!$this->dryRun) {
                        // Move asset file physically and update database
                        $result = $this->moveAssetFile(
                            $asset,
                            $sourceVolume,
                            $targetVolume,
                            $targetRootFolder
                        );

                        if ($result['success']) {
                            // Check if there's a warning (e.g., file not found but volumeId updated)
                            if (isset($result['warning'])) {
                                $this->stdout("w", Console::FG_YELLOW);
                                $missingFiles++;
                                Craft::info(
                                    "VolumeId updated for asset {$asset->id} ({$asset->filename}) but {$result['warning']}",
                                    __METHOD__
                                );
                            } else {
                                // Print 'd' for duplicate overwrite, '.' for normal move
                                if ($isOverwrite) {
                                    $this->stdout("d", Console::FG_YELLOW);
                                    $duplicatesResolved++;
                                } else {
                                    $this->stdout(".", Console::FG_GREEN);
                                }
                            }
                            $moved++;
                        } else {
                            $this->stdout("x", Console::FG_RED);
                            $errors++;
                            Craft::warning(
                                "Failed to move asset {$asset->id} ({$asset->filename}): {$result['error']}",
                                __METHOD__
                            );
                        }
                    } else {
                        // In dry run mode, show what would happen
                        if ($isOverwrite) {
                            $this->stdout("d", Console::FG_GREY);
                            $duplicatesResolved++;
                        } else {
                            $this->stdout(".", Console::FG_GREY);
                        }
                        $moved++;
                    }
                } catch (\Exception $e) {
                    $this->stdout("x", Console::FG_RED);
                    $errors++;
                    Craft::error("Error moving asset {$asset->id}: " . $e->getMessage(), __METHOD__);
                }

                // Show progress every 50 assets
                $processed = $moved + $errors + $skipped;
                if ($processed % 50 === 0) {
                    $this->stdout(" [{$processed}/{$totalAssets}]\n  ");
                }
            }

            $offset += $this->batchSize;
        }

        $this->stdout("\n\n");
        $this->stdout("Summary:\n");
        $this->stdout("  Moved: {$moved}\n", Console::FG_GREEN);
        if ($duplicatesResolved > 0) {
            $this->stdout("  Duplicates resolved: {$duplicatesResolved}\n", Console::FG_CYAN);
        }
        if ($missingFiles > 0) {
            $this->stdout("  Missing files (volumeId updated): {$missingFiles}\n", Console::FG_YELLOW);
        }
        if ($errors > 0) {
            $this->stdout("  Errors: {$errors}\n", Console::FG_RED);
        }
        if ($skipped > 0) {
            $this->stdout("  Skipped (merged into existing): {$skipped}\n", Console::FG_YELLOW);
        }

        if ($this->dryRun) {
            $this->stdout("\nTo apply changes, run with --dryRun=0\n\n", Console::FG_YELLOW);
        } else {
            $this->stdout("\n✓ Done! Assets moved from '{$sourceVolume->handle}' to '{$targetVolume->handle}'\n\n", Console::FG_GREEN);
            if ($missingFiles > 0) {
                $this->stdout("Note: {$missingFiles} assets had their volumeId updated but physical files were not found.\n", Console::FG_YELLOW);
                $this->stdout("      These can be handled by your missing file recovery process.\n", Console::FG_YELLOW);
            }
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Flatten all subfolders in Images volume to root
     *
     * This command moves ALL assets from all subfolders in the Images volume
     * to the root folder. This is useful for consolidating a complex folder
     * structure into a flat structure.
     *
     * Example usage:
     *   ./craft spaghetti-migrator/volume-consolidation/flatten-to-root --dryRun=0
     */
    public function actionFlattenToRoot(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("FLATTEN SUBFOLDERS TO ROOT\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->stdout("MODE: DRY RUN - No changes will be made\n\n", Console::FG_YELLOW);
        } else {
            $this->stdout("MODE: LIVE - Changes will be applied\n\n", Console::FG_RED);
        }

        // Get volume
        $volumesService = Craft::$app->getVolumes();
        $volume = $volumesService->getVolumeByHandle($this->volumeHandle);

        if (!$volume) {
            $this->stdout("✗ Volume '{$this->volumeHandle}' not found\n\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Get root folder
        $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        if (!$rootFolder) {
            $this->stdout("✗ Could not find root folder for volume '{$this->volumeHandle}'\n\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Count assets in subfolders (not in root)
        $totalAssets = Asset::find()
            ->volumeId($volume->id)
            ->where(['not', ['folderId' => $rootFolder->id]])
            ->count();

        $this->stdout("Volume: {$volume->name} (handle: {$volume->handle})\n");
        $this->stdout("Assets in subfolders: {$totalAssets}\n\n");

        if ($totalAssets === 0) {
            $this->stdout("✓ No assets in subfolders to move\n\n", Console::FG_GREEN);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        // Show folder structure
        $folders = (new Query())
            ->select(['id', 'name', 'path'])
            ->from('{{%volumefolders}}')
            ->where(['volumeId' => $volume->id])
            ->andWhere(['!=', 'id', $rootFolder->id])
            ->orderBy(['path' => SORT_ASC])
            ->all();

        $this->stdout("Folders to process:\n");
        foreach ($folders as $folder) {
            $count = Asset::find()
                ->volumeId($volume->id)
                ->folderId($folder['id'])
                ->count();

            if ($count > 0) {
                $this->stdout("  📁 {$folder['path']} ({$count} assets)\n");
            }
        }
        $this->stdout("\n");

        // Confirm if not in --yes mode
        if (!$this->dryRun && !$this->yes) {
            $this->stdout("This will move {$totalAssets} assets from subfolders to root in '{$this->volumeHandle}'.\n", Console::FG_YELLOW);
            $this->stdout("Are you sure you want to continue? (yes/no): ");

            $handle = fopen("php://stdin", "r");
            $line = trim(fgets($handle));
            fclose($handle);

            if (strtolower($line) !== 'yes') {
                $this->stdout("\nOperation cancelled\n\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }
            $this->stdout("\n");
        }

        // Process assets in batches
        $this->stdout("Processing assets...\n");
        $this->stdout("Progress: ");

        $moved = 0;
        $errors = 0;
        $skipped = 0;
        $duplicatesResolved = 0;
        $offset = 0;

        while ($offset < $totalAssets) {
            $assets = Asset::find()
                ->volumeId($volume->id)
                ->where(['not', ['folderId' => $rootFolder->id]])
                ->limit($this->batchSize)
                ->offset($offset)
                ->all();

            if (empty($assets)) {
                break;
            }

            foreach ($assets as $asset) {
                try {
                    // Resolve any duplicate filename collisions
                    $resolution = DuplicateResolver::resolveFilenameCollision(
                        $asset,
                        $volume->id,
                        $rootFolder->id,
                        $this->dryRun
                    );

                    // Track if this is a duplicate overwrite
                    $isOverwrite = ($resolution['action'] === 'overwrite');

                    if ($resolution['action'] === 'merge_into_existing') {
                        // Asset was merged into existing - skip moving
                        $this->stdout("m", Console::FG_CYAN);
                        $duplicatesResolved++;
                        $skipped++;
                        continue;
                    }

                    if (!$this->dryRun) {
                        // Move asset file physically from subfolder to root
                        $result = $this->moveAssetFileToFolder(
                            $asset,
                            $volume,
                            $rootFolder
                        );

                        if ($result['success']) {
                            // Print 'd' for duplicate overwrite, '.' for normal move
                            if ($isOverwrite) {
                                $this->stdout("d", Console::FG_YELLOW);
                                $duplicatesResolved++;
                            } else {
                                $this->stdout(".", Console::FG_GREEN);
                            }
                            $moved++;
                        } else {
                            $this->stdout("x", Console::FG_RED);
                            $errors++;
                            Craft::warning(
                                "Failed to move asset {$asset->id} ({$asset->filename}) to root: {$result['error']}",
                                __METHOD__
                            );
                        }
                    } else {
                        // In dry run mode, show what would happen
                        if ($isOverwrite) {
                            $this->stdout("d", Console::FG_GREY);
                            $duplicatesResolved++;
                        } else {
                            $this->stdout(".", Console::FG_GREY);
                        }
                        $moved++;
                    }
                } catch (\Exception $e) {
                    $this->stdout("x", Console::FG_RED);
                    $errors++;
                    Craft::error("Error moving asset {$asset->id} to root: " . $e->getMessage(), __METHOD__);
                }

                // Show progress every 50 assets
                $processed = $moved + $errors + $skipped;
                if ($processed % 50 === 0) {
                    $this->stdout(" [{$processed}/{$totalAssets}]\n  ");
                }
            }

            $offset += $this->batchSize;
        }

        $this->stdout("\n\n");
        $this->stdout("Summary:\n");
        $this->stdout("  Moved: {$moved}\n", Console::FG_GREEN);
        if ($duplicatesResolved > 0) {
            $this->stdout("  Duplicates resolved: {$duplicatesResolved}\n", Console::FG_CYAN);
        }
        if ($errors > 0) {
            $this->stdout("  Errors: {$errors}\n", Console::FG_RED);
        }
        if ($skipped > 0) {
            $this->stdout("  Skipped: {$skipped}\n", Console::FG_YELLOW);
        }

        if ($this->dryRun) {
            $this->stdout("\nTo apply changes, run with --dryRun=0\n\n", Console::FG_YELLOW);
        } else {
            $this->stdout("\n✓ Done! Assets moved to root folder\n\n", Console::FG_GREEN);
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Show status of volume consolidation needs
     *
     * Example usage:
     *   ./craft spaghetti-migrator/volume-consolidation/status
     */
    public function actionStatus(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("VOLUME CONSOLIDATION STATUS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $volumesService = Craft::$app->getVolumes();

        // Check OptimisedImages volume
        $optimisedVolume = $volumesService->getVolumeByHandle('optimisedImages')
                        ?? $volumesService->getVolumeByHandle('optimizedImages');

        if ($optimisedVolume) {
            $count = Asset::find()->volumeId($optimisedVolume->id)->count();
            $this->stdout("OptimisedImages Volume:\n");
            $this->stdout("  Name: {$optimisedVolume->name}\n");
            $this->stdout("  Handle: {$optimisedVolume->handle}\n");
            $this->stdout("  Volume ID: {$optimisedVolume->id}\n");
            $this->stdout("  Assets with volumeId={$optimisedVolume->id}: {$count}\n");
            $this->stdout("  (Only assets with this volumeId will be migrated)\n", Console::FG_GREY);

            if ($count > 0) {
                $this->stdout("  Status: ", Console::FG_YELLOW);
                $this->stdout("⚠ Needs consolidation\n", Console::FG_YELLOW);
                $this->stdout("  Action: ./craft spaghetti-migrator/volume-consolidation/merge-optimized-to-images --dryRun=0\n\n");
            } else {
                $this->stdout("  Status: ✓ Empty\n\n", Console::FG_GREEN);
            }
        } else {
            $this->stdout("OptimisedImages Volume: Not found\n\n");
        }

        // Check Images volume subfolders
        $imagesVolume = $volumesService->getVolumeByHandle('images');

        if ($imagesVolume) {
            $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($imagesVolume->id);
            $subfolderCount = Asset::find()
                ->volumeId($imagesVolume->id)
                ->where(['not', ['folderId' => $rootFolder->id]])
                ->count();

            $this->stdout("Images Volume:\n");
            $this->stdout("  Name: {$imagesVolume->name}\n");
            $this->stdout("  Handle: {$imagesVolume->handle}\n");
            $this->stdout("  Assets in subfolders: {$subfolderCount}\n");

            if ($subfolderCount > 0) {
                $this->stdout("  Status: ", Console::FG_YELLOW);
                $this->stdout("⚠ Has subfolders\n", Console::FG_YELLOW);
                $this->stdout("  Action: ./craft spaghetti-migrator/volume-consolidation/flatten-to-root --dryRun=0\n\n");
            } else {
                $this->stdout("  Status: ✓ All assets in root\n\n", Console::FG_GREEN);
            }
        } else {
            $this->stdout("Images Volume: Not found\n\n");
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Move asset file physically from source volume to target volume
     *
     * @param Asset $asset The asset to move
     * @param craft\models\Volume $sourceVolume Source volume
     * @param craft\models\Volume $targetVolume Target volume
     * @param craft\models\VolumeFolder $targetFolder Target folder
     * @return array ['success' => bool, 'error' => string|null]
     */
    private function moveAssetFile($asset, $sourceVolume, $targetVolume, $targetFolder): array
    {
        try {
            // Get the current path before making any changes
            $oldPath = $asset->getPath();
            $sourceFs = $sourceVolume->getFs();
            $targetFs = $targetVolume->getFs();

            // STEP 1: Update asset's volume and folder FIRST
            // This ensures the database is updated regardless of file operation success
            $asset->volumeId = $targetVolume->id;
            $asset->folderId = $targetFolder->id;

            // Save the asset record immediately to update the volumeId
            if (!Craft::$app->getElements()->saveElement($asset, false)) {
                $errorSummary = $asset->getErrorSummary(true);
                return [
                    'success' => false,
                    'error' => 'Failed to save asset record: ' . implode(', ', $errorSummary)
                ];
            }

            // STEP 2: Now handle file operations
            // Get the new path after updating volume/folder
            $newPath = $asset->filename; // Root folder = just filename

            // Try to find the physical file in multiple locations
            $fileContent = null;
            $fileLocation = null;

            // 1. Check if source file exists in optimisedImages
            if ($sourceFs->fileExists($oldPath)) {
                $fileLocation = 'optimisedImages';
                $fileContent = $sourceFs->read($oldPath);
            } else {
                // 2. Check if file exists in images volume (target)
                if ($targetFs->fileExists($newPath)) {
                    $fileLocation = 'images';
                    $fileContent = $targetFs->read($newPath);
                    Craft::info(
                        "File for asset {$asset->id} ({$asset->filename}) not found in {$sourceVolume->handle}, but found in {$targetVolume->handle}",
                        __METHOD__
                    );
                } else {
                    // 3. Check if file exists in quarantine volume
                    $quarantineVolume = Craft::$app->getVolumes()->getVolumeByHandle('quarantine');
                    if ($quarantineVolume) {
                        $quarantineFs = $quarantineVolume->getFs();
                        if ($quarantineFs->fileExists($asset->filename)) {
                            $fileLocation = 'quarantine';
                            $fileContent = $quarantineFs->read($asset->filename);
                            Craft::info(
                                "File for asset {$asset->id} ({$asset->filename}) not found in {$sourceVolume->handle} or {$targetVolume->handle}, but found in quarantine",
                                __METHOD__
                            );
                        }
                    }
                }

                // 4. File not found anywhere - log but continue (volumeId is already updated)
                if ($fileContent === null) {
                    Craft::warning(
                        "File for asset {$asset->id} ({$asset->filename}) not found in any location (optimisedImages, images, or quarantine). VolumeId updated but file is missing.",
                        __METHOD__
                    );
                    return [
                        'success' => true,
                        'error' => null,
                        'warning' => "VolumeId updated but file not found: {$asset->filename}"
                    ];
                }
            }

            // Check if we need to write the file to target
            if ($fileLocation === 'images' && $targetFs->fileExists($newPath)) {
                // File already exists at destination and that's where we found it - no need to write
                Craft::info(
                    "Target file already exists at {$newPath} (file was already in images), skipping file copy for asset {$asset->id}",
                    __METHOD__
                );
            } else {
                // Write file to target using Craft's filesystem API
                if ($fileContent === false) {
                    Craft::warning(
                        "Failed to read file from {$fileLocation} for asset {$asset->id}. VolumeId updated but file copy failed.",
                        __METHOD__
                    );
                    return [
                        'success' => true,
                        'error' => null,
                        'warning' => "VolumeId updated but failed to read file from {$fileLocation}"
                    ];
                }

                // Write the file to target (may overwrite if it exists but came from a different location)
                $targetFs->write($newPath, $fileContent, []);

                // Verify the file was written
                if (!$targetFs->fileExists($newPath)) {
                    Craft::warning(
                        "Failed to write target file: {$newPath} for asset {$asset->id}. VolumeId updated but file write failed.",
                        __METHOD__
                    );
                    return [
                        'success' => true,
                        'error' => null,
                        'warning' => "VolumeId updated but failed to write file to {$newPath}"
                    ];
                }

                Craft::info(
                    "Successfully moved file for asset {$asset->id} from {$fileLocation} to images",
                    __METHOD__
                );
            }

            // Delete source file from its original location (if different from target)
            // Only delete if we actually wrote the file to a new location
            if ($fileLocation === 'optimisedImages' && $sourceFs->fileExists($oldPath)) {
                try {
                    $sourceFs->deleteFile($oldPath);
                    Craft::info(
                        "Deleted source file from optimisedImages: {$oldPath}",
                        __METHOD__
                    );
                } catch (\Exception $e) {
                    // Log but don't fail - file was copied successfully
                    Craft::warning(
                        "Failed to delete source file {$oldPath} from optimisedImages after moving asset {$asset->id}: " . $e->getMessage(),
                        __METHOD__
                    );
                }
            } elseif ($fileLocation === 'quarantine') {
                try {
                    $quarantineVolume = Craft::$app->getVolumes()->getVolumeByHandle('quarantine');
                    if ($quarantineVolume) {
                        $quarantineFs = $quarantineVolume->getFs();
                        $filename = $asset->filename;
                        if ($quarantineFs->fileExists($filename)) {
                            $quarantineFs->deleteFile($filename);
                            Craft::info(
                                "Deleted source file from quarantine: {$filename}",
                                __METHOD__
                            );
                        }
                    }
                } catch (\Exception $e) {
                    // Log but don't fail - file was copied successfully
                    Craft::warning(
                        "Failed to delete source file from quarantine after moving asset {$asset->id}: " . $e->getMessage(),
                        __METHOD__
                    );
                }
            }
            // If fileLocation was 'images', no deletion needed as file is already in target location

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Move asset file physically to a different folder within the same volume
     *
     * @param Asset $asset The asset to move
     * @param craft\models\Volume $volume The volume
     * @param craft\models\VolumeFolder $targetFolder Target folder
     * @return array ['success' => bool, 'error' => string|null]
     */
    private function moveAssetFileToFolder($asset, $volume, $targetFolder): array
    {
        try {
            // Get the current path before making any changes
            $oldPath = $asset->getPath();
            $fs = $volume->getFs();

            // Check if source file exists
            if (!$fs->fileExists($oldPath)) {
                return [
                    'success' => false,
                    'error' => "Source file does not exist at path: {$oldPath}"
                ];
            }

            // Update asset's folder to calculate new path
            $asset->folderId = $targetFolder->id;

            // Get the new path after updating folder
            $newPath = $asset->filename; // Root folder = just filename

            // If paths are the same, just save the asset
            if ($oldPath === $newPath) {
                if (Craft::$app->getElements()->saveElement($asset, false)) {
                    return ['success' => true, 'error' => null];
                } else {
                    $errorSummary = $asset->getErrorSummary(true);
                    return [
                        'success' => false,
                        'error' => 'Failed to save asset record: ' . implode(', ', $errorSummary)
                    ];
                }
            }

            // Check if target file already exists
            if ($fs->fileExists($newPath)) {
                // File already exists at destination - this is OK, might be from earlier migration
                Craft::info(
                    "Target file already exists at {$newPath}, skipping file copy for asset {$asset->id}",
                    __METHOD__
                );
            } else {
                // Read file from source using Craft's filesystem API
                $fileContent = $fs->read($oldPath);

                if ($fileContent === false) {
                    return [
                        'success' => false,
                        'error' => "Failed to read source file: {$oldPath}"
                    ];
                }

                // Write file to new path using Craft's filesystem API
                $fs->write($newPath, $fileContent, []);

                // Verify the file was written
                if (!$fs->fileExists($newPath)) {
                    return [
                        'success' => false,
                        'error' => "Failed to write target file: {$newPath}"
                    ];
                }
            }

            // Save asset record using Craft's Elements service
            if (!Craft::$app->getElements()->saveElement($asset, false)) {
                $errorSummary = $asset->getErrorSummary(true);
                return [
                    'success' => false,
                    'error' => 'Failed to save asset record: ' . implode(', ', $errorSummary)
                ];
            }

            // Delete source file if different from target
            if ($oldPath !== $newPath && $fs->fileExists($oldPath)) {
                try {
                    $fs->deleteFile($oldPath);
                } catch (\Exception $e) {
                    // Log but don't fail - file was copied successfully
                    Craft::warning(
                        "Failed to delete source file {$oldPath} after moving asset {$asset->id}: " . $e->getMessage(),
                        __METHOD__
                    );
                }
            }

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Configure CLI options
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'merge-optimized-to-images') {
            $options[] = 'dryRun';
            $options[] = 'yes';
            $options[] = 'batchSize';
        }

        if ($actionID === 'flatten-to-root') {
            $options[] = 'volumeHandle';
            $options[] = 'dryRun';
            $options[] = 'yes';
            $options[] = 'batchSize';
        }

        return $options;
    }
}
