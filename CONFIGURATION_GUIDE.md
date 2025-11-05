# Centralized Configuration System - Implementation Guide

**Single Source of Truth for Multi-Environment Migration**

This guide explains how to implement and use the centralized configuration system for your AWS S3 to DigitalOcean Spaces migration toolkit.

---

## 🎯 Overview

### **Problem Solved**
- ✅ Eliminates hardcoded values across multiple controllers
- ✅ Supports multiple environments (dev, staging, prod)
- ✅ Single point of configuration management
- ✅ Easy to switch between environments
- ✅ Validates configuration before operations
- ✅ Type-safe access to all settings

### **Architecture**
```
.env (environment variables)
    ↓
migration-config.php (centralized config with env logic)
    ↓
MigrationConfig.php (helper class with typed methods)
    ↓
Controllers (use config helper for all settings)
```

---

## 📁 File Structure

```
your-craft-project/
├── config/
│   ├── migration-config.php          ← Main config file (copy here)
│   ├── .env.example                   ← Example environment vars
│   ├── .env.dev                       ← Dev environment vars
│   ├── .env.staging                   ← Staging environment vars
│   └── .env.prod                      ← Production environment vars
├── modules/
│   ├── helpers/
│   │   └── MigrationConfig.php        ← Config helper class
│   └── console/
│       └── controllers/
│           ├── UrlReplacementController.php      (refactored)
│           ├── FilesystemController.php          (refactored)
│           ├── ImageMigrationController.php      (refactored)
│           └── ... (all other controllers)
└── .env                                ← Active environment file
```

---

## 🚀 Quick Start

### **Step 1: Copy Configuration Files**

```bash
# Copy main config to Craft config directory
cp config/migration-config.php path/to/craft/config/

# Copy helper class to modules
cp MigrationConfig.php path/to/craft/modules/helpers/

# Copy environment examples
cp config/.env.* path/to/craft/config/
```

### **Step 2: Set Environment Variables**

Choose your environment and set variables in `.env`:

```bash
# For Development
MIGRATION_ENV=dev
DO_S3_ACCESS_KEY=your_dev_key
DO_S3_SECRET_KEY=your_dev_secret
DO_S3_BUCKET=dev-medias-bucket
DO_S3_BASE_URL=https://dev-medias-test.tor1.digitaloceanspaces.com
```

Or copy environment-specific file:
```bash
# For development
cp config/.env.dev .env

# For staging
cp config/.env.staging .env

# For production
cp config/.env.prod .env
```

### **Step 3: Customize Configuration**

Edit `config/migration-config.php` to match your setup:

```php
// Environment-specific AWS/DO settings
'dev' => [
    'aws' => [
        'bucket' => 'your-aws-bucket-name',  // ← Update this
        'region' => 'ca-central-1',
        'urls' => [
            'https://your-bucket.s3.amazonaws.com',  // ← Update URLs
            // ... add all your AWS URL formats
        ],
    ],
    'digitalocean' => [
        'region' => 'tor1',
        'bucket' => App::env('DO_S3_BUCKET'),
        'baseUrl' => App::env('DO_S3_BASE_URL'),
    ],
],
```

### **Step 4: Verify Configuration**

```bash
# Test configuration loading
./craft url-replacement/show-config

# Should show:
# Environment: DEV
# AWS Bucket: your-aws-bucket
# DO Bucket: dev-medias-bucket
# DO Base URL: https://dev-medias-test.tor1.digitaloceanspaces.com
# ✓ Configuration is valid
```

### **Step 5: Refactor Controllers**

Update your controllers to use the centralized config (see examples below).

---

## 🔧 Configuration File Explained

### **migration-config.php**

```php
<?php
use craft\helpers\App;

// Get environment from .env
$env = App::env('MIGRATION_ENV') ?? 'dev';

// Environment-specific settings
$environments = [
    'dev' => [
        'aws' => [...],
        'digitalocean' => [...],
    ],
    'staging' => [...],
    'prod' => [...],
];

// Common settings (shared across all environments)
$commonConfig = [
    'filesystemMappings' => [...],   // AWS → DO handle mappings
    'volumes' => [...],              // Volume configuration
    'filesystems' => [...],          // DO Spaces filesystem definitions
    'migration' => [...],            // Batch sizes, retries, etc.
    'templates' => [...],            // Template scanning settings
    'database' => [...],             // Database scanning settings
    'paths' => [...],                // File paths
];

// Merge and return
return array_merge($commonConfig, [
    'environment' => $env,
    'aws' => $environments[$env]['aws'],
    'digitalocean' => $environments[$env]['digitalocean'],
]);
```

### **Key Sections to Customize**

#### **1. AWS S3 URLs** (Critical)
```php
'aws' => [
    'bucket' => 'your-actual-bucket-name',  // ← Change this
    'urls' => [
        'https://your-bucket.s3.amazonaws.com',  // ← Add all URL formats
        'http://your-bucket.s3.amazonaws.com',
        'https://s3.region.amazonaws.com/your-bucket',
        // ... add any other URL patterns you use
    ],
],
```

#### **2. DigitalOcean Spaces** (Critical)
```php
'digitalocean' => [
    'region' => 'tor1',  // ← Your DO Spaces region
    'bucket' => App::env('DO_S3_BUCKET'),
    'baseUrl' => App::env('DO_S3_BASE_URL'),  // Set in .env per environment
],
```

#### **3. Filesystem Mappings** (If different)
```php
'filesystemMappings' => [
    'images' => 'images_do',           // ← Update if your handles differ
    'optimisedImages' => 'optimisedImages_do',
    'documents' => 'documents_do',
    // ... add your volume mappings
],
```

#### **4. Migration Settings** (Optional)
```php
'migration' => [
    'batchSize' => 100,              // ← Adjust based on your server
    'maxRetries' => 3,
    'checkpointRetentionHours' => 72,
    'maxRepeatedErrors' => 10,
],
```

---

## 💻 Using MigrationConfig in Controllers

### **Pattern 1: Initialize Config**

```php
<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use modules\helpers\MigrationConfig;

class YourController extends Controller
{
    private $config;

    public function init(): void
    {
        parent::init();

        // Load configuration
        try {
            $this->config = MigrationConfig::getInstance();
        } catch (\Exception $e) {
            $this->stderr("Config Error: " . $e->getMessage() . "\n", Console::FG_RED);
            exit(ExitCode::CONFIG);
        }
    }

    public function actionYourAction()
    {
        // Validate before running
        $errors = $this->config->validate();
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->stderr("  • {$error}\n", Console::FG_RED);
            }
            return ExitCode::CONFIG;
        }

        // Use config values...
    }
}
```

### **Pattern 2: Get URL Mappings**

**Before (hardcoded):**
```php
private function getUrlMappings($customNewUrl = null): array
{
    $newUrl = $customNewUrl ?? 'https://dev-medias-test.tor1.digitaloceanspaces.com';

    return [
        'https://ncc-website-2.s3.amazonaws.com' => $newUrl,
        'http://ncc-website-2.s3.amazonaws.com' => $newUrl,
        // ... more hardcoded mappings
    ];
}
```

**After (from config):**
```php
public function actionReplaceUrls($customNewUrl = null)
{
    // Get URL mappings from config
    $urlMappings = $this->config->getUrlMappings($customNewUrl);

    // Use mappings...
    foreach ($urlMappings as $oldUrl => $newUrl) {
        // Replace...
    }
}
```

### **Pattern 3: Get AWS S3 URLs**

**Before:**
```php
$oldUrls = [
    'https://ncc-website-2.s3.amazonaws.com',
    'http://ncc-website-2.s3.amazonaws.com',
    // ... hardcoded list
];
```

**After:**
```php
$oldUrls = $this->config->getAwsUrls();
```

### **Pattern 4: Get Filesystem Mappings**

**Before:**
```php
private array $fsMappings = [
    'images' => 'images_do',
    'optimisedImages' => 'optimisedImages_do',
    // ... hardcoded mappings
];
```

**After:**
```php
$fsMappings = $this->config->getFilesystemMappings();
```

### **Pattern 5: Get Volume Settings**

**Before:**
```php
private $sourceVolumeHandles = ['images', 'optimisedImages'];
private $targetVolumeHandle = 'images';
private $quarantineVolumeHandle = 'quarantine';
```

**After:**
```php
$sourceHandles = $this->config->getSourceVolumeHandles();
$targetHandle = $this->config->getTargetVolumeHandle();
$quarantineHandle = $this->config->getQuarantineVolumeHandle();
```

### **Pattern 6: Get Migration Settings**

**Before:**
```php
const BATCH_SIZE = 100;
const MAX_RETRIES = 3;
const CHECKPOINT_EVERY_BATCHES = 1;
```

**After:**
```php
$batchSize = $this->config->getBatchSize();
$maxRetries = $this->config->getMaxRetries();
$checkpointEvery = $this->config->getCheckpointEveryBatches();
```

### **Pattern 7: Get DO Spaces Settings**

**Before:**
```php
$fs->endpoint = 'https://dev-medias-test.tor1.digitaloceanspaces.com';
$fs->region = 'tor1';
$fs->bucket = '$DO_S3_BUCKET';
```

**After:**
```php
$fs->endpoint = $this->config->getDoBaseUrl();
$fs->region = $this->config->getDoRegion();
$fs->bucket = '$DO_S3_BUCKET';  // Still use env var for Craft's parsing
```

---

## 📋 Complete Refactoring Checklist

For each controller, replace:

### **UrlReplacementController.php**
- [ ] Add `private $config;` property
- [ ] Add `init()` method with `MigrationConfig::getInstance()`
- [ ] Replace `getUrlMappings()` with `$this->config->getUrlMappings()`
- [ ] Replace hardcoded AWS URLs with `$this->config->getAwsUrls()`
- [ ] Replace table patterns with `$this->config->getContentTablePatterns()`
- [ ] Replace report path with `$this->config->getLogsPath()`
- [ ] Add `actionShowConfig()` for verification

### **TemplateUrlReplacementController.php**
- [ ] Add `private $config;` property
- [ ] Add `init()` method
- [ ] Replace hardcoded AWS URLs with `$this->config->getAwsUrls()`
- [ ] Replace templates path with `$this->config->getTemplatesPath()`
- [ ] Replace backup suffix with `$this->config->getTemplateBackupSuffix()`
- [ ] Replace env var name with `$this->config->getTemplateEnvVarName()`

### **FilesystemController.php**
- [ ] Add `private $config;` property
- [ ] Add `init()` method
- [ ] Replace filesystem definitions with `$this->config->getFilesystemDefinitions()`
- [ ] Replace DO region with `$this->config->getDoRegion()`
- [ ] Replace DO base URL with `$this->config->getDoBaseUrl()`

### **FilesystemSwitchController.php**
- [ ] Add `private $config;` property
- [ ] Add `init()` method
- [ ] Replace `$fsMappings` array with `$this->config->getFilesystemMappings()`

### **ImageMigrationController.php**
- [ ] Add `private $config;` property
- [ ] Add `init()` method
- [ ] Replace `$sourceVolumeHandles` with `$this->config->getSourceVolumeHandles()`
- [ ] Replace `$targetVolumeHandle` with `$this->config->getTargetVolumeHandle()`
- [ ] Replace `$quarantineVolumeHandle` with `$this->config->getQuarantineVolumeHandle()`
- [ ] Replace `BATCH_SIZE` with `$this->config->getBatchSize()`
- [ ] Replace `MAX_RETRIES` with `$this->config->getMaxRetries()`
- [ ] Replace all constants with config methods

### **ExtendedUrlReplacementController.php**
- [ ] Add `private $config;` property
- [ ] Add `init()` method
- [ ] Replace hardcoded table list with `$this->config->getAdditionalTables()`
- [ ] Replace AWS URLs with `$this->config->getAwsUrls()`
- [ ] Replace URL mappings with `$this->config->getUrlMappings()`

---

## 🔄 Environment Switching

### **Development to Staging**

```bash
# 1. Switch environment file
cp config/.env.staging .env

# 2. Verify configuration
./craft url-replacement/show-config

# Expected output:
# Environment: STAGING
# AWS Bucket: ncc-website-2
# DO Bucket: staging-medias-bucket
# DO Base URL: https://staging-medias.tor1.digitaloceanspaces.com
# ✓ Configuration is valid

# 3. Run migration
./craft url-replacement/replace-s3-urls --dryRun=1
```

### **Staging to Production**

```bash
# 1. Switch environment
cp config/.env.prod .env

# 2. Verify configuration
./craft url-replacement/show-config

# Expected output:
# Environment: PROD
# AWS Bucket: ncc-website-2
# DO Bucket: prod-medias-bucket
# DO Base URL: https://medias.tor1.digitaloceanspaces.com
# ✓ Configuration is valid

# 3. IMPORTANT: Double-check settings before proceeding!

# 4. Run migration (with extra caution in prod)
./craft url-replacement/replace-s3-urls --dryRun=1  # Always dry-run first!
```

### **Using Environment Variable**

Alternative: Set `MIGRATION_ENV` directly:

```bash
# For one-off commands
MIGRATION_ENV=staging ./craft url-replacement/replace-s3-urls --dryRun=1

# Or export for session
export MIGRATION_ENV=staging
./craft url-replacement/replace-s3-urls --dryRun=1
./craft template-url/scan
```

---

## ✅ Available Config Methods

### **General**
```php
$config->get('path.to.value', $default)  // Generic getter (dot notation)
$config->getAll()                        // Get entire config array
$config->getEnvironment()                // 'dev', 'staging', or 'prod'
$config->validate()                      // Returns array of errors (empty if valid)
$config->displaySummary()                // Returns formatted config summary
```

### **AWS S3**
```php
$config->getAwsBucket()                  // AWS bucket name
$config->getAwsRegion()                  // AWS region
$config->getAwsUrls()                    // Array of all AWS URL patterns
```

### **DigitalOcean Spaces**
```php
$config->getDoBucket()                   // DO bucket name
$config->getDoRegion()                   // DO region
$config->getDoBaseUrl()                  // DO Spaces base URL
$config->getDoAccessKey()                // DO access key (from env)
$config->getDoSecretKey()                // DO secret key (from env)
```

### **URL Mappings**
```php
$config->getUrlMappings($customUrl)      // Old AWS URLs → New DO URL mappings
```

### **Filesystems**
```php
$config->getFilesystemMappings()         // AWS handle → DO handle mappings
$config->getFilesystemDefinitions()      // Array of all DO filesystem configs
$config->getFilesystemDefinition($handle) // Single filesystem config
```

### **Volumes**
```php
$config->getSourceVolumeHandles()        // Source volumes for migration
$config->getTargetVolumeHandle()         // Target volume
$config->getQuarantineVolumeHandle()     // Quarantine volume
$config->getRootLevelVolumeHandles()     // Root-level volumes
```

### **Migration Settings**
```php
$config->getBatchSize()                  // Batch processing size
$config->getCheckpointEveryBatches()     // Checkpoint frequency
$config->getChangelogFlushEvery()        // Changelog flush frequency
$config->getMaxRetries()                 // Max retries for operations
$config->getCheckpointRetentionHours()   // Checkpoint retention period
$config->getMaxRepeatedErrors()          // Max repeated errors before stop
```

### **Templates**
```php
$config->getTemplateExtensions()         // File extensions to scan
$config->getTemplateBackupSuffix()       // Backup file suffix pattern
$config->getTemplateEnvVarName()         // Environment variable name for URLs
```

### **Database**
```php
$config->getContentTablePatterns()       // Content table patterns
$config->getAdditionalTables()           // Additional tables to scan
$config->getColumnTypes()                // Column types to scan
```

### **Paths**
```php
$config->getTemplatesPath()              // Resolved templates path
$config->getStoragePath()                // Resolved storage path
$config->getLogsPath()                   // Resolved logs path
$config->getBackupsPath()                // Resolved backups path
```

---

## 🧪 Testing Configuration

### **1. Verify Config Loads**
```bash
./craft url-replacement/show-config
```

### **2. Test in Each Environment**
```bash
# Dev
MIGRATION_ENV=dev ./craft url-replacement/show-config

# Staging
MIGRATION_ENV=staging ./craft url-replacement/show-config

# Prod
MIGRATION_ENV=prod ./craft url-replacement/show-config
```

### **3. Validate Settings**
```bash
# Check for missing required values
./craft url-replacement/show-config

# Look for:
# ✓ Configuration is valid
# OR
# ⚠ Configuration Issues:
#   • DO access key not configured
```

### **4. Dry-Run in Each Environment**
```bash
# Test migration with dev settings
MIGRATION_ENV=dev ./craft url-replacement/replace-s3-urls --dryRun=1

# Test with staging settings
MIGRATION_ENV=staging ./craft url-replacement/replace-s3-urls --dryRun=1
```

---

## 🚨 Common Issues & Solutions

### **Issue: "Configuration file not found"**
```
Error: Migration config file not found. Expected at: @config/migration-config.php
```

**Solution:**
```bash
# Ensure file is in correct location
cp config/migration-config.php path/to/craft/config/

# Verify path
ls -la path/to/craft/config/migration-config.php
```

### **Issue: "DO access key not configured"**
```
⚠ Configuration Issues:
  • DO access key not configured (check DO_S3_ACCESS_KEY in .env)
```

**Solution:**
```bash
# Add to .env file
echo "DO_S3_ACCESS_KEY=your_key_here" >> .env
echo "DO_S3_SECRET_KEY=your_secret_here" >> .env

# Verify
./craft url-replacement/show-config
```

### **Issue: Wrong environment being used**
```
Environment: DEV
(but you expected STAGING)
```

**Solution:**
```bash
# Check .env file
cat .env | grep MIGRATION_ENV

# Should show:
MIGRATION_ENV=staging

# If not, update:
echo "MIGRATION_ENV=staging" >> .env
```

### **Issue: Can't find MigrationConfig class**
```
Error: Class 'modules\helpers\MigrationConfig' not found
```

**Solution:**
```bash
# Ensure file is in modules/helpers directory
cp MigrationConfig.php path/to/craft/modules/helpers/

# Check namespace in file
head -2 path/to/craft/modules/helpers/MigrationConfig.php
# Should show: namespace modules\helpers;

# Composer autoload
composer dump-autoload
```

---

## 📊 Migration Workflow with Config

### **Standard Workflow**

```bash
# 1. Setup
cp config/migration-config.php craft/config/
cp config/.env.dev .env
vim .env  # Edit credentials

# 2. Verify
./craft url-replacement/show-config

# 3. Dev Migration
MIGRATION_ENV=dev ./craft url-replacement/replace-s3-urls --dryRun=1
MIGRATION_ENV=dev ./craft url-replacement/replace-s3-urls

# 4. Staging Migration
cp config/.env.staging .env
./craft url-replacement/show-config  # Verify staging settings
./craft url-replacement/replace-s3-urls --dryRun=1
./craft url-replacement/replace-s3-urls

# 5. Production Migration
cp config/.env.prod .env
./craft url-replacement/show-config  # Double-check prod settings
./craft url-replacement/replace-s3-urls --dryRun=1
# Review dry-run output carefully!
./craft url-replacement/replace-s3-urls
```

---

## 📝 Benefits Summary

### **Before (Hardcoded)**
```php
// Controller 1
$newUrl = 'https://dev-medias-test.tor1.digitaloceanspaces.com';

// Controller 2
$awsUrls = ['https://ncc-website-2.s3.amazonaws.com'];

// Controller 3
$fsMappings = ['images' => 'images_do'];

// Problems:
// ❌ Multiple places to update
// ❌ Easy to miss one
// ❌ Different values per environment
// ❌ No validation
// ❌ Manual switching between environments
```

### **After (Centralized)**
```php
// All controllers
$this->config = MigrationConfig::getInstance();
$newUrl = $this->config->getDoBaseUrl();
$awsUrls = $this->config->getAwsUrls();
$fsMappings = $this->config->getFilesystemMappings();

// Benefits:
// ✅ Single source of truth
// ✅ Environment-aware (dev/staging/prod)
// ✅ Automatic validation
// ✅ Type-safe access
// ✅ Easy environment switching
// ✅ Consistent across all controllers
```

---

## 🎯 Next Steps

1. **Copy files to your Craft project:**
   - `migration-config.php` → `config/`
   - `MigrationConfig.php` → `modules/helpers/`
   - `.env.example` → `config/`

2. **Customize config for your setup:**
   - Update AWS bucket name
   - Update AWS URL patterns
   - Set DO Spaces URLs per environment
   - Adjust filesystem mappings if needed

3. **Set environment variables:**
   - Copy appropriate `.env.*` file
   - Update credentials
   - Set `MIGRATION_ENV`

4. **Refactor controllers one by one:**
   - Start with `UrlReplacementController`
   - Use patterns from examples
   - Test each controller after refactoring

5. **Test thoroughly:**
   - Verify config loads: `./craft url-replacement/show-config`
   - Dry-run in each environment
   - Validate output matches expectations

---

## 📚 Additional Resources

- **See:** `examples/UrlReplacementController.refactored.php` for complete refactored example
- **Reference:** `MigrationConfig.php` for all available methods
- **Config:** `migration-config.php` for customization options

---

**Version:** 1.0
**Last Updated:** 2025-11-05
**Status:** Ready for Implementation
