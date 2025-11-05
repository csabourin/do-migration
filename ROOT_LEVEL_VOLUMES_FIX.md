# Root-Level Volumes Configuration Issue - Analysis & Fix

**Issue Identified:** Configuration incorrectly lists `optimisedImages` as a root-level volume when it actually contains subfolders.

---

## 🔍 Problem Analysis

### **Current Configuration (INCORRECT)**

```php
'volumes' => [
    'rootLevel' => ['optimisedImages', 'chartData'],
],
```

### **Reality**

- **optimisedImages** volume structure:
  ```
  optimisedImages/
  ├── images/           ← Has subfolders!
  ├── optimizedImages/  ← Has subfolders!
  └── images-Winter/    ← Has subfolders!
  ```

- **chartData** volume structure:
  ```
  chartData/
  ├── file1.csv         ← No subfolders (truly root-level)
  ├── file2.json
  └── file3.xml
  ```

### **Impact**

The `handleOptimisedImagesAtRoot()` method (line 530) assumes ALL files in `optimisedImages` are at root level, which is incorrect.

**Current code behavior:**
```php
// Line 555: Scans recursively from root
$allFiles = $this->scanFilesystem($optimisedFs, '', true, null);

// Line 647-651: Assumes files are at root
$sourcePath = $file['path'];  // Could be "images/photo.jpg"
$targetPath = $asset->filename;  // Just "photo.jpg"

// This loses folder structure!
```

**Problems:**
1. ❌ Folder structure from subfolders is lost
2. ❌ Files in different subfolders with same name could conflict
3. ❌ Asset folder relationships may be incorrect
4. ❌ Configuration doesn't match actual structure

---

## ✅ Recommended Solution

### **Option 1: Fix Configuration (Recommended)**

Remove `optimisedImages` from `rootLevel` since it has subfolders:

```php
'volumes' => [
    // Source volumes for migration
    'source' => ['images', 'optimisedImages'],

    // Target volume (consolidated location)
    'target' => 'images',

    // Quarantine volume for unused/orphaned assets
    'quarantine' => 'quarantine',

    // Root-level volumes (no subfolders)
    'rootLevel' => ['chartData'],  // Only chartData is truly root-level
],
```

**AND** update the code to handle `optimisedImages` with its subfolder structure.

### **Option 2: Clarify Configuration Meaning**

If "root-level" means something different (like "exists at bucket root" vs "has no subfolders"), then:

1. Rename the config key to be clearer:
   ```php
   'atBucketRoot' => ['optimisedImages', 'chartData'],  // Files at bucket root
   'hasSubfolders' => ['images', 'optimisedImages'],    // Contains subfolders
   'flatStructure' => ['chartData'],                     // No subfolders
   ```

2. Update code to use appropriate key

---

## 🔧 Required Code Changes

### **Change 1: Update Configuration**

**File:** `config/migration-config.php`

```php
// Common settings
$commonConfig = [
    // ... other settings ...

    // Volume configurations
    'volumes' => [
        // Source volumes for migration
        'source' => ['images', 'optimisedImages'],

        // Target volume (consolidated location)
        'target' => 'images',

        // Quarantine volume for unused/orphaned assets
        'quarantine' => 'quarantine',

        // Volumes at bucket root (not in subfolders)
        'atBucketRoot' => ['optimisedImages', 'chartData'],

        // Volumes with subfolder structure
        'withSubfolders' => ['images', 'optimisedImages'],

        // Volumes with flat structure (no subfolders, truly root-level)
        'flatStructure' => ['chartData'],
    ],
];
```

### **Change 2: Update MigrationConfig Helper**

**File:** `MigrationConfig.php`

Add new getter methods:

```php
/**
 * Get volumes that exist at bucket root (vs in subfolders)
 */
public function getVolumesAtBucketRoot(): array
{
    return $this->get('volumes.atBucketRoot', ['optimisedImages', 'chartData']);
}

/**
 * Get volumes with subfolder structure
 */
public function getVolumesWithSubfolders(): array
{
    return $this->get('volumes.withSubfolders', ['images', 'optimisedImages']);
}

/**
 * Get volumes with flat structure (no subfolders)
 */
public function getFlatStructureVolumes(): array
{
    return $this->get('volumes.flatStructure', ['chartData']);
}

/**
 * Check if volume has subfolder structure
 */
public function volumeHasSubfolders(string $volumeHandle): bool
{
    return in_array($volumeHandle, $this->getVolumesWithSubfolders());
}
```

### **Change 3: Fix handleOptimisedImagesAtRoot Method**

**File:** `ImageMigrationController.php`

The method needs to preserve subfolder structure:

```php
private function handleOptimisedImagesAtRoot($assetInventory, $fileInventory, $targetVolume, $quarantineVolume)
{
    $this->printPhaseHeader("SPECIAL: OPTIMISED IMAGES MIGRATION");

    $volumesService = Craft::$app->getVolumes();
    $optimisedVolume = $volumesService->getVolumeByHandle('optimisedImages');

    if (!$optimisedVolume) {
        $this->stdout("  Skipping - optimisedImages volume not found\n\n");
        return;
    }

    $this->stdout("  Processing optimisedImages (with subfolder structure)...\n");

    // Get all assets from optimisedImages
    $optimisedAssets = array_filter($assetInventory, function($asset) use ($optimisedVolume) {
        return $asset['volumeId'] == $optimisedVolume->id;
    });

    $this->stdout("  Found " . count($optimisedAssets) . " assets in optimisedImages\n");

    // Get all files from optimisedImages filesystem (recursive to get subfolders)
    $optimisedFs = $optimisedVolume->getFs();
    $this->stdout("  Scanning filesystem (including subfolders)... ");

    $allFiles = $this->scanFilesystem($optimisedFs, '', true, null);
    $this->stdout("done (" . count($allFiles['all']) . " files)\n\n");

    // Group files by subfolder for reporting
    $filesByFolder = [];
    foreach ($allFiles['all'] as $file) {
        if ($file['type'] !== 'file') continue;

        $folder = dirname($file['path']);
        if ($folder === '.') $folder = 'root';

        if (!isset($filesByFolder[$folder])) {
            $filesByFolder[$folder] = 0;
        }
        $filesByFolder[$folder]++;
    }

    $this->stdout("  Files by folder:\n");
    foreach ($filesByFolder as $folder => $count) {
        $this->stdout("    - {$folder}: {$count} files\n");
    }
    $this->stdout("\n");

    // Build asset lookup by filename (considering folder context)
    $assetsByPath = [];
    $assetsByFilename = [];
    foreach ($optimisedAssets as $asset) {
        // Try to find asset by full path if folder info available
        if (!empty($asset['folder'])) {
            $fullPath = trim($asset['folder'], '/') . '/' . $asset['filename'];
            $assetsByPath[$fullPath] = $asset['id'];
        }

        // Also index by filename alone (fallback)
        if (!isset($assetsByFilename[$asset['filename']])) {
            $assetsByFilename[$asset['filename']] = [];
        }
        $assetsByFilename[$asset['filename']][] = $asset['id'];
    }

    // Categorize files (considering folder structure)
    $categories = [
        'linked_assets' => [],      // Files with asset records → move to images
        'transforms' => [],         // Transform files → move to imageTransforms or delete
        'orphans' => [],           // Files without assets → quarantine or leave
    ];

    $this->stdout("  Categorizing files...\n");

    foreach ($allFiles['all'] as $file) {
        if ($file['type'] !== 'file') continue;

        $filename = basename($file['path']);
        $filePath = $file['path'];

        // Is this a transform?
        if ($this->isTransformFile($filename, $filePath)) {
            $categories['transforms'][] = $file;
            continue;
        }

        // Try to match by full path first
        if (isset($assetsByPath[$filePath])) {
            $categories['linked_assets'][] = [
                'file' => $file,
                'assetId' => $assetsByPath[$filePath],
                'preserveFolder' => true,
            ];
            continue;
        }

        // Fallback: match by filename
        if (isset($assetsByFilename[$filename])) {
            $assetIds = $assetsByFilename[$filename];

            if (count($assetIds) === 1) {
                // Single match - safe to use
                $categories['linked_assets'][] = [
                    'file' => $file,
                    'assetId' => $assetIds[0],
                    'preserveFolder' => true,
                ];
            } else {
                // Multiple assets with same filename - potential conflict
                $this->stdout("    ⚠ Multiple assets with filename '{$filename}' - needs manual review\n", Console::FG_YELLOW);
                $categories['orphans'][] = $file;  // Treat as orphan for safety
            }
            continue;
        }

        // It's an orphan
        $categories['orphans'][] = $file;
    }

    $this->stdout("    Assets with records: " . count($categories['linked_assets']) . "\n");
    $this->stdout("    Transform files:     " . count($categories['transforms']) . "\n");
    $this->stdout("    Orphaned files:      " . count($categories['orphans']) . "\n\n");

    // STEP 1: Move linked assets to images volume (preserving subfolder structure)
    if (!empty($categories['linked_assets'])) {
        $this->moveOptimisedAssetsToImages(
            $categories['linked_assets'],
            $targetVolume,
            $optimisedVolume
        );
    }

    // STEP 2: Handle transforms
    if (!empty($categories['transforms'])) {
        $this->handleTransforms($categories['transforms'], $optimisedFs);
    }

    // STEP 3: Report on orphans
    if (!empty($categories['orphans'])) {
        $this->reportOrphansWithFolders($categories['orphans']);
    }
}
```

### **Change 4: Update moveOptimisedAssetsToImages**

Update to preserve folder structure:

```php
private function moveOptimisedAssetsToImages($linkedAssets, $targetVolume, $sourceVolume)
{
    $this->stdout("  STEP 1: Moving " . count($linkedAssets) . " assets to images volume\n");
    $this->stdout("          (preserving subfolder structure)\n");
    $this->printProgressLegend();
    $this->stdout("  Progress: ");

    $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);
    $targetFs = $targetVolume->getFs();
    $sourceFs = $sourceVolume->getFs();

    $moved = 0;
    $errors = 0;
    $folderCache = [];  // Cache folder lookups

    foreach ($linkedAssets as $item) {
        $file = $item['file'];
        $assetId = $item['assetId'];
        $preserveFolder = $item['preserveFolder'] ?? false;

        $asset = Asset::findOne($assetId);
        if (!$asset) {
            $this->stdout("?", Console::FG_GREY);
            continue;
        }

        try {
            // Source path (may include subfolder)
            $sourcePath = $file['path'];  // e.g., "images/photo.jpg" or "photo.jpg"

            // Determine target path
            if ($preserveFolder) {
                // Keep subfolder structure
                $subfolder = dirname($sourcePath);
                if ($subfolder && $subfolder !== '.') {
                    // Ensure folder exists in target volume
                    if (!isset($folderCache[$subfolder])) {
                        $folderCache[$subfolder] = $this->ensureFolder($targetVolume, $subfolder);
                    }
                    $targetFolderId = $folderCache[$subfolder];
                    $targetPath = $sourcePath;  // Keep full path with subfolder
                } else {
                    $targetFolderId = $targetRootFolder->id;
                    $targetPath = $asset->filename;
                }
            } else {
                // Move to root
                $targetFolderId = $targetRootFolder->id;
                $targetPath = $asset->filename;
            }

            // Copy file
            if (!$targetFs->fileExists($targetPath)) {
                $content = $sourceFs->read($sourcePath);
                $targetFs->write($targetPath, $content, []);
            }

            // Update asset record
            $db = Craft::$app->getDb();
            $transaction = $db->beginTransaction();

            try {
                $asset->volumeId = $targetVolume->id;
                $asset->folderId = $targetFolderId;

                if (Craft::$app->getElements()->saveElement($asset)) {
                    $transaction->commit();

                    // Delete from source
                    try {
                        $sourceFs->deleteFile($sourcePath);
                    } catch (\Exception $e) {
                        // File might already be gone, that's ok
                    }

                    $this->changeLogManager->logChange([
                        'type' => 'moved_from_optimised',
                        'assetId' => $asset->id,
                        'filename' => $asset->filename,
                        'fromPath' => $sourcePath,
                        'toPath' => $targetPath,
                        'preservedFolder' => $preserveFolder,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);

                    $moved++;
                    $this->stdout(".", Console::FG_GREEN);
                } else {
                    $transaction->rollBack();
                    $errors++;
                    $this->stdout("x", Console::FG_RED);
                }
            } catch (\Exception $e) {
                $transaction->rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $errors++;
            $this->stdout("!", Console::FG_YELLOW);
        }
    }

    $this->stdout("\n");
    $this->stdout("  Moved: {$moved}, Errors: {$errors}\n\n");
}

/**
 * Ensure folder exists in target volume, create if needed
 */
private function ensureFolder($volume, $path): int
{
    $folderService = Craft::$app->getAssets();
    $parts = explode('/', trim($path, '/'));

    $parentFolder = $folderService->getRootFolderByVolumeId($volume->id);

    foreach ($parts as $part) {
        $folder = $folderService->findFolder([
            'parentId' => $parentFolder->id,
            'name' => $part,
        ]);

        if (!$folder) {
            $folder = new AssetFolder();
            $folder->volumeId = $volume->id;
            $folder->parentId = $parentFolder->id;
            $folder->name = $part;
            $folder->path = $parentFolder->path . $part . '/';

            $folderService->storeFolderRecord($folder);
        }

        $parentFolder = $folder;
    }

    return $parentFolder->id;
}
```

---

## 📋 Summary of Changes

### **Configuration Changes**

1. ✅ Remove `optimisedImages` from `rootLevel` array (or rename key)
2. ✅ Add clearer configuration keys: `atBucketRoot`, `withSubfolders`, `flatStructure`
3. ✅ Update MigrationConfig helper with new getter methods

### **Code Changes**

1. ✅ Update `handleOptimisedImagesAtRoot` to recognize subfolder structure
2. ✅ Update file categorization to match by full path (not just filename)
3. ✅ Update `moveOptimisedAssetsToImages` to preserve folder structure
4. ✅ Add `ensureFolder` helper to create folders as needed
5. ✅ Handle duplicate filenames in different folders

### **Behavior Changes**

**Before:**
- Treated all files in `optimisedImages` as root-level
- Lost folder structure when moving files
- Risk of filename conflicts

**After:**
- Recognizes subfolder structure in `optimisedImages`
- Preserves folder structure when moving files
- Handles duplicate filenames safely
- Clear configuration distinguishing volume types

---

## 🧪 Testing Recommendations

After implementing changes:

```bash
# 1. Test dry-run with new logic
./craft ncc-module/image-migration/migrate --dryRun=1

# 2. Check that subfolders are recognized
# Look for output like:
#   Files by folder:
#     - images: 150 files
#     - optimizedImages: 200 files
#     - images-Winter: 50 files

# 3. Verify folder structure preservation
# Check change log for 'preservedFolder' entries

# 4. Test with small batch
./craft ncc-module/image-migration/migrate --batchSize=10
```

---

## 📝 Documentation Updates Needed

1. Update `migration-config.php` comments
2. Update `CONFIGURATION_GUIDE.md` with new volume configuration
3. Add section about subfolder handling in `migrationGuide.md`
4. Update `MigrationConfig.php` PHPDoc comments

---

**Status:** Issue identified, solution designed, implementation pending
**Priority:** High (affects data integrity during migration)
**Risk:** Medium (changes migration behavior, needs thorough testing)

---

**Version:** 1.0
**Date:** 2025-11-05
