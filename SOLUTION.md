# Solution: "Call to undefined method" Error

## Problem
Error: `Call to undefined method modules\helpers\MigrationConfig::getDoEnvVarBaseUrl()`

## Root Cause
Your Craft application is using an **outdated version** of the migration module code. The required methods exist in the latest code (confirmed by diagnostic), but your running application doesn't have them.

## Verification
✅ Diagnostic confirms all methods exist in this repository:
- `getDoEnvVarAccessKey()` - FOUND
- `getDoEnvVarSecretKey()` - FOUND
- `getDoEnvVarBucket()` - FOUND
- `getDoEnvVarBaseUrl()` - FOUND ← The "missing" method DOES exist
- `getDoEnvVarEndpoint()` - FOUND

## Solution Steps

### 1. Pull Latest Changes
If your Craft application has this module as a git submodule or symlinked directory:

```bash
cd /path/to/your/craft/modules/ncc-migration
git pull origin claude/move-hardcoded-config-values-011CUsEyV89Hn6MsQrUHUBcY
```

### 2. Copy Updated Files
If you manually copy module files to your Craft installation:

```bash
# Copy the updated MigrationConfig.php
cp modules/helpers/MigrationConfig.php /path/to/craft/modules/helpers/

# Copy the updated migration-config.php example
cp modules/console/controllers/config_examples/migration-config.php /path/to/craft/config/migration-config.php
```

### 3. Clear All Caches

```bash
cd /path/to/your/craft

# Clear Craft cache
./craft clear-caches/all

# Clear Composer autoloader
composer dump-autoload

# If PHP opcache is enabled, restart PHP-FPM
sudo systemctl restart php-fpm
# OR
sudo systemctl restart php8.1-fpm  # Adjust version as needed
```

### 4. Verify the Fix

Run the diagnostic script:

```bash
cd /path/to/your/craft/modules/do-migration
php check-method-exists.php
```

You should see:
```
✓ getDoEnvVarBaseUrl() - FOUND
```

### 5. Update Your Config File

Make sure your Craft `config/migration-config.php` includes the new `envVars` section:

```php
'digitalocean' => [
    'region' => 'tor1',
    'bucket' => App::env('DO_S3_BUCKET'),
    'baseUrl' => App::env('DO_S3_BASE_URL'),
    'accessKey' => App::env('DO_S3_ACCESS_KEY'),
    'secretKey' => App::env('DO_S3_SECRET_KEY'),
    'endpoint' => App::env('DO_S3_BASE_ENDPOINT'),

    // NEW: Environment variable references
    'envVars' => [
        'accessKey' => '$DO_S3_ACCESS_KEY',
        'secretKey' => '$DO_S3_SECRET_KEY',
        'bucket' => '$DO_S3_BUCKET',
        'baseUrl' => '$DO_S3_BASE_URL',
        'endpoint' => '$DO_S3_BASE_ENDPOINT',
    ],
],
```

## What Changed

### Commit History
- **a7bb63e**: Added all `getDoEnvVar*()` methods
- **c4dd06c**: Moved hardcoded values to migration-config.php (latest)

### Files Modified
1. `modules/helpers/MigrationConfig.php` - Contains the "missing" methods
2. `modules/console/controllers/config_examples/migration-config.php` - Updated config structure

## Common Issues

### Issue: "I pulled the latest code but still get the error"
**Solution**: Clear PHP opcache or restart your web server
```bash
sudo systemctl restart php-fpm
sudo systemctl restart nginx  # or apache2
```

### Issue: "The methods exist in the file but PHP says they don't"
**Solution**: You might have multiple copies of the file. Find them:
```bash
find /path/to/craft -name "MigrationConfig.php" -type f
```

Make sure they're all updated.

### Issue: "I don't know where my Craft installation is"
**Solution**: Check your web server document root:
```bash
# For nginx
grep -r "root" /etc/nginx/sites-enabled/

# For Apache
grep -r "DocumentRoot" /etc/apache2/sites-enabled/
```

## Need More Help?

Run the diagnostic script and send the output:
```bash
php check-method-exists.php
```

This will show:
- Where MigrationConfig.php is located
- Which methods exist
- When the file was last modified
