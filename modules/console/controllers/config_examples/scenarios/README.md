# Migration Configuration Presets

This directory contains pre-built configuration templates for common migration scenarios.

## Available Scenarios

### 1. **root-to-subfolder.php** - Volume at Bucket Root → Subfolder
**The edge case this plugin was designed for!**

- **Use Case**: Source volume at bucket root, target in subfolder
- **Example**: `optimisedImages` at root → consolidate into `images` subfolder
- **Features**:
  - Prevents double indexing using `targetSubfolder` mechanism
  - Uses temporary file approach for nested filesystem safety
  - Automatically switches subfolder after migration completes

**Perfect for**:
- Legacy Craft setups with root-level volumes
- Consolidating optimized image volumes
- Migrating from flat to hierarchical structure

---

### 2. **multi-volume-consolidation.php** - Consolidate Multiple Volumes
- **Use Case**: Multiple source volumes → single target volume
- **Example**: `images`, `documents`, `videos` → unified `assets` volume
- **Features**:
  - Simplifies volume management
  - Preserves folder structure within target
  - Resolves duplicates by usage count

**Perfect for**:
- Simplifying complex multi-volume setups
- Centralizing asset storage
- Reducing S3 bucket complexity

---

### 3. **simple-one-to-one.php** - Basic Migration
- **Use Case**: Single volume, same structure
- **Example**: AWS `images` → DO `images` (1:1 mapping)
- **Features**:
  - Minimal configuration
  - Straightforward migration
  - Good for testing

**Perfect for**:
- First-time users
- Testing the migration process
- Simple Craft installations

---

## How to Use a Preset

### Option 1: Copy to Your Config Directory

```bash
# Copy the preset you want to use
cp modules/console/controllers/config_examples/scenarios/root-to-subfolder.php config/migration-config.php

# Edit with your specific values
nano config/migration-config.php
```

### Option 2: Use as Reference

1. Open the scenario file that matches your use case
2. Copy sections you need into your own `config/migration-config.php`
3. Customize for your specific requirements

---

## Environment Variables Required

All scenarios require these environment variables in your `.env` file:

```bash
# AWS/Source Credentials
S3_KEY_AWS=your-aws-key
S3_SECRET_AWS=your-aws-secret
S3_REGION_AWS=us-east-1
S3_BUCKET_AWS=your-aws-bucket

# DigitalOcean/Target Credentials
DO_SPACES_KEY=your-do-key
DO_SPACES_SECRET=your-do-secret
DO_SPACES_REGION=nyc3
DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
DO_SPACES_BUCKET=your-do-bucket

# Subfolders (customize per scenario)
DO_S3_SUBFOLDER_IMAGES=images
DO_S3_SUBFOLDER_DOCUMENTS=documents
DO_S3_SUBFOLDER_QUARANTINE=quarantine
```

---

## Customizing a Preset

Each preset is a starting point. Customize these sections for your needs:

### 1. Volume Handles
```php
'source' => ['yourVolume'],  // Your actual volume handle
'target' => 'yourTarget',
```

### 2. Subfolder Paths
```php
'subfolder' => '$YOUR_ENV_VARIABLE',
```

### 3. Volume Behavior
```php
'atBucketRoot' => ['volumesAtRoot'],
'withSubfolders' => ['volumesWithFolders'],
```

---

## Need Help?

- **Documentation**: See main `ARCHITECTURE.md` for detailed explanations
- **Issues**: https://github.com/csabourin/do-migration/issues
- **Migration Flow**: Review `ARCHITECTURE.md` for phase-by-phase breakdown

---

## Creating Your Own Preset

Found a common pattern not covered here? Create your own preset:

1. Copy an existing scenario as a template
2. Document your use case at the top
3. Modify the configuration arrays
4. Consider contributing it back to the project!

```php
<?php
/**
 * SCENARIO: Your Use Case Name
 *
 * USE CASE:
 * - What problem does this solve?
 * - What makes it unique?
 *
 * PERFECT FOR:
 * - Who should use this?
 */

return [
    // Your configuration here
];
```
