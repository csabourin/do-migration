# Provider Configuration Examples

Complete configuration examples for all supported storage providers in Spaghetti Migrator v2.0.

## Table of Contents

- [AWS S3](#aws-s3)
- [DigitalOcean Spaces](#digitalocean-spaces)
- [Google Cloud Storage](#google-cloud-storage)
- [Azure Blob Storage](#azure-blob-storage)
- [Backblaze B2](#backblaze-b2)
- [Wasabi](#wasabi)
- [Cloudflare R2](#cloudflare-r2)
- [Local Filesystem](#local-filesystem)

---

## AWS S3

### Basic Configuration

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
```

### With Custom Endpoint (S3-Compatible Services)

```php
'config' => [
    'bucket' => 'my-bucket',
    'region' => 'us-east-1',
    'accessKey' => getenv('S3_ACCESS_KEY'),
    'secretKey' => getenv('S3_SECRET_KEY'),
    'endpoint' => 'https://s3.custom-provider.com',
    'baseUrl' => 'https://my-bucket.custom-provider.com',
],
```

### With Subfolder

```php
'config' => [
    'bucket' => 'my-bucket',
    'region' => 'us-west-2',
    'accessKey' => getenv('AWS_ACCESS_KEY_ID'),
    'secretKey' => getenv('AWS_SECRET_ACCESS_KEY'),
    'subfolder' => 'assets/images', // Optional subfolder
    'baseUrl' => 'https://my-bucket.s3.us-west-2.amazonaws.com/assets/images',
],
```

### Environment Variables

```bash
# .env
AWS_BUCKET=my-s3-bucket
AWS_REGION=us-east-1
AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
AWS_BASE_URL=https://my-bucket.s3.amazonaws.com
```

---

## DigitalOcean Spaces

### Basic Configuration

```php
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

### With Custom Domain/CDN

```php
'config' => [
    'bucket' => 'my-space',
    'region' => 'sfo3',
    'accessKey' => getenv('DO_SPACES_KEY'),
    'secretKey' => getenv('DO_SPACES_SECRET'),
    'baseUrl' => 'https://cdn.example.com', // Custom CDN URL
    'endpoint' => 'https://sfo3.digitaloceanspaces.com', // Region-only, no bucket
],
```

### Available Regions

```php
// nyc3  - New York 3
// sfo2  - San Francisco 2
// sfo3  - San Francisco 3
// ams3  - Amsterdam 3
// sgp1  - Singapore 1
// fra1  - Frankfurt 1

'region' => 'ams3', // Choose based on user location
```

### Environment Variables

```bash
# .env
DO_SPACES_BUCKET=my-space
DO_SPACES_REGION=nyc3
DO_SPACES_KEY=DO00EXAMPLEKEY123456
DO_SPACES_SECRET=example_secret_key_here_32_chars_long
DO_SPACES_BASE_URL=https://my-space.nyc3.digitaloceanspaces.com
DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
```

---

## Google Cloud Storage

### Basic Configuration

```php
'targetProvider' => [
    'type' => 'gcs',
    'config' => [
        'bucket' => 'my-gcs-bucket',
        'projectId' => 'my-project-id',
        'keyFilePath' => '/path/to/service-account.json',
    ],
],
```

### With Custom Base URL

```php
'config' => [
    'bucket' => 'my-bucket',
    'projectId' => 'my-project-123',
    'keyFilePath' => getenv('GCS_KEY_FILE_PATH'),
    'baseUrl' => 'https://storage.googleapis.com/my-bucket',
    'subfolder' => 'assets',
],
```

### Service Account Setup

1. **Create Service Account:**
   - Go to Google Cloud Console → IAM & Admin → Service Accounts
   - Click "Create Service Account"
   - Name: "spaghetti-migrator"
   - Grant role: "Storage Admin"

2. **Generate JSON Key:**
   - Click on service account
   - Keys tab → Add Key → Create New Key
   - Choose JSON format
   - Download to secure location

3. **Configure:**
   ```php
   'keyFilePath' => '/secure/path/to/my-project-abc123-1a2b3c4d5e6f.json',
   ```

### Environment Variables

```bash
# .env
GCS_BUCKET=my-gcs-bucket
GCS_PROJECT_ID=my-project-123
GCS_KEY_FILE_PATH=/path/to/service-account.json
```

### Service Account JSON Example

```json
{
  "type": "service_account",
  "project_id": "my-project-123",
  "private_key_id": "abc123...",
  "private_key": "-----BEGIN PRIVATE KEY-----\n...",
  "client_email": "spaghetti-migrator@my-project.iam.gserviceaccount.com",
  "client_id": "123456789",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token"
}
```

---

## Azure Blob Storage

### Basic Configuration

```php
'targetProvider' => [
    'type' => 'azure-blob',
    'config' => [
        'container' => 'my-container',
        'accountName' => 'mystorageaccount',
        'accountKey' => getenv('AZURE_ACCOUNT_KEY'),
    ],
],
```

### With Custom Domain

```php
'config' => [
    'container' => 'assets',
    'accountName' => 'mycompanystorage',
    'accountKey' => getenv('AZURE_ACCOUNT_KEY'),
    'baseUrl' => 'https://cdn.example.com',
],
```

### Getting Your Azure Credentials

1. **Storage Account Name:**
   - Azure Portal → Storage Accounts → Select your account
   - Name is shown at the top

2. **Account Key:**
   - Storage Account → Security + networking → Access Keys
   - Copy "Key 1" or "Key 2"

3. **Container Name:**
   - Storage Account → Data Storage → Containers
   - Create new or use existing container name

### Environment Variables

```bash
# .env
AZURE_CONTAINER=my-container
AZURE_ACCOUNT_NAME=mystorageaccount
AZURE_ACCOUNT_KEY=very_long_base64_encoded_key_here==
AZURE_BASE_URL=https://mystorageaccount.blob.core.windows.net/my-container
```

---

## Backblaze B2

### Basic Configuration

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

### Available Regions

```php
// us-west-001 - US West (Phoenix)
// us-west-002 - US West (Original)
// us-west-004 - US West (Latest)
// eu-central-003 - EU Central (Amsterdam)

'region' => 'us-west-002',
```

### Getting B2 Credentials

1. **Create Bucket:**
   - Backblaze B2 Dashboard → Buckets
   - Create Bucket → Note bucket name

2. **Create Application Key:**
   - B2 Dashboard → App Keys
   - Add New Application Key
   - Access: Read and Write
   - Note: keyID and applicationKey

3. **Region:**
   - Shown in bucket details as "Endpoint"
   - Extract region from: `s3.us-west-002.backblazeb2.com`

### Environment Variables

```bash
# .env
B2_BUCKET=my-b2-bucket
B2_REGION=us-west-002
B2_KEY_ID=0012abc3456789def0001
B2_APPLICATION_KEY=K001abcdefghijklmnopqrstuvwxyz123456789
```

### Cost Comparison

Typical savings vs AWS S3:
- Storage: ~$0.005/GB vs $0.023/GB (78% cheaper)
- Egress: First 3x storage free, then $0.01/GB vs $0.09/GB
- API calls: Cheaper per transaction

---

## Wasabi

### Basic Configuration

```php
'targetProvider' => [
    'type' => 'wasabi',
    'config' => [
        'bucket' => 'my-wasabi-bucket',
        'region' => 'us-east-1',
        'accessKey' => getenv('WASABI_ACCESS_KEY'),
        'secretKey' => getenv('WASABI_SECRET_KEY'),
    ],
],
```

### Available Regions

```php
// us-east-1 - Virginia
// us-east-2 - N. Virginia
// us-central-1 - Texas
// us-west-1 - Oregon
// eu-central-1 - Amsterdam
// eu-west-1 - London
// eu-west-2 - Paris
// ap-northeast-1 - Tokyo
// ap-northeast-2 - Osaka
// ap-southeast-1 - Singapore
// ap-southeast-2 - Sydney

'region' => 'us-east-1',
```

### Getting Wasabi Credentials

1. **Create Bucket:**
   - Wasabi Console → Buckets
   - Create Bucket → Choose region

2. **Access Keys:**
   - Wasabi Console → Access Keys
   - Create Access Key
   - Note Access Key ID and Secret Key

### Environment Variables

```bash
# .env
WASABI_BUCKET=my-wasabi-bucket
WASABI_REGION=us-east-1
WASABI_ACCESS_KEY=WASABI_ACCESS_KEY_123456789ABC
WASABI_SECRET_KEY=wasabi_secret_key_very_long_string_here
```

### Why Choose Wasabi

- 80% cheaper than AWS S3
- No egress fees
- No API charges
- Minimum 90-day storage commitment
- Fast performance

---

## Cloudflare R2

### Basic Configuration

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

### With Custom Domain

```php
'config' => [
    'bucket' => 'my-assets',
    'accountId' => 'abc123def456',
    'accessKey' => getenv('R2_ACCESS_KEY_ID'),
    'secretKey' => getenv('R2_SECRET_ACCESS_KEY'),
    'baseUrl' => 'https://assets.example.com', // Custom domain
],
```

### Getting R2 Credentials

1. **Create R2 Bucket:**
   - Cloudflare Dashboard → R2
   - Create Bucket → Note bucket name

2. **Account ID:**
   - R2 Overview page → Account ID displayed at top
   - Format: `abc123def456789...`

3. **API Token:**
   - R2 → Manage R2 API Tokens
   - Create API Token
   - Permissions: Object Read & Write
   - Note Access Key ID and Secret Access Key

### Environment Variables

```bash
# .env
R2_BUCKET=my-r2-bucket
R2_ACCOUNT_ID=abc123def456789ghi012jkl345mno
R2_ACCESS_KEY_ID=abcd1234efgh5678ijkl9012mnop3456
R2_SECRET_ACCESS_KEY=very_long_secret_access_key_string_64_chars_long_example
```

### Why Choose R2

- **Zero egress fees** (vs $0.09/GB on S3)
- S3-compatible API
- Global edge network (auto-distribution)
- Flat pricing: $0.015/GB storage
- Free up to 10GB storage

---

## Local Filesystem

### Basic Configuration

```php
'sourceProvider' => [
    'type' => 'local',
    'config' => [
        'basePath' => '/path/to/files',
    ],
],
```

### With Public URL

```php
'config' => [
    'basePath' => '/var/www/html/uploads',
    'baseUrl' => 'https://example.com/uploads',
    'visibility' => 'public', // or 'private'
],
```

### Common Use Cases

#### 1. Local to Cloud Migration

```php
'sourceProvider' => [
    'type' => 'local',
    'config' => [
        'basePath' => '/var/www/legacy/assets',
    ],
],

'targetProvider' => [
    'type' => 's3',
    'config' => [...],
],
```

#### 2. Cloud Backup

```php
'sourceProvider' => [
    'type' => 's3',
    'config' => [...],
],

'targetProvider' => [
    'type' => 'local',
    'config' => [
        'basePath' => '/backups/cloud-assets',
    ],
],
```

#### 3. Filesystem Reorganization

```php
'migrationMode' => 'local-reorganize',

'sourceProvider' => [
    'type' => 'local',
    'config' => [
        'basePath' => '/Users/me/Photos/Messy',
    ],
],

'targetProvider' => [
    'type' => 'local',
    'config' => [
        'basePath' => '/Users/me/Photos/Organized',
    ],
],
```

---

## Migration Combinations

### All Possible Migrations

With 8 providers, you can migrate between **64 combinations**:

| From ↓ | S3 | DO | GCS | Azure | B2 | Wasabi | R2 | Local |
|--------|----|----|-----|-------|-------|--------|----|----|
| **S3** | ↻ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **DO** | ✓ | ↻ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **GCS** | ✓ | ✓ | ↻ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Azure** | ✓ | ✓ | ✓ | ↻ | ✓ | ✓ | ✓ | ✓ |
| **B2** | ✓ | ✓ | ✓ | ✓ | ↻ | ✓ | ✓ | ✓ |
| **Wasabi** | ✓ | ✓ | ✓ | ✓ | ✓ | ↻ | ✓ | ✓ |
| **R2** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ↻ | ✓ |
| **Local** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ↻ |

*(↻ = Same-provider reorganization/backup)*

### Popular Migration Paths

1. **S3 → DO Spaces** - Cost reduction
2. **S3 → Backblaze B2** - Maximum savings
3. **S3 → Cloudflare R2** - Zero egress fees
4. **S3 → GCS** - Google Cloud ecosystem
5. **Any → Local** - Backup/archival
6. **Local → Any** - Initial cloud migration

---

## Testing Commands

Test each provider configuration:

```bash
# Test source provider
php craft s3-spaces-migration/provider-test/test-source

# Test target provider
php craft s3-spaces-migration/provider-test/test-target

# Test both at once
php craft s3-spaces-migration/provider-test/test-all

# List files
php craft s3-spaces-migration/provider-test/list-files \
  --provider=source \
  --limit=10

# Test copy
php craft s3-spaces-migration/provider-test/copy-test \
  --source-path=test.jpg \
  --target-path=test-copy.jpg
```

---

## Need Help?

- **Main Guide:** See [MIGRATION_GUIDE.md](../MIGRATION_GUIDE.md)
- **Architecture:** See [ARCHITECTURE.md](../ARCHITECTURE.md)
- **Issues:** https://github.com/csabourin/do-migration/issues
