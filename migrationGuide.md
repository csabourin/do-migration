# Asset Migration to DO Spaces - Setup & Usage Guide

## Critical Improvements Over Previous Script

### 1. **DO Spaces Compatibility**
- **Problem Fixed**: The `readStream()` error you encountered
- **Solution**: Uses `read()` method and manual file operations instead of `readStream()`
- **Fallback**: If `moveAsset()` fails, automatically uses manual method

### 2. **File Verification**
- **Before**: Script assumed files existed
- **Now**: Verifies file existence before every operation
- **Result**: Detects broken asset-file links and orphaned files

### 3. **Orphaned File Handling**
- **Before**: Only handled unused assets
- **Now**: Detects and quarantines files that have no corresponding asset
- **Result**: Clean filesystem with no orphaned files

### 4. **Proper Quarantine**
- **Before**: Used folder in same volume (still indexed)
- **Now**: Uses separate volume with different filesystem
- **Result**: Quarantined files are truly isolated and not indexed

### 5. **Change Logging**
- **Before**: No rollback capability
- **Now**: Logs every change in JSON format
- **Result**: Can rollback individual or all changes

### 6. **Error Detection**
- **Before**: Would run indefinitely with repeated errors
- **Now**: Stops after 10 identical errors
- **Result**: Prevents wasted time on configuration issues

### 7. **Progress Indicators**
- **Visual**: See exactly what's happening
- **Legend**: Understand what each symbol means
- **ETA**: Know how long operations will take

---

## Prerequisites & Setup

### Step 1: Create Quarantine Volume

You need a **separate volume** with its own filesystem for quarantine:

```php
// In Craft Admin → Settings → Assets → Volumes

1. Create new volume: "Quarantine"
2. Handle: "quarantine"
3. Create new filesystem for it:
   - Option A: Subdirectory in same bucket
     Path: quarantine/ (not indexed by your app)
   
   - Option B: Separate bucket (recommended)
     Bucket: your-bucket-quarantine
     
4. Important: DO NOT use the same filesystem as Images volume
```

### Step 2: Verify Volume Handles

Check your current volume handles match the script:

```bash
# Check what volumes you have
php craft graphql/dump-schema | grep -i volume

# Or in PHP:
foreach (Craft::$app->getVolumes()->getAllVolumes() as $v) {
    echo $v->handle . " - " . $v->name . "\n";
}
```

Update the script if your handles differ:
```php
private $sourceVolumeHandles = ['images', 'optimisedImages']; // Your sources
private $targetVolumeHandle = 'images';                        // Your target
private $quarantineVolumeHandle = 'quarantine';               // Your quarantine
```

### Step 3: Install the Controller

```bash
# Copy controller to your modules directory
cp ImageMigrationController.php modules/console/controllers/

# Verify it's accessible
php craft help ncc-module/image-migration
```

---

## Running the Migration

### Step 1: Dry Run (ALWAYS DO THIS FIRST)

```bash
ddev craft ncc-module/image-migration/migrate --dryRun=1
```

**What it shows:**
- Number of broken links to fix
- Number of files to move
- Number of files to quarantine
- Estimated scope of work

**Example output:**
```
ANALYSIS RESULTS:
  ✓ Assets with files:           15000
  ✓ Used assets (correct loc):   12000
  ⚠ Used assets (wrong loc):      2500  [NEEDS MOVE]
  ⚠ Broken links:                   150  [NEEDS FIX]
  ⚠ Unused assets:                  300  [QUARANTINE]
  ⚠ Orphaned files:                  50  [QUARANTINE]

DRY RUN - Would perform:
  1. Fix 150 broken asset-file links
  2. Move 2500 used files to target root
  3. Quarantine 300 unused assets
  4. Quarantine 50 orphaned files
```

### Step 2: Real Migration

```bash
# With database backup (recommended)
ddev craft ncc-module/image-migration/migrate

# Without backup (if you have external backup)
ddev craft ncc-module/image-migration/migrate --skipBackup=1
```

**Progress Indicators:**

```
Legend: .=success  x=failed  !=error  ?=not found  -=skipped

Progress: ..........x.......!........-- [45/2500]
```

- `.` (green) = Success
- `x` (red) = Failed (asset error, validation)
- `!` (yellow) = Exception occurred
- `?` (grey) = Asset/file not found
- `-` (grey) = Skipped (already correct location)

### Step 3: Monitor for Errors

**The script will STOP automatically if:**
- Same error occurs 10+ times
- Critical configuration issue detected

**Example:**
```
✗ STOPPING: Too many repeated errors (10) in operation 'consolidate_file'
Last error: Calling unknown method: vaersaagod\dospaces\Fs::readStream()
This usually indicates a configuration or permissions issue.
```

**What to do:**
1. Check the error message
2. Fix the underlying issue (filesystem config, permissions)
3. Resume: The change log lets you see what was completed
4. Re-run if needed (script will skip already-completed items)

---

## Understanding the Change Log

Location: `storage/migration-changes-YYYYMMDD-HHMMSS.json`

### Structure:
```json
{
  "version": "3.0",
  "timestamp": "2024-10-30 14:30:00",
  "stats": {
    "files_moved": 2450,
    "files_quarantined": 350,
    "errors": 5
  },
  "changes": [
    {
      "type": "moved_asset",
      "assetId": 12345,
      "filename": "photo.jpg",
      "fromVolume": 2,
      "fromFolder": 156,
      "toVolume": 1,
      "toFolder": 1,
      "timestamp": "2024-10-30 14:30:15"
    },
    {
      "type": "fixed_broken_link",
      "assetId": 12346,
      "filename": "banner.jpg",
      "sourceVolume": "optimisedImages",
      "sourcePath": "banner.jpg",
      "timestamp": "2024-10-30 14:31:22"
    }
  ]
}
```

### Change Types:
- `moved_asset` - Asset moved between volumes/folders
- `fixed_broken_link` - Found and reconnected file to asset
- `quarantined_unused_asset` - Moved unused asset to quarantine
- `quarantined_orphaned_file` - Moved file without asset to quarantine

---

## Rollback

### Full Rollback (all changes):
```bash
ddev craft ncc-module/image-migration/rollback storage/migration-changes-20241030-143000.json
```

### Partial Rollback:
Edit the JSON file to remove changes you want to keep, then rollback the rest.

### Database Rollback:
If you need to restore the database backup:
```bash
ddev craft ncc-module/image-cleanup/rollback <timestamp>
# e.g.: ddev craft ncc-module/image-cleanup/rollback 20241030143000
```

---

## Verification Steps

### After Migration:

1. **Check your website**
   ```bash
   # Visit pages with lots of images
   # Check if images display correctly
   ```

2. **Verify assets in admin**
   ```bash
   # Craft Admin → Assets
   # Check Images volume
   # Check Quarantine volume
   ```

3. **Check for broken images**
   ```bash
   ddev craft ncc-module/image-migration/diagnose
   ```

4. **Clear all caches**
   ```bash
   php craft clear-caches/all
   php craft invalidate-tags/all
   ```

5. **Regenerate transforms**
   ```bash
   # Visit a few pages to trigger transform generation
   # Or use a transform regeneration plugin
   ```

---

## Common Issues & Solutions

### Issue: "readStream() error"
**Cause**: DO Spaces filesystem doesn't support `readStream()`
**Solution**: Already handled by script - uses fallback method automatically

### Issue: "Too many repeated errors"
**Cause**: Usually filesystem permissions or configuration
**Solution**: 
1. Check DO Spaces credentials
2. Verify bucket permissions (read/write)
3. Check filesystem configuration in Craft

### Issue: "File not found" for many assets
**Cause**: Files may not have been copied to DO Spaces yet
**Solution**: 
1. Ensure all files are copied to DO Spaces first
2. Run dry-run to see scope
3. Check "broken_links" section of analysis

### Issue: "Quarantine volume not found"
**Cause**: Quarantine volume not created
**Solution**: Create it (see Step 1 above)

### Issue: Assets moved but images don't display
**Cause**: Transform cache or CDN cache
**Solution**:
```bash
php craft clear-caches/all
php craft invalidate-tags/all
# Also clear CDN cache if applicable
```

---

## Optimizations for Large Datasets

If you have 10,000+ assets:

### 1. Process in Batches
Modify the script to process subsets:
```php
// In analyzeAssetFileLinks method, add:
WHERE a.id BETWEEN 1 AND 5000  // First batch
WHERE a.id BETWEEN 5001 AND 10000  // Second batch
```

### 2. Increase PHP Memory
```bash
php -d memory_limit=2G craft image-migration/migrate
```

### 3. Disable Search Indexing
The script already does this, but verify:
```php
// Already in script - no action needed
private function disableSearchIndexing() { ... }
```

### 4. Run During Off-Hours
- Less traffic = better performance
- Easier to verify results

---

## Migration Checklist

- [ ] Create quarantine volume with separate filesystem
- [ ] Verify all volume handles match script configuration
- [ ] Ensure DO Spaces credentials are correct
- [ ] Test filesystem read/write permissions
- [ ] Run dry-run to see scope: `ddev craft ncc-module/image-migration/migrate --dryRun=1`
- [ ] Review dry-run output - does it match expectations?
- [ ] Create database backup (or verify external backup exists)
- [ ] Run migration: `ddev craft ncc-module/image-migration/migrate`
- [ ] Monitor progress - watch for error patterns
- [ ] Review change log after completion
- [ ] Verify images on website
- [ ] Clear all caches
- [ ] Check quarantine volume for false positives
- [ ] Keep backups for 1 week
- [ ] After verification, delete quarantined files if appropriate

---

## Performance Expectations

### Typical Processing Speed:
- **File verification**: ~500-1000 files/second
- **File moves (same filesystem)**: ~100-200 files/second
- **File moves (cross filesystem)**: ~20-50 files/second
- **Quarantine operations**: ~50-100 files/second

### For 10,000 assets:
- Analysis: ~5-10 minutes
- Migration: ~15-30 minutes (depends on file locations)
- Verification: ~5 minutes

**Total**: ~30-45 minutes for typical migration

---

## Getting Help

### Enable Debug Logging:
```php
// In .env file
CRAFT_DEV_MODE=true
CRAFT_LOG_LEVEL=4

// Check logs
tail -f storage/logs/console.log
```

### Diagnostic Command:
```bash
# Get detailed information
ddev craft ncc-module/image-migration/diagnose

# For specific asset
ddev craft ncc-module/image-migration/diagnose 12345
```

### What to Include When Asking for Help:
1. Output from dry-run
2. Error messages from logs
3. Change log JSON (if migration partially completed)
4. Your volume/filesystem configuration
5. DO Spaces permissions/credentials status