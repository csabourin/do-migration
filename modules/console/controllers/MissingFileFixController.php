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
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("MISSING FILE ANALYSIS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // Get all volumes
        $volumesService = Craft::$app->getVolumes();
        $imagesVolume = $volumesService->getVolumeByHandle('images');
        $documentsVolume = $volumesService->getVolumeByHandle('documents');
        $quarantineVolume = $volumesService->getVolumeByHandle('quarantine');

        if (!$imagesVolume || !$documentsVolume) {
            $this->stderr("✗ Required volumes not found. Need 'images' and 'documents' volumes.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $this->stdout("Volumes:\n", Console::FG_YELLOW);
        $this->stdout("  Images: {$imagesVolume->name} (ID: {$imagesVolume->id})\n");
        $this->stdout("  Documents: {$documentsVolume->name} (ID: {$documentsVolume->id})\n");
        if ($quarantineVolume) {
            $this->stdout("  Quarantine: {$quarantineVolume->name} (ID: {$quarantineVolume->id})\n");
        }
        $this->stdout("\n");

        // Find all assets
        $this->stdout("Scanning for missing files...\n", Console::FG_YELLOW);

        $allAssets = Asset::find()->all();
        $missingFiles = [];
        $wrongVolumeFiles = [];

        foreach ($allAssets as $asset) {
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

        $this->stdout("\n");
        $this->stdout("Results:\n", Console::FG_CYAN);
        $this->stdout("  Total missing files: {$this->stats['total_missing']}\n", $this->stats['total_missing'] > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout("  Files in wrong volume: " . count($wrongVolumeFiles) . "\n", count($wrongVolumeFiles) > 0 ? Console::FG_YELLOW : Console::FG_GREEN);
        $this->stdout("\n");

        // Show missing files details
        if (!empty($missingFiles)) {
            $this->stdout("\nMissing Files:\n", Console::FG_RED);
            $this->stdout(str_repeat("-", 80) . "\n");

            foreach (array_slice($missingFiles, 0, 50) as $item) {
                $asset = $item['asset'];
                $this->stdout("  {$asset->filename} (ID: {$asset->id}, Volume: {$item['volume']}, Ext: {$item['extension']})\n");
                $this->stdout("    Expected: {$item['expected_path']}\n", Console::FG_GREY);
            }

            if (count($missingFiles) > 50) {
                $this->stdout("  ... and " . (count($missingFiles) - 50) . " more\n", Console::FG_GREY);
            }
        }

        // Check quarantine for missing files
        if ($quarantineVolume && !empty($missingFiles)) {
            $this->stdout("\nSearching quarantine for missing files...\n", Console::FG_YELLOW);
            $this->findInQuarantine($missingFiles, $quarantineVolume);
        }

        $this->stdout("\n");
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Fix missing file associations
     */
    public function actionFix(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("FIX MISSING FILE ASSOCIATIONS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->stdout("⚠ DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
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

        // Find assets with missing files in Images volume
        $this->stdout("Finding assets with missing files...\n", Console::FG_YELLOW);

        $documentsAssets = Asset::find()->volumeId($documentsVolume->id)->all();
        $this->stdout("Found " . count($documentsAssets) . " assets in Documents volume\n");

        // Get quarantine filesystem and list all files
        $quarantineFs = $quarantineVolume->getFs();
        $this->stdout("\nScanning quarantine for files...\n", Console::FG_YELLOW);

        $quarantineFiles = $this->listQuarantineFiles($quarantineFs);
        $this->stdout("Found " . count($quarantineFiles) . " files in quarantine\n\n");

        // Process each asset in Documents volume
        $this->stdout("Processing assets...\n", Console::FG_YELLOW);
        $processed = 0;
        $fixed = 0;
        $errors = 0;

        foreach ($documentsAssets as $asset) {
            $fs = $asset->getVolume()->getFs();
            $path = $asset->getPath();

            // Check if file exists
            if (!$fs->fileExists($path)) {
                $processed++;
                $this->stdout("\n[{$processed}] Missing: {$asset->filename} (ID: {$asset->id})\n", Console::FG_RED);
                $this->stdout("    Expected path: {$path}\n", Console::FG_GREY);

                // Try to find in quarantine
                $found = $this->findFileInQuarantine($asset->filename, $quarantineFiles);

                if ($found) {
                    $this->stdout("    ✓ Found in quarantine: {$found['path']}\n", Console::FG_GREEN);

                    if (!$this->dryRun) {
                        if ($this->moveFromQuarantine($asset, $found, $quarantineFs, $fs)) {
                            $fixed++;
                            $this->stdout("    ✓ Fixed!\n", Console::FG_GREEN);
                        } else {
                            $errors++;
                            $this->stderr("    ✗ Failed to move file\n", Console::FG_RED);
                        }
                    } else {
                        $this->stdout("    → Would move to: {$path}\n", Console::FG_CYAN);
                    }
                } else {
                    $this->stdout("    ✗ Not found in quarantine\n", Console::FG_YELLOW);
                }
            }
        }

        $this->stdout("\n");
        $this->stdout(str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("SUMMARY\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);
        $this->stdout("  Processed: {$processed}\n");
        $this->stdout("  Fixed: {$fixed}\n", $fixed > 0 ? Console::FG_GREEN : Console::FG_GREY);
        $this->stdout("  Errors: {$errors}\n", $errors > 0 ? Console::FG_RED : Console::FG_GREY);

        if ($this->dryRun) {
            $this->stdout("\n⚠ This was a dry run. Use --dryRun=0 to apply changes.\n\n", Console::FG_YELLOW);
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
        $quarantineAssets = Asset::find()->volumeId($quarantineVolume->id)->all();

        $this->stdout("  Quarantine contains " . count($quarantineAssets) . " assets\n");

        // Build a map of quarantine assets by filename
        $quarantineMap = [];
        foreach ($quarantineAssets as $qAsset) {
            $quarantineMap[$qAsset->filename] = $qAsset;
        }

        // Check for files in quarantine without asset records
        $found = 0;
        foreach ($missingFiles as $item) {
            $filename = $item['asset']->filename;

            if (isset($quarantineMap[$filename])) {
                $found++;
                $this->stats['found_in_quarantine']++;
            }
        }

        if ($found > 0) {
            $this->stdout("  ✓ Found {$found} missing files in quarantine with asset records\n", Console::FG_GREEN);
        } else {
            $this->stdout("  No missing files found in quarantine with asset records\n", Console::FG_GREY);
        }

        // Check for orphaned files (files without asset records)
        $this->stdout("\n  Checking for orphaned files in quarantine...\n");
        try {
            $fileList = $this->listQuarantineFiles($quarantineFs);
            $this->stdout("  Found " . count($fileList) . " physical files in quarantine\n");

            // Compare with asset records
            $orphanedFiles = [];
            foreach ($fileList as $file) {
                $filename = basename($file['path']);
                if (!isset($quarantineMap[$filename])) {
                    $orphanedFiles[] = $file;
                }
            }

            if (!empty($orphanedFiles)) {
                $this->stdout("  ⚠ Found " . count($orphanedFiles) . " orphaned files (no asset record)\n", Console::FG_YELLOW);

                // Check if any match our missing files
                $matched = 0;
                foreach ($missingFiles as $item) {
                    $filename = $item['asset']->filename;
                    foreach ($orphanedFiles as $orphan) {
                        if (basename($orphan['path']) === $filename) {
                            $matched++;
                            break;
                        }
                    }
                }

                if ($matched > 0) {
                    $this->stdout("  ✓ {$matched} orphaned files match missing asset filenames!\n", Console::FG_GREEN);
                    $this->stdout("    Run 'fix' action to reconnect them\n", Console::FG_CYAN);
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

        try {
            // List all files recursively
            $iterator = $quarantineFs->listContents('/', true);

            foreach ($iterator as $item) {
                if ($item['type'] === 'file') {
                    $files[] = [
                        'path' => $item['path'],
                        'filename' => basename($item['path']),
                        'size' => $item['size'] ?? 0
                    ];
                }
            }
        } catch (\Exception $e) {
            Craft::error("Error listing quarantine files: " . $e->getMessage(), __METHOD__);
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
}
