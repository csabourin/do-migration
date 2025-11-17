#!/usr/bin/env php
<?php
/**
 * Quick script to check volume filesystem paths and diagnose the missing files issue
 *
 * This will show you what subfolders your volumes are currently configured with
 * and help identify if the 'ncc-website-2/' prefix is missing.
 */

define('CRAFT_BASE_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

// Bootstrap Craft
require_once CRAFT_VENDOR_PATH . '/autoload.php';
$app = require CRAFT_BASE_PATH . '/bootstrap/app.php';
$app->run();

use Craft\Craft;

echo "\n";
echo "=================================================================\n";
echo " Volume Path Configuration Checker\n";
echo "=================================================================\n\n";

$volumesService = Craft::$app->getVolumes();
$volumes = $volumesService->getAllVolumes();

echo "Found " . count($volumes) . " volumes:\n\n";

foreach ($volumes as $volume) {
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "Volume: {$volume->name}\n";
    echo "  Handle: {$volume->handle}\n";

    try {
        $fs = $volume->getFs();
        echo "  Filesystem: {$fs->name}\n";
        echo "  Filesystem Handle: {$fs->handle}\n";

        // Get the subfolder/prefix
        $subfolder = '';
        if (method_exists($fs, 'getRootPath')) {
            $subfolder = Craft::parseEnv((string) $fs->getRootPath());
        } elseif (method_exists($fs, 'getSubfolder')) {
            $subfolder = Craft::parseEnv((string) $fs->getSubfolder());
        } elseif (property_exists($fs, 'subfolder')) {
            $subfolder = Craft::parseEnv((string) $fs->subfolder);
        }

        $subfolder = ltrim($subfolder, '/');

        echo "  Subfolder: '{$subfolder}'\n";

        // Check if it has the ncc-website-2 prefix
        if (empty($subfolder)) {
            echo "  ⚠️  WARNING: No subfolder configured (will scan bucket root)\n";
        } elseif (!str_starts_with($subfolder, 'ncc-website-2/')) {
            echo "  ❌ ISSUE: Missing 'ncc-website-2/' prefix!\n";
            echo "  💡 Should be: 'ncc-website-2/{$subfolder}'\n";
        } else {
            echo "  ✓ Looks correct!\n";
        }

        // Try to list a few files
        echo "  Scanning for files... ";
        try {
            $fileList = $fs->getFileList($subfolder, false);
            $count = 0;
            foreach ($fileList as $file) {
                $count++;
                if ($count >= 3) break;
            }
            if ($count > 0) {
                echo "✓ Found {$count}+ files\n";
            } else {
                echo "⚠️  No files found\n";
            }
        } catch (\Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }

    } catch (\Exception $e) {
        echo "  ❌ Error getting filesystem: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

echo "=================================================================\n";
echo " Recommendations:\n";
echo "=================================================================\n\n";

$needsFix = false;
foreach ($volumes as $volume) {
    try {
        $fs = $volume->getFs();
        $subfolder = '';
        if (method_exists($fs, 'getSubfolder')) {
            $subfolder = Craft::parseEnv((string) $fs->getSubfolder());
        } elseif (property_exists($fs, 'subfolder')) {
            $subfolder = Craft::parseEnv((string) $fs->subfolder);
        }
        $subfolder = ltrim($subfolder, '/');

        if (!empty($subfolder) && !str_starts_with($subfolder, 'ncc-website-2/')) {
            if (!$needsFix) {
                echo "The following volumes need their subfolder updated:\n\n";
                $needsFix = true;
            }
            echo "  {$volume->handle}:\n";
            echo "    Current:  '{$subfolder}'\n";
            echo "    Update to: 'ncc-website-2/{$subfolder}'\n\n";
        }
    } catch (\Exception $e) {
        // Skip
    }
}

if (!$needsFix) {
    echo "✓ All volumes appear to be configured correctly!\n\n";
} else {
    echo "How to fix:\n";
    echo "  1. Go to Craft CP → Settings → Assets → Filesystems\n";
    echo "  2. Edit each filesystem above\n";
    echo "  3. Update the 'Subfolder' field with the new value\n";
    echo "  4. Save and re-run this script to verify\n\n";
}

echo "After fixing, re-run migration:\n";
echo "  ./craft s3-spaces-migration/image-migration/migrate\n\n";
