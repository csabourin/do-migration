<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\BaseConsoleController;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Missing File Fix Controller
 *
 * Fixes missing file associations by:
 * - Finding assets with missing physical files
 * - Locating files in quarantine or other locations
 * - Moving files to correct volumes based on file type
 * - Updating database records
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class MissingFileFixController extends BaseConsoleController
{
    public $defaultAction = 'analyze';

    /**
     * @var bool Whether to run in dry-run mode
     */
    public $dryRun = true;

    /**
     * @var bool Skip all confirmation prompts (for automation)
     */
    public $yes = false;

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * @var array Statistics
     */
    private $stats = [
        'total_missing' => 0,
        'found_in_quarantine' => 0,
        'found_in_wrong_volume' => 0,
        'fixed' => 0,
        'errors' => 0
    ];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();
    }

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'dryRun';
        $options[] = 'yes';
        return $options;
    }

    /**
     * Analyze missing files and their locations
     */
    public function actionAnalyze(): int
    {
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("MISSING FILE ANALYSIS\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // Get all volumes
        $volumesService = Craft::$app->getVolumes();
        $imagesVolume = $volumesService->getVolumeByHandle('images');
        $documentsVolume = $volumesService->getVolumeByHandle('documents');
        $quarantineVolume = $volumesService->getVolumeByHandle('quarantine');

        if (!$imagesVolume || !$documentsVolume) {
            $this->stderr("✗ Required volumes not found. Need 'images' and 'documents' volumes.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $this->output("Volumes:\n", Console::FG_YELLOW);
        $this->output("  Images: {$imagesVolume->name} (ID: {$imagesVolume->id})\n");
        $this->output("  Documents: {$documentsVolume->name} (ID: {$documentsVolume->id})\n");
        if ($quarantineVolume) {
            $this->output("  Quarantine: {$quarantineVolume->name} (ID: {$quarantineVolume->id})\n");
        }
        $this->output("\n");

        // Find all assets
        $this->output("Counting assets...\n", Console::FG_YELLOW);
        $totalAssets = Asset::find()->count();
        $this->output("Found {$totalAssets} total assets to scan\n", Console::FG_CYAN);

        $this->output("\nScanning assets for missing files...\n", Console::FG_YELLOW);
        $this->output("Progress: ", Console::FG_CYAN);

        $missingFiles = [];
        $wrongVolumeFiles = [];
        $scanned = 0;
        $batchSize = 100;
        $offset = 0;

        while (true) {
            $assets = Asset::find()
                ->limit($batchSize)
                ->offset($offset)
                ->all();

            if (empty($assets)) {
                break;
            }

            foreach ($assets as $asset) {
                $scanned++;

                // Show progress every 100 assets
                if ($scanned % 100 === 0) {
                    $percent = round(($scanned / $totalAssets) * 100);
                    $this->output("\rProgress: {$scanned}/{$totalAssets} ({$percent}%) ", Console::FG_CYAN);
                }

                $fs = $asset->getVolume()->getFs();
                $path = $asset->getPath();

                // Check if file exists
                if (!$fs->fileExists($path)) {
                    $missingFiles[] = [
                        'asset' => $asset,
                        'expected_path' => $path,
                        'volume' => $asset->getVolume()->handle,
                        'extension' => strtolower($asset->getExtension())
                    ];
                    $this->stats['total_missing']++;
                } else {
                    // Check if file is in wrong volume based on extension
                    $extension = strtolower($asset->getExtension());
                    $shouldBeInDocuments = in_array($extension, ['pdf', 'doc', 'docx', 'zip', 'txt']);

                    if ($shouldBeInDocuments && $asset->volumeId === $imagesVolume->id) {
                        $wrongVolumeFiles[] = [
                            'asset' => $asset,
                            'current_volume' => 'images',
                            'correct_volume' => 'documents',
                            'extension' => $extension
                        ];
                    }
                }
            }

            $offset += $batchSize;
            gc_collect_cycles();
        }

        // Final progress update
        $this->output("\rProgress: {$totalAssets}/{$totalAssets} (100%) - Complete!   \n", Console::FG_GREEN);

        $this->output("\n");
        $this->output("Results:\n", Console::FG_CYAN);
        $this->output("  Total missing files: {$this->stats['total_missing']}\n", $this->stats['total_missing'] > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->output("  Files in wrong volume: " . count($wrongVolumeFiles) . "\n", count($wrongVolumeFiles) > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
        $this->output("\n");

        // Show missing files details
        if (!empty($missingFiles)) {
            $this->output("\nMissing Files:\n", Console::FG_RED);
            $this->output(str_repeat("-", 80) . "\n");

            foreach (array_slice($missingFiles, 0, 50) as $item) {
                $asset = $item['asset'];
                $this->output("  {$asset->filename} (ID: {$asset->id}, Volume: {$item['volume']}, Ext: {$item['extension']})\n");
                $this->output("    Expected: {$item['expected_path']}\n", Console::FG_GREY);
            }

            if (count($missingFiles) > 50) {
                $this->output("  ... and " . (count($missingFiles) - 50) . " more\n", Console::FG_GREY);
            }
        }

        // Check quarantine for missing files
        if ($quarantineVolume && !empty($missingFiles)) {
            $this->output("\nSearching quarantine for missing files...\n", Console::FG_YELLOW);
            $this->findInQuarantine($missingFiles, $quarantineVolume);
        }

        $this->output("\n");
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Fix missing file associations
     */
    public function actionFix(): int
    {
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("FIX MISSING FILE ASSOCIATIONS\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->output("⚠ DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        // Get volumes
        $volumesService = Craft::$app->getVolumes();
        $imagesVolume = $volumesService->getVolumeByHandle('images');
        $documentsVolume = $volumesService->getVolumeByHandle('documents');
        $quarantineVolume = $volumesService->getVolumeByHandle('quarantine');

        if (!$imagesVolume || !$documentsVolume || !$quarantineVolume) {
            $this->stderr("✗ Required volumes not found.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        // Find assets with missing files in Documents volume
        $this->output("Counting assets in Documents volume...\n", Console::FG_YELLOW);
        $totalDocs = Asset::find()->volumeId($documentsVolume->id)->count();
        $this->output("Found {$totalDocs} assets in Documents volume\n", Console::FG_CYAN);

        // Get quarantine filesystem and list all files
        $quarantineFs = $quarantineVolume->getFs();
        $this->output("\nScanning quarantine for files...\n", Console::FG_YELLOW);

        $quarantineFiles = $this->listQuarantineFiles($quarantineFs);
        $this->output("\n");

        // Process each asset in Documents volume
        $this->output("Processing assets...\n", Console::FG_YELLOW);
        $processed = 0;
        $fixed = 0;
        $errors = 0;

        $batchSize = 100;
        $offset = 0;

        while (true) {
            $documentsAssets = Asset::find()
                ->volumeId($documentsVolume->id)
                ->limit($batchSize)
                ->offset($offset)
                ->all();

            if (empty($documentsAssets)) {
                break;
            }

            foreach ($documentsAssets as $asset) {
                $fs = $asset->getVolume()->getFs();
                $path = $asset->getPath();

                // Check if file exists
                if (!$fs->fileExists($path)) {
                    $processed++;
                    $this->output("\n[{$processed}] Missing: {$asset->filename} (ID: {$asset->id})\n", Console::FG_RED);
                    $this->output("    Expected path: {$path}\n", Console::FG_GREY);

                    // Try to find in quarantine
                    $found = $this->findFileInQuarantine($asset->filename, $quarantineFiles);

                    if ($found) {
                        $this->output("    ✓ Found in quarantine: {$found['path']}\n", Console::FG_GREEN);

                        if (!$this->dryRun) {
                            if ($this->moveFromQuarantine($asset, $found, $quarantineFs, $fs)) {
                                $fixed++;
                                $this->output("    ✓ Fixed!\n", Console::FG_GREEN);
                            } else {
                                $errors++;
                                $this->stderr("    ✗ Failed to move file\n", Console::FG_RED);
                            }
                        } else {
                            $this->output("    → Would move to: {$path}\n", Console::FG_CYAN);
                        }
                    } else {
                        $this->output("    ✗ Not found in quarantine\n", Console::FG_YELLOW);
                    }
                }
            }

            $offset += $batchSize;
            gc_collect_cycles();
        }

        $this->output("\n");
        $this->output(str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("SUMMARY\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);
        $this->output("  Processed: {$processed}\n");
        $this->output("  Fixed: {$fixed}\n", $fixed > 0 ? Console::FG_GREEN : Console::FG_GREY);
        $this->output("  Errors: {$errors}\n", $errors > 0 ? Console::FG_RED : Console::FG_GREY);

        if ($this->dryRun) {
            $this->output("\n⚠ This was a dry run. Use --dryRun=0 to apply changes.\n\n", Console::FG_YELLOW);
        }

        $this->stdout("\n__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Find files in quarantine
     */
    private function findInQuarantine(array $missingFiles, $quarantineVolume): void
    {
        $quarantineFs = $quarantineVolume->getFs();

        // Use count() instead of loading all assets into memory
        $totalQuarantineAssets = Asset::find()->volumeId($quarantineVolume->id)->count();
        $this->output("  Quarantine contains " . $totalQuarantineAssets . " assets\n");

        // Check for files in quarantine - query database for each file instead of loading all
        $found = 0;
        foreach ($missingFiles as $item) {
            $filename = $item['asset']->filename;

            // Query database to check if this specific file exists in quarantine
            $existsInQuarantine = Asset::find()
                ->volumeId($quarantineVolume->id)
                ->filename($filename)
                ->exists();

            if ($existsInQuarantine) {
                $found++;
                $this->stats['found_in_quarantine']++;
            }
        }

        // Build quarantine map for orphaned files check using batch processing
        // This is needed for the orphaned files logic below
        $quarantineMap = $this->buildQuarantineMap($quarantineVolume->id);

        if ($found > 0) {
            $this->output("  ✓ Found {$found} missing files in quarantine with asset records\n", Console::FG_GREEN);
        } else {
            $this->output("  No missing files found in quarantine with asset records\n", Console::FG_GREY);
        }

        // Check for orphaned files (files without asset records)
        $this->output("\n  Checking for orphaned files in quarantine...\n");
        try {
            $fileList = $this->listQuarantineFiles($quarantineFs);

            // Compare with asset records
            $orphanedFiles = [];
            foreach ($fileList as $file) {
                $filename = basename($file['path']);
                if (!isset($quarantineMap[$filename])) {
                    $orphanedFiles[] = $file;
                }
            }

            if (!empty($orphanedFiles)) {
                $this->output("  ⚠ Found " . count($orphanedFiles) . " orphaned files (no asset record)\n", Console::FG_YELLOW);

                // Track orphaned file subfolders
                $orphanSubfolders = [];
                foreach ($orphanedFiles as $orphan) {
                    $subfolder = dirname($orphan['path']);
                    if (!isset($orphanSubfolders[$subfolder])) {
                        $orphanSubfolders[$subfolder] = 0;
                    }
                    $orphanSubfolders[$subfolder]++;
                }

                $this->output("\n  Orphaned file locations:\n", Console::FG_GREY);
                foreach ($orphanSubfolders as $subfolder => $count) {
                    $this->output("    {$subfolder}: {$count} files\n", Console::FG_GREY);
                }

                // Check if any match our missing files
                $matched = 0;
                $matchedPaths = [];
                foreach ($missingFiles as $item) {
                    $filename = $item['asset']->filename;
                    foreach ($orphanedFiles as $orphan) {
                        if (basename($orphan['path']) === $filename) {
                            $matched++;
                            $matchedPaths[] = "    • {$filename} → {$orphan['path']}";
                            break;
                        }
                    }
                }

                if ($matched > 0) {
                    $this->output("\n  ✓ {$matched} orphaned files match missing asset filenames!\n", Console::FG_GREEN);

                    // Show first few matches as examples
                    if (count($matchedPaths) <= 10) {
                        $this->output("\n  Matches found:\n", Console::FG_CYAN);
                        foreach ($matchedPaths as $match) {
                            $this->output("{$match}\n", Console::FG_GREY);
                        }
                    } else {
                        $this->output("\n  Sample matches:\n", Console::FG_CYAN);
                        foreach (array_slice($matchedPaths, 0, 10) as $match) {
                            $this->output("{$match}\n", Console::FG_GREY);
                        }
                        $this->output("    ... and " . (count($matchedPaths) - 10) . " more\n", Console::FG_GREY);
                    }

                    $this->output("\n    Run 'fix' action to reconnect them\n", Console::FG_CYAN);
                }
            }
        } catch (\Exception $e) {
            $this->stderr("  Error checking quarantine files: " . $e->getMessage() . "\n", Console::FG_RED);
        }
    }

    /**
     * List all files in quarantine
     */
    private function listQuarantineFiles($quarantineFs): array
    {
        $files = [];
        $count = 0;
        $subfolders = [];

        try {
            $this->output("  Scanning quarantine filesystem recursively...\n", Console::FG_CYAN);
            $this->output("  Progress: ", Console::FG_CYAN);

            // List all files recursively (including subfolders like "quarantined/", "orphaned/", etc.)
            $iterator = $quarantineFs->getFileList('', true);

            foreach ($iterator as $item) {
                // Extract file information from FsListing object
                $itemData = $this->extractFsListingData($item);

                // Skip directories
                if ($itemData['isDir']) {
                    continue;
                }

                // Only process files with valid paths
                if (!empty($itemData['path'])) {
                    $files[] = [
                        'path' => $itemData['path'],
                        'filename' => basename($itemData['path']),
                        'size' => $itemData['fileSize'] ?? 0
                    ];
                    $count++;

                    // Track subfolders
                    $subfolder = dirname($itemData['path']);
                    if ($subfolder !== '.' && $subfolder !== '/') {
                        if (!isset($subfolders[$subfolder])) {
                            $subfolders[$subfolder] = 0;
                        }
                        $subfolders[$subfolder]++;
                    }

                    // Show progress every 50 files
                    if ($count % 50 === 0) {
                        $this->output(".", Console::FG_CYAN);
                    }
                }
            }

            $this->output(" Done!\n", Console::FG_GREEN);
            $this->output("  Found {$count} files in quarantine\n", Console::FG_CYAN);

            // Show subfolder breakdown
            if (!empty($subfolders)) {
                $this->output("\n  Subfolder breakdown:\n", Console::FG_GREY);
                foreach ($subfolders as $subfolder => $fileCount) {
                    $this->output("    {$subfolder}: {$fileCount} files\n", Console::FG_GREY);
                }
            }
        } catch (\Exception $e) {
            Craft::error("Error listing quarantine files: " . $e->getMessage(), __METHOD__);
            $this->stderr("\n  Error: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        return $files;
    }

    /**
     * Find a file in quarantine by filename
     */
    private function findFileInQuarantine(string $filename, array $quarantineFiles): ?array
    {
        foreach ($quarantineFiles as $file) {
            if ($file['filename'] === $filename) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Move file from quarantine to correct location
     */
    private function moveFromQuarantine($asset, array $quarantineFile, $quarantineFs, $targetFs): bool
    {
        try {
            // Read file from quarantine
            $content = $quarantineFs->read($quarantineFile['path']);

            // Write to target location
            $targetPath = $asset->getPath();
            $targetFs->write($targetPath, $content, []);

            // Delete from quarantine
            $quarantineFs->delete($quarantineFile['path']);

            return true;
        } catch (\Exception $e) {
            Craft::error("Error moving file from quarantine: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Extract file listing data from FsListing object
     * Handles different formats returned by various filesystem implementations
     */
    private function extractFsListingData($item): array
    {
        $data = [
            'path' => '',
            'isDir' => false,
            'fileSize' => null,
        ];

        // Handle string format
        if (is_string($item)) {
            $data['path'] = $item;
            $data['isDir'] = substr($item, -1) === '/';
            return $data;
        }

        // Handle array format
        if (is_array($item)) {
            $data['path'] = $item['path'] ?? $item['uri'] ?? $item['key'] ?? '';
            $data['isDir'] = ($item['type'] ?? 'file') === 'dir';
            $data['fileSize'] = $item['fileSize'] ?? $item['size'] ?? null;
            return $data;
        }

        // Handle object format (FsListing, StorageAttributes, etc.)
        if (is_object($item)) {
            // Try to get path/uri
            if (method_exists($item, 'getUri')) {
                try {
                    $data['path'] = (string) $item->getUri();
                } catch (\Throwable $e) {
                    // Fallback to property if method fails
                }
            } elseif (method_exists($item, 'path')) {
                try {
                    $data['path'] = (string) $item->path();
                } catch (\Throwable $e) {
                    // Fallback
                }
            } elseif (property_exists($item, 'path')) {
                $data['path'] = (string) $item->path;
            } elseif (property_exists($item, 'uri')) {
                $data['path'] = (string) $item->uri;
            }

            // Try to determine if directory
            if (method_exists($item, 'getIsDir')) {
                try {
                    $data['isDir'] = (bool) $item->getIsDir();
                } catch (\Throwable $e) {
                    $data['isDir'] = $data['path'] ? substr($data['path'], -1) === '/' : false;
                }
            } elseif (method_exists($item, 'isDir')) {
                try {
                    $data['isDir'] = (bool) $item->isDir();
                } catch (\Throwable $e) {
                    $data['isDir'] = $data['path'] ? substr($data['path'], -1) === '/' : false;
                }
            } elseif (property_exists($item, 'type')) {
                $data['isDir'] = $item->type === 'dir';
            }

            // Try to get file size
            if (!$data['isDir'] && method_exists($item, 'getFileSize')) {
                try {
                    $data['fileSize'] = $item->getFileSize();
                } catch (\Throwable $e) {
                    // File size not available
                }
            } elseif (!$data['isDir'] && property_exists($item, 'fileSize')) {
                $data['fileSize'] = $item->fileSize;
            } elseif (!$data['isDir'] && property_exists($item, 'size')) {
                $data['fileSize'] = $item->size;
            }
        }

        return $data;
    }

    /**
     * Build quarantine asset map using batch processing to avoid memory exhaustion
     *
     * @param int $volumeId Quarantine volume ID
     * @return array Map of filename => Asset
     */
    private function buildQuarantineMap(int $volumeId): array
    {
        $quarantineMap = [];
        $batchSize = 100;
        $offset = 0;

        while (true) {
            $batch = Asset::find()
                ->volumeId($volumeId)
                ->limit($batchSize)
                ->offset($offset)
                ->all();

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $qAsset) {
                $quarantineMap[$qAsset->filename] = $qAsset;
            }

            $offset += $batchSize;

            // Free memory after each batch
            gc_collect_cycles();
        }

        return $quarantineMap;
    }
}
