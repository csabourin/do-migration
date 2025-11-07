# SSL Certificate Error Fix Guide

## Problem Description

You're seeing this error:
```
SSL: no alternative certificate subject name matches target host name
'dev-medias-test.dev-medias-test.tor1.digitaloceanspaces.com'
```

Notice the bucket name `dev-medias-test` is **duplicated** in the hostname.

## Root Cause

The DO Spaces filesystem is configured with an endpoint that includes the bucket name:
- **Current (WRONG)**: endpoint = `https://dev-medias-test.tor1.digitaloceanspaces.com`
- **Bucket**: `dev-medias-test`

When the AWS SDK makes requests, it constructs URLs as `bucket.endpoint`, resulting in:
```
https://dev-medias-test.dev-medias-test.tor1.digitaloceanspaces.com
```

This hostname doesn't exist and fails SSL certificate validation.

## Solution

The endpoint should NOT include the bucket name:
- **Correct**: endpoint = `https://tor1.digitaloceanspaces.com`
- **Bucket**: `dev-medias-test`

This allows the SDK to construct the correct URL:
```
https://dev-medias-test.tor1.digitaloceanspaces.com
```

## How to Fix

### Option 1: Automatic Fix (Recommended)

Run the automatic fix command:

```bash
ddev craft s3-spaces-migration/filesystem-fix/fix-endpoints
```

This will:
1. Scan all DO Spaces filesystems
2. Detect incorrect endpoint configurations
3. Fix them automatically
4. Save the corrected configuration

### Option 2: Manual Fix via Craft Admin Panel

1. Go to **Settings → Filesystems** in Craft admin
2. For each DO Spaces filesystem (especially `quarantine`):
   - Click to edit
   - Find the **Endpoint** field
   - Change from: `https://dev-medias-test.tor1.digitaloceanspaces.com`
   - To: `https://tor1.digitaloceanspaces.com`
   - Keep the **Bucket** field as: `dev-medias-test`
   - Save

### Option 3: Check Configuration First

View current filesystem configurations:

```bash
ddev craft s3-spaces-migration/filesystem-fix/show
```

This will show all DO Spaces filesystems and highlight any with incorrect endpoints.

## Verification

After applying the fix, verify it worked:

```bash
ddev craft s3-spaces-migration/migration-check
```

The quarantine filesystem checks should now pass:
```
2. Checking filesystem access...
     ✓ Read access: quarantine
     ✓ Write access: quarantine
     ✓ Delete access: quarantine
   ✓ PASS
```

## Affected Filesystems

This issue can affect any DO Spaces filesystem, but commonly impacts:
- `quarantine` filesystem
- Image transform filesystems
- Any filesystem created with the bucket name in the endpoint

## Technical Details

### AWS SDK URL Construction

The AWS SDK (which DO Spaces uses) supports two URL styles:

1. **Virtual-hosted style** (what we want):
   ```
   https://bucket-name.region.digitaloceanspaces.com/path
   ```

2. **Path style**:
   ```
   https://region.digitaloceanspaces.com/bucket-name/path
   ```

When you set:
- `endpoint = "https://bucket-name.region.digitaloceanspaces.com"`
- `bucket = "bucket-name"`

The SDK treats `bucket-name.region.digitaloceanspaces.com` as the endpoint and prepends the bucket:
```
https://bucket-name.bucket-name.region.digitaloceanspaces.com/path
```

### Correct Configuration Pattern

Always use the region-only endpoint:

```php
[
    'endpoint' => 'https://tor1.digitaloceanspaces.com',  // No bucket name
    'bucket' => 'dev-medias-test',                        // Bucket here
    'region' => 'tor1',
]
```

The SDK will construct: `https://dev-medias-test.tor1.digitaloceanspaces.com`

## Related Issues

- **Migration check fails**: Quarantine filesystem cannot be accessed
- **Asset uploads fail**: Files cannot be written to DO Spaces
- **SSL/TLS errors**: Certificate validation fails
- **cURL error 60**: SSL certificate problem

## Need Help?

If the automatic fix doesn't work:

1. Check the filesystem configuration manually in Craft admin
2. Verify your `.env` has correct values:
   ```
   DO_S3_REGION=tor1
   DO_S3_BUCKET=dev-medias-test
   DO_S3_BASE_URL=https://dev-medias-test.tor1.digitaloceanspaces.com
   ```
3. Note: `DO_S3_BASE_URL` is for display/access URLs and is different from the SDK endpoint
4. Run `ddev craft s3-spaces-migration/filesystem-fix/show` to see current config
5. Check Craft logs: `storage/logs/web.log` and `storage/logs/console.log`
