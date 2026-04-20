<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\BaseConsoleController;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\db\Query;
use yii\console\ExitCode;
use csabourin\spaghettiMigrator\helpers\DuplicateResolver;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;

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
    public $defaultAction = 'merge-optimized-to-images';

    /**
     * @var bool Dry run mode - preview changes without applying them
     */
    public $dryRun = false;

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
    public $volumeHandle = null;

    /**
     * @var bool Bypass the flattenable-volumes safety guard for flatten-to-root
     */
    public $force = false;

    /**
     * @var MigrationConfig
     */
    private MigrationConfig $config;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();
        if ($this->volumeHandle === null || $this->volumeHandle === '') {
            $this->volumeHandle = $this->config->getTargetVolumeHandle();
        }
    }

    private function getOptimisedHandle(): string
    {
        return $this->config->getOptimisedImagesVolumeHandle();
    }

    private function getTargetHandle(): string
    {
        return $this->config->getTargetVolumeHandle();
    }

    private function getQuarantineHandle(): string
    {
        return $this->config->getQuarantineVolumeHandle();
    }

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
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("MERGE OPTIMISEDIMAGES → IMAGES\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->output("MODE: DRY RUN - No changes will be made\n\n", Console::FG_YELLOW);
        } else {
            $this->output("MODE: LIVE - Changes will be applied\n\n", Console::FG_RED);
        }

        // Get volumes
        $volumesService = Craft::$app->getVolumes();

        $optimisedHandle = $this->getOptimisedHandle();
        $targetHandle = $this->getTargetHandle();
        $sourceVolume = $volumesService->getVolumeByHandle($optimisedHandle)
                     ?? ($optimisedHandle !== 'optimizedImages' ? $volumesService->getVolumeByHandle('optimizedImages') : null);
        $targetVolume = $volumesService->getVolumeByHandle($targetHandle);

        if (!$sourceVolume) {
            $this->output("✗ Source volume '{$optimisedHandle}' not found\n\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$targetVolume) {
            $this->output("✗ Target volume '{$targetHandle}' not found\n\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Resolve the DO filesystem for the optimised images volume.
        // The volume may still point to the AWS filesystem (not yet switched), so we
        // use the mapped DO filesystem handle from config rather than $sourceVolume->getFs().
        $optimisedDoFsHandle = $this->config->getOptimisedImagesFilesystemHandle();
        $optimisedDoFs = Craft::$app->getFs()->getFilesystemByHandle($optimisedDoFsHandle);
        if (!$optimisedDoFs) {
            $this->output("⚠ Configured DO filesystem '{$optimisedDoFsHandle}' not found — falling back to volume's current filesystem\n", Console::FG_YELLOW);
        } else {
            $this->output("Source Filesystem: {$optimisedDoFsHandle} (DO/target storage)\n");
        }

        // Get target root folder
        $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);
        if (!$targetRootFolder) {
            $this->output("✗ Could not find root folder for target volume '{$targetHandle}'\n\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Count assets to move - ONLY those with volumeId matching source volume
        $totalAssets = Asset::find()
            ->volumeId($sourceVolume->id)
            ->count();

        $this->output("Source Volume: {$sourceVolume->name} (handle: {$sourceVolume->handle}, ID: {$sourceVolume->id})\n");
        $this->output("Target Volume: {$targetVolume->name} (handle: {$targetVolume->handle}, ID: {$targetVolume->id})\n");
        $this->output("\n");
        $this->output("FILTER: Only assets with volumeId = {$sourceVolume->id} will be processed\n", Console::FG_YELLOW);
        $this->output("        (Files in filesystem that belong to other volumes will be ignored)\n", Console::FG_YELLOW);
        $this->output("\n");
        $this->output("Assets to move: {$totalAssets}\n\n");

        if ($totalAssets === 0) {
            $this->output("✓ No assets to move\n\n", Console::FG_GREEN);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        // Confirm if not in --yes mode
        if (!$this->dryRun && !$this->yes) {
            $this->output("This will move {$totalAssets} assets from '{$sourceVolume->handle}' to '{$targetVolume->handle}'.\n", Console::FG_YELLOW);
            $this->output("Are you sure you want to continue? (yes/no): ");

            $handle = fopen("php://stdin", "r");
            $line = trim(fgets($handle));
            fclose($handle);

            if (strtolower($line) !== 'yes') {
                $this->output("\nOperation cancelled\n\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }
            $this->output("\n");
        }

        // Process assets in batches
        $this->output("Processing assets...\n");
        $this->output("Legend: . = moved, m = merged, d = duplicate overwrite, w = missing file (volumeId updated), x = error\n");
        $this->output("Progress: ");

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
                        // but we still need to clean up the physical file from the configured optimised-images volume
                        if (!$this->dryRun) {
                            $sourceFs = $optimisedDoFs ?? $sourceVolume->getFs();
                            if ($sourceFs->fileExists($originalPath)) {
                                try {
                                    $sourceFs->deleteFile($originalPath);
                                    Craft::info(
                                        "Cleaned up file from {$sourceVolume->handle} after merge: {$originalPath}",
                                        __METHOD__
                                    );
                                } catch (\Exception $e) {
                                    Craft::warning(
                                        "Failed to delete file from {$sourceVolume->handle} after merge: {$originalPath} - " . $e->getMessage(),
                                        __METHOD__
                                    );
                                }
                            }
                        }
                        $this->output("m", Console::FG_CYAN);
                        $duplicatesResolved++;
                        $skipped++;
                        continue;
                    }

                    if (!$this->dryRun) {
                        // Move asset file physically and update database.
                        // Pass the DO filesystem override so files are read from DO/target
                        // storage rather than the volume's current (potentially AWS) filesystem.
                        $result = $this->moveAssetFile(
                            $asset,
                            $sourceVolume,
                            $targetVolume,
                            $targetRootFolder,
                            $optimisedDoFs ?: null
                        );

                        if ($result['success']) {
                            // Check if there's a warning (e.g., file not found but volumeId updated)
                            if (isset($result['warning'])) {
                                $this->output("w", Console::FG_YELLOW);
                                $missingFiles++;
                                Craft::info(
                                    "VolumeId updated for asset {$asset->id} ({$asset->filename}) but {$result['warning']}",
                                    __METHOD__
                                );
                            } else {
                                // Print 'd' for duplicate overwrite, '.' for normal move
                                if ($isOverwrite) {
                                    $this->output("d", Console::FG_YELLOW);
                                    $duplicatesResolved++;
                                } else {
                                    $this->output(".", Console::FG_GREEN);
                                }
                            }
                            $moved++;
                        } else {
                            $this->output("x", Console::FG_RED);
                            $errors++;
                            Craft::warning(
                                "Failed to move asset {$asset->id} ({$asset->filename}): {$result['error']}",
                                __METHOD__
                            );
                        }
                    } else {
                        // In dry run mode, show what would happen
                        if ($isOverwrite) {
                            $this->output("d", Console::FG_GREY);
                            $duplicatesResolved++;
                        } else {
                            $this->output(".", Console::FG_GREY);
                        }
                        $moved++;
                    }
                } catch (\Exception $e) {
                    $this->output("x", Console::FG_RED);
                    $errors++;
                    Craft::error("Error moving asset {$asset->id}: " . $e->getMessage(), __METHOD__);
                }

                // Show progress every 50 assets
                $processed = $moved + $errors + $skipped;
                if ($processed % 50 === 0) {
                    $this->output(" [{$processed}/{$totalAssets}]\n  ");
                }
            }

            $offset += $this->batchSize;
        }

        $this->output("\n\n");
        $this->output("Summary:\n");
        $this->output("  Moved: {$moved}\n", Console::FG_GREEN);
        if ($duplicatesResolved > 0) {
            $this->output("  Duplicates resolved: {$duplicatesResolved}\n", Console::FG_CYAN);
        }
        if ($missingFiles > 0) {
            $this->output("  Missing files (volumeId updated): {$missingFiles}\n", Console::FG_YELLOW);
        }
        if ($errors > 0) {
            $this->output("  Errors: {$errors}\n", Console::FG_RED);
        }
        if ($skipped > 0) {
            $this->output("  Skipped (merged into existing): {$skipped}\n", Console::FG_YELLOW);
        }

        if ($this->dryRun) {
            $this->output("\nTo apply changes, run with --dryRun=0\n\n", Console::FG_YELLOW);
        } else {
            $this->output("\n✓ Done! Assets moved from '{$sourceVolume->handle}' to '{$targetVolume->handle}'\n\n", Console::FG_GREEN);
            if ($missingFiles > 0) {
                $this->output("Note: {$missingFiles} assets had their volumeId updated but physical files were not found.\n", Console::FG_YELLOW);
                $this->output("      These can be handled by your missing file recovery process.\n", Console::FG_YELLOW);
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
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("FLATTEN SUBFOLDERS TO ROOT\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->output("MODE: DRY RUN - No changes will be made\n\n", Console::FG_YELLOW);
        } else {
            $this->output("MODE: LIVE - Changes will be applied\n\n", Console::FG_RED);
        }

        // Get volume
        $volumesService = Craft::$app->getVolumes();
        $volume = $volumesService->getVolumeByHandle($this->volumeHandle);

        if (!$volume) {
            $this->output("✗ Volume '{$this->volumeHandle}' not found\n\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Safety guard: only permit volumes configured as flattenable
        $flattenable = $this->config->getFlattenableVolumes();
        if (!in_array($volume->handle, $flattenable, true) && !$this->force) {
            $this->stderr("✗ Volume '{$volume->handle}' is not in the flattenable volumes list.\n", Console::FG_RED);
            $this->stderr("  Flattenable: " . implode(', ', $flattenable) . "\n", Console::FG_RED);
            $this->stderr("  Flattening would destroy folder structure and break Craft-generated URLs.\n", Console::FG_RED);
            $this->stderr("  Pass --force=1 to override (use with extreme caution).\n", Console::FG_YELLOW);
            $this->stdout("__CLI_EXIT_CODE_78__\n");
            return ExitCode::CONFIG;
        }
        if (!in_array($volume->handle, $flattenable, true) && $this->force) {
            $this->output("⚠ --force override: flattening non-flattenable volume '{$volume->handle}'.\n", Console::FG_YELLOW);
            $this->output("  Folder structure will be destroyed.\n\n", Console::FG_YELLOW);
        }

        // Get root folder
        $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        if (!$rootFolder) {
            $this->output("✗ Could not find root folder for volume '{$this->volumeHandle}'\n\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Count assets in subfolders (not in root)
        $totalAssets = Asset::find()
            ->volumeId($volume->id)
            ->where(['not', ['folderId' => $rootFolder->id]])
            ->count();

        $this->output("Volume: {$volume->name} (handle: {$volume->handle})\n");
        $this->output("Assets in subfolders: {$totalAssets}\n\n");

        if ($totalAssets === 0) {
            $this->output("✓ No assets in subfolders to move\n\n", Console::FG_GREEN);
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

        $this->output("Folders to process:\n");
        foreach ($folders as $folder) {
            $count = Asset::find()
                ->volumeId($volume->id)
                ->folderId($folder['id'])
                ->count();

            if ($count > 0) {
                $this->output("  📁 {$folder['path']} ({$count} assets)\n");
            }
        }
        $this->output("\n");

        // Confirm if not in --yes mode
        if (!$this->dryRun && !$this->yes) {
            $this->output("This will move {$totalAssets} assets from subfolders to root in '{$this->volumeHandle}'.\n", Console::FG_YELLOW);
            $this->output("Are you sure you want to continue? (yes/no): ");

            $handle = fopen("php://stdin", "r");
            $line = trim(fgets($handle));
            fclose($handle);

            if (strtolower($line) !== 'yes') {
                $this->output("\nOperation cancelled\n\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }
            $this->output("\n");
        }

        // Process assets in batches
        $this->output("Processing assets...\n");
        $this->output("Progress: ");

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
                    // If asset is from a priority folder (e.g. 'originals'), it always wins
                    $priorityPatterns = $this->config->getPriorityFolderPatterns();
                    $sourceFolder = $asset->getFolder();
                    $isFromPriorityFolder = false;
                    if ($sourceFolder && !empty($sourceFolder->path)) {
                        foreach ($priorityPatterns as $pattern) {
                            if (stripos($sourceFolder->path, $pattern) !== false) {
                                $isFromPriorityFolder = true;
                                break;
                            }
                        }
                    }

                    // Resolve any duplicate filename collisions
                    // Priority-folder assets bypass the normal winner-selection logic
                    $resolution = DuplicateResolver::resolveFilenameCollision(
                        $asset,
                        $volume->id,
                        $rootFolder->id,
                        $this->dryRun,
                        $isFromPriorityFolder
                    );

                    // Track if this is a duplicate overwrite
                    $isOverwrite = ($resolution['action'] === 'overwrite');

                    if ($resolution['action'] === 'merge_into_existing') {
                        // Asset was merged into existing - skip moving
                        $this->output("m", Console::FG_CYAN);
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
                                $this->output("d", Console::FG_YELLOW);
                                $duplicatesResolved++;
                            } else {
                                $this->output(".", Console::FG_GREEN);
                            }
                            $moved++;
                        } else {
                            $this->output("x", Console::FG_RED);
                            $errors++;
                            Craft::warning(
                                "Failed to move asset {$asset->id} ({$asset->filename}) to root: {$result['error']}",
                                __METHOD__
                            );
                        }
                    } else {
                        // In dry run mode, show what would happen
                        if ($isOverwrite) {
                            $this->output("d", Console::FG_GREY);
                            $duplicatesResolved++;
                        } else {
                            $this->output(".", Console::FG_GREY);
                        }
                        $moved++;
                    }
                } catch (\Exception $e) {
                    $this->output("x", Console::FG_RED);
                    $errors++;
                    Craft::error("Error moving asset {$asset->id} to root: " . $e->getMessage(), __METHOD__);
                }

                // Show progress every 50 assets
                $processed = $moved + $errors + $skipped;
                if ($processed % 50 === 0) {
                    $this->output(" [{$processed}/{$totalAssets}]\n  ");
                }
            }

            $offset += $this->batchSize;
        }

        $this->output("\n\n");
        $this->output("Summary:\n");
        $this->output("  Moved: {$moved}\n", Console::FG_GREEN);
        if ($duplicatesResolved > 0) {
            $this->output("  Duplicates resolved: {$duplicatesResolved}\n", Console::FG_CYAN);
        }
        if ($errors > 0) {
            $this->output("  Errors: {$errors}\n", Console::FG_RED);
        }
        if ($skipped > 0) {
            $this->output("  Skipped: {$skipped}\n", Console::FG_YELLOW);
        }

        if ($this->dryRun) {
            $this->output("\nTo apply changes, run with --dryRun=0\n\n", Console::FG_YELLOW);
        } else {
            $this->output("\n✓ Done! Assets moved to root folder\n\n", Console::FG_GREEN);
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
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("VOLUME CONSOLIDATION STATUS\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $volumesService = Craft::$app->getVolumes();

        // Check OptimisedImages volume
        $optimisedHandle = $this->getOptimisedHandle();
        $targetHandle = $this->getTargetHandle();
        $optimisedVolume = $volumesService->getVolumeByHandle($optimisedHandle)
                        ?? ($optimisedHandle !== 'optimizedImages' ? $volumesService->getVolumeByHandle('optimizedImages') : null);

        if ($optimisedVolume) {
            $count = Asset::find()->volumeId($optimisedVolume->id)->count();
            $this->output("Optimised Images Volume:\n");
            $this->output("  Name: {$optimisedVolume->name}\n");
            $this->output("  Handle: {$optimisedVolume->handle}\n");
            $this->output("  Volume ID: {$optimisedVolume->id}\n");
            $this->output("  Assets with volumeId={$optimisedVolume->id}: {$count}\n");
            $this->output("  (Only assets with this volumeId will be migrated)\n", Console::FG_GREY);

            if ($count > 0) {
                $this->output("  Status: ", Console::FG_YELLOW);
                $this->output("⚠ Needs consolidation\n", Console::FG_YELLOW);
                $this->output("  Action: ./craft spaghetti-migrator/volume-consolidation/merge-optimized-to-images --dryRun=0\n\n");
            } else {
                $this->output("  Status: ✓ Empty\n\n", Console::FG_GREEN);
            }
        } else {
            $this->output("Optimised Images Volume: Not found\n\n");
        }

        // Check Images volume subfolders
        $imagesVolume = $volumesService->getVolumeByHandle($targetHandle);

        if ($imagesVolume) {
            $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($imagesVolume->id);
            $subfolderCount = Asset::find()
                ->volumeId($imagesVolume->id)
                ->where(['not', ['folderId' => $rootFolder->id]])
                ->count();

            $this->output("Target Volume:\n");
            $this->output("  Name: {$imagesVolume->name}\n");
            $this->output("  Handle: {$imagesVolume->handle}\n");
            $this->output("  Assets in subfolders: {$subfolderCount}\n");

            if ($subfolderCount > 0) {
                $this->output("  Status: ", Console::FG_YELLOW);
                $this->output("⚠ Has subfolders\n", Console::FG_YELLOW);
                $this->output("  Action: ./craft spaghetti-migrator/volume-consolidation/flatten-to-root --dryRun=0\n\n");
            } else {
                $this->output("  Status: ✓ All assets in root\n\n", Console::FG_GREEN);
            }
        } else {
            $this->output("Images Volume: Not found\n\n");
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
     * @param mixed|null $sourceFsOverride Filesystem to read from instead of the volume's own filesystem
     * @return array ['success' => bool, 'error' => string|null]
     */
    private function moveAssetFile($asset, $sourceVolume, $targetVolume, $targetFolder, $sourceFsOverride = null): array
    {
        try {
            // Get the current path before making any changes
            $oldPath = $asset->getPath();
            // Use the override when provided (e.g., DO filesystem even if volume still points to AWS)
            $sourceFs = $sourceFsOverride ?? $sourceVolume->getFs();
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
            $targetHandle = $targetVolume->handle;
            $quarantineHandle = $this->getQuarantineHandle();

            // 1. Check if source file exists in the configured optimised-images volume
            if ($sourceFs->fileExists($oldPath)) {
                $fileLocation = $sourceVolume->handle;
                $fileContent = $sourceFs->read($oldPath);
            } else {
                // 2. Check if file exists in the target volume
                if ($targetFs->fileExists($newPath)) {
                    $fileLocation = $targetHandle;
                    $fileContent = $targetFs->read($newPath);
                    Craft::info(
                        "File for asset {$asset->id} ({$asset->filename}) not found in {$sourceVolume->handle}, but found in {$targetVolume->handle}",
                        __METHOD__
                    );
                } else {
                    // 3. Check if file exists in quarantine volume
                    $quarantineVolume = Craft::$app->getVolumes()->getVolumeByHandle($quarantineHandle);
                    if ($quarantineVolume) {
                        $quarantineFs = $quarantineVolume->getFs();
                        if ($quarantineFs->fileExists($asset->filename)) {
                            $fileLocation = $quarantineHandle;
                            $fileContent = $quarantineFs->read($asset->filename);
                            Craft::info(
                                "File for asset {$asset->id} ({$asset->filename}) not found in {$sourceVolume->handle} or {$targetVolume->handle}, but found in {$quarantineHandle}",
                                __METHOD__
                            );
                        }
                    }
                }

                // 4. File not found anywhere - log but continue (volumeId is already updated)
                if ($fileContent === null) {
                    Craft::warning(
                        "File for asset {$asset->id} ({$asset->filename}) not found in any location ({$sourceVolume->handle}, {$targetHandle}, or {$quarantineHandle}). VolumeId updated but file is missing.",
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
            if ($fileLocation === $targetHandle && $targetFs->fileExists($newPath)) {
                // File already exists at destination and that's where we found it - no need to write
                Craft::info(
                    "Target file already exists at {$newPath} (file was already in {$targetHandle}), skipping file copy for asset {$asset->id}",
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
                    "Successfully moved file for asset {$asset->id} from {$fileLocation} to {$targetHandle}",
                    __METHOD__
                );
            }

            // Delete source file from its original location (if different from target)
            // Only delete if we actually wrote the file to a new location
            if ($fileLocation === $sourceVolume->handle && $sourceFs->fileExists($oldPath)) {
                try {
                    $sourceFs->deleteFile($oldPath);
                    Craft::info(
                        "Deleted source file from {$sourceVolume->handle}: {$oldPath}",
                        __METHOD__
                    );
                } catch (\Exception $e) {
                    // Log but don't fail - file was copied successfully
                    Craft::warning(
                        "Failed to delete source file {$oldPath} from {$sourceVolume->handle} after moving asset {$asset->id}: " . $e->getMessage(),
                        __METHOD__
                    );
                }
            } elseif ($fileLocation === $quarantineHandle) {
                try {
                    $quarantineVolume = Craft::$app->getVolumes()->getVolumeByHandle($quarantineHandle);
                    if ($quarantineVolume) {
                        $quarantineFs = $quarantineVolume->getFs();
                        $filename = $asset->filename;
                        if ($quarantineFs->fileExists($filename)) {
                            $quarantineFs->deleteFile($filename);
                            Craft::info(
                                "Deleted source file from {$quarantineHandle}: {$filename}",
                                __METHOD__
                            );
                        }
                    }
                } catch (\Exception $e) {
                    // Log but don't fail - file was copied successfully
                    Craft::warning(
                        "Failed to delete source file from {$quarantineHandle} after moving asset {$asset->id}: " . $e->getMessage(),
                        __METHOD__
                    );
                }
            }
            // If fileLocation was the target volume, no deletion needed as file is already in the destination

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
            $options[] = 'force';
        }

        return $options;
    }
}
