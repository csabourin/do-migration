<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\BaseConsoleController;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\db\Query;
use yii\console\ExitCode;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;

/**
 * Post-Migration Diagnostic Controller
 * 
 * Analyze why migration showed 0 moved/quarantined/updated
 */
class MigrationDiagController extends BaseConsoleController
{
    public $defaultAction = 'analyze';

    /**
     * @var bool Whether to run in dry-run mode
     */
    public $dryRun = false;

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * Analyze current state after migration
     */
    public function actionAnalyze(): int
    {
        // Initialize configuration
        $this->config = MigrationConfig::getInstance();

        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("POST-MIGRATION DIAGNOSTIC\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // 1. Check volumes configuration
        $this->output("1. VOLUMES CONFIGURATION\n", Console::FG_YELLOW);
        $this->output(str_repeat("-", 80) . "\n");
        
        $volumesService = Craft::$app->getVolumes();
        $allVolumes = $volumesService->getAllVolumes();
        
        foreach ($allVolumes as $volume) {
            $assetCount = Asset::find()->volumeId($volume->id)->count();
            $this->output("\n  {$volume->name} (handle: {$volume->handle})\n", Console::FG_GREEN);
            $this->output("    Volume ID: {$volume->id}\n");
            $this->output("    Filesystem: {$volume->fsHandle}\n");
            $this->output("    Assets: {$assetCount}\n");
            
            // Check transform filesystem
            if (property_exists($volume, 'transformFs') && $volume->transformFs) {
                $this->output("    Transform FS: {$volume->transformFs}\n");
            } else {
                $this->output("    Transform FS: (same as volume)\n", Console::FG_GREY);
            }
        }

        // 2. Check folder structure
        $targetVolumeHandle = $this->config->getTargetVolumeHandle();
        $this->output("\n\n2. FOLDER STRUCTURE IN '{$targetVolumeHandle}' VOLUME\n", Console::FG_YELLOW);
        $this->output(str_repeat("-", 80) . "\n");

        $imagesVolume = $volumesService->getVolumeByHandle($targetVolumeHandle);
        if ($imagesVolume) {
            $folders = (new Query())
                ->select(['id', 'parentId', 'name', 'path'])
                ->from('{{%volumefolders}}')
                ->where(['volumeId' => $imagesVolume->id])
                ->orderBy(['path' => SORT_ASC])
                ->all();
            
            $this->output("\n  Found " . count($folders) . " folders:\n");
            foreach ($folders as $folder) {
                $assetCount = Asset::find()
                    ->volumeId($imagesVolume->id)
                    ->folderId($folder['id'])
                    ->count();
                
                $indent = str_repeat("  ", substr_count($folder['path'], '/'));
                $this->output("  {$indent}📁 {$folder['path']} ({$assetCount} assets)\n");
            }
        }

        // 3. Check for /originals folder (informational - this is normal in Craft)
        $this->output("\n\n3. CHECK FOR /originals FOLDER\n", Console::FG_YELLOW);
        $this->output(str_repeat("-", 80) . "\n");

        if ($imagesVolume) {
            $originalsFolder = (new Query())
                ->from('{{%volumefolders}}')
                ->where(['volumeId' => $imagesVolume->id])
                ->andWhere(['like', 'path', 'originals%', false])
                ->one();

            if ($originalsFolder) {
                $originalsCount = Asset::find()
                    ->volumeId($imagesVolume->id)
                    ->folderId($originalsFolder['id'])
                    ->count();

                $this->output("\n  ℹ Found 'originals' folder (normal for Craft volumes with transforms)\n", Console::FG_CYAN);
                $this->output("    Path: {$originalsFolder['path']}\n");
                $this->output("    Assets: {$originalsCount}\n");
                $this->output("\n  NOTE: Craft automatically creates /originals folders for volumes with image transforms.\n", Console::FG_GREY);
                $this->output("        This is expected behavior and not an error.\n", Console::FG_GREY);
            } else {
                $this->output("\n  No 'originals' folder found (volume may not have transforms configured)\n", Console::FG_GREY);
            }
        }

        // 4. Check why nothing was quarantined
        $this->output("\n\n4. QUARANTINE ANALYSIS\n", Console::FG_YELLOW);
        $this->output(str_repeat("-", 80) . "\n");

        $quarantineVolumeHandle = $this->config->getQuarantineVolumeHandle();
        $quarantineVolume = $volumesService->getVolumeByHandle($quarantineVolumeHandle);
        if ($quarantineVolume) {
            $quarantinedCount = Asset::find()->volumeId($quarantineVolume->id)->count();
            $this->output("\n  Quarantine volume: {$quarantineVolume->name}\n");
            $this->output("  Assets in quarantine: {$quarantinedCount}\n");
            
            if ($quarantinedCount === 0) {
                $this->output("\n  Possible reasons for 0 quarantined:\n", Console::FG_YELLOW);
                $this->output("    1. All assets are actually used (referenced somewhere)\n");
                $this->output("    2. Inline detection worked perfectly\n");
                $this->output("    3. No orphaned files were found\n");
            }
        }

        // 5. Check filesystem structure on DO
        $this->output("\n\n5. FILESYSTEM STRUCTURE ON DO\n", Console::FG_YELLOW);
        $this->output(str_repeat("-", 80) . "\n");
        
        $this->output("\n  Checking actual files on DO Spaces...\n");
        
        if ($imagesVolume) {
            try {
                $fs = $imagesVolume->getFs();
                $this->output("  Filesystem: " . get_class($fs) . "\n");
                
                // List top-level directories
                $this->output("\n  Top-level directories:\n");
                $iterator = $fs->getFileList('', false);
                $dirs = [];
                $files = 0;
                
                foreach ($iterator as $item) {
                    if (method_exists($item, 'isDir') && $item->isDir()) {
                        $path = method_exists($item, 'path') ? $item->path() : 
                               (property_exists($item, 'path') ? $item->path : 'unknown');
                        $dirs[] = $path;
                    } else {
                        $files++;
                    }
                }
                
                if (!empty($dirs)) {
                    foreach ($dirs as $dir) {
                        $this->output("    📁 {$dir}/\n", Console::FG_CYAN);
                    }
                }
                
                if ($files > 0) {
                    $this->output("    📄 {$files} files at root level\n", Console::FG_GREY);
                }
                
                // Check for _* directories (transforms)
                $this->output("\n  Checking for transform directories (_*):\n");
                $transformDirs = [];
                foreach ($dirs as $dir) {
                    if (strpos($dir, '_') === 0) {
                        $transformDirs[] = $dir;
                    }
                }
                
                if (!empty($transformDirs)) {
                    $this->output("    Found " . count($transformDirs) . " transform directories:\n", Console::FG_YELLOW);
                    foreach (array_slice($transformDirs, 0, 5) as $dir) {
                        $this->output("      - {$dir}\n", Console::FG_GREY);
                    }
                    if (count($transformDirs) > 5) {
                        $this->output("      ... and " . (count($transformDirs) - 5) . " more\n", Console::FG_GREY);
                    }
                }
                
            } catch (\Exception $e) {
                $this->stderr("  ✗ Error: " . $e->getMessage() . "\n", Console::FG_RED);
            }
        }

        // 6. Why were 0 files moved?
        $this->output("\n\n6. WHY 0 FILES MOVED?\n", Console::FG_YELLOW);
        $this->output(str_repeat("-", 80) . "\n");
        
        $this->output("\n  The migration script moves assets when:\n");
        $this->output("    1. Asset is in wrong volume (e.g., optimizedImages instead of images)\n");
        $this->output("    2. Asset is in wrong folder (e.g., not in root folder)\n");
        $this->output("\n  Likely reason for 0 moved:\n", Console::FG_CYAN);
        $this->output("    → Assets were already in correct volume AND correct folder\n");
        $this->output("    → This happens if volumes were already switched (Phase 3)\n");
        $this->output("    → Or if ImageMigrationController wasn't needed\n");

        // 7. Recommendations
        $this->output("\n\n7. RECOMMENDATIONS\n", Console::FG_YELLOW);
        $this->output(str_repeat("=", 80) . "\n\n");
        
        $recommendations = [];

        // NOTE: /originals folder is NOT checked here anymore - it's normal for Craft volumes with transforms

        // Check if transform filesystem is set
        $transformFsHandle = $this->config->getTransformFilesystemHandle();
        if ($imagesVolume && (!property_exists($imagesVolume, 'transformFs') || !$imagesVolume->transformFs)) {
            $recommendations[] = [
                'priority' => 'HIGH',
                'issue' => "Transform filesystem not configured for volume '{$targetVolumeHandle}'",
                'action' => "Configure volume '{$targetVolumeHandle}' to use '{$transformFsHandle}' for transforms"
            ];
        }
        
        // Check for transforms at root
        if (!empty($transformDirs)) {
            $recommendations[] = [
                'priority' => 'MEDIUM',
                'issue' => "Transform directories found at root (" . count($transformDirs) . ")",
                'action' => "Regenerate transforms after configuring transform filesystem"
            ];
        }
        
        // Check optimizedImages volume still exists
        $optimizedVolume = $volumesService->getVolumeByHandle('optimizedImages') ??
                          $volumesService->getVolumeByHandle('optimisedImages');
        if ($optimizedVolume) {
            $optimizedCount = Asset::find()->volumeId($optimizedVolume->id)->count();
            if ($optimizedCount > 0) {
                $recommendations[] = [
                    'priority' => 'HIGH',
                    'issue' => "Volume '{$optimizedVolume->handle}' still exists with {$optimizedCount} assets",
                    'action' => "./craft spaghetti-migrator/volume-consolidation/merge-optimized-to-images --dryRun=0"
                ];
            }
        }

        // Check for assets in subfolders (only flag as issue for flat structure volumes)
        if ($imagesVolume) {
            $imagesRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($imagesVolume->id);
            if ($imagesRootFolder) {
                $subfolderAssetCount = Asset::find()
                    ->volumeId($imagesVolume->id)
                    ->where(['not', ['folderId' => $imagesRootFolder->id]])
                    ->count();

                // Only flag as issue if this volume is configured as flat structure
                // Volumes with transforms (like images) naturally have subfolders (/originals, etc.)
                $flatStructureVolumes = $this->config->getFlatStructureVolumes();
                $isFlatStructureVolume = in_array($targetVolumeHandle, $flatStructureVolumes);

                if ($subfolderAssetCount > 0 && $isFlatStructureVolume) {
                    $recommendations[] = [
                        'priority' => 'HIGH',
                        'issue' => "Volume '{$targetVolumeHandle}' is configured as flat structure but has {$subfolderAssetCount} assets in subfolders",
                        'action' => "./craft spaghetti-migrator/volume-consolidation/flatten-to-root --volumeHandle={$targetVolumeHandle} --dryRun=0"
                    ];
                }
            }
        }
        
        if (empty($recommendations)) {
            $this->output("  ✓ No critical issues found!\n\n", Console::FG_GREEN);
        } else {
            foreach ($recommendations as $rec) {
                $color = $rec['priority'] === 'HIGH' ? Console::FG_RED : Console::FG_YELLOW;
                $this->output("  [{$rec['priority']}] {$rec['issue']}\n", $color);
                $this->output("      → {$rec['action']}\n", Console::FG_GREY);
                $this->output("\n");
            }
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Move assets from /originals to target volume
     *
     * IMPORTANT: This command is for EDGE CASES ONLY where user assets were incorrectly
     * placed in the /originals folder during migration. The /originals folder is normally
     * used by Craft for storing original versions of transformed images and should NOT be
     * emptied under normal circumstances.
     *
     * Only run this if you have verified that non-transform assets were mistakenly placed
     * in /originals during migration.
     */
    public function actionMoveOriginals(): int
    {
        // Initialize configuration
        $this->config = MigrationConfig::getInstance();
        $targetVolumeHandle = $this->config->getTargetVolumeHandle();

        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("MOVE ASSETS FROM /originals TO /{$targetVolumeHandle}\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $this->output("⚠ WARNING: This command is for EDGE CASES ONLY!\n", Console::FG_YELLOW);
        $this->output("The /originals folder is normally used by Craft for image transforms.\n", Console::FG_YELLOW);
        $this->output("Only proceed if you have verified that user assets were incorrectly placed there.\n\n", Console::FG_YELLOW);

        if ($this->dryRun) {
            $this->output("MODE: DRY RUN\n\n", Console::FG_YELLOW);
        } else {
            $this->output("MODE: LIVE - Changes will be made\n\n", Console::FG_RED);
        }

        $volumesService = Craft::$app->getVolumes();
        $imagesVolume = $volumesService->getVolumeByHandle($targetVolumeHandle);

        if (!$imagesVolume) {
            $this->stderr("✗ Volume '{$targetVolumeHandle}' not found!\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Find originals folder
        $originalsFolder = (new Query())
            ->from('{{%volumefolders}}')
            ->where(['volumeId' => $imagesVolume->id])
            ->andWhere(['like', 'path', 'originals%', false])
            ->one();
        
        if (!$originalsFolder) {
            $this->output("✓ No 'originals' folder found\n\n", Console::FG_GREEN);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        // Find or create images folder
        $imagesFolder = (new Query())
            ->from('{{%volumefolders}}')
            ->where(['volumeId' => $imagesVolume->id, 'name' => 'images'])
            ->one();
        
        if (!$imagesFolder) {
            $this->output("  Creating 'images' folder...\n");

            if (!$this->dryRun) {
                $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($imagesVolume->id);
                $newFolder = new \craft\models\VolumeFolder([
                    'volumeId' => $imagesVolume->id,
                    'parentId' => $rootFolder->id,
                    'name' => 'images',
                    'path' => 'images/',
                ]);
                
                if (Craft::$app->getAssets()->createFolder($newFolder)) {
                    $imagesFolder = [
                        'id' => $newFolder->id,
                        'name' => $newFolder->name,
                        'path' => $newFolder->path
                    ];
                    $this->output("  ✓ Created 'images' folder\n", Console::FG_GREEN);
                }
            }
        }

        // Get assets in originals folder
        $assets = Asset::find()
            ->volumeId($imagesVolume->id)
            ->folderId($originalsFolder['id'])
            ->all();
        
        $this->output("\n  Found " . count($assets) . " assets in /originals/\n");
        
        if (empty($assets)) {
            $this->output("  Nothing to move\n\n");
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        // Move assets
        $moved = 0;
        $errors = 0;
        
        $this->output("\n  Moving assets:\n");
        
        foreach ($assets as $asset) {
            $this->output("    {$asset->filename} ... ");

            if (!$this->dryRun && isset($imagesFolder['id'])) {
                try {
                    $asset->folderId = $imagesFolder['id'];
                    $asset->folderPath = 'images/';
                    
                    // Don't move the physical file (it's already in the right place on DO)
                    // Just update the Craft database
                    if (Craft::$app->getElements()->saveElement($asset, false)) {
                        $this->output("✓\n", Console::FG_GREEN);
                        $moved++;
                    } else {
                        $this->output("✗ " . implode(', ', $asset->getErrorSummary(true)) . "\n", Console::FG_RED);
                        $errors++;
                    }
                } catch (\Exception $e) {
                    $this->output("✗ " . $e->getMessage() . "\n", Console::FG_RED);
                    $errors++;
                }
            } else {
                $this->output("(dry-run)\n", Console::FG_GREY);
                $moved++;
            }
        }

        $this->output("\n  Summary:\n");
        $this->output("    Moved: {$moved}\n", Console::FG_GREEN);
        $this->output("    Errors: {$errors}\n", $errors > 0 ? Console::FG_RED : Console::FG_GREEN);

        // SECOND PASS: Check filesystems directly for remaining physical files
        $this->output("\n" . str_repeat("-", 80) . "\n", Console::FG_CYAN);
        $this->output("SECOND PASS: Checking filesystems for remaining originals files\n", Console::FG_CYAN);
        $this->output(str_repeat("-", 80) . "\n\n", Console::FG_CYAN);

        $fsResults = $this->moveOriginalsFromFilesystems($imagesVolume, $this->dryRun);

        $this->output("\n  Filesystem migration summary:\n");
        $this->output("    Files moved: {$fsResults['moved']}\n", Console::FG_GREEN);
        $this->output("    Overwritten (duplicates): {$fsResults['overwritten']}\n", Console::FG_YELLOW);
        $this->output("    Errors: {$fsResults['errors']}\n", $fsResults['errors'] > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->output("    Empty folders verified: {$fsResults['emptyFolders']}\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->output("\n  To execute, run: ./craft spaghetti-migrator/migration-diag/move-originals --dryRun=0\n\n");
        } else {
            $this->output("\n  ✓ Done! All originals moved from database and filesystems\n\n", Console::FG_GREEN);
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Second pass: Move physical originals files from all source filesystems
     *
     * This method checks all volumes' filesystems for remaining files in the originals folder
     * and moves them to the target volume. It allows overwrites since originals are highest quality.
     *
     * @param craft\models\Volume $targetVolume Target volume (images)
     * @param bool $dryRun Whether to run in dry-run mode
     * @return array Statistics about the operation
     */
    private function moveOriginalsFromFilesystems($targetVolume, bool $dryRun): array
    {
        $volumesService = Craft::$app->getVolumes();
        $allVolumes = $volumesService->getAllVolumes();

        $stats = [
            'moved' => 0,
            'overwritten' => 0,
            'errors' => 0,
            'emptyFolders' => 0,
            'skipped' => 0
        ];

        $targetFs = $targetVolume->getFs();

        $this->output("  Checking " . count($allVolumes) . " volume(s) for originals files...\n\n");

        foreach ($allVolumes as $volume) {
            try {
                $fs = $volume->getFs();
                $volumeInfo = "Volume '{$volume->handle}' (ID: {$volume->id})";

                $this->output("  📦 {$volumeInfo}\n", Console::FG_CYAN);

                // Check for files in originals folder
                $originalsFiles = $this->getFilesInOriginalsFolder($fs);

                if (empty($originalsFiles)) {
                    $this->output("     ✓ No files in originals folder\n", Console::FG_GREEN);
                    $stats['emptyFolders']++;
                    continue;
                }

                $this->output("     Found " . count($originalsFiles) . " file(s) in originals folder\n", Console::FG_YELLOW);

                // Move each file to target
                foreach ($originalsFiles as $filePath) {
                    $filename = basename($filePath);
                    $targetPath = $filename; // Move to root of target volume

                    $this->output("       {$filename} ... ");

                    if (!$dryRun) {
                        try {
                            // Read file from source
                            $fileContent = $fs->read($filePath);

                            if ($fileContent === false) {
                                $this->output("✗ Failed to read\n", Console::FG_RED);
                                $stats['errors']++;
                                continue;
                            }

                            // Check if target file exists
                            $targetExists = $targetFs->fileExists($targetPath);

                            // Write to target (overwrites if exists - originals are highest quality)
                            $targetFs->write($targetPath, $fileContent, []);

                            // Verify write
                            if (!$targetFs->fileExists($targetPath)) {
                                $this->output("✗ Failed to write\n", Console::FG_RED);
                                $stats['errors']++;
                                continue;
                            }

                            // Delete source file
                            try {
                                $fs->deleteFile($filePath);
                            } catch (\Exception $e) {
                                $this->output("⚠ Moved but couldn't delete source\n", Console::FG_YELLOW);
                                Craft::warning(
                                    "Failed to delete source file {$filePath} from volume {$volume->handle}: " . $e->getMessage(),
                                    __METHOD__
                                );
                            }

                            if ($targetExists) {
                                $this->output("✓ Overwritten\n", Console::FG_YELLOW);
                                $stats['overwritten']++;
                            } else {
                                $this->output("✓\n", Console::FG_GREEN);
                            }

                            $stats['moved']++;

                        } catch (\Exception $e) {
                            $this->output("✗ " . $e->getMessage() . "\n", Console::FG_RED);
                            $stats['errors']++;
                            Craft::error(
                                "Failed to move file {$filePath} from volume {$volume->handle}: " . $e->getMessage(),
                                __METHOD__
                            );
                        }
                    } else {
                        $this->output("(dry-run)\n", Console::FG_GREY);
                        $stats['moved']++;
                    }
                }

                // Verify folder is empty after migration (if not dry-run)
                if (!$dryRun) {
                    $remainingFiles = $this->getFilesInOriginalsFolder($fs);
                    if (empty($remainingFiles)) {
                        $this->output("     ✓ Originals folder is now empty\n", Console::FG_GREEN);
                        $stats['emptyFolders']++;
                    } else {
                        $this->output("     ⚠ Warning: " . count($remainingFiles) . " file(s) still remain\n", Console::FG_YELLOW);
                    }
                }

            } catch (\Exception $e) {
                $this->output("     ✗ Error accessing filesystem: " . $e->getMessage() . "\n", Console::FG_RED);
                Craft::error("Error checking volume {$volume->handle} for originals: " . $e->getMessage(), __METHOD__);
                $stats['errors']++;
            }

            $this->output("\n");
        }

        return $stats;
    }

    /**
     * Get all files in the originals folder from a filesystem
     *
     * @param craft\fs\Fs $fs Filesystem to check
     * @return array List of file paths in originals folder
     */
    private function getFilesInOriginalsFolder($fs): array
    {
        $files = [];

        try {
            // Check both "originals/" and "images/originals/" paths
            $pathsToCheck = ['originals/', 'images/originals/'];

            foreach ($pathsToCheck as $basePath) {
                if (!$fs->directoryExists($basePath)) {
                    continue;
                }

                // Get file list from the directory
                $fileIterator = $fs->getFileList($basePath, true); // recursive

                foreach ($fileIterator as $fileInfo) {
                    // Check if it's a file (not a directory)
                    if (method_exists($fileInfo, 'isDir') && $fileInfo->isDir()) {
                        continue;
                    }

                    // Get the file path
                    if (method_exists($fileInfo, 'path')) {
                        $path = $fileInfo->path();
                    } elseif (property_exists($fileInfo, 'path')) {
                        $path = $fileInfo->path;
                    } elseif (is_string($fileInfo)) {
                        $path = $fileInfo;
                    } else {
                        continue;
                    }

                    // Only include files that are actually in the originals path
                    if (strpos($path, 'originals/') !== false || strpos($path, 'originals\\') !== false) {
                        $files[] = $path;
                    }
                }
            }
        } catch (\Exception $e) {
            // If there's an error listing files, return empty array
            Craft::info("Could not list files in originals folder: " . $e->getMessage(), __METHOD__);
        }

        return $files;
    }

    /**
     * Check for missing files that caused errors
     */
    public function actionCheckMissingFiles(): int
    {
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("CHECK FOR MISSING FILES\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // Check console log for errors
        $logPath = Craft::getAlias('@storage/logs/console.log');
        
        if (!file_exists($logPath)) {
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            $this->output("No console log found at: {$logPath}\n\n");
            return ExitCode::OK;
        }

        $this->output("Analyzing: {$logPath}\n\n");
        
        // Read last 10000 lines
        $lines = [];
        $fp = fopen($logPath, 'r');
        if ($fp) {
            fseek($fp, -1, SEEK_END);
            $pos = ftell($fp);
            $lineCount = 0;
            $chunk = '';
            
            while ($pos > 0 && $lineCount < 10000) {
                fseek($fp, $pos, SEEK_SET);
                $char = fgetc($fp);
                if ($char === "\n") {
                    $lineCount++;
                    if ($chunk !== '') {
                        array_unshift($lines, $chunk);
                        $chunk = '';
                    }
                } else {
                    $chunk = $char . $chunk;
                }
                $pos--;
            }
            if ($chunk !== '') {
                array_unshift($lines, $chunk);
            }
            fclose($fp);
        }

        // Find error patterns
        $missingFiles = [];
        $errorPatterns = [
            '/File not found|does not exist|No such file|FileNotFoundException/i',
            '/Error.*asset.*\d+/i',
            '/Failed to.*file/i'
        ];

        foreach ($lines as $line) {
            foreach ($errorPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    // Extract asset ID or filename if possible
                    if (preg_match('/asset.*?(\d+)/i', $line, $matches)) {
                        $missingFiles[] = ['type' => 'asset', 'id' => $matches[1], 'line' => substr($line, 0, 150)];
                    } else if (preg_match('/([^\s]+\.(jpg|png|gif|pdf|jpeg))/i', $line, $matches)) {
                        $missingFiles[] = ['type' => 'file', 'name' => $matches[1], 'line' => substr($line, 0, 150)];
                    } else {
                        $missingFiles[] = ['type' => 'unknown', 'line' => substr($line, 0, 150)];
                    }
                    break;
                }
            }
        }

        if (empty($missingFiles)) {
            $this->output("✓ No missing file errors found in recent logs\n\n", Console::FG_GREEN);
        } else {
            $this->output("Found " . count($missingFiles) . " potential missing file errors:\n\n", Console::FG_YELLOW);
            
            foreach (array_slice($missingFiles, 0, 20) as $i => $error) {
                $this->output("  " . ($i + 1) . ". ");
                if ($error['type'] === 'asset') {
                    $this->output("Asset ID: {$error['id']}\n", Console::FG_CYAN);
                } else if ($error['type'] === 'file') {
                    $this->output("File: {$error['name']}\n", Console::FG_CYAN);
                }
                $this->output("     {$error['line']}\n\n", Console::FG_GREY);
            }
            
            if (count($missingFiles) > 20) {
                $this->output("  ... and " . (count($missingFiles) - 20) . " more\n\n");
            }
        }
        $this->stdout("__CLI_EXIT_CODE_0__\n");

        return ExitCode::OK;
    }

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'move-originals') {
            $options[] = 'dryRun';
        }
        return $options;
    }
}