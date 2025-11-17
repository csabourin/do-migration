# Troubleshooting: Files Exist in Bucket but Migration Reports Them as Missing

## Problem Summary

You're seeing files listed as "missing" in the migration-issues file, but when you search the S3 bucket, those files clearly exist. This is a **path configuration mismatch** issue.

## Root Cause

The Craft CMS volume filesystem configuration doesn't match the actual bucket structure.

### Your Bucket Structure
```
ncc-website-2/
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

All files have a root prefix: **`ncc-website-2/`**

### What's Happening

1. **Craft volumes are configured** with subfolder: `images`
2. **Actual files exist at**: `ncc-website-2/images/FILENAME`
3. **Migration scanner looks for**: `images/FILENAME` (missing the `ncc-website-2/` prefix)
4. **Result**: Files not found → marked as "missing"

## Solution

You need to update your Craft volume filesystem configuration to include the `ncc-website-2/` bucket prefix.

### Method 1: Update Craft Filesystem Configuration (Recommended)

#### Step 1: Access Craft Control Panel

1. Log into your Craft CMS admin panel
2. Navigate to: **Settings → Assets → Filesystems**

#### Step 2: Update Each Filesystem

For each filesystem (images, documents, etc.):

1. Click to edit the filesystem
2. Find the **"Subfolder"** or **"Base Path"** field
3. Update it to include the `ncc-website-2/` prefix:

**Before:**
```
images
```

**After:**
```
ncc-website-2/images
```

**Example filesystem configurations:**

| Volume | Current Subfolder | Corrected Subfolder |
|--------|------------------|---------------------|
| images | `images` | `ncc-website-2/images` |
| documents | `documents` | `ncc-website-2/documents` |
| optimisedImages | `images/originals` | `ncc-website-2/images/originals` |

#### Step 3: Save and Verify

1. Save each filesystem configuration
2. Run the diagnostic command:
   ```bash
   ./craft s3-spaces-migration/migration-diag/check-volumes
   ```
3. Verify the output shows the correct subfolder with `ncc-website-2/` prefix

### Method 2: Update Using Environment Variables

If your filesystem configurations use environment variables:

#### Step 1: Edit Your .env File

Add or update these variables:

```bash
# AWS Source Volume Subfolders
AWS_SOURCE_SUBFOLDER_IMAGES='ncc-website-2/images'
AWS_SOURCE_SUBFOLDER_DOCUMENTS='ncc-website-2/documents'
AWS_SOURCE_SUBFOLDER_OPTIMISED='ncc-website-2/images/originals'

# DO Target Volume Subfolders (no prefix needed on new bucket)
DO_S3_SUBFOLDER_IMAGES='images'
DO_S3_SUBFOLDER_DOCUMENTS='documents'
```

#### Step 2: Update Filesystem Configuration

In your Craft filesystems, set the subfolder to use the environment variable:

```
$AWS_SOURCE_SUBFOLDER_IMAGES
```

#### Step 3: Verify Configuration

```bash
# Check that environment variables are loaded
./craft s3-spaces-migration/migration-check/check

# Verify volumes can see files
./craft s3-spaces-migration/migration-diag/check-volumes
```

### Method 3: Update migration-config.php

If your filesystems are defined in code rather than the Craft CP:

#### Edit config/migration-config.php

Update the filesystem definitions to include the bucket prefix:

```php
$filesystemDefinitions = [
    [
        'handle' => 'images_do',
        'name' => 'Images (DO Spaces)',
        'subfolder' => 'images',  // Target subfolder (no prefix needed)
        'hasUrls' => true,
    ],
    // For AWS source, you might need to update volume config
];

// For source volumes, ensure the subfolder includes the bucket prefix
$volumeConfig = [
    'source' => ['images', 'documents'],
    'target' => 'images',
    'quarantine' => 'quarantine',

    // Add this if needed:
    'sourcePrefixes' => [
        'images' => 'ncc-website-2/images',
        'documents' => 'ncc-website-2/documents',
    ],
];
```

## Verification Steps

After making configuration changes:

### 1. Check Volume Configuration

```bash
./craft s3-spaces-migration/migration-diag/check-volumes
```

Expected output should show:
```
Volume: images
  Filesystem: images (AWS S3)
  Subfolder: 'ncc-website-2/images'
  Files found: 5234
  ✓ Configuration looks correct
```

### 2. Run Migration Check

```bash
./craft s3-spaces-migration/migration-check/check
```

This should now report:
- ✓ Source volumes accessible
- ✓ Files found in source volumes
- Fewer (or zero) broken links

### 3. Re-run Migration Discovery

If you've already started a migration:

```bash
# Clear previous migration state
./craft s3-spaces-migration/image-migration/clear

# Start fresh with correct paths
./craft s3-spaces-migration/image-migration/migrate
```

## Understanding the File Distribution

Your files are distributed across multiple locations:

### Pattern 1: Root Level Files
```
/ncc-website-2/FILENAME.jpg
```
**Purpose**: Legacy files at bucket root

### Pattern 2: Volume Subfolder Files
```
/ncc-website-2/images/FILENAME.jpg
```
**Purpose**: Main files in organized volumes

### Pattern 3: Originals Subfolder
```
/ncc-website-2/images/originals/FILENAME.jpg
```
**Purpose**: Original/unoptimized versions

### Pattern 4: Volume-Level Originals
```
/ncc-website-2/originals/FILENAME.jpg
```
**Purpose**: Original versions at volume level (legacy structure)

### Recommended Source Volume Configuration

To capture all files, configure your source volumes like this:

```php
'source' => [
    'images',           // Points to ncc-website-2/images
    'documents',        // Points to ncc-website-2/documents
    'optimisedImages'   // Points to ncc-website-2/images/originals (if separate volume)
],
```

## Common Questions

### Q: Should I update the DO target volumes too?

**A:** No, you typically want a clean structure on the target. Keep DO subfolders simple:
- DO images: `images` (not `ncc-website-2/images`)
- DO documents: `documents`

The migration will handle moving files from the complex source structure to the clean target structure.

### Q: What if I have multiple bucket prefixes?

**A:** You may need to create separate source volumes for each prefix:
- Volume 1: `ncc-website-2/images` → handle: `images_legacy`
- Volume 2: `images` → handle: `images_new`

Then add both to the source volumes list.

### Q: Will this affect my live site?

**A:** If you're updating the AWS source volumes that your live site uses, YES. Be careful:
1. Test in a dev environment first
2. Take a database backup before changing volume configuration
3. Alternatively, create NEW volumes with the correct paths for migration only

### Q: How do I add a migration-only source volume?

In Craft CP:
1. Settings → Assets → Volumes → New Volume
2. Name: "Images (Migration Source)"
3. Handle: `imagesMigration`
4. Filesystem: Create new filesystem with correct `ncc-website-2/images` subfolder
5. Add `imagesMigration` to your source volumes in migration-config.php

## Next Steps

1. ✅ Update volume/filesystem configuration with `ncc-website-2/` prefix
2. ✅ Run diagnostic checks to verify
3. ✅ Re-run migration discovery phase
4. ✅ Verify missing files are now found
5. ✅ Proceed with migration

## Need More Help?

Run the diagnostic script:
```bash
php diagnose-missing-files.php
```

Check migration logs:
```bash
tail -f storage/logs/web.log | grep -i migration
```

Review volume configuration:
```bash
./craft s3-spaces-migration/migration-diag/check-volumes --verbose
```
