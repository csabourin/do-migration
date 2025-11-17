#!/usr/bin/env php
<?php
/**
 * Diagnose why specific files exist in bucket but migration reports them as missing
 * This checks the asset database records vs. actual file locations
 */

define('CRAFT_BASE_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

// Bootstrap Craft
require_once CRAFT_VENDOR_PATH . '/autoload.php';
$app = require CRAFT_BASE_PATH . '/bootstrap/app.php';
$app->run();

use craft\Craft;

// Sample of missing files from user's list
$missingFiles = [
    '2025-26_UrbLab_Nov_Alexandre.jpg',
    'Wakefield-bridge-and-dam-1.JPG',
    'Westboro-beach-drone.JPG',
    'IMG_1880.jpg',
    'pont-alexandra-bridge.jpg',
    'Echo-6.jpg',
    '054A4456_LR.jpg',
    '12-18-York-Front.PNG',
    'Open-NCC-main-image-web-header-v2.jpg',
    '210laurier.jpg',
];

echo "\n=================================================================\n";
echo " Asset-File Mismatch Diagnostic\n";
echo "=================================================================\n\n";

echo "Analyzing why files exist in bucket but can't be matched to assets...\n\n";

foreach ($missingFiles as $filename) {
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "File: {$filename}\n";
    echo "─────────────────────────────────────────────────────────────────\n";

    // Find asset record(s) for this filename
    $assets = \craft\elements\Asset::find()
        ->filename($filename)
        ->all();

    if (empty($assets)) {
        echo "  ❌ NO ASSET RECORD FOUND IN DATABASE\n";
        echo "  → File exists in bucket but has no Craft asset record\n";
        echo "  → Likely an orphaned file or never imported into Craft\n\n";
        continue;
    }

    foreach ($assets as $asset) {
        echo "  Asset ID: {$asset->id}\n";
        echo "  Title: {$asset->title}\n";
        echo "  Filename: {$asset->filename}\n";

        try {
            $volume = $asset->getVolume();
            echo "  Volume: {$volume->name} (ID: {$volume->id})\n";
            echo "  Volume Handle: {$volume->handle}\n";

            $fs = $volume->getFs();
            echo "  Filesystem: {$fs->name} (Handle: {$fs->handle})\n";

            // Get filesystem subfolder
            $subfolder = '';
            if (method_exists($fs, 'getSubfolder')) {
                $subfolder = Craft::parseEnv((string) $fs->getSubfolder());
            } elseif (property_exists($fs, 'subfolder')) {
                $subfolder = Craft::parseEnv((string) $fs->subfolder);
            }
            $subfolder = ltrim($subfolder, '/');

            echo "  FS Subfolder: '{$subfolder}'\n";

            // Get asset's folder path
            $folder = $asset->getFolder();
            $folderPath = $folder->path ?? '';
            echo "  Asset Folder Path: '{$folderPath}'\n";

            // Construct expected path
            $expectedPath = $subfolder;
            if ($folderPath && $folderPath !== '') {
                $expectedPath .= ($expectedPath ? '/' : '') . trim($folderPath, '/');
            }
            $expectedPath .= ($expectedPath ? '/' : '') . $filename;

            echo "  Expected File Path: '{$expectedPath}'\n";

            // Check if file exists at expected path
            try {
                $exists = $fs->fileExists($expectedPath);
                if ($exists) {
                    echo "  ✓ File EXISTS at expected path\n";
                    echo "  → Migration should find this file!\n";
                } else {
                    echo "  ❌ File NOT FOUND at expected path\n";
                    echo "  → Checking alternate locations...\n";

                    // Check common alternate paths
                    $alternatePaths = [
                        "medias/images/{$filename}",
                        "medias/images/originals/{$filename}",
                        "medias/originals/{$filename}",
                        "medias/{$filename}",
                        "images/{$filename}",
                        "images/originals/{$filename}",
                        "originals/{$filename}",
                        $filename,
                    ];

                    foreach ($alternatePaths as $altPath) {
                        try {
                            if ($fs->fileExists($altPath)) {
                                echo "  ✓ FOUND at: '{$altPath}'\n";
                            }
                        } catch (\Exception $e) {
                            // Skip
                        }
                    }
                }
            } catch (\Exception $e) {
                echo "  ⚠ Error checking file: " . $e->getMessage() . "\n";
            }

            // Check asset URI/path
            echo "  Asset URI: {$asset->uri}\n";
            echo "  Asset Path: {$asset->path}\n";

        } catch (\Exception $e) {
            echo "  ❌ Error: " . $e->getMessage() . "\n";
        }
    }

    echo "\n";
}

echo "=================================================================\n";
echo " COMMON ISSUES & SOLUTIONS\n";
echo "=================================================================\n\n";

echo "Issue 1: Asset record points to wrong volume\n";
echo "  → Asset DB says volume=X but file is in volume=Y\n";
echo "  → Solution: Update asset volume_id in database\n\n";

echo "Issue 2: Asset folderPath doesn't match file location\n";
echo "  → Asset DB says folder='subfolder' but file is at root\n";
echo "  → Solution: Update asset folder_id in database\n\n";

echo "Issue 3: Filesystem subfolder misconfigured\n";
echo "  → Volume filesystem subfolder doesn't include 'medias/'\n";
echo "  → Solution: Update filesystem subfolder setting\n\n";

echo "Issue 4: File exists in multiple locations\n";
echo "  → File exists at both /medias/images/ and /medias/images/originals/\n";
echo "  → Migration might be checking wrong location first\n";
echo "  → Solution: Configure source volumes to scan all locations\n\n";

echo "Issue 5: No asset record (orphaned file)\n";
echo "  → File exists in bucket but never imported into Craft\n";
echo "  → Solution: These files will be quarantined as orphans\n\n";

echo "Next Steps:\n";
echo "1. Check which issue applies to your missing files\n";
echo "2. Fix asset records or volume configuration\n";
echo "3. Re-run migration\n\n";
