# Volume Consolidation Guide

## Overview

This guide explains how to handle the edge case where your AWS S3 bucket has a complex structure with the `OptimisedImages` volume pointing to the bucket root, containing all other volumes as subfolders.

## The Problem

In some Craft CMS setups, the volume structure can be:

```
AWS S3 Bucket Root (optimisedImages volume)
├── images/               (images volume subfolder)
│   ├── originals/
│   │   └── 4,905 assets
│   └── 5,676 assets (root)
├── documents/            (documents volume subfolder)
├── videos/               (videos volume subfolder)
└── transforms/           (transform files)
```

After syncing to DigitalOcean Spaces, you need to:

1. **Consolidate volumes**: Move all assets from `optimisedImages` → `images`
2. **Flatten folder structure**: Move all assets from subfolders (including `/originals/`) → root
3. **Clean up**: Remove empty folders and unused volumes

## Step 1: Diagnose Current State

Run the diagnostic command to see what needs to be done:

```bash
./craft s3-spaces-migration/migration-diag/analyze
```

This will show you:
- How many assets are in each volume
- How many assets are in subfolders
- Specific recommendations for your setup

## Step 2: Check Consolidation Status

Before starting, check the current status:

```bash
./craft s3-spaces-migration/volume-consolidation/status
```

This command shows:
- **OptimisedImages Volume**: How many assets need to be moved
- **Images Volume Subfolders**: How many assets need to be flattened

## Step 3: Merge OptimisedImages → Images

Move all assets from the `optimisedImages` volume into the `images` volume:

```bash
# Dry run first (preview changes)
./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images --dryRun=1

# When ready, apply changes
./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images --dryRun=0
```

**What this does:**
- Moves ALL assets from `optimisedImages` volume → `images` volume
- Places them in the root folder of `images`
- Handles filename conflicts by renaming (adds `-1`, `-2`, etc.)
- Processes in batches for memory efficiency
- Atomic operations with error recovery

**Options:**
- `--dryRun=0`: Apply changes (default is dry run mode)
- `--yes`: Skip confirmation prompts (for automation)
- `--batchSize=100`: Number of assets to process per batch (default: 100)

## Step 4: Flatten Subfolders to Root

Move all assets from subfolders (including `/originals/`) to the root of the `images` volume:

```bash
# Dry run first (preview changes)
./craft s3-spaces-migration/volume-consolidation/flatten-to-root --dryRun=1

# When ready, apply changes
./craft s3-spaces-migration/volume-consolidation/flatten-to-root --dryRun=0
```

**What this does:**
- Moves ALL assets from any subfolder → root folder
- Handles the `/originals/` folder automatically
- Handles filename conflicts by renaming
- Processes in batches for memory efficiency

**Options:**
- `--volumeHandle=images`: Volume to process (default: `images`)
- `--dryRun=0`: Apply changes (default is dry run mode)
- `--yes`: Skip confirmation prompts
- `--batchSize=100`: Number of assets to process per batch

## Step 5: Verify Results

After consolidation, run the diagnostic again to verify:

```bash
./craft s3-spaces-migration/migration-diag/analyze
```

You should see:
- ✅ `optimisedImages` volume is empty (or can be deleted)
- ✅ All assets are in `images` volume root folder
- ✅ No more recommendations for consolidation

## Step 6: Configure Transform Filesystem

After consolidation, configure the `images` volume to use a separate filesystem for transforms:

1. Go to: **Craft CP → Settings → Assets → Volumes → Images**
2. Set **Transform Filesystem** to: `optimisedImages_do`
3. Save

This ensures that image transforms are stored separately from originals.

## Edge Case Handling

### Duplicate Filenames

Both consolidation commands automatically handle duplicate filenames:

```
Original: photo.jpg
Existing in target: photo.jpg
Result: photo-1.jpg (renamed)
```

### Missing Files

If an asset record exists in the database but the physical file is missing:
- The migration will skip it and log a warning
- Continue with other assets
- Check logs for details: `storage/logs/console.log`

### Large Volumes

For very large volumes (10,000+ assets):
- Increase `--batchSize` for faster processing
- The script automatically refreshes locks to prevent timeouts
- Progress is shown every 50 assets
- Can be interrupted and resumed (assets already moved are skipped)

## Command Reference

### Status Check
```bash
./craft s3-spaces-migration/volume-consolidation/status
```
Shows current state of volume consolidation needs.

### Merge Volumes
```bash
./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images [options]
```
Moves all assets from `optimisedImages` → `images`.

**Options:**
- `--dryRun=0|1` (default: 1)
- `--yes` (skip confirmations)
- `--batchSize=N` (default: 100)

### Flatten Folders
```bash
./craft s3-spaces-migration/volume-consolidation/flatten-to-root [options]
```
Moves all assets from subfolders → root.

**Options:**
- `--volumeHandle=handle` (default: images)
- `--dryRun=0|1` (default: 1)
- `--yes` (skip confirmations)
- `--batchSize=N` (default: 100)

### Move Originals (Legacy)
```bash
./craft s3-spaces-migration/migration-diag/move-originals [options]
```
Specifically moves assets from `/originals/` folder. Use `flatten-to-root` instead for more comprehensive consolidation.

## Automation Example

For CI/CD or automated migrations:

```bash
#!/bin/bash
set -e

echo "Step 1: Check status"
./craft s3-spaces-migration/volume-consolidation/status

echo "Step 2: Merge optimisedImages → images"
./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images \
  --dryRun=0 \
  --yes \
  --batchSize=200

echo "Step 3: Flatten subfolders to root"
./craft s3-spaces-migration/volume-consolidation/flatten-to-root \
  --dryRun=0 \
  --yes \
  --batchSize=200

echo "Step 4: Verify results"
./craft s3-spaces-migration/migration-diag/analyze

echo "✓ Volume consolidation complete!"
```

## Troubleshooting

### "Volume 'optimisedImages' not found"

The volume might be named `optimizedImages` (American spelling). The script automatically checks both spellings.

### "Failed to move asset X"

Check the logs for details:
```bash
tail -n 100 storage/logs/console.log | grep "Failed to move"
```

Common causes:
- Asset has invalid filename
- Filesystem permissions issue
- Database constraint violation

### "Out of memory" errors

Reduce batch size:
```bash
./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images \
  --dryRun=0 \
  --batchSize=25
```

## Why These Commands Exist

The main `ImageMigrationController` is designed for the standard migration flow where:
- Assets have relations (used in Asset fields or RTE fields)
- Assets need to be analyzed for usage before moving
- Complex inline detection and linking is required

The volume consolidation commands are simpler and more direct:
- Move ALL assets regardless of usage
- No complex analysis required
- Straightforward batch processing
- Designed for the specific edge case of bucket-root volumes

## Next Steps

After successful volume consolidation:

1. **Run URL replacement** to update database references
2. **Regenerate transforms** for the new filesystem structure
3. **Delete old volumes** if no longer needed
4. **Backup and test** thoroughly before going live

See the main migration guide for complete workflow details.
