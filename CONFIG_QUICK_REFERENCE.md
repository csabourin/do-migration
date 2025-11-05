# Configuration System - Quick Reference Card

**🎯 Single Source of Truth for Multi-Environment Migrations**

---

## 📁 File Locations

```
craft/config/
  └── migration-config.php          ← Main config (customize this)

craft/modules/helpers/
  └── MigrationConfig.php            ← Helper class (don't modify)

craft/.env                           ← Active environment variables
```

---

## ⚙️ Setup (3 Steps)

```bash
# 1. Copy files
cp config/migration-config.php craft/config/
cp MigrationConfig.php craft/modules/helpers/

# 2. Set environment
echo "MIGRATION_ENV=dev" >> craft/.env
echo "DO_S3_ACCESS_KEY=your_key" >> craft/.env
echo "DO_S3_SECRET_KEY=your_secret" >> craft/.env
echo "DO_S3_BUCKET=your-bucket" >> craft/.env
echo "DO_S3_BASE_URL=https://your-bucket.tor1.digitaloceanspaces.com" >> craft/.env

# 3. Verify
./craft url-replacement/show-config
```

---

## 🔄 Environment Switching

```bash
# Method 1: Copy pre-configured file
cp config/.env.dev .env          # Development
cp config/.env.staging .env      # Staging
cp config/.env.prod .env         # Production

# Method 2: Set variable directly
MIGRATION_ENV=staging ./craft your-command

# Method 3: Export for session
export MIGRATION_ENV=staging
./craft your-command
```

---

## 💻 Usage in Controllers

### Initialize
```php
use modules\helpers\MigrationConfig;

class YourController extends Controller
{
    private $config;

    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();

        // Validate
        $errors = $this->config->validate();
        if (!empty($errors)) {
            // Handle errors...
        }
    }
}
```

### Common Methods
```php
// Environment
$env = $this->config->getEnvironment();              // 'dev'|'staging'|'prod'

// AWS S3
$awsBucket = $this->config->getAwsBucket();          // 'ncc-website-2'
$awsUrls = $this->config->getAwsUrls();              // Array of URLs

// DigitalOcean Spaces
$doBucket = $this->config->getDoBucket();            // 'your-bucket'
$doUrl = $this->config->getDoBaseUrl();              // 'https://...'
$doRegion = $this->config->getDoRegion();            // 'tor1'

// URL Mappings
$mappings = $this->config->getUrlMappings();         // Old → New URLs

// Filesystems
$fsMappings = $this->config->getFilesystemMappings(); // AWS → DO handles
$filesystems = $this->config->getFilesystemDefinitions();

// Volumes
$sources = $this->config->getSourceVolumeHandles();  // ['images', ...]
$target = $this->config->getTargetVolumeHandle();    // 'images'
$quarantine = $this->config->getQuarantineVolumeHandle(); // 'quarantine'

// Migration Settings
$batchSize = $this->config->getBatchSize();          // 100
$maxRetries = $this->config->getMaxRetries();        // 3

// Paths
$logsPath = $this->config->getLogsPath();            // '@storage/logs'
$templatesPath = $this->config->getTemplatesPath();  // '@templates'

// Validation & Display
$errors = $this->config->validate();                 // Array of errors
$summary = $this->config->displaySummary();          // Formatted string
```

---

## 🎨 Refactoring Pattern

### Before (Hardcoded)
```php
private function getUrlMappings(): array
{
    $newUrl = 'https://dev-medias-test.tor1.digitaloceanspaces.com';
    return [
        'https://ncc-website-2.s3.amazonaws.com' => $newUrl,
        'http://ncc-website-2.s3.amazonaws.com' => $newUrl,
    ];
}
```

### After (Centralized)
```php
public function actionYourAction()
{
    $urlMappings = $this->config->getUrlMappings();
    // Use mappings...
}
```

---

## ✅ Verification Commands

```bash
# Show current config
./craft url-replacement/show-config

# Expected output:
# Environment: DEV
# AWS Bucket: ncc-website-2
# DO Bucket: your-bucket
# DO Base URL: https://your-bucket.tor1.digitaloceanspaces.com
# ✓ Configuration is valid

# Test each environment
MIGRATION_ENV=dev ./craft url-replacement/show-config
MIGRATION_ENV=staging ./craft url-replacement/show-config
MIGRATION_ENV=prod ./craft url-replacement/show-config
```

---

## 🔧 Customization Points

### migration-config.php

```php
// 1. AWS S3 URLs (add all your URL patterns)
'aws' => [
    'bucket' => 'your-actual-bucket',      // ← Change
    'urls' => [
        'https://your-bucket.s3.amazonaws.com',  // ← Add all
        'http://your-bucket.s3.amazonaws.com',
        // ... your URL patterns
    ],
],

// 2. DigitalOcean per environment
'dev' => [
    'digitalocean' => [
        'baseUrl' => 'https://dev-bucket.tor1.digitaloceanspaces.com',
    ],
],
'staging' => [
    'digitalocean' => [
        'baseUrl' => 'https://staging-bucket.tor1.digitaloceanspaces.com',
    ],
],
'prod' => [
    'digitalocean' => [
        'baseUrl' => 'https://prod-bucket.tor1.digitaloceanspaces.com',
    ],
],

// 3. Filesystem mappings (if your handles differ)
'filesystemMappings' => [
    'your_aws_handle' => 'your_do_handle',
],

// 4. Volume settings (if your setup differs)
'volumes' => [
    'source' => ['your', 'volumes'],
    'target' => 'your_target',
    'quarantine' => 'your_quarantine',
],
```

---

## 🚨 Troubleshooting

| Error | Solution |
|-------|----------|
| Config file not found | `cp config/migration-config.php craft/config/` |
| Class not found | `cp MigrationConfig.php craft/modules/helpers/` |
| DO access key not set | Add `DO_S3_ACCESS_KEY=...` to `.env` |
| Wrong environment | Check `MIGRATION_ENV` in `.env` |
| Validation errors | Run `./craft url-replacement/show-config` |

---

## 📋 Migration Workflow

```bash
# 1. Setup config
cp config/migration-config.php craft/config/
vim craft/config/migration-config.php  # Customize

# 2. Set dev environment
cp config/.env.dev craft/.env
vim craft/.env  # Add credentials

# 3. Verify
./craft url-replacement/show-config

# 4. Test in dev
./craft url-replacement/replace-s3-urls --dryRun=1
./craft url-replacement/replace-s3-urls

# 5. Move to staging
cp config/.env.staging craft/.env
./craft url-replacement/show-config  # Verify
./craft url-replacement/replace-s3-urls --dryRun=1
./craft url-replacement/replace-s3-urls

# 6. Move to prod (with caution!)
cp config/.env.prod craft/.env
./craft url-replacement/show-config  # Double-check!
./craft url-replacement/replace-s3-urls --dryRun=1
# Review carefully before proceeding!
./craft url-replacement/replace-s3-urls
```

---

## 📚 Documentation

- **Complete Guide:** CONFIGURATION_GUIDE.md
- **Example Controller:** examples/UrlReplacementController.refactored.php
- **Main Config:** config/migration-config.php
- **Helper Class:** MigrationConfig.php

---

## 🎯 Benefits

| Before | After |
|--------|-------|
| ❌ Hardcoded in 10+ files | ✅ Single config file |
| ❌ Manual env switching | ✅ One variable change |
| ❌ No validation | ✅ Auto-validation |
| ❌ Error-prone updates | ✅ Update once, applies everywhere |
| ❌ No type safety | ✅ Type-safe methods |

---

**Quick Start:** Copy 2 files → Set 1 variable → Verify → Done!

**Version:** 1.0
