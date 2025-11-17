# Troubleshooting: Files Found in Bucket But Reported as Missing

## Problem Summary

The migration scanner successfully finds all 17,000 files in your bucket, but specific files are still reported in the "migration-issues" file as missing. When you search the bucket directly, these files clearly exist.

## Root Cause

This is **NOT** a path configuration issue (since the scanner finds 17,000 files). Instead, it's an **asset-to-file matching problem**. The Craft asset database records cannot be matched to their corresponding files in the bucket.

## Your Bucket Structure (Confirmed)

```
/medias/
├── images/
│   ├── FILENAME.jpg
│   └── originals/
│       └── FILENAME.jpg
├── documents/
│   └── FILENAME.pdf
├── originals/
│   └── FILENAME.jpg
└── FILENAME.jpg
```

## Why Files Can't Be Matched

When the migration runs:

1. **Build Asset Inventory**: Reads Craft database (`assets` table)
   - Gets: asset ID, filename, volumeId, folderId, etc.

2. **Build File Inventory**: Scans filesystem (✓ finds all 17,000 files)
   - Gets: actual file paths in bucket

3. **Match Assets to Files**: For each asset, tries to find the file
   - Uses: volumeId → volume → filesystem → subfolder + folderPath + filename
   - **This is where it fails for some files**

### Possible Reasons for Match Failure

#### Reason 1: Asset Points to Wrong Volume

**Symptom**: File exists at `/medias/images/file.jpg` but asset record says it's in the `documents` volume.

**Example**:
```
Asset DB:  volumeId=2 (documents), filename=file.jpg
Expected:  medias/documents/file.jpg
Actual:    medias/images/file.jpg ← mismatch!
```

**How to check**:
```bash
php diagnose-asset-file-mismatch.php
```

**Solution**:
```sql
-- Find the correct volume ID for 'images'
SELECT id, name, handle FROM volumes;

-- Update asset to point to correct volume
UPDATE assets
SET volumeId = <correct_volume_id>
WHERE filename = 'missing-file.jpg';
```

#### Reason 2: Asset Folder Path Doesn't Match

**Symptom**: File is at root of volume, but asset record says it's in a subfolder (or vice versa).

**Example**:
```
Asset DB:  folder_path='subfolder/', filename=file.jpg
Expected:  medias/images/subfolder/file.jpg
Actual:    medias/images/file.jpg ← file is at root!
```

**Solution**:
```sql
-- Find the root folder ID for the volume
SELECT id, path, volumeId FROM volumefolders WHERE path IS NULL OR path = '';

-- Update asset to point to root folder
UPDATE assets
SET folderId = <root_folder_id>
WHERE filename = 'missing-file.jpg';
```

#### Reason 3: File Exists in Multiple Locations

**Symptom**: File exists at both `/medias/images/file.jpg` AND `/medias/images/originals/file.jpg`.

The asset record might point to one location, but the file was moved or copied to another location, and the database wasn't updated.

**Example**:
```
Buckethas:
  - /medias/images/file.jpg (new location)
  - /medias/images/originals/file.jpg (old location)

Asset DB points to old location
Migration scans the wrong path first
```

**Solution**: Configure migration to scan multiple source volumes or consolidate files.

#### Reason 4: No Asset Record (Orphaned File)

**Symptom**: File exists in bucket but has zero asset records in Craft database.

This happens when:
- Files uploaded directly to S3 (bypassing Craft)
- Asset records deleted but files remained
- Failed imports/migrations

**Solution**: These files will be automatically quarantined as orphans. You can later review and re-import them if needed.

#### Reason 5: Filesystem Subfolder Mismatch

**Symptom**: Volume filesystem is configured with wrong subfolder.

**Example**:
```
Volume "images" filesystem subfolder: "images"
Asset expects: images/file.jpg
Scanner looks in: images/file.jpg ← but file is at medias/images/file.jpg
```

**Check**:
```bash
php check-volume-paths.php
```

Should show:
```
Volume: images
  Filesystem: images (AWS S3)
  Subfolder: 'medias/images'  ← Must include 'medias/'
```

**Solution**: Update filesystem subfolder in Craft CP:
- Settings → Assets → Filesystems
- Edit filesystem
- Update "Subfolder" to include `medias/` prefix

#### Reason 6: Case Sensitivity Mismatch

**Symptom**: Filename case doesn't match between database and filesystem.

**Example**:
```
Asset DB: "Wakefield-Bridge-And-Dam-1.JPG"
Actual:   "Wakefield-bridge-and-dam-1.JPG"  ← lowercase 'b'
```

S3 is case-sensitive. The matching algorithm has case-insensitive fallback, but it might not find it if the volume is wrong.

**Solution**: Normalize filenames or fix asset records.

## Diagnostic Steps

### Step 1: Check Asset Database Records

Run the PHP diagnostic:
```bash
php diagnose-asset-file-mismatch.php
```

This will show:
- Asset ID and details
- Volume and filesystem info
- Expected path vs. actual path
- Where the file actually exists

### Step 2: Check SQL Directly

If PHP isn't working, use the SQL script:
```bash
chmod +x check-missing-files-sql.sh
./check-missing-files-sql.sh
```

Or run manually:
```sql
SELECT
    a.id AS asset_id,
    a.filename,
    a.volumeId,
    v.name AS volume_name,
    v.handle AS volume_handle,
    f.path AS folder_path
FROM assets a
LEFT JOIN volumes v ON a.volumeId = v.id
LEFT JOIN volumefolders f ON a.folderId = f.id
WHERE a.filename IN (
    '2025-26_UrbLab_Nov_Alexandre.jpg',
    'Wakefield-bridge-and-dam-1.JPG',
    'IMG_1880.jpg'
);
```

### Step 3: Check Actual File Locations

Search your bucket:
```bash
# Find all occurrences of a file
aws s3 ls s3://YOUR-BUCKET/medias/ --recursive | grep "2025-26_UrbLab_Nov_Alexandre.jpg"
```

Or use Digital Ocean CLI:
```bash
s3cmd ls s3://YOUR-BUCKET/medias/ --recursive | grep "filename.jpg"
```

### Step 4: Compare Results

Create a comparison:

| Filename | Asset Volume | Expected Path | Actual Path | Issue |
|----------|--------------|---------------|-------------|-------|
| file.jpg | images (ID:1) | medias/documents/file.jpg | medias/images/file.jpg | Wrong volume |

## Solutions

### Solution 1: Fix Asset Volume IDs (SQL)

If assets are assigned to wrong volumes:

```sql
-- 1. Find correct volume IDs
SELECT id, name, handle FROM volumes;

-- 2. Update assets in bulk
UPDATE assets
SET volumeId = <correct_volume_id>
WHERE filename IN (
    'file1.jpg',
    'file2.jpg',
    'file3.jpg'
);

-- 3. Verify
SELECT id, filename, volumeId FROM assets
WHERE filename IN ('file1.jpg', 'file2.jpg');
```

### Solution 2: Fix Asset Folder Paths (SQL)

If asset folder paths are wrong:

```sql
-- 1. Find root folder for each volume
SELECT id, volumeId, path FROM volumefolders
WHERE path IS NULL OR path = '';

-- 2. Move assets to root folder
UPDATE assets
SET folderId = <root_folder_id>
WHERE filename IN ('file1.jpg', 'file2.jpg');
```

### Solution 3: Add Multiple Source Volumes

If files are scattered across multiple locations, configure migration to scan all of them:

In `config/migration-config.php`:
```php
$volumeConfig = [
    'source' => [
        'images',           // Scans medias/images/
        'documents',        // Scans medias/documents/
        'optimisedImages',  // Scans medias/images/originals/ (if separate volume)
    ],
    'target' => 'images',
    'quarantine' => 'quarantine',
];
```

### Solution 4: Create Migration-Specific Volumes

Create temporary volumes specifically for migration that point to all file locations:

1. **In Craft CP**: Settings → Assets → Volumes → New Volume
   - Name: "Migration - Images Root"
   - Handle: `migrationImagesRoot`
   - Filesystem: Point to `medias/images`

2. **Create another for originals**:
   - Name: "Migration - Originals"
   - Handle: `migrationOriginals`
   - Filesystem: Point to `medias/images/originals`

3. **Update migration config**:
```php
'source' => [
    'images',
    'documents',
    'migrationImagesRoot',
    'migrationOriginals'
],
```

### Solution 5: Accept Orphaned Files

If files have no asset records, they'll be quarantined. After migration:

1. Review quarantined files
2. Re-import needed files through Craft CP
3. Delete truly unused files

## Prevention

To prevent this in future:

1. **Always use Craft's asset manager** to upload files (don't upload directly to S3)
2. **Keep database in sync** when moving files
3. **Use asset indexing** regularly: Utilities → Asset Indexes
4. **Test in dev environment** before production migration

## Still Having Issues?

If diagnostics show everything looks correct but files still won't match:

1. **Check migration logs**:
   ```bash
   tail -f storage/logs/web.log | grep -i "migration\|missing"
   ```

2. **Enable verbose logging** in migration code

3. **Check for encoding issues**:
   ```bash
   # Check if filename has special characters
   ls -b /medias/images/ | grep filename
   ```

4. **Manual test**:
   ```php
   // Test if filesystem can access the file
   $fs = Craft::$app->getVolumes()->getVolumeById(1)->getFs();
   $exists = $fs->fileExists('medias/images/filename.jpg');
   var_dump($exists); // Should be true
   ```

## Summary

Your issue is NOT a configuration problem - it's an asset-database-to-file-path matching problem. The files exist, the scanner finds them, but the Craft asset records point to the wrong locations.

**Action Plan**:
1. Run `php diagnose-asset-file-mismatch.php`
2. Identify which reason applies (volume mismatch, folder mismatch, etc.)
3. Fix asset records or add source volumes
4. Re-run migration
5. Verify missing files are now found
