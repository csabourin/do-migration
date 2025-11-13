<?php
namespace csabourin\craftS3SpacesMigration\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\db\Query;
use yii\console\ExitCode;

/**
 * Volume Consolidation Controller
 *
 * Handles robust consolidation of assets from OptimisedImages → Images
 * and flattening of folder structures to root level.
 *
 * This controller addresses the edge case where OptimisedImages volume
 * points to the bucket root and contains all other volumes as subfolders.
 */
class VolumeConsolidationController extends Controller
{
    public $defaultAction = 'merge-optimized-to-images';

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
     * This command moves ALL assets from OptimisedImages volume to Images volume,
     * placing them in the root folder of Images. This is useful when OptimisedImages
     * was used as a bucket-root volume that should be consolidated.
     *
     * Example usage:
     *   ./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images --dryRun=0
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

        // Count assets to move
        $totalAssets = Asset::find()
            ->volumeId($sourceVolume->id)
            ->count();

        $this->stdout("Source Volume: {$sourceVolume->name} (handle: {$sourceVolume->handle})\n");
        $this->stdout("Target Volume: {$targetVolume->name} (handle: {$targetVolume->handle})\n");
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
        $this->stdout("Progress: ");

        $moved = 0;
        $errors = 0;
        $skipped = 0;
        $offset = 0;

        while ($offset < $totalAssets) {
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
                    // Check if asset with same filename already exists in target
                    $existingAsset = Asset::find()
                        ->volumeId($targetVolume->id)
                        ->folderId($targetRootFolder->id)
                        ->filename($asset->filename)
                        ->one();

                    if ($existingAsset && $existingAsset->id !== $asset->id) {
                        // Duplicate exists - rename this one
                        $this->stdout("d", Console::FG_YELLOW);

                        if (!$this->dryRun) {
                            $pathinfo = pathinfo($asset->filename);
                            $basename = $pathinfo['filename'];
                            $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
                            $counter = 1;

                            do {
                                $newFilename = $basename . '-' . $counter . $extension;
                                $existingAsset = Asset::find()
                                    ->volumeId($targetVolume->id)
                                    ->folderId($targetRootFolder->id)
                                    ->filename($newFilename)
                                    ->one();
                                $counter++;
                            } while ($existingAsset);

                            $asset->filename = $newFilename;
                        }
                    }

                    if (!$this->dryRun) {
                        // Move asset to target volume and root folder
                        $asset->volumeId = $targetVolume->id;
                        $asset->folderId = $targetRootFolder->id;

                        // Save without validating (skip validation to avoid file existence checks)
                        if (Craft::$app->getElements()->saveElement($asset, false)) {
                            $this->stdout(".", Console::FG_GREEN);
                            $moved++;
                        } else {
                            $this->stdout("x", Console::FG_RED);
                            $errors++;

                            // Log error details
                            $errorSummary = $asset->getErrorSummary(true);
                            Craft::warning(
                                "Failed to move asset {$asset->id} ({$asset->filename}): " . implode(', ', $errorSummary),
                                __METHOD__
                            );
                        }
                    } else {
                        $this->stdout(".", Console::FG_GREY);
                        $moved++;
                    }
                } catch (\Exception $e) {
                    $this->stdout("x", Console::FG_RED);
                    $errors++;
                    Craft::error("Error moving asset {$asset->id}: " . $e->getMessage(), __METHOD__);
                }

                // Show progress every 50 assets
                if (($moved + $errors + $skipped) % 50 === 0) {
                    $this->stdout(" [{$moved}/{$totalAssets}]\n  ");
                }
            }

            $offset += $this->batchSize;
        }

        $this->stdout("\n\n");
        $this->stdout("Summary:\n");
        $this->stdout("  Moved: {$moved}\n", Console::FG_GREEN);
        if ($errors > 0) {
            $this->stdout("  Errors: {$errors}\n", Console::FG_RED);
        }
        if ($skipped > 0) {
            $this->stdout("  Skipped: {$skipped}\n", Console::FG_YELLOW);
        }

        if ($this->dryRun) {
            $this->stdout("\nTo apply changes, run with --dryRun=0\n\n", Console::FG_YELLOW);
        } else {
            $this->stdout("\n✓ Done! Assets moved from '{$sourceVolume->handle}' to '{$targetVolume->handle}'\n\n", Console::FG_GREEN);
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
     *   ./craft s3-spaces-migration/volume-consolidation/flatten-to-root --dryRun=0
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
        $renamed = 0;
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
                    // Check if asset with same filename already exists in root
                    $existingAsset = Asset::find()
                        ->volumeId($volume->id)
                        ->folderId($rootFolder->id)
                        ->filename($asset->filename)
                        ->one();

                    if ($existingAsset && $existingAsset->id !== $asset->id) {
                        // Duplicate exists - rename this one
                        $this->stdout("d", Console::FG_YELLOW);

                        if (!$this->dryRun) {
                            $pathinfo = pathinfo($asset->filename);
                            $basename = $pathinfo['filename'];
                            $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
                            $counter = 1;

                            do {
                                $newFilename = $basename . '-' . $counter . $extension;
                                $existingAsset = Asset::find()
                                    ->volumeId($volume->id)
                                    ->folderId($rootFolder->id)
                                    ->filename($newFilename)
                                    ->one();
                                $counter++;
                            } while ($existingAsset);

                            $asset->filename = $newFilename;
                            $renamed++;
                        }
                    }

                    if (!$this->dryRun) {
                        // Move asset to root folder
                        $asset->folderId = $rootFolder->id;

                        // Save without validating (skip validation to avoid file existence checks)
                        if (Craft::$app->getElements()->saveElement($asset, false)) {
                            $this->stdout(".", Console::FG_GREEN);
                            $moved++;
                        } else {
                            $this->stdout("x", Console::FG_RED);
                            $errors++;

                            // Log error details
                            $errorSummary = $asset->getErrorSummary(true);
                            Craft::warning(
                                "Failed to move asset {$asset->id} ({$asset->filename}) to root: " . implode(', ', $errorSummary),
                                __METHOD__
                            );
                        }
                    } else {
                        $this->stdout(".", Console::FG_GREY);
                        $moved++;
                    }
                } catch (\Exception $e) {
                    $this->stdout("x", Console::FG_RED);
                    $errors++;
                    Craft::error("Error moving asset {$asset->id} to root: " . $e->getMessage(), __METHOD__);
                }

                // Show progress every 50 assets
                if (($moved + $errors + $skipped) % 50 === 0) {
                    $this->stdout(" [{$moved}/{$totalAssets}]\n  ");
                }
            }

            $offset += $this->batchSize;
        }

        $this->stdout("\n\n");
        $this->stdout("Summary:\n");
        $this->stdout("  Moved: {$moved}\n", Console::FG_GREEN);
        if ($renamed > 0) {
            $this->stdout("  Renamed (duplicates): {$renamed}\n", Console::FG_YELLOW);
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
     *   ./craft s3-spaces-migration/volume-consolidation/status
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
            $this->stdout("  Assets: {$count}\n");

            if ($count > 0) {
                $this->stdout("  Status: ", Console::FG_YELLOW);
                $this->stdout("⚠ Needs consolidation\n", Console::FG_YELLOW);
                $this->stdout("  Action: ./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images --dryRun=0\n\n");
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
                $this->stdout("  Action: ./craft s3-spaces-migration/volume-consolidation/flatten-to-root --dryRun=0\n\n");
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
