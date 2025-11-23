# Spaghetti Migrator v2.0 - Complete Migration Guide

A comprehensive guide to migrating assets between cloud providers and reorganizing filesystems using Spaghetti Migrator.

## Table of Contents

- [Quick Start](#quick-start)
- [Supported Providers](#supported-providers)
- [Configuration](#configuration)
- [Migration Scenarios](#migration-scenarios)
- [Testing Your Setup](#testing-your-setup)
- [Running Migrations](#running-migrations)
- [Troubleshooting](#troubleshooting)
- [Best Practices](#best-practices)

---

## Quick Start

### 1. Install the Plugin

```bash
composer require csabourin/spaghetti-migrator
```

### 2. Configure Your Providers

Copy the v2.0 config template:

```bash
cp config/migration-config-v2.php config/migration-config.php
```

Edit `config/migration-config.php` and set your provider credentials:

```php
'sourceProvider' => [
    'type' => 's3',
    'config' => [
        'bucket' => getenv('AWS_BUCKET'),
        'region' => 'us-east-1',
        'accessKey' => getenv('AWS_ACCESS_KEY_ID'),
        'secretKey' => getenv('AWS_SECRET_ACCESS_KEY'),
    ],
],

'targetProvider' => [
    'type' => 'do-spaces',
    'config' => [
        'bucket' => getenv('DO_SPACES_BUCKET'),
        'region' => 'nyc3',
        'accessKey' => getenv('DO_SPACES_KEY'),
        'secretKey' => getenv('DO_SPACES_SECRET'),
    ],
],
```

### 3. Test Your Connection

```bash
php craft s3-spaces-migration/provider-test/test-all
```

### 4. Run Migration

```bash
php craft s3-spaces-migration/image-migration/migrate
```

---

## Supported Providers

Spaghetti Migrator v2.0 supports **8 storage providers**:

| Provider | Type | Identifier | Notes |
|----------|------|------------|-------|
| **AWS S3** | Cloud | `s3` | Original AWS cloud storage |
| **DigitalOcean Spaces** | Cloud | `do-spaces` | S3-compatible |
| **Google Cloud Storage** | Cloud | `gcs` | Requires service account JSON |
| **Azure Blob Storage** | Cloud | `azure-blob` | Microsoft Azure |
| **Backblaze B2** | Cloud | `backblaze-b2` | Budget S3-compatible |
| **Wasabi** | Cloud | `wasabi` | Hot S3-compatible storage |
| **Cloudflare R2** | Cloud | `cloudflare-r2` | Zero egress fees |
| **Local Filesystem** | Local | `local` | For reorganization/backups |

### Provider Capabilities Comparison

| Feature | S3 | DO | GCS | Azure | B2 | Wasabi | R2 | Local |
|---------|----|----|-----|-------|-------|--------|----|----|
| Versioning | ✓ | ✗ | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| ACLs | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ | ✗ | ✗ |
| Server-side Copy | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Multipart Upload | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| Max File Size | 5TB | 5TB | 5TB | 191GB | 10TB | 5TB | 5TB | Unlimited |
| Presigned URLs | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |

---

## Configuration

### Environment Variables

Add these to your `.env` file:

```bash
# AWS S3 (Source)
AWS_BUCKET=my-s3-bucket
AWS_REGION=us-east-1
AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY

# DigitalOcean Spaces (Target)
DO_SPACES_BUCKET=my-do-bucket
DO_SPACES_REGION=nyc3
DO_SPACES_KEY=DO00EXAMPLE
DO_SPACES_SECRET=secret_key_here
DO_SPACES_BASE_URL=https://my-bucket.nyc3.digitaloceanspaces.com
DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
```

### Provider-Specific Configuration

#### AWS S3

```php
'sourceProvider' => [
    'type' => 's3',
    'config' => [
        'bucket' => getenv('AWS_BUCKET'),
        'region' => getenv('AWS_REGION'),
        'accessKey' => getenv('AWS_ACCESS_KEY_ID'),
        'secretKey' => getenv('AWS_SECRET_ACCESS_KEY'),
        'baseUrl' => 'https://my-bucket.s3.amazonaws.com', // Optional
        'subfolder' => 'images', // Optional
    ],
],
```

#### DigitalOcean Spaces

```php
'targetProvider' => [
    'type' => 'do-spaces',
    'config' => [
        'bucket' => getenv('DO_SPACES_BUCKET'),
        'region' => 'nyc3', // nyc3, sfo2, sfo3, ams3, sgp1, fra1
        'accessKey' => getenv('DO_SPACES_KEY'),
        'secretKey' => getenv('DO_SPACES_SECRET'),
        'baseUrl' => getenv('DO_SPACES_BASE_URL'),
        'endpoint' => getenv('DO_SPACES_ENDPOINT'), // Region-only, no bucket
    ],
],
```

#### Google Cloud Storage

```php
'targetProvider' => [
    'type' => 'gcs',
    'config' => [
        'bucket' => 'my-gcs-bucket',
        'projectId' => 'my-project-id',
        'keyFilePath' => '/path/to/service-account.json',
        'baseUrl' => 'https://storage.googleapis.com/my-bucket', // Optional
    ],
],
```

**Service Account Setup:**
1. Go to Google Cloud Console → IAM & Admin → Service Accounts
2. Create service account with "Storage Admin" role
3. Generate JSON key
4. Save to secure location and reference in config

#### Azure Blob Storage

```php
'targetProvider' => [
    'type' => 'azure-blob',
    'config' => [
        'container' => 'my-container',
        'accountName' => 'mystorageaccount',
        'accountKey' => getenv('AZURE_ACCOUNT_KEY'),
        'baseUrl' => 'https://mystorageaccount.blob.core.windows.net/my-container',
    ],
],
```

#### Backblaze B2

```php
'targetProvider' => [
    'type' => 'backblaze-b2',
    'config' => [
        'bucket' => 'my-b2-bucket',
        'region' => 'us-west-002', // us-west-001, us-west-002, eu-central-003
        'accessKey' => getenv('B2_KEY_ID'), // Application Key ID
        'secretKey' => getenv('B2_APPLICATION_KEY'),
    ],
],
```

#### Wasabi

```php
'targetProvider' => [
    'type' => 'wasabi',
    'config' => [
        'bucket' => 'my-wasabi-bucket',
        'region' => 'us-east-1', // us-east-1, eu-central-1, ap-northeast-1, etc.
        'accessKey' => getenv('WASABI_ACCESS_KEY'),
        'secretKey' => getenv('WASABI_SECRET_KEY'),
    ],
],
```

#### Cloudflare R2

```php
'targetProvider' => [
    'type' => 'cloudflare-r2',
    'config' => [
        'bucket' => 'my-r2-bucket',
        'accountId' => 'your-account-id',
        'accessKey' => getenv('R2_ACCESS_KEY_ID'),
        'secretKey' => getenv('R2_SECRET_ACCESS_KEY'),
    ],
],
```

**R2 Setup:**
1. Cloudflare Dashboard → R2 → Create Bucket
2. Create API Token (R2 Read & Write)
3. Note your Account ID from the R2 overview page

#### Local Filesystem

```php
'sourceProvider' => [
    'type' => 'local',
    'config' => [
        'basePath' => '/path/to/files',
        'baseUrl' => 'https://example.com/files', // Optional
    ],
],
```

---

## Migration Scenarios

### Scenario 1: AWS S3 → DigitalOcean Spaces

**Use Case:** Cost reduction, simpler pricing

**Configuration:**

```php
'sourceProvider' => [
    'type' => 's3',
    'config' => [
        'bucket' => getenv('AWS_BUCKET'),
        'region' => 'us-east-1',
        'accessKey' => getenv('AWS_ACCESS_KEY_ID'),
        'secretKey' => getenv('AWS_SECRET_ACCESS_KEY'),
    ],
],

'targetProvider' => [
    'type' => 'do-spaces',
    'config' => [
        'bucket' => getenv('DO_SPACES_BUCKET'),
        'region' => 'nyc3',
        'accessKey' => getenv('DO_SPACES_KEY'),
        'secretKey' => getenv('DO_SPACES_SECRET'),
    ],
],

'urlReplacementStrategies' => [
    [
        'type' => 'simple',
        'search' => 'https://my-bucket.s3.amazonaws.com',
        'replace' => 'https://my-bucket.nyc3.digitaloceanspaces.com',
    ],
],
```

**Steps:**
1. Test connections: `php craft s3-spaces-migration/provider-test/test-all`
2. Run migration: `php craft s3-spaces-migration/image-migration/migrate`
3. Verify: `php craft s3-spaces-migration/migration-diag/check`

### Scenario 2: AWS S3 → Google Cloud Storage

**Use Case:** Google Cloud ecosystem integration

**Configuration:**

```php
'sourceProvider' => ['type' => 's3', ...],
'targetProvider' => [
    'type' => 'gcs',
    'config' => [
        'bucket' => 'my-gcs-bucket',
        'projectId' => 'my-project',
        'keyFilePath' => '/path/to/service-account.json',
    ],
],
```

### Scenario 3: Multi-CDN Consolidation

**Use Case:** Consolidating multiple CDN domains

**Configuration:**

```php
'urlReplacementStrategies' => [
    [
        'type' => 'multi-mapping',
        'mappings' => [
            'cdn1.example.com' => 'cdn.example.com',
            'cdn2.example.com' => 'cdn.example.com',
            'cdn3.example.com' => 'cdn.example.com',
            's3.amazonaws.com/old-bucket' => 'cdn.example.com',
        ],
    ],
],
```

### Scenario 4: Local Filesystem Reorganization

**Use Case:** Untangle nested photo library

**Configuration:**

```php
'migrationMode' => 'local-reorganize',

'sourceProvider' => [
    'type' => 'local',
    'config' => [
        'basePath' => '/Users/me/Photos/Mess',
    ],
],

'targetProvider' => [
    'type' => 'local',
    'config' => [
        'basePath' => '/Users/me/Photos/Organized',
    ],
],
```

### Scenario 5: Cloud → Local Backup

**Use Case:** Backup cloud assets locally

**Configuration:**

```php
'migrationMode' => 'cloud-to-local',

'sourceProvider' => ['type' => 's3', ...],
'targetProvider' => [
    'type' => 'local',
    'config' => [
        'basePath' => '/backups/cloud-assets',
    ],
],
```

### Scenario 6: Cost Optimization with Backblaze B2

**Use Case:** Reduce storage costs by 75%

**Configuration:**

```php
'targetProvider' => [
    'type' => 'backblaze-b2',
    'config' => [
        'bucket' => 'my-b2-bucket',
        'region' => 'us-west-002',
        'accessKey' => getenv('B2_KEY_ID'),
        'secretKey' => getenv('B2_APPLICATION_KEY'),
    ],
],
```

---

## Testing Your Setup

### Test Individual Providers

```bash
# Test source provider
php craft s3-spaces-migration/provider-test/test-source

# Test target provider
php craft s3-spaces-migration/provider-test/test-target

# Test both
php craft s3-spaces-migration/provider-test/test-all
```

**Expected Output:**

```
Testing Source Provider...
─────────────────────────────────────────────────
Provider Type: s3
Creating provider instance...
✓ Provider created successfully

Provider Information:
  Name: s3
  Bucket: my-bucket
  Region: us-east-1

Capabilities:
  Server-side copy: ✓
  Versioning: ✓
  Streaming: ✓
  Max file size: 5 TB
  Optimal batch size: 100

Testing connection...
✓ S3 connection successful: my-bucket (us-east-1)
  bucket: my-bucket
  region: us-east-1
  subfolder: (root)
  Response time: 0.234s
```

### List Files

```bash
# List 10 files from source
php craft s3-spaces-migration/provider-test/list-files --provider=source --limit=10

# List files from target
php craft s3-spaces-migration/provider-test/list-files --provider=target --limit=20
```

### Test File Copy

```bash
# Copy a single file to verify it works
php craft s3-spaces-migration/provider-test/copy-test \
  --source-path=test.jpg \
  --target-path=test-copy.jpg
```

**Expected Output:**

```
Copy Test
─────────────────────────────────────────────────
Source Path: test.jpg
Target Path: test-copy.jpg

Creating source provider (s3)...
Creating target provider (do-spaces)...
Checking if source file exists...
Source file: 2.45 MB, image/jpeg
Copying file...
✓ Copy successful in 1.23 seconds
✓ Target file verified: 2.45 MB
```

---

## Running Migrations

### Pre-Flight Checks

Always run pre-flight checks before migrating:

```bash
php craft s3-spaces-migration/migration-check/check
```

This validates:
- Provider connections
- Credentials
- Bucket permissions
- Configuration
- Volume settings

### Full Migration

```bash
# Dry run first (recommended)
php craft s3-spaces-migration/image-migration/migrate --dry-run

# Actual migration
php craft s3-spaces-migration/image-migration/migrate

# With checkpointing (resume from failures)
php craft s3-spaces-migration/image-migration/migrate --resume
```

### Migration Options

```bash
--dry-run         # Preview without making changes
--resume          # Resume from last checkpoint
--skip-backup     # Skip backup step
--batch-size=100  # Number of files per batch
--yes             # Skip confirmations
```

### Monitor Progress

The migration shows real-time progress:

```
Migrating Assets...
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ 450/1000 (45%)
ETA: 5 minutes 32 seconds | Speed: 15.2 files/sec
```

### Resume After Interruption

If migration is interrupted, resume from last checkpoint:

```bash
php craft s3-spaces-migration/image-migration/migrate --resume
```

Spaghetti Migrator automatically saves checkpoints every batch, so you never lose progress.

---

## Troubleshooting

### Connection Failures

**Error:** `S3 connection failed: Access Denied`

**Solution:**
1. Verify credentials in `.env`
2. Check IAM policy has S3 read/write permissions
3. Verify bucket name is correct
4. Test with `aws s3 ls s3://bucket-name` to confirm access

**Error:** `GCS connection failed: invalid_grant`

**Solution:**
1. Verify service account JSON is valid
2. Check service account has "Storage Admin" role
3. Ensure project ID matches JSON file
4. Re-download service account key if necessary

### Slow Migrations

**Issue:** Migration is slower than expected

**Solutions:**
1. Increase batch size: `--batch-size=200`
2. Check network connectivity
3. Verify you're in the same region as storage
4. Use server-side copy when both providers support it

### Missing Files

**Error:** `File not found: image.jpg`

**Solution:**
1. Run diagnostics: `php craft s3-spaces-migration/migration-diag/check`
2. Verify source path in Craft volumes
3. Check subfolder configuration
4. List files to find actual paths: `--list-files`

### URL Replacement Not Working

**Issue:** URLs not being replaced in database

**Solution:**
1. Check URL patterns match exactly
2. Use regex for flexible matching
3. Test strategies: `php craft s3-spaces-migration/url-replacement/preview`
4. Verify database tables are included in scan

---

## Best Practices

### 1. Always Test First

- Test connections before migrating
- Run dry-run migrations
- Copy a few test files first
- Verify URL replacement preview

### 2. Use Environment Variables

Never hardcode credentials:

```php
// ✅ Good
'accessKey' => getenv('AWS_ACCESS_KEY_ID'),

// ❌ Bad
'accessKey' => 'AKIAIOSFODNN7EXAMPLE',
```

### 3. Plan Your URLs

Consider:
- CDN configuration
- SSL certificates
- Custom domains
- URL structure (virtual-hosted vs path-style)

### 4. Monitor Costs

Check pricing for:
- Storage costs
- Egress/bandwidth
- API requests
- Data transfer between regions

### 5. Backup Before Migrating

```bash
# Backup database
mysqldump craft > backup-$(date +%Y%m%d).sql

# Backup current config
cp config/migration-config.php config/migration-config.backup.php
```

### 6. Use Checkpoints

Spaghetti Migrator saves checkpoints automatically:
- Every batch by default
- Configurable frequency
- Resume from any checkpoint
- Roll back if needed

### 7. Verify After Migration

```bash
# Check for missing files
php craft s3-spaces-migration/migration-diag/check

# Verify random samples
php craft s3-spaces-migration/migration-diag/verify-sample
```

### 8. Clean Up

After successful migration:

```bash
# Remove old checkpoints
php craft s3-spaces-migration/image-migration/cleanup --older-than-hours=24

# Archive change logs
# (useful for rollback, keep for 30 days)
```

---

## Advanced Topics

### Custom URL Strategies

Create complex URL transformations:

```php
'urlReplacementStrategies' => [
    // Protocol upgrade
    [
        'type' => 'simple',
        'search' => 'http://cdn.example.com',
        'replace' => 'https://cdn.example.com',
        'priority' => 100, // Run first
    ],

    // Regex with capture groups
    [
        'type' => 'regex',
        'pattern' => '#https://([^.]+)\.s3\.([^.]+)\.amazonaws\.com#',
        'replacement' => 'https://$1.nyc3.digitaloceanspaces.com',
        'priority' => 50,
    ],

    // Multi-domain mapping
    [
        'type' => 'multi-mapping',
        'mappings' => [
            'old-cdn1.com' => 'new-cdn.com',
            'old-cdn2.com' => 'new-cdn.com',
        ],
        'priority' => 10,
    ],
],
```

### Custom Provider Adapters

Extend the system with your own providers:

```php
use csabourin\spaghettiMigrator\interfaces\StorageProviderInterface;

class CustomStorageAdapter implements StorageProviderInterface
{
    public function getProviderName(): string
    {
        return 'custom';
    }

    // Implement remaining interface methods...
}

// Register in Plugin.php or config
Plugin::getInstance()->providerRegistry->registerAdapter('custom', CustomStorageAdapter::class);
```

---

## Need Help?

- **Documentation:** See ARCHITECTURE.md for technical details
- **Examples:** Check `config/migration-config-v2.php` for all options
- **Issues:** Report bugs at https://github.com/csabourin/do-migration/issues
- **Discussions:** Ask questions in GitHub Discussions

---

**Happy Migrating! 🚀**
