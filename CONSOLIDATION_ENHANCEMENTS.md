# S3 Migration Consolidation Enhancements

## Summary

This document describes the enhancements made to handle the edge case where the `OptimisedImages` volume is at the bucket root and contains all other volumes as subfolders.

## Problem Statement

### The Edge Case

In the current setup:
- **AWS S3 Structure**: `OptimisedImages` volume points to bucket root
- **Other Volumes**: All other volumes (images, documents, videos) exist as subfolders within the bucket
- **After Migration**: Assets are in wrong volumes and wrong folders
- **Goal**: Consolidate all image assets into `Images` volume at root level

### Why ImageMigrationController Wasn't Sufficient

The existing `ImageMigrationController` is designed for:
1. **Usage-based migration**: Only moves assets that are "used" (have relations)
2. **Complex analysis**: Scans RTE fields for inline images
3. **Inline detection**: Links images found in rich text content
4. **Quarantine system**: Moves unused assets to quarantine volume

For the bucket-root edge case:
- ❌ We need to move ALL assets, not just "used" ones
- ❌ Volume structure is the issue, not usage
- ❌ Simple consolidation is needed, not complex analysis

## Solution: VolumeConsolidationController

Created a new dedicated controller for straightforward volume consolidation operations.

### File: `VolumeConsolidationController.php`

Location: `/modules/console/controllers/VolumeConsolidationController.php`

### Commands

#### 1. merge-optimized-to-images

**Purpose**: Move ALL assets from OptimisedImages → Images

**Usage**:
```bash
./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images --dryRun=0
```

**Features**:
- ✅ Moves ALL assets (no usage filtering)
- ✅ Places assets in Images root folder
- ✅ Handles duplicate filenames (auto-rename)
- ✅ Batch processing (memory efficient)
- ✅ Dry run mode (preview before apply)
- ✅ Error recovery and logging
- ✅ Progress tracking

**Implementation Highlights**:
```php
// Get source and target volumes
$sourceVolume = optimisedImages (or optimizedImages)
$targetVolume = images

// Process in batches
foreach (assets in sourceVolume) {
    // Check for duplicates
    if (duplicate exists in target) {
        rename with counter (photo-1.jpg, photo-2.jpg, etc.)
    }

    // Move to target volume root
    asset->volumeId = targetVolume->id
    asset->folderId = targetRootFolder->id
    saveElement(asset)
}
```

#### 2. flatten-to-root

**Purpose**: Move ALL assets from subfolders → root

**Usage**:
```bash
./craft s3-spaces-migration/volume-consolidation/flatten-to-root --dryRun=0
```

**Features**:
- ✅ Flattens entire volume folder structure
- ✅ Handles /originals/ folder automatically
- ✅ Handles duplicate filenames (auto-rename)
- ✅ Batch processing
- ✅ Dry run mode
- ✅ Shows folder structure before flattening

**Implementation Highlights**:
```php
// Get all assets NOT in root folder
$assets = Asset::find()
    ->volumeId($volume->id)
    ->where(['not', ['folderId' => $rootFolder->id]])
    ->all();

// Move each to root
foreach ($assets as $asset) {
    asset->folderId = $rootFolder->id
    saveElement(asset)
}
```

#### 3. status

**Purpose**: Check current consolidation status

**Usage**:
```bash
./craft s3-spaces-migration/volume-consolidation/status
```

**Shows**:
- OptimisedImages volume asset count
- Images volume subfolder asset count
- Recommended actions

## Enhanced Diagnostic Output

### Updated: `MigrationDiagController.php`

#### New Recommendations

Added two new HIGH priority recommendations:

1. **OptimisedImages Volume Detection**:
   ```
   [HIGH] Volume 'optimisedImages' still exists with N assets
       → ./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images --dryRun=0
   ```

2. **Subfolder Detection**:
   ```
   [HIGH] Images volume has N assets in subfolders (should be in root)
       → ./craft s3-spaces-migration/volume-consolidation/flatten-to-root --dryRun=0
   ```

#### Code Changes

```php
// Check OptimisedImages volume
$optimizedVolume = getVolumeByHandle('optimizedImages') ?? getVolumeByHandle('optimisedImages');
if ($optimizedVolume && count > 0) {
    recommendations[] = merge-optimized-to-images command
}

// Check for subfolder assets
$subfolderAssetCount = Asset::find()
    ->volumeId($imagesVolume->id)
    ->where(['not', ['folderId' => $rootFolder->id]])
    ->count();

if ($subfolderAssetCount > 0) {
    recommendations[] = flatten-to-root command
}
```

## Workflow

### Recommended Migration Workflow

```
1. Run diagnostic
   └→ ./craft s3-spaces-migration/migration-diag/analyze

2. Check consolidation status
   └→ ./craft s3-spaces-migration/volume-consolidation/status

3. Merge OptimisedImages → Images (if needed)
   ├→ Dry run: --dryRun=1 (preview)
   └→ Execute: --dryRun=0 (apply)

4. Flatten subfolders → root (if needed)
   ├→ Dry run: --dryRun=1 (preview)
   └→ Execute: --dryRun=0 (apply)

5. Verify results
   └→ ./craft s3-spaces-migration/migration-diag/analyze

6. Configure transform filesystem
   └→ Set Images volume transform FS to optimisedImages_do

7. Continue with standard migration
   └→ URL replacement, transform regeneration, etc.
```

## Recommendations for ImageMigrationController

While the `VolumeConsolidationController` handles the edge case, here are recommendations for improving `ImageMigrationController` in the future:

### 1. Add Configuration Option for "Move All Mode"

**Current Behavior**: Only moves "used" assets (relationCount > 0)

**Proposed Enhancement**:
```php
// Add option to migration-config.php
'migration' => [
    'moveAllAssets' => false,  // Default: only move used assets
    'ignoreUsageCheck' => false, // Force move regardless of usage
]

// In analyzeAssetFileLinks()
if ($config->getMoveAllAssets() || $config->getIgnoreUsageCheck()) {
    // Mark all assets as "used" to force migration
    $analysis['used_assets_wrong_location'][] = $asset;
} else {
    // Current behavior: check isUsed flag
    if ($asset['isUsed']) {
        $analysis['used_assets_wrong_location'][] = $asset;
    }
}
```

### 2. Add Explicit Volume Consolidation Phase

**Current Phases**:
- Phase 0: Preparation
- Phase 1: Discovery & Analysis
- Phase 2: Fix Broken Links
- Phase 3: Consolidate Used Files
- Phase 4: Quarantine Unused

**Proposed Enhancement**:
```php
// Add Phase 2.5: Volume Consolidation
$this->printPhaseHeader("PHASE 2.5: VOLUME CONSOLIDATION");

// Get all assets in source volumes (e.g., optimisedImages)
$assetsToConsolidate = Asset::find()
    ->volumeId($sourceVolumes)
    ->all();

// Move to target volume without checking "isUsed"
foreach ($assetsToConsolidate as $asset) {
    $this->moveAssetCrossVolume($asset, $targetVolume, $targetRootFolder);
}
```

### 3. Add Folder Flattening Option

**Proposed Enhancement**:
```php
// Add option to migration-config.php
'volumes' => [
    'flattenStructure' => true,  // Move all assets to root
    'preserveFolders' => ['keep-this-folder'], // Exceptions
]

// In consolidation phase
if ($config->shouldFlattenStructure()) {
    foreach ($assets as $asset) {
        if ($asset->folderId !== $rootFolder->id) {
            $asset->folderId = $rootFolder->id;
            saveElement($asset);
        }
    }
}
```

### 4. Improve Detection of Bucket-Root Volumes

**Proposed Enhancement**:
```php
// In migration-config.php, already exists:
'volumes' => [
    'atBucketRoot' => ['optimisedImages'],
]

// Use this in ImageMigrationController
$bucketRootVolumes = $config->getVolumesAtBucketRoot();

// Special handling for bucket root volumes
foreach ($bucketRootVolumes as $volumeHandle) {
    // These volumes need special treatment:
    // - May contain other volumes as subfolders
    // - Should be consolidated first
    // - Assets should be moved OUT of these volumes
}
```

### 5. Add Pre-Migration Validation

**Proposed Enhancement**:
```php
// Before starting migration, validate volume structure
public function actionValidateStructure(): int
{
    $issues = [];

    // Check for bucket-root volumes with assets
    $bucketRootVolumes = $config->getVolumesAtBucketRoot();
    foreach ($bucketRootVolumes as $handle) {
        $count = Asset::find()->volumeId($volume->id)->count();
        if ($count > 0) {
            $issues[] = [
                'type' => 'bucket_root_volume',
                'volume' => $handle,
                'count' => $count,
                'recommendation' => 'Run volume-consolidation/merge first'
            ];
        }
    }

    // Check for deep folder structures
    $deepFolders = // Find folders more than 2 levels deep
    if ($deepFolders > 0) {
        $issues[] = [
            'type' => 'deep_structure',
            'recommendation' => 'Consider flattening structure first'
        ];
    }

    return $issues;
}
```

## Benefits of This Approach

### Separation of Concerns

- **VolumeConsolidationController**: Simple, direct operations
- **ImageMigrationController**: Complex migration with analysis

### Flexibility

- Users can run consolidation separately
- Can be used outside of full migration
- Useful for other scenarios (not just S3 migration)

### Maintainability

- Easier to understand and debug
- Each controller has a clear purpose
- Less risk of breaking existing migrations

### Robustness

- ✅ Handles duplicate filenames
- ✅ Batch processing for large volumes
- ✅ Dry run mode for safety
- ✅ Progress tracking
- ✅ Error recovery
- ✅ Atomic operations

## Configuration Example

For the edge case, update `migration-config.php`:

```php
// Volume configuration
'volumes' => [
    'source' => ['optimisedImages'],  // Volume to consolidate FROM
    'target' => 'images',              // Volume to consolidate TO
    'atBucketRoot' => ['optimisedImages'], // Mark as bucket root
    'withSubfolders' => ['images'],    // Will need flattening
],

// Filesystem mappings
'filesystemMappings' => [
    'optimisedImages' => 'optimisedImages_do',
    'images' => 'images_do',
],
```

## Testing

### Test Scenarios

1. **Empty Source Volume**:
   - ✅ Handles gracefully, exits with success

2. **Duplicate Filenames**:
   - ✅ Auto-renames with counter (photo-1.jpg, photo-2.jpg)

3. **Large Volumes (10,000+ assets)**:
   - ✅ Batch processing prevents memory issues
   - ✅ Progress tracking shows status

4. **Interrupted Migration**:
   - ✅ Can be rerun safely (idempotent)
   - ✅ Already-moved assets are skipped

5. **Missing Files**:
   - ✅ Logs warning, continues with next asset
   - ✅ Doesn't crash entire migration

## Future Enhancements

### Potential Improvements

1. **Parallel Processing**: Use Craft Queue system for background processing
2. **Rollback Support**: Create rollback logs for volume consolidation
3. **Dry Run Statistics**: Show more detailed preview of changes
4. **File Integrity Checks**: Verify file hashes before/after move
5. **Folder Preservation Option**: Allow selective folder preservation

### Integration with Main Migration

Consider adding volume consolidation as an optional phase in `ImageMigrationController`:

```php
// In actionMigrate()
if ($this->shouldConsolidateVolumes()) {
    $this->printPhaseHeader("PHASE 0.5: VOLUME CONSOLIDATION");
    $this->runVolumeConsolidation();
}
```

## Conclusion

The new `VolumeConsolidationController` provides a robust, straightforward solution for the bucket-root edge case, while keeping the existing `ImageMigrationController` focused on its primary purpose of intelligent asset migration with usage analysis.

The separation of concerns makes both controllers easier to maintain, test, and use independently.
