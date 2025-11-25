# Missing Files Fix Guide

## Problem Description

After migration, some files exist physically in quarantine but their database asset records don't properly link to them. Additionally, some files (PDFs, DOCX, ZIP) may be in the wrong volume (Images instead of Documents).

## Solution

A new console controller `MissingFileFixController` has been created to diagnose and fix these issues. It can be used via:
- **Command Line Interface (CLI)** - For direct control and scripting
- **Web Dashboard** - Integrated into Phase 8 (Audit & Diagnostics)

## Usage

### Via Web Dashboard (Recommended)

The missing file fix tools are integrated into the migration dashboard at **Phase 8: Audit & Diagnostics**:

1. Navigate to **Control Panel → Spaghetti Migrator → Dashboard**
2. Scroll to **Phase 8: Audit & Diagnostics**
3. Click **"🔍 Analyze Missing Files"** to scan for issues
4. Review the analysis output
5. Click **"🔧 Fix Missing File Associations"** to reconnect files
6. Toggle dry-run mode off when ready to apply changes

**Benefits of Web Dashboard:**
- Visual progress indicators
- One-click execution
- Real-time output streaming
- No terminal access required
- Integrated with migration workflow

### Via Command Line (CLI)

### 1. Analyze Missing Files

First, analyze the current state to understand what's missing:

```bash
./craft spaghetti-migrator/missing-file-fix/analyze
```

This will:
- Scan all volumes for missing files
- Check if missing files exist in quarantine
- Identify files in wrong volumes based on file extension
- Show detailed statistics

### 2. Fix Missing Files (Dry Run)

Before making changes, run in dry-run mode to see what would be fixed:

```bash
./craft spaghetti-migrator/missing-file-fix/fix --dryRun=1
```

This will:
- Find all assets with missing files
- Search quarantine for matching files
- Show what would be moved (without actually moving anything)

### 3. Fix Missing Files (Execute)

Once you're satisfied with the dry-run results, execute the fix:

```bash
./craft spaghetti-migrator/missing-file-fix/fix --dryRun=0
```

This will:
- Move files from quarantine to their correct locations
- Update database records to reflect the new paths
- Show summary of fixed files

## Expected File Type → Volume Mapping

The controller uses the following logic:

- **Documents Volume**: `.pdf`, `.doc`, `.docx`, `.zip`, `.txt`
- **Images Volume**: `.jpg`, `.jpeg`, `.png`, `.gif`, `.svg`, `.webp`

## Troubleshooting

### Issue: "Required volumes not found"

**Solution**: Ensure you have the following volume handles configured:
- `images` - Images volume
- `documents` - Documents volume
- `quarantine` - Quarantine volume

You can check existing volumes with:
```bash
./craft spaghetti-migrator/volume-config/status
```

### Issue: "Files not found in quarantine"

**Possible causes**:
1. Files may have been deleted during migration
2. Files may be in a different location
3. Filenames may have been changed

**Next steps**:
1. Check quarantine volume manually
2. Look for similar filenames
3. Check migration logs for errors

### Issue: "Fixed count is 0"

This could mean:
1. Files don't exist in quarantine (truly missing)
2. Filenames don't match between asset records and quarantine files
3. Files are already in the correct location

## Manual Investigation

If automated fix doesn't work, you can manually investigate:

### 1. Check Quarantine Assets

```bash
# Via Craft CMS control panel
# Assets > Quarantine volume
# Look for your missing files
```

### 2. Check Filesystem Directly

Connect to your storage (DigitalOcean Spaces, S3, etc.) and check:
- `/quarantine/` - Orphaned files without asset records
- `/quarantine/orphaned/` - Files that had no asset record during migration
- `/quarantine/unused/` - Assets that had no relations during migration

### 3. Check Asset IDs

Your missing files list includes Asset IDs. You can query the database to see their current state:

```sql
SELECT id, filename, volumeId, folderId
FROM craft_assets
WHERE id IN (1148114, 1103471, 972890);
```

## Example Output

### Analyze Command

```
================================================================================
MISSING FILE ANALYSIS
================================================================================

Volumes:
  Images: Images (ID: 1)
  Documents: Documents (ID: 2)
  Quarantine: Quarantine (ID: 3)

Scanning for missing files...

Results:
  Total missing files: 95
  Files in wrong volume: 0

Missing Files:
--------------------------------------------------------------------------------
  OneDrive_1_15-08-2025.zip (ID: 1148114, Volume: images, Ext: zip)
    Expected: OneDrive_1_15-08-2025.zip
  front-render-03-1.png (ID: 1103471, Volume: images, Ext: png)
    Expected: front-render-03-1.png
  ...

Searching quarantine for missing files...
  Quarantine contains 150 assets
  ✓ Found 95 missing files in quarantine with asset records

  Checking for orphaned files in quarantine...
  Found 200 physical files in quarantine
  ⚠ Found 50 orphaned files (no asset record)
  ✓ 45 orphaned files match missing asset filenames!
    Run 'fix' action to reconnect them
```

### Fix Command (Dry Run)

```
================================================================================
FIX MISSING FILE ASSOCIATIONS
================================================================================

⚠ DRY RUN MODE - No changes will be made

Finding assets with missing files...
Found 95 assets in Documents volume

Scanning quarantine for files...
Found 200 files in quarantine

Processing assets...

[1] Missing: OneDrive_1_15-08-2025.zip (ID: 1148114)
    Expected path: OneDrive_1_15-08-2025.zip
    ✓ Found in quarantine: orphaned/OneDrive_1_15-08-2025.zip
    → Would move to: OneDrive_1_15-08-2025.zip

[2] Missing: EN-Tobi-Nussbaum-June-2019.pdf (ID: 7113)
    Expected path: EN-Tobi-Nussbaum-June-2019.pdf
    ✓ Found in quarantine: orphaned/EN-Tobi-Nussbaum-June-2019.pdf
    → Would move to: EN-Tobi-Nussbaum-June-2019.pdf

...

================================================================================
SUMMARY
================================================================================

  Processed: 95
  Fixed: 45
  Errors: 0

⚠ This was a dry run. Use --dryRun=0 to apply changes.
```

## Important Notes

1. **Always run analyze first** to understand the scope of the problem
2. **Always test with dry-run** before executing the fix
3. **Backup your database** before making changes (optional but recommended)
4. **Check logs** after running the fix to ensure no errors occurred

## File Locations

- **Controller**: `modules/console/controllers/MissingFileFixController.php`
- **Documentation**: `MISSING_FILES_FIX.md` (this file)

## Support

If you encounter issues:
1. Check this documentation
2. Review the analyze output carefully
3. Run with dry-run first to see what would change
4. Check Craft logs at `storage/logs/`

## Related Commands

- `spaghetti-migrator/migration-diag/analyze` - General migration diagnostics
- `spaghetti-migrator/volume-config/status` - Check volume configuration
- `spaghetti-migrator/volume-consolidation/consolidate` - Consolidate volumes

---

**Created**: 2025-11-25
**Version**: 1.0.0
**Author**: Christian Sabourin
