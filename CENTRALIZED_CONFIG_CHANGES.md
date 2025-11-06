# Centralized Configuration Changes

## Overview

This document summarizes the changes made to centralize hardcoded configuration values across all controllers into the `MigrationConfig.php` helper class.

## Problem Statement

Previously, configuration values like filesystem handles, field names, environment variable names, and various limits were hardcoded throughout multiple controller files. This made it:
- Difficult to update settings across environments
- Error-prone when customizing for different projects
- Hard to maintain consistency

## Solution

All configurable values have been moved to `modules/helpers/MigrationConfig.php` with getter methods, allowing environment-specific customization through `migration-config.php`.

---

## Key Fix: SSL Certificate Error Resolution

### Issue
FilesystemController was using `DO_S3_BASE_URL` for the filesystem endpoint, which includes the bucket name:
```
DO_S3_BASE_URL=https://dev-medias-test.tor1.digitaloceanspaces.com
```

When the AWS SDK constructs URLs, it prepends the bucket name to the endpoint:
```
bucket.endpoint = dev-medias-test.dev-medias-test.tor1.digitaloceanspaces.com ❌
```

This causes SSL certificate errors because the duplicated hostname doesn't exist.

### Solution
Introduced `DO_S3_BASE_ENDPOINT` environment variable for SDK configuration:
```
DO_S3_BASE_URL=https://dev-medias-test.tor1.digitaloceanspaces.com      # For file access
DO_S3_BASE_ENDPOINT=https://tor1.digitaloceanspaces.com                 # For SDK config ✓
```

The SDK now correctly constructs:
```
bucket.endpoint = dev-medias-test.tor1.digitaloceanspaces.com ✓
```

---

## New Configuration Methods

### Environment Variable References

```php
// Get environment variable references (returns full references with $ prefix)
// These can be stored directly in the database and Craft resolves them at runtime
// This keeps secrets in .env files, not in the database
$config->getDoEnvVarAccessKey();       // '$DO_S3_ACCESS_KEY'
$config->getDoEnvVarSecretKey();       // '$DO_S3_SECRET_KEY'
$config->getDoEnvVarBucket();          // '$DO_S3_BUCKET'
$config->getDoEnvVarBaseUrl();         // '$DO_S3_BASE_URL'
$config->getDoEnvVarEndpoint();        // '$DO_S3_BASE_ENDPOINT' ⭐ NEW
```

### Filesystem Handles

```php
// Get filesystem handles used by controllers
$config->getTransformFilesystemHandle();      // 'imageTransforms_do'
$config->getQuarantineFilesystemHandle();     // 'quarantine'
```

### Field Handles

```php
// Get field handles for ImageOptimize fields
$config->getOptimizedImagesFieldHandle();     // 'optimizedImagesField'
```

### Migration Settings

```php
// Additional migration control settings
$config->getErrorThreshold();                  // 50
$config->getLockTimeoutSeconds();              // 43200 (12 hours)
$config->getLockAcquireTimeoutSeconds();       // 3
```

### Transform Settings

```php
// Transform generation control
$config->getMaxConcurrentTransforms();         // 5
$config->getWarmupTimeout();                   // 10 seconds
```

### Database Settings

```php
// Database query patterns
$config->getFieldColumnPattern();              // 'field_%'
```

### URL Replacement Settings

```php
// URL replacement behavior
$config->getSampleUrlLimit();                  // 5
```

### Diagnostics Settings

```php
// Diagnostic output limits
$config->getFileListLimit();                   // 50
```

### Dashboard Settings

```php
// Dashboard display settings
$config->getDashboardLogLinesDefault();        // 100
$config->getDashboardLogFileName();            // 'web.log'
```

---

## Files Modified

### Configuration Helper
- ✅ `modules/helpers/MigrationConfig.php` - Added 17 new getter methods

### Controllers Updated
- ✅ `modules/console/controllers/FilesystemController.php` - Uses centralized env var names + BASE_ENDPOINT fix
- ✅ `modules/console/controllers/VolumeConfigController.php` - Uses centralized filesystem/field handles
- ✅ `modules/console/controllers/MigrationCheckController.php` - Uses centralized filesystem/field handles

### Environment Variable Examples
- ✅ `modules/console/controllers/config_examples/.env.dev` - Added DO_S3_BASE_ENDPOINT
- ✅ `modules/console/controllers/config_examples/.env.staging` - Added DO_S3_BASE_ENDPOINT
- ✅ `modules/console/controllers/config_examples/.env.prod` - Added DO_S3_BASE_ENDPOINT
- ✅ `modules/console/controllers/config_examples/.env.example` - Added DO_S3_BASE_ENDPOINT with documentation

---

## Configuration Structure

The configuration now supports the following structure in `migration-config.php`:

```php
return [
    'digitalocean' => [
        'bucket' => getenv('DO_S3_BUCKET'),
        'region' => getenv('DO_S3_REGION') ?: 'tor1',
        'baseUrl' => getenv('DO_S3_BASE_URL'),
        'endpoint' => getenv('DO_S3_BASE_ENDPOINT'),
        'accessKey' => getenv('DO_S3_ACCESS_KEY'),
        'secretKey' => getenv('DO_S3_SECRET_KEY'),

        // Environment variable references (for storing in database)
        // Include $ prefix so Craft resolves them at runtime (keeps secrets out of DB)
        'envVars' => [
            'accessKey' => '$DO_S3_ACCESS_KEY',
            'secretKey' => '$DO_S3_SECRET_KEY',
            'bucket' => '$DO_S3_BUCKET',
            'baseUrl' => '$DO_S3_BASE_URL',
            'endpoint' => '$DO_S3_BASE_ENDPOINT',
        ],
    ],

    'filesystems' => [
        // ... existing filesystem definitions ...

        'transformHandle' => 'imageTransforms_do',
        'quarantineHandle' => 'quarantine',
    ],

    'fields' => [
        'optimizedImages' => 'optimizedImagesField',
    ],

    'migration' => [
        // ... existing migration settings ...

        'errorThreshold' => 50,
        'lockTimeoutSeconds' => 43200,
        'lockAcquireTimeoutSeconds' => 3,
    ],

    'transforms' => [
        'maxConcurrent' => 5,
        'warmupTimeout' => 10,
    ],

    'database' => [
        // ... existing database settings ...

        'fieldColumnPattern' => 'field_%',
    ],

    'urlReplacement' => [
        'sampleUrlLimit' => 5,
    ],

    'diagnostics' => [
        'fileListLimit' => 50,
    ],

    'dashboard' => [
        'logLinesDefault' => 100,
        'logFileName' => 'web.log',
    ],
];
```

---

## Benefits

### 1. **Environment Independence**
All environment-specific values are now configurable, making it easy to:
- Support dev/staging/prod environments
- Customize for different projects
- Test with different configurations

### 2. **SSL Certificate Fix**
The BASE_ENDPOINT fix resolves SSL errors by using the correct endpoint format for the AWS SDK.

### 3. **Single Source of Truth**
All configuration values are defined in one place (`migration-config.php`), eliminating duplication.

### 4. **Type Safety**
Getter methods provide type hints and return types, catching configuration errors early.

### 5. **Documentation**
Each getter method includes PHPDoc describing what the setting controls.

### 6. **Backward Compatibility**
All methods provide sensible defaults, so existing installations continue working.

---

## Migration Path

### For New Installations
1. Copy `config_examples/.env.example` to your Craft project `.env`
2. Update values for your environment
3. Ensure `DO_S3_BASE_ENDPOINT` is set correctly

### For Existing Installations
1. Add `DO_S3_BASE_ENDPOINT` to your `.env`:
   ```bash
   DO_S3_BASE_ENDPOINT=https://tor1.digitaloceanspaces.com
   ```
2. Run the filesystem fix command:
   ```bash
   ddev craft ncc-module/filesystem-fix/fix-endpoints
   ```
3. Verify with migration check:
   ```bash
   ddev craft ncc-module/migration-check
   ```

---

## Testing

After applying these changes:

1. **Test filesystem creation:**
   ```bash
   ddev craft ncc-module/filesystem/create --force
   ```

2. **Verify no SSL errors:**
   ```bash
   ddev craft ncc-module/migration-check
   ```

3. **Check configuration values:**
   ```bash
   ddev craft ncc-module/filesystem-fix/show
   ```

---

## Future Improvements

Additional controllers that could benefit from centralization:
- `ImageMigrationController.php` - Some defaults still remain (will be cleaned up)
- `TransformPreGenerationController.php` - Concurrency settings
- `TemplateUrlReplacementController.php` - Already uses some config, could use more
- `MigrationController.php` - Dashboard settings

These are lower priority as they're less likely to change between environments.

---

## Questions?

For questions about configuration or customization:
1. Check `modules/helpers/MigrationConfig.php` for available options
2. Review `config_examples/` for environment-specific examples
3. Consult `SSL_FIX_GUIDE.md` for SSL troubleshooting
