# Originals-First Migration Strategy

## Overview

The migration system has been modified to implement an **originals-first strategy** that:

1. **Prioritizes original files** over transforms/optimized versions
2. **Moves originals to volume 1 root** (replacing existing transforms)
3. **Updates asset records** to point to volume 1, folder 1 (root)
4. **Ensures Craft can regenerate transforms** from the original files

## Why This Strategy?

When migrating from AWS S3 to DigitalOcean Spaces, you want to:
- Replace transforms with original, high-quality files
- Consolidate all assets into a single volume (volume 1)
- Place files at the root level for clean organization
- Allow Craft to regenerate transforms as needed

## Changes Made

### 1. Modified File Prioritization (`prioritizeFile`)

**Location**: `ImageMigrationController.php:4429-4479`

**Before**:
```php
private function prioritizeFile($files, $targetVolume)
{
    usort($files, function ($a, $b) use ($targetVolume) {
        // PRIORITY 1: Files in target volume
        // PRIORITY 2: Most recently modified
    });
}
```

**After**:
```php
private function prioritizeFile($files, $targetVolume)
{
    usort($files, function ($a, $b) use ($targetVolume) {
        // PRIORITY 1: Files in 'originals' folders (HIGHEST)
        // PRIORITY 2: Files in target volume
        // PRIORITY 3: Most recently modified
    });
}
```

**What it does**:
- When multiple files match an asset (e.g., `/medias/images/file.jpg` and `/medias/images/originals/file.jpg`)
- Prefers the file in the `originals/` folder
- Ensures original files are used instead of transforms

### 2. Added Originals Detection (`isInOriginalsFolder`)

**Location**: `ImageMigrationController.php:4470-4479`

**New method**:
```php
private function isInOriginalsFolder($path)
{
    $path = strtolower(trim($path, '/'));

    return (
        strpos($path, '/originals/') !== false ||
        strpos($path, 'originals/') === 0 ||
        preg_match('#/originals/[^/]+$#i', $path)
    );
}
```

**Matches**:
- `/medias/originals/file.jpg`
- `/medias/images/originals/file.jpg`
- `originals/file.jpg`
- `images/originals/file.jpg`

### 3. Enhanced Fix Broken Link Logic

**Location**: `ImageMigrationController.php:2615-2662`

**Added**:
```php
// Detect if file is from originals folder
$isFromOriginals = $this->isInOriginalsFolder($sourceFile['path']);

if ($isFromOriginals) {
    $this->stdout("    📦 Found in originals: {$sourceFile['path']}\n");
    $this->stdout("    → Moving to volume {$targetVolume->name}, root folder\n");
}
```

**What it does**:
- Detects when a matched file is in an originals folder
- Logs it clearly in the console
- Tracks statistics for reporting

### 4. Updated Asset Copy/Move Logic

**Location**: `ImageMigrationController.php:2641`

**Existing behavior** (no changes needed):
```php
$success = $this->copyFileToAsset($sourceFile, $asset, $targetVolume, $targetRootFolder);
```

The `copyFileToAsset` method already:
1. Reads file from source location (including originals folders)
2. Sets `asset->volumeId = targetVolume->id` (volume 1)
3. Sets `asset->folderId = targetRootFolder->id` (root folder)
4. Deletes source file (completing the move operation)

### 5. Added Statistics Tracking

**Location**: `ImageMigrationController.php:2657-2662`

**Added**:
```php
if ($isFromOriginals) {
    $this->stats['originals_moved']++;
    $this->stdout("    ✓ Original moved to volume 1 root\n", Console::FG_GREEN);
}
```

**Change log tracking**:
```php
$this->changeLogManager->logChange([
    // ... existing fields ...
    'fromOriginals' => $isFromOriginals,  // NEW
]);
```

### 6. Updated Final Report

**Location**: `ImageMigrationController.php:5547-5550`

**Added**:
```
FINAL STATISTICS:
  Duration:          5m 32s
  Files moved:       1250
  Files quarantined: 45
  Assets updated:    1205
  Originals moved:   823              ← NEW
                     (Original files moved to volume 1 root, replacing transforms)
  Errors:            0
```

## How It Works

### Migration Flow

```
1. Build Asset Inventory
   └─ Load all asset records from Craft database

2. Build File Inventory
   └─ Scan all volumes: /medias/images/, /medias/images/originals/, /medias/originals/, etc.

3. Fix Broken Links
   ├─ For each asset without a matching file:
   │  ├─ Find matching files using various strategies
   │  ├─ PRIORITIZE files in /originals/ folders   ← NEW
   │  ├─ Detect if matched file is in originals     ← NEW
   │  ├─ Copy file to volume 1, root folder
   │  ├─ Update asset: volumeId=1, folderId=1      ← ENSURES volume 1, root
   │  ├─ Delete original file (complete the move)
   │  └─ Log: "✓ Original moved to volume 1 root"  ← NEW
   │
4. Final Report
   └─ Show: "Originals moved: 823"                 ← NEW
```

### Example Scenario

**Before Migration**:
```
Bucket structure:
├─ /medias/images/sunset.jpg (optimized, 800x600)
├─ /medias/images/originals/sunset.jpg (original, 4000x3000)
└─ /medias/documents/sunset.jpg (wrong volume)

Craft database:
- Asset ID 123: sunset.jpg, volumeId=2 (documents), folderId=5 (subfolder)
```

**Migration Process**:
1. Find asset 123 (sunset.jpg) in documents volume → broken link
2. Search for files named "sunset.jpg"
3. Find 3 matches:
   - `/medias/images/sunset.jpg` (800x600)
   - `/medias/images/originals/sunset.jpg` (4000x3000) ← PRIORITIZED
   - `/medias/documents/sunset.jpg`
4. Select `/medias/images/originals/sunset.jpg` (highest priority)
5. Log: "📦 Found in originals: /medias/images/originals/sunset.jpg"
6. Copy to: `/medias/images/sunset.jpg` (volume 1, root)
7. Update asset: volumeId=1, folderId=1
8. Delete: `/medias/images/originals/sunset.jpg` (cleanup)
9. Log: "✓ Original moved to volume 1 root"

**After Migration**:
```
Bucket structure:
├─ /medias/images/sunset.jpg (original, 4000x3000) ← REPLACED with original
└─ /medias/documents/sunset.jpg (orphaned, will be quarantined)

Craft database:
- Asset ID 123: sunset.jpg, volumeId=1 (images), folderId=1 (root)
```

**Result**:
- Asset now points to volume 1, root folder ✓
- High-quality original file is used ✓
- Craft can regenerate transforms as needed ✓
- Asset record correctly updated ✓

## Configuration Requirements

To ensure this works correctly, your migration config should include:

### Source Volumes

Make sure all originals folders are scanned:

**Option 1**: Single volume that scans both images and originals
```php
$volumeConfig = [
    'source' => ['images'],  // Volume configured to scan /medias/images/ recursively
];
```

**Option 2**: Separate volumes for images and originals
```php
$volumeConfig = [
    'source' => [
        'images',           // Scans /medias/images/
        'optimisedImages'   // Scans /medias/images/originals/
    ],
];
```

### Target Volume

Ensure target is volume 1:
```php
$volumeConfig = [
    'target' => 'images',  // Volume handle for volume ID 1
];
```

### Expected Behavior

When you run the migration, you should see:

```
PHASE 2: FIX BROKEN ASSET-FILE LINKS
──────────────────────────────────────────────────
Processing batch 1/25 (100 assets)...
  Asset 123: sunset.jpg
    📦 Found in originals: medias/images/originals/sunset.jpg
    → Moving to volume images, root folder
    ✓ Original moved to volume 1 root
  Asset 124: beach.jpg
    📦 Found in originals: medias/originals/beach.jpg
    → Moving to volume images, root folder
    ✓ Original moved to volume 1 root
...

FINAL STATISTICS:
  Originals moved:   823
                     (Original files moved to volume 1 root, replacing transforms)
```

## Verification

After migration, verify:

### 1. Check Asset Records (SQL)

```sql
SELECT
    a.id,
    a.filename,
    a.volumeId,
    v.name AS volume_name,
    a.folderId,
    f.path AS folder_path
FROM assets a
LEFT JOIN volumes v ON a.volumeId = v.id
LEFT JOIN volumefolders f ON a.folderId = f.id
WHERE a.filename = 'sunset.jpg';
```

Expected:
- `volumeId`: 1
- `volume_name`: images
- `folder_path`: `` (empty = root)

### 2. Check Files in Bucket

```bash
# Check that originals were moved
aws s3 ls s3://YOUR-BUCKET/medias/images/ | grep sunset.jpg

# Should show:
# sunset.jpg (original file, larger size)

# Originals folder should be empty or deleted
aws s3 ls s3://YOUR-BUCKET/medias/images/originals/ | grep sunset.jpg
# Should show nothing
```

### 3. Check Craft CP

1. Go to Craft CP → Assets
2. Find an asset that was moved from originals
3. Click to view
4. Check:
   - Volume: "Images" (volume 1)
   - Location: Root (no subfolder)
   - File size: Should be larger (original file)

### 4. Check Migration Logs

```bash
tail -f storage/logs/web.log | grep -i "originals"
```

Should show:
```
Found in originals: medias/images/originals/file.jpg
Original moved to volume 1 root
```

## Troubleshooting

### Issue: Files not being prioritized from originals

**Check**:
```bash
php diagnose-asset-file-mismatch.php
```

Look for:
- Are files being found in originals folders?
- Is the `isInOriginalsFolder` method detecting them?

**Solution**:
- Verify originals folders are being scanned (check source volumes)
- Check path format matches detection patterns

### Issue: Assets not moving to volume 1 root

**Check SQL**:
```sql
SELECT a.id, a.filename, a.volumeId, a.folderId
FROM assets a
WHERE a.filename = 'problem-file.jpg';
```

**Solution**:
- Verify target volume is configured correctly
- Check that `targetRootFolder` is actually the root folder of volume 1

### Issue: Originals still in originals folder after migration

**This is normal** if the file is referenced by multiple assets. The migration only deletes the source file after the FIRST successful copy. Subsequent assets that reference the same file will get a "already_copied" message.

**To clean up**:
Run Phase 4 (Quarantine) to remove unused files from originals folders.

## Benefits

1. **High-Quality Assets**: Original files preserved, not optimized versions
2. **Clean Structure**: All files in volume 1 root, no scattered subfolders
3. **Correct References**: All assets point to volume 1, folder 1
4. **Regenerable Transforms**: Craft can create transforms from originals as needed
5. **Tracked Migration**: Statistics show how many originals were moved

## Notes

- The migration is **non-destructive** for assets: all changes logged in change log
- Original files are **deleted from source** after successful copy (completing the move)
- If migration fails, you can resume from checkpoint
- Change log tracks `fromOriginals: true` for all moved originals
- Original location stored in change log for potential rollback

## Summary

The originals-first strategy ensures that:
- ✅ Original high-quality files are prioritized
- ✅ Files are moved to volume 1, root folder
- ✅ Asset records correctly updated
- ✅ Transforms replaced with originals
- ✅ Clean, organized structure in target
