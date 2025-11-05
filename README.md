# AWS S3 to DigitalOcean Spaces Migration Toolkit

**Complete production-grade migration suite for Craft CMS 4**

Migrating from: **AWS S3 bucket (ncc-website-2, ca-central-1)**
Migrating to: **DigitalOcean Spaces (Toronto - tor1)**

---

## 📋 Table of Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Quick Setup](#quick-setup)
- [Complete Migration Workflow](#complete-migration-workflow)
- [Available Controllers](#available-controllers)
- [Configuration System](#configuration-system)
- [Documentation](#documentation)
- [Troubleshooting](#troubleshooting)

---

## Overview

This toolkit provides **11 specialized controllers** for migrating all aspects of a Craft CMS 4 installation from AWS S3 to DigitalOcean Spaces:

- ✅ Database content URL replacement
- ✅ Template file URL updates
- ✅ Physical asset file migration (with checkpoint/resume)
- ✅ Filesystem and volume management
- ✅ Pre-migration validation
- ✅ Post-migration verification
- ✅ Transform discovery and pre-generation
- ✅ Plugin configuration auditing

**Coverage:** 85-90% automated → 95-98% with additional steps

**Namespace:** All commands use `craft ncc-module/{controller}/{action}`

---

## Prerequisites

### 1. Craft CMS Setup
- Craft CMS 4.x installed and running
- DDEV or local PHP environment
- Database backup completed
- Admin access to Craft CP

### 2. DigitalOcean Spaces Setup
- DigitalOcean Spaces bucket created
- Access key and secret key generated
- Bucket permissions configured (read/write)
- CORS configured if needed

### 3. Required Craft Plugins
- [vaersaagod/dospaces](https://github.com/vaersaagod/dospaces) plugin installed
- Or custom S3-compatible filesystem adapter

### 4. Environment Variables
Add to your `.env` file:
```bash
# Migration Environment
MIGRATION_ENV=dev  # or staging, prod

# DigitalOcean Spaces Credentials
DO_S3_ACCESS_KEY=your_access_key
DO_S3_SECRET_KEY=your_secret_key
DO_S3_BUCKET=your-bucket-name
DO_S3_BASE_URL=https://your-bucket.tor1.digitaloceanspaces.com
DO_S3_REGION=tor1

# Subfolders (optional - can be empty for root-level)
DO_S3_SUBFOLDER_IMAGES=images
DO_S3_SUBFOLDER_OPTIMISEDIMAGES=optimisedImages
DO_S3_SUBFOLDER_IMAGETRANSFORMS=imageTransforms
DO_S3_SUBFOLDER_DOCUMENTS=documents
DO_S3_SUBFOLDER_VIDEOS=videos
DO_S3_SUBFOLDER_FORMDOCUMENTS=formDocuments
DO_S3_SUBFOLDER_CHARTDATA=chartData
DO_S3_SUBFOLDER_QUARANTINE=quarantine
```

See `config/.env.example` for complete example.

---

## Quick Setup

### Step 1: Copy Configuration Files

```bash
# Copy centralized config (recommended but not yet integrated)
cp config/migration-config.php your-craft-project/config/

# Copy helper class
cp MigrationConfig.php your-craft-project/modules/helpers/

# Copy environment template
cp config/.env.dev .env
# Edit .env with your actual credentials
```

**Note:** The centralized configuration system is available but controllers have not yet been updated to use it. Controllers currently use hardcoded values that must be manually updated. See [Configuration Status](#configuration-status) below.

### Step 2: Install Controllers

Copy all controller files to your Craft modules directory:

```bash
# Copy all controllers
cp *Controller.php your-craft-project/modules/console/controllers/
```

Controllers to copy:
- `FilesystemController.php` - Create DO Spaces filesystems
- `FilesystemSwitchController.php` - Switch volumes between AWS/DO
- `UrlReplacementController.php` - Replace URLs in database
- `TemplateUrlReplacementController.php` - Replace URLs in templates
- `ImageMigrationController.php` - Migrate physical asset files
- `MigrationCheckController.php` - Pre-migration validation
- `FsDiagController.php` - Filesystem diagnostics
- `MigrationDiagController.php` - Post-migration analysis
- `TransformDiscoveryController.php` - Discover image transforms
- `TransformPreGenerationController.php` - Pre-generate transforms
- `PluginConfigAuditController.php` - Audit plugin configurations

### Step 3: Update Namespace

Ensure your Craft module is configured with the `ncc-module` namespace. In your module class:

```php
namespace modules;

use craft\console\Application as ConsoleApplication;
use yii\base\Event;

class Module extends \yii\base\Module
{
    public function init()
    {
        parent::init();

        // Set module ID
        Craft::$app->setModule('ncc-module', $this);

        // Register console controllers
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'modules\\console\\controllers';
        }
    }
}
```

### Step 4: Verify Installation

```bash
# List available commands
./craft help ncc-module

# Test a simple command
./craft ncc-module/fs-diag/list-fs
```

---

## Complete Migration Workflow

Follow these steps **in order** for a complete migration:

### Phase 0: Setup (Do This First!)

#### 0.1 Create DigitalOcean Spaces Filesystems in Craft

**Before any migration**, you must create the DO Spaces filesystems in Craft CMS:

```bash
# Show what filesystems will be created
./craft ncc-module/filesystem/show-plan

# Create all DO Spaces filesystems
./craft ncc-module/filesystem/create-all

# Verify filesystems were created
./craft ncc-module/fs-diag/list-fs
```

This creates 8 filesystems:
- `images_do` - Main images
- `optimisedImages_do` - Optimized images
- `imageTransforms_do` - Image transforms
- `documents_do` - Documents
- `videos_do` - Videos
- `formDocuments_do` - Form documents
- `chartData_do` - Chart data
- `quarantine` - Quarantine (unused assets)

**Manual Alternative:** Create filesystems in Craft CP:
1. Settings → Assets → Filesystems
2. Click "+ New filesystem"
3. Configure for each volume (see `config/migration-config.php` for details)

#### 0.2 Update Volume Configurations (Optional)

You can switch volumes to DO now or wait until after file migration:

```bash
# Show current volume assignments
./craft ncc-module/filesystem-switch/show

# Switch a specific volume to DO (optional - can wait)
# ./craft ncc-module/filesystem-switch/to-do images
```

**Recommendation:** Wait until after file migration to switch volumes.

---

### Phase 1: Pre-Migration Checks

#### 1.1 Run Diagnostics

```bash
# Check filesystem connectivity
./craft ncc-module/fs-diag/test-connection images_do
./craft ncc-module/fs-diag/test-connection optimisedImages_do

# List all filesystems and their status
./craft ncc-module/fs-diag/list-fs

# Run comprehensive pre-migration check
./craft ncc-module/migration-check/check-all
```

#### 1.2 Backup Everything

```bash
# Backup database
./craft db/backup

# Or using DDEV
ddev export-db --file=backup-before-migration.sql.gz

# Backup templates and config
tar -czf backup-files.tar.gz templates/ config/ modules/
```

#### 1.3 Scan for S3 References

```bash
# Scan plugin configurations
./craft ncc-module/plugin-config-audit/scan

# Search for hardcoded S3 URLs in codebase
grep -r "s3.amazonaws.com\|ncc-website-2" config/ modules/ templates/
```

---

### Phase 2: Database URL Replacement

Replace all AWS S3 URLs in database content with DO Spaces URLs.

#### 2.1 Dry Run (Test First!)

```bash
# Dry run - shows what will be replaced without making changes
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1
```

Review the output carefully:
- Number of rows affected
- Sample URLs to be replaced
- Tables and columns to be modified

#### 2.2 Execute Replacement

```bash
# Live execution - will modify database
./craft ncc-module/url-replacement/replace-s3-urls

# Confirm with 'y' when prompted
```

#### 2.3 Verify Database Replacement

```bash
# Verify no AWS URLs remain
./craft ncc-module/url-replacement/verify

# Show statistics about replaced URLs
./craft ncc-module/url-replacement/show-stats
```

**Expected Result:** "✓ No AWS S3 URLs found in database content"

---

### Phase 3: Template URL Replacement

Replace AWS S3 URLs in Twig template files.

#### 3.1 Scan Templates

```bash
# Scan all template files for S3 URLs
./craft ncc-module/template-url/scan
```

Review which templates contain S3 URLs.

#### 3.2 Backup Templates

```bash
# Backup is automatic, but verify
ls -la templates/*.backup-*
```

#### 3.3 Replace URLs in Templates

```bash
# Replace URLs in all templates
./craft ncc-module/template-url/replace
```

#### 3.4 Verify Template Replacement

```bash
# Verify no AWS URLs remain in templates
./craft ncc-module/template-url/verify

# Or manual check
grep -r "s3.amazonaws.com\|ncc-website-2" templates/
```

---

### Phase 4: Physical Asset File Migration

Migrate actual asset files from AWS S3 to DO Spaces.

**Features:**
- Checkpoint system (resume if interrupted)
- Change logging for rollback
- Progress tracking
- Orphaned file handling
- Error detection and recovery

#### 4.1 Prepare for Migration

```bash
# Show migration plan
./craft ncc-module/image-migration/show-plan

# Check asset statistics
./craft ncc-module/image-migration/show-stats
```

#### 4.2 Dry Run (Recommended)

```bash
# Test migration without moving files
./craft ncc-module/image-migration/migrate --dryRun=1
```

#### 4.3 Execute Migration

```bash
# Start migration (can be interrupted and resumed)
./craft ncc-module/image-migration/migrate

# The migration will:
# - Move files from AWS to DO
# - Create checkpoints every 100 assets
# - Log all changes
# - Handle orphaned files
# - Show progress and ETA
```

**If interrupted**, simply run the same command again:
```bash
# Resume from last checkpoint
./craft ncc-module/image-migration/migrate
```

#### 4.4 Monitor Progress

```bash
# Check migration status
./craft ncc-module/image-migration/status

# View change log
./craft ncc-module/image-migration/show-changes
```

#### 4.5 Verify File Migration

```bash
# Verify all files migrated successfully
./craft ncc-module/migration-check/verify-files

# Check for broken asset links
./craft ncc-module/migration-check/check-broken-assets
```

---

### Phase 5: Switch Filesystems

Point Craft volumes to use DigitalOcean Spaces filesystems.

#### 5.1 Show Current Status

```bash
# Show which volumes use which filesystems
./craft ncc-module/filesystem-switch/show
```

#### 5.2 Switch to DigitalOcean

```bash
# Switch all volumes to DO Spaces
./craft ncc-module/filesystem-switch/to-do

# Or switch individual volumes
./craft ncc-module/filesystem-switch/to-do images
./craft ncc-module/filesystem-switch/to-do optimisedImages
```

#### 5.3 Verify Switch

```bash
# Verify volumes are using DO filesystems
./craft ncc-module/filesystem-switch/verify

# Test file access
./craft ncc-module/fs-diag/list-files images_do --limit=10
```

---

### Phase 6: Post-Migration Tasks

Critical tasks to complete after migration.

#### 6.1 Rebuild Search Indexes

```bash
# CRITICAL: Rebuild asset indexes
./craft index-assets/all

# Rebuild search indexes for entries
./craft resave/entries --update-search-index=1

# Resave all assets (updates URLs in search)
./craft resave/assets
```

#### 6.2 Clear All Caches

```bash
# Clear Craft caches
./craft clear-caches/all
./craft invalidate-tags/all

# Clear template caches
./craft clear-caches/template-caches

# Clear data cache
./craft clear-caches/data-caches
```

#### 6.3 Purge CDN Cache

If using CloudFlare, Fastly, or other CDN:

```bash
# CloudFlare: Dashboard → Caching → Purge Everything
# OR use CloudFlare API

# Fastly: Dashboard → Purge → Purge All
# OR use Fastly API
```

#### 6.4 Run Post-Migration Diagnostics

```bash
# Comprehensive post-migration analysis
./craft ncc-module/migration-diag/analyze

# Check for any remaining issues
./craft ncc-module/migration-check/check-all
```

---

### Phase 7: Image Transforms (If Applicable)

If your site uses image transforms:

#### 7.1 Discover Transforms

```bash
# Discover all image transforms used
./craft ncc-module/transform-discovery/scan

# Show transform statistics
./craft ncc-module/transform-discovery/show-stats
```

#### 7.2 Pre-Generate Transforms

```bash
# Pre-generate transforms on DO Spaces (optional)
./craft ncc-module/transform-pre-generation/generate

# This can take several hours for large sites
# Consider running in background or scheduled job
```

---

### Phase 8: Final Verification

#### 8.1 Database Scan

```bash
# Final comprehensive database scan
./craft db/query "SELECT COUNT(*) as count FROM content WHERE field_body LIKE '%s3.amazonaws%'"
./craft db/query "SELECT COUNT(*) as count FROM content WHERE field_body LIKE '%ncc-website-2%'"

# Check projectconfig table (Craft 4)
./craft db/query "SELECT path FROM projectconfig WHERE value LIKE '%s3.amazonaws%'"
```

**Expected Result:** All queries return 0 rows.

#### 8.2 Manual Testing

Test the following manually:
- [ ] Browse the website - images display correctly
- [ ] Test image uploads in Craft CP
- [ ] Test Redactor/CKEditor image insertion
- [ ] Check asset browser in CP works
- [ ] Verify image transforms generate correctly
- [ ] Test from different browsers
- [ ] Check mobile responsiveness

#### 8.3 Monitor Logs

```bash
# Watch for errors (let run for a few hours)
tail -f storage/logs/web.log
tail -f storage/logs/console.log

# Check for 404 errors in server logs
grep "404" /var/log/nginx/access.log | grep -i "\.jpg\|\.png\|\.gif\|\.svg"
```

---

### Phase 9: Additional Edge Cases

Handle remaining edge cases for 100% coverage:

#### 9.1 Plugin Configurations

Update any plugins that reference S3:

```bash
# Check plugin configs
ls -la config/imager-x.php config/blitz.php config/redactor.php

# Edit any S3 references manually
```

Common plugins to check:
- **Imager-X:** Transform storage locations
- **Blitz:** Static cache storage
- **Redactor:** Custom config paths
- **Feed Me:** Import source URLs

#### 9.2 JSON Fields

If you have Table fields or JSON columns:

```bash
# Search for S3 URLs in JSON fields
./craft db/query "SELECT * FROM content WHERE field_tableField LIKE '%s3.amazonaws%' LIMIT 5"

# Manual update required - see EXTENDED_CONTROLLERS.md
```

#### 9.3 Static Assets (JS/CSS)

```bash
# Search JS/CSS for hardcoded S3 URLs
grep -r "s3.amazonaws.com\|ncc-website-2" web/assets/ web/dist/

# Update any found references
```

---

## Available Controllers

### 1. FilesystemController
**Purpose:** Create DigitalOcean Spaces filesystems in Craft

```bash
# Show plan
./craft ncc-module/filesystem/show-plan

# Create all filesystems
./craft ncc-module/filesystem/create-all

# Create individual filesystem
./craft ncc-module/filesystem/create images_do
```

### 2. FilesystemSwitchController
**Purpose:** Switch volumes between AWS and DO filesystems

```bash
# Show current status
./craft ncc-module/filesystem-switch/show

# Switch to DO
./craft ncc-module/filesystem-switch/to-do [volume-handle]

# Switch back to AWS (rollback)
./craft ncc-module/filesystem-switch/to-aws [volume-handle]

# Verify switch
./craft ncc-module/filesystem-switch/verify
```

### 3. UrlReplacementController
**Purpose:** Replace AWS S3 URLs with DO Spaces URLs in database content

```bash
# Dry run (test without changes)
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1

# Live execution
./craft ncc-module/url-replacement/replace-s3-urls

# Verify replacement
./craft ncc-module/url-replacement/verify

# Show statistics
./craft ncc-module/url-replacement/show-stats
```

### 4. TemplateUrlReplacementController
**Purpose:** Replace AWS S3 URLs in Twig template files

```bash
# Scan templates
./craft ncc-module/template-url/scan

# Replace URLs
./craft ncc-module/template-url/replace

# Verify
./craft ncc-module/template-url/verify

# Show backups
./craft ncc-module/template-url/list-backups
```

### 5. ImageMigrationController
**Purpose:** Migrate physical asset files from AWS to DO

```bash
# Show plan
./craft ncc-module/image-migration/show-plan

# Dry run
./craft ncc-module/image-migration/migrate --dryRun=1

# Execute migration
./craft ncc-module/image-migration/migrate

# Resume (if interrupted)
./craft ncc-module/image-migration/migrate

# Show status
./craft ncc-module/image-migration/status

# Show changes
./craft ncc-module/image-migration/show-changes

# Rollback (if needed)
./craft ncc-module/image-migration/rollback
```

### 6. MigrationCheckController
**Purpose:** Pre-migration validation and checks

```bash
# Check all
./craft ncc-module/migration-check/check-all

# Individual checks
./craft ncc-module/migration-check/check-filesystems
./craft ncc-module/migration-check/check-credentials
./craft ncc-module/migration-check/check-volumes
./craft ncc-module/migration-check/verify-files
./craft ncc-module/migration-check/check-broken-assets
```

### 7. FsDiagController
**Purpose:** Filesystem diagnostics and testing

```bash
# List all filesystems
./craft ncc-module/fs-diag/list-fs

# Test connection
./craft ncc-module/fs-diag/test-connection [filesystem-handle]

# List files
./craft ncc-module/fs-diag/list-files [filesystem-handle] --limit=20

# Show filesystem info
./craft ncc-module/fs-diag/info [filesystem-handle]
```

### 8. MigrationDiagController
**Purpose:** Post-migration analysis and diagnostics

```bash
# Comprehensive analysis
./craft ncc-module/migration-diag/analyze

# Check specific aspects
./craft ncc-module/migration-diag/check-volumes
./craft ncc-module/migration-diag/check-assets
./craft ncc-module/migration-diag/check-transforms
```

### 9. TransformDiscoveryController
**Purpose:** Discover image transforms used in the site

```bash
# Scan for transforms
./craft ncc-module/transform-discovery/scan

# Show statistics
./craft ncc-module/transform-discovery/show-stats

# List all transforms
./craft ncc-module/transform-discovery/list
```

### 10. TransformPreGenerationController
**Purpose:** Pre-generate image transforms

```bash
# Generate all transforms
./craft ncc-module/transform-pre-generation/generate

# Generate for specific volume
./craft ncc-module/transform-pre-generation/generate --volume=images

# Show progress
./craft ncc-module/transform-pre-generation/status
```

### 11. PluginConfigAuditController
**Purpose:** Audit plugin configurations for S3 references

```bash
# List installed plugins
./craft ncc-module/plugin-config-audit/list-plugins

# Scan for S3 references
./craft ncc-module/plugin-config-audit/scan
```

---

## Configuration System

### Centralized Configuration

The toolkit includes a centralized configuration system (`config/migration-config.php` and `MigrationConfig.php`) that provides:

- ✅ Single source of truth for all settings
- ✅ Environment-aware (dev/staging/prod)
- ✅ Type-safe accessor methods
- ✅ Auto-validation of required settings
- ✅ No hardcoded values needed

### Configuration Status

⚠️ **IMPORTANT:** The configuration files are available but **controllers have NOT yet been integrated** to use them.

**Current Status:**
- ✅ `config/migration-config.php` - Created and ready
- ✅ `MigrationConfig.php` helper class - Created and ready
- ⚠️ Controllers still use hardcoded values
- ⚠️ Manual updates required in controller files

**What Needs To Be Done:**

Each controller currently has hardcoded values like:
```php
// Example from UrlReplacementController.php (line 156)
$newUrl = $customNewUrl ?? 'https://dev-medias-test.tor1.digitaloceanspaces.com';

// Should be replaced with:
$config = MigrationConfig::getInstance();
$newUrl = $customNewUrl ?? $config->getDigitalOceanBaseUrl();
```

**To Integrate Configuration:**

1. Add MigrationConfig to controller imports:
```php
use modules\helpers\MigrationConfig;
```

2. Replace hardcoded values with config methods:
```php
$config = MigrationConfig::getInstance();
$doUrl = $config->getDigitalOceanBaseUrl();
$doBucket = $config->getDigitalOceanBucket();
$awsUrls = $config->getAwsUrls();
```

3. Test each controller after updating

See **[CONFIGURATION_GUIDE.md](CONFIGURATION_GUIDE.md)** for complete integration instructions and **[examples/UrlReplacementController.refactored.php](examples/UrlReplacementController.refactored.php)** for a working example.

### Manual Configuration (Current Approach)

Until controllers are integrated, you must manually edit each controller file to update hardcoded values:

**Files to Update:**
- `UrlReplacementController.php` - Line 156 (DO Spaces URL), lines 161-171 (AWS URLs)
- `TemplateUrlReplacementController.php` - Similar hardcoded URLs
- `ImageMigrationController.php` - Line 62 (rootLevelVolumes), filesystem handles
- `FilesystemController.php` - DO Spaces configuration
- Other controllers as needed

**Values to Update:**
- AWS S3 bucket name: `ncc-website-2`
- AWS S3 region: `ca-central-1`
- AWS S3 URLs (6 different formats)
- DO Spaces bucket name
- DO Spaces base URL
- DO Spaces region: `tor1`
- Filesystem handles

---

## Documentation

### Core Documentation

| File | Purpose | When to Read |
|------|---------|--------------|
| **[README.md](README.md)** | Main guide with complete workflow (this file) | Start here |
| **[MIGRATION_ANALYSIS.md](MIGRATION_ANALYSIS.md)** | Comprehensive coverage analysis, gaps, action plan | Before starting |
| **[QUICK_CHECKLIST.md](QUICK_CHECKLIST.md)** | Quick reference checklist for execution | During migration |
| **[migrationGuide.md](migrationGuide.md)** | Detailed operational guide for controllers | Reference as needed |

### Configuration Documentation

| File | Purpose | When to Read |
|------|---------|--------------|
| **[CONFIGURATION_GUIDE.md](CONFIGURATION_GUIDE.md)** | Complete guide to centralized config system | Before integration |
| **[CONFIG_QUICK_REFERENCE.md](CONFIG_QUICK_REFERENCE.md)** | Quick reference for config methods | During development |
| **[examples/UrlReplacementController.refactored.php](examples/UrlReplacementController.refactored.php)** | Working example of config integration | When refactoring |

### Advanced Documentation

| File | Purpose | When to Read |
|------|---------|--------------|
| **[EXTENDED_CONTROLLERS.md](EXTENDED_CONTROLLERS.md)** | Additional controllers for edge cases (JSON fields, plugins, static assets) | For 95%+ coverage |
| **[ARCHITECTURE_RECOMMENDATION.md](ARCHITECTURE_RECOMMENDATION.md)** | Analysis of splitting large controllers | If refactoring |
| **[MANAGER_EXTRACTION_GUIDE.md](MANAGER_EXTRACTION_GUIDE.md)** | Guide for extracting manager classes | If refactoring |

### Configuration Files

| File | Purpose |
|------|---------|
| **config/migration-config.php** | Centralized configuration (not yet integrated) |
| **config/.env.example** | Environment variables template |
| **config/.env.dev** | Development environment example |
| **config/.env.staging** | Staging environment example |
| **config/.env.prod** | Production environment example |
| **MigrationConfig.php** | Configuration helper class (not yet integrated) |

---

## Troubleshooting

### Images Not Displaying After Migration

**Symptoms:** Images show as broken links or 404 errors

**Solutions:**
```bash
# 1. Clear caches
./craft clear-caches/all

# 2. Verify filesystem switch
./craft ncc-module/filesystem-switch/verify

# 3. Test DO connectivity
./craft ncc-module/fs-diag/test-connection images_do

# 4. Check file exists in DO
./craft ncc-module/fs-diag/list-files images_do --limit=10

# 5. Check browser network tab for actual error
# Look at failed image URLs
```

### Still Finding AWS URLs in Database

**Symptoms:** Verification shows remaining AWS URLs

**Solutions:**
```bash
# 1. Identify exact location
./craft db/query "SELECT * FROM content WHERE field_body LIKE '%s3.amazonaws%' LIMIT 1"

# 2. Check if JSON field (needs special handling)
# If field contains JSON, see EXTENDED_CONTROLLERS.md

# 3. Check additional tables
./craft db/query "SELECT * FROM projectconfig WHERE value LIKE '%s3.amazonaws%'"
./craft db/query "SELECT * FROM elements_sites WHERE metadata LIKE '%s3.amazonaws%'"

# 4. Check revisions
./craft db/query "SELECT * FROM revisions WHERE data LIKE '%s3.amazonaws%'"
```

### Transforms Not Generating

**Symptoms:** Transform URLs return 404 errors

**Solutions:**
```bash
# 1. Check imageTransforms volume
./craft ncc-module/fs-diag/test-connection imageTransforms_do

# 2. Check transforms filesystem settings
./craft ncc-module/fs-diag/info imageTransforms_do

# 3. Clear transform cache
./craft clear-caches/asset-transform-index
./craft clear-caches/asset-indexes

# 4. Manually trigger transform
# Visit transform URL in browser

# 5. Check DO Spaces permissions
# Ensure write permissions enabled
```

### Migration Interrupted

**Symptoms:** Migration stopped mid-process

**Solutions:**
```bash
# 1. Simply resume - checkpoints handle this
./craft ncc-module/image-migration/migrate

# 2. Check last checkpoint
./craft ncc-module/image-migration/status

# 3. View change log
./craft ncc-module/image-migration/show-changes
```

### Permission Errors

**Symptoms:** "Access denied" or permission errors

**Solutions:**
```bash
# 1. Check DO Spaces permissions
# Dashboard → Spaces → Settings → Permissions

# 2. Verify credentials
./craft ncc-module/fs-diag/test-connection images_do

# 3. Check access key scope
# Ensure key has read/write permissions

# 4. Verify CORS settings (if browser errors)
```

### High Memory Usage

**Symptoms:** PHP memory errors during migration

**Solutions:**
```bash
# 1. Reduce batch size
# Edit ImageMigrationController.php:
# Line ~80: private $batchSize = 50; // reduced from 100

# 2. Increase PHP memory limit
# In .env:
PHP_MEMORY_LIMIT=512M

# Or in php.ini:
memory_limit = 512M

# 3. Process in smaller chunks
./craft ncc-module/image-migration/migrate --limit=500
```

### Enable Debug Logging

```bash
# Add to .env
CRAFT_DEV_MODE=true
CRAFT_LOG_LEVEL=4

# Watch logs
tail -f storage/logs/console.log
tail -f storage/logs/web.log
```

---

## Success Criteria

Migration is **100% complete** when:

- ✅ Database: No AWS URLs found in any content tables
- ✅ Templates: No AWS URLs found in any template files
- ✅ Files: All asset files migrated to DO Spaces
- ✅ Volumes: All volumes pointing to DO filesystems
- ✅ Website: Images display correctly on frontend
- ✅ Admin: Asset browser works in Craft CP
- ✅ Uploads: New file uploads work correctly
- ✅ Transforms: Image transforms generate correctly
- ✅ Search: Search indexes rebuilt
- ✅ Caches: All caches cleared (Craft + CDN)
- ✅ Logs: No 404 errors for assets
- ✅ Plugins: Plugin configs updated
- ✅ Testing: Manual testing passes

---

## Migration Statistics

### Source (AWS S3)
- **Bucket:** ncc-website-2
- **Region:** ca-central-1
- **URL Formats:** 6 different patterns detected

### Target (DigitalOcean Spaces)
- **Region:** tor1 (Toronto)
- **Filesystems:** 8 (images, optimisedImages, imageTransforms, documents, videos, formDocuments, chartData, quarantine)
- **Subfolders:** Configurable per filesystem

### Toolkit
- **Controllers:** 11 specialized controllers
- **Documentation:** 9 comprehensive guides
- **Coverage:** 85-90% automated → 95-98% with additional steps
- **Namespace:** `ncc-module`
- **Estimated Time:** 3-5 days for complete migration

---

## Support & Resources

### Documentation
- [Craft CMS 4 Documentation](https://craftcms.com/docs/4.x/)
- [DigitalOcean Spaces Documentation](https://docs.digitalocean.com/products/spaces/)
- [vaersaagod/dospaces Plugin](https://github.com/vaersaagod/dospaces)

### Enable Logging
```bash
# .env
CRAFT_DEV_MODE=true
CRAFT_LOG_LEVEL=4
```

### Run Diagnostics
```bash
./craft ncc-module/migration-diag/analyze
./craft ncc-module/fs-diag/list-fs
```

---

## Version History

### v2.0 (2025-11-05)
- ✅ Reorganized README with complete step-by-step workflow
- ✅ Added Phase 0: Setup section (create filesystems first!)
- ✅ Documented configuration status (available but not yet integrated)
- ✅ Removed irrelevant summary/changelog .md files
- ✅ Updated all documentation references
- ✅ Added comprehensive troubleshooting section
- ✅ Improved controller documentation
- ✅ Added success criteria checklist

### v1.0 (2025-11-05)
- Initial comprehensive analysis
- Created migration analysis and documentation
- Built centralized configuration system
- Updated namespace to ncc-module

---

## Quick Command Reference

```bash
# === SETUP (DO THIS FIRST!) ===
./craft ncc-module/filesystem/create-all

# === PRE-MIGRATION ===
./craft ncc-module/fs-diag/list-fs
./craft ncc-module/migration-check/check-all
./craft db/backup

# === DATABASE ===
./craft ncc-module/url-replacement/replace-s3-urls --dryRun=1
./craft ncc-module/url-replacement/replace-s3-urls
./craft ncc-module/url-replacement/verify

# === TEMPLATES ===
./craft ncc-module/template-url/scan
./craft ncc-module/template-url/replace
./craft ncc-module/template-url/verify

# === FILES ===
./craft ncc-module/image-migration/migrate
./craft ncc-module/image-migration/status

# === SWITCH ===
./craft ncc-module/filesystem-switch/to-do
./craft ncc-module/filesystem-switch/verify

# === POST-MIGRATION ===
./craft index-assets/all
./craft resave/entries --update-search-index=1
./craft clear-caches/all
./craft ncc-module/migration-diag/analyze
```

---

**Project:** do-migration
**Status:** Ready for Execution 🚀
**Target:** 100% AWS S3 → DigitalOcean Spaces Migration
**Confidence:** 95-98% coverage achievable
**Last Updated:** 2025-11-05
**Version:** 2.0
