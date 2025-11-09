# 🏗️ Architecture Overview

> **AWS S3 → DigitalOcean Spaces Migration Toolkit**
> A production-grade Craft CMS 4 module for seamless cloud storage migration

---

## 📖 Table of Contents

1. [System Overview](#-system-overview)
2. [Core Components](#-core-components)
3. [Architecture Patterns](#-architecture-patterns)
4. [Data Flow](#-data-flow)
5. [Migration Phases](#-migration-phases)
6. [Component Interactions](#-component-interactions)
7. [Configuration System](#-configuration-system)
8. [Error Handling & Recovery](#-error-handling--recovery)
9. [Extension Points](#-extension-points)

---

## 🎯 System Overview

### Purpose

This module provides a comprehensive toolkit for migrating Craft CMS assets from AWS S3 to DigitalOcean Spaces with zero data loss and minimal downtime.

### Key Characteristics

- **Production-Ready**: Checkpoint/resume, rollback, error recovery
- **Memory Efficient**: Batch processing for 100k+ assets
- **Safe**: Dry-run mode, backups, validation at every step
- **Observable**: Progress tracking, detailed logging, monitoring
- **Idempotent**: Safe to run multiple times

### Technology Stack

```
┌─────────────────────────────────────────────┐
│           Craft CMS 4 Framework             │
├─────────────────────────────────────────────┤
│  Yii 2 Console Controllers & Components     │
├─────────────────────────────────────────────┤
│  PHP 8.0+  │  MySQL/PostgreSQL  │  Composer │
├─────────────────────────────────────────────┤
│  AWS S3 SDK  │  DigitalOcean Spaces SDK     │
└─────────────────────────────────────────────┘
```

---

## 🧩 Core Components

### 1. Module Entry Point (`module.php`)

**Role**: Bootstrap and initialization
**Responsibilities**:
- Auto-detect web vs console requests
- Route to appropriate controller namespace
- Register Twig filters
- Configure module aliases

```
┌─────────────────────────────────┐
│      Craft CMS Bootstrap        │
└────────────┬────────────────────┘
             │
             ▼
┌─────────────────────────────────┐
│     MigrationModule::init()     │
├─────────────────────────────────┤
│ • Set aliases                   │
│ • Detect request type           │
│ • Load controller namespace     │
│ • Register Twig filters         │
└────────────┬────────────────────┘
             │
    ┌────────┴────────┐
    ▼                 ▼
┌──────────┐    ┌──────────────┐
│   Web    │    │   Console    │
│Controllers│    │ Controllers  │
└──────────┘    └──────────────┘
```

### 2. Configuration System (`MigrationConfig.php`)

**Role**: Single source of truth for all configuration
**Pattern**: Singleton
**Location**: `modules/helpers/MigrationConfig.php`

**Key Features**:
- Centralized configuration loading
- Type-safe getter methods
- Environment-aware (dev/staging/prod)
- Validation built-in
- Dot-notation access

```php
// Usage in controllers
$config = MigrationConfig::getInstance();
$awsUrls = $config->getAwsUrls();
$doBaseUrl = $config->getDoBaseUrl();
$batchSize = $config->getBatchSize();
```

**Configuration Sources**:
1. **Primary**: `config/migration-config.php` (user-customized)
2. **Fallback**: `modules/config/migration-config.php`
3. **Environment Variables**: `.env` (DO_S3_*, MIGRATION_ENV)

### 3. Console Controllers (14 Specialized Controllers)

Each controller handles a specific domain of the migration process:

| Controller | Phase | Primary Responsibility |
|-----------|-------|----------------------|
| **MigrationCheckController** | Pre-flight | Configuration & environment validation (10 automated checks) |
| **FilesystemController** | Setup | Create/delete DO Spaces filesystems |
| **VolumeConfigController** | Setup | Configure transform filesystem & field layouts |
| **UrlReplacementController** | Phase 2 | Database URL replacement (content tables) |
| **ExtendedUrlReplacementController** | Phase 2 | Additional tables & JSON fields |
| **TemplateUrlReplacementController** | Phase 3 | Twig template URL replacement |
| **ImageMigrationController** | Phase 4 | Physical file migration (checkpoint/resume) |
| **FilesystemSwitchController** | Phase 5 | Switch volumes between AWS ↔ DO |
| **FsDiagController** | Diagnostic | Compare and analyze filesystems |
| **MigrationDiagController** | Post-flight | Verify migration success |
| **TransformDiscoveryController** | Phase 7 | Discover image transformations |
| **TransformPreGenerationController** | Phase 7 | Pre-generate transforms |
| **PluginConfigAuditController** | Audit | Scan plugin configurations |
| **StaticAssetScanController** | Audit | Scan JS/CSS for hardcoded URLs |

### 4. Checkpoint System

**Purpose**: Enable resumable migrations that survive interruptions

**Components**:
- **CheckpointManager**: Save/restore state
- **File Location**: `@storage/migration-checkpoints/`
- **Data Stored**: Progress, processed IDs, errors, configuration

**Checkpoint Structure**:
```php
[
    'timestamp' => time(),
    'environment' => 'production',
    'phase' => 'migration',
    'progress' => [
        'total' => 50000,
        'processed' => 12500,
        'remaining' => 37500,
        'percent' => 25.0
    ],
    'state' => [
        'processedAssetIds' => [1, 2, 3, ...],
        'processedFileKeys' => ['file1.jpg', 'file2.png', ...],
        'failedOperations' => [...]
    ],
    'config' => [...]
]
```

**Resume Flow**:
```
1. Check for existing checkpoint
2. Load saved state
3. Restore progress counters
4. Skip already-processed items
5. Continue from last position
```

### 5. Change Log System

**Purpose**: Enable complete rollback of migrations

**Components**:
- **ChangeLogManager**: Continuous atomic logging
- **File Location**: `@storage/migration-logs/changelog-{timestamp}.json`
- **Log Format**: JSON Lines (one operation per line)

**Logged Operations**:
- Asset record updates (volumeId, folderId changes)
- File copies (source → destination)
- Folder structure changes
- Database modifications

**Rollback Process**:
```
1. Read changelog in reverse order
2. For each operation:
   - Restore old asset record values
   - Delete copied files
   - Revert database changes
3. Verify rollback success
```

---

## 🎨 Architecture Patterns

### 1. Singleton Pattern

**Used In**: `MigrationConfig`

**Why**: Single source of truth for configuration across all controllers

```php
class MigrationConfig {
    private static $instance = null;

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
```

### 2. Controller Pattern

**Used In**: All console controllers

**Why**: Organize migration logic into domain-specific actions

```php
class UrlReplacementController extends Controller {
    public function actionReplaceS3Urls($dryRun = false) { }
    public function actionVerify() { }
    public function actionShowConfig() { }
}
```

### 3. Batch Processing Pattern

**Used In**: `ImageMigrationController`

**Why**: Memory-efficient handling of large datasets

```php
// Process 100 assets at a time
$batchSize = 100;
$offset = 0;

while ($batch = $this->getNextBatch($offset, $batchSize)) {
    foreach ($batch as $asset) {
        $this->processAsset($asset);
    }

    $offset += $batchSize;
    $this->checkpoint(); // Save progress
}
```

### 4. Strategy Pattern

**Used In**: Volume structure handling

**Why**: Different volumes have different folder structures

```php
// Configured in migration-config.php
'volumes' => [
    'atBucketRoot' => ['optimisedImages', 'chartData'],
    'withSubfolders' => ['images', 'optimisedImages'],
    'flatStructure' => ['chartData']
]

// Controller logic adapts based on strategy
if ($config->volumeHasSubfolders($volumeHandle)) {
    // Handle subfolder structure
} else {
    // Handle flat structure
}
```

### 5. Transaction Pattern

**Used In**: Database operations

**Why**: Atomic operations that can be rolled back

```php
$transaction = Craft::$app->getDb()->beginTransaction();
try {
    // Multiple database operations
    $this->updateAssetRecord($asset);
    $this->updateFolderReferences($asset);

    $transaction->commit();
} catch (\Exception $e) {
    $transaction->rollBack();
    throw $e;
}
```

---

## 🔄 Data Flow

### Overall Migration Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    PHASE 0: Configuration                    │
│  • Copy migration-config.php to config/                     │
│  • Configure .env variables                                 │
│  • Run validation checks                                    │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│              PHASE 1: Pre-Migration Validation               │
│  • MigrationCheckController: Verify configuration           │
│  • FsDiagController: Compare filesystems                    │
│  • PluginConfigAuditController: Check plugins               │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│              PHASE 2: Database URL Replacement               │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ UrlReplacementController:                            │  │
│  │  • Scan content tables for AWS URLs                  │  │
│  │  • Replace with DO URLs                              │  │
│  │  • Generate replacement report                       │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ ExtendedUrlReplacementController:                    │  │
│  │  • Handle additional tables                          │  │
│  │  • Process JSON fields                               │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│             PHASE 3: Template URL Replacement                │
│  TemplateUrlReplacementController:                          │
│  • Scan Twig templates for hardcoded URLs                   │
│  • Create backups                                           │
│  • Replace with environment variables                       │
│  • Verify replacements                                      │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│            PHASE 4: Physical File Migration                  │
│  ImageMigrationController:                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 1. Enumerate assets (batch processing)              │   │
│  │ 2. Copy files AWS S3 → DO Spaces                    │   │
│  │ 3. Update asset records (volumeId, folderId)        │   │
│  │ 4. Create checkpoints (resume capability)           │   │
│  │ 5. Log all changes (rollback capability)            │   │
│  │ 6. Verify file integrity                            │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│              PHASE 5: Filesystem Switching                   │
│  FilesystemSwitchController:                                │
│  • Preview changes (dry-run)                                │
│  • Switch volumes from AWS FS → DO FS                       │
│  • Test connectivity                                        │
│  • Verify all volumes pointing to DO                        │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│            PHASE 6: Post-Migration Validation                │
│  MigrationDiagController:                                   │
│  • Analyze migration results                                │
│  • Check for missing files                                  │
│  • Verify asset integrity                                   │
│  • Generate diagnostic report                               │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│            PHASE 7: Image Transform Handling                 │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ TransformDiscoveryController:                        │  │
│  │  • Scan database for transforms                      │  │
│  │  • Scan templates for transforms                     │  │
│  │  • Generate transform inventory                      │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ TransformPreGenerationController:                    │  │
│  │  • Pre-generate transforms on DO                     │  │
│  │  • Warm up CDN cache                                 │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│              PHASE 8: Final Verification                     │
│  • Cache clearing (Craft cache/clear-caches)                │
│  • Index rebuilding (if needed)                             │
│  • End-to-end testing                                       │
│  • Performance validation                                   │
└─────────────────────────────────────────────────────────────┘
```

### Configuration Loading Flow

```
User Configuration Files          Module Configuration
┌──────────────────┐              ┌──────────────────┐
│  .env            │              │ config_examples/ │
│  ┌────────────┐  │              │  ┌────────────┐  │
│  │ DO_S3_*    │◄─┼──────────────┼──┤ .env.dev   │  │
│  │ Variables  │  │              │  │ .env.staging  │
│  └────────────┘  │              │  │ .env.prod  │  │
└──────────────────┘              │  └────────────┘  │
                                  │                  │
┌──────────────────┐              │  ┌────────────┐  │
│ config/          │              │  │ migration- │  │
│  migration-      │◄─────────────┼──┤ config.php │  │
│  config.php      │   (template) │  │ (template) │  │
└────────┬─────────┘              └──┴────────────┴──┘
         │
         │ require
         ▼
┌─────────────────────────────────────────────┐
│   MigrationConfig::getInstance()            │
├─────────────────────────────────────────────┤
│  1. Load @config/migration-config.php       │
│  2. Fallback to module/config if not found  │
│  3. Parse environment-specific settings     │
│  4. Provide type-safe getter methods        │
└─────────────────────────────────────────────┘
         │
         │ used by
         ▼
┌─────────────────────────────────────────────┐
│        All Console Controllers               │
│  • UrlReplacementController                 │
│  • ImageMigrationController                 │
│  • FilesystemSwitchController               │
│  • ... (all 13 controllers)                 │
└─────────────────────────────────────────────┘
```

---

## 🔢 Migration Phases

### Detailed Phase Breakdown

#### Phase 0: Configuration Setup
**Duration**: 15-30 minutes
**Actions**:
1. **Install DO Spaces plugin**: `composer require vaersaagod/dospaces`
2. **Install rclone**: Verify with `which rclone`
3. **Fresh AWS → DO sync**: `rclone copy aws-s3:bucket do:bucket -P`
4. Copy `migration-config.php` to `config/`
5. Update AWS settings (bucket, region)
6. Configure `.env` with DO credentials
7. Verify volume handles match Craft volumes
8. Create DO filesystems: `./craft s3-spaces-migration/filesystem/create`
9. **Configure transform filesystem for ALL volumes**: `./craft s3-spaces-migration/volume-config/set-transform-filesystem`
10. Run `./craft s3-spaces-migration/migration-check/check` (10 automated checks)

**Artifacts**: Configuration files, validation report
**Critical**: Ensure DO plugin, rclone, fresh sync, and transform filesystem configuration are complete before proceeding

#### Phase 1: Pre-Migration Validation
**Duration**: 5-10 minutes
**Actions**:
1. Verify configuration completeness
2. Test AWS S3 connectivity
3. Test DO Spaces connectivity
4. Check database schema
5. Validate PHP environment
6. **Verify DO Spaces plugin installation**
7. **Verify rclone availability and configuration**
8. **Verify transform filesystem configuration**
9. **Verify volume field layouts**
10. Audit plugin configurations

**Success Criteria**: All 10 automated checks pass, no blocking issues
**Command**: `./craft s3-spaces-migration/migration-check/check`

#### Phase 2: Database URL Replacement
**Duration**: 10-60 minutes (depends on DB size)
**Actions**:
1. Scan content tables for AWS URLs
2. Preview URL mappings
3. Perform replacements (with transaction safety)
4. Handle additional tables (projectconfig, revisions)
5. Process JSON fields
6. Generate replacement report
7. Verify no AWS URLs remain

**Artifacts**: CSV reports, database backups

#### Phase 3: Template URL Replacement
**Duration**: 5-15 minutes
**Actions**:
1. Scan Twig templates for hardcoded AWS URLs
2. Create timestamped backups
3. Replace URLs with environment variables
4. Verify replacements
5. Test template rendering

**Artifacts**: Template backups, replacement report

#### Phase 4: Physical File Migration
**Duration**: 1-48 hours (depends on file count/size)
**Actions**:
1. Enumerate assets in batches
2. For each asset:
   - Copy file AWS S3 → DO Spaces
   - Update asset record (volumeId, folderId)
   - Log change for rollback
   - Verify file integrity
3. Create checkpoints every N batches
4. Handle errors with retry logic
5. Report progress continuously

**Artifacts**:
- Checkpoints (resumable state)
- Change logs (rollback data)
- Migration report

**Special Features**:
- Resume capability (survives interruptions)
- Rollback capability (can undo everything)
- Memory efficient (batch processing)
- Progress tracking (ETA calculation)

#### Phase 5: Filesystem Switching
**Duration**: 2-5 minutes
**Actions**:
1. Preview filesystem switch (dry-run)
2. Switch volume filesystem handles (AWS → DO)
3. Test connectivity to DO Spaces
4. Verify all volumes pointing to DO
5. Clear Craft caches

**Artifacts**: Switch report

#### Phase 6: Post-Migration Validation
**Duration**: 10-30 minutes
**Actions**:
1. Analyze migration results
2. Check for missing files
3. Verify asset integrity
4. Validate URLs resolve correctly
5. Test asset uploads
6. Performance checks

**Artifacts**: Diagnostic report, asset inventory

#### Phase 7: Image Transform Handling
**Duration**: 30 minutes - 6 hours (depends on transform count)
**Actions**:
1. **CRITICAL: Add optimisedImagesField to Images (DO) volume**: `./craft s3-spaces-migration/volume-config/add-optimised-field images`
   - This MUST be done AFTER migration but BEFORE generating transforms
   - Ensures transforms are correctly generated
2. Discover all image transformations used
3. Scan database for transform references
4. Scan templates for transform definitions
5. Pre-generate transforms on DO Spaces
6. Warm up CDN cache
7. Verify transform URLs

**Artifacts**: Transform inventory, generation report
**Critical**: Step 1 is essential for proper transform generation

#### Phase 8: Final Verification
**Duration**: 15-30 minutes
**Actions**:
1. Clear all Craft caches
2. Rebuild search indexes (if needed)
3. End-to-end testing
4. Performance validation
5. Monitor error logs

**Success Criteria**: All assets accessible, no errors, performance acceptable

---

## 🔗 Component Interactions

### URL Replacement Workflow

```
┌────────────────────────────────────────────────────────────┐
│             UrlReplacementController                       │
└────────────┬───────────────────────────────────────────────┘
             │
             │ 1. Get configuration
             ▼
┌────────────────────────────────────────────────────────────┐
│         MigrationConfig::getInstance()                     │
│  • getAwsUrls()                                            │
│  • getDoBaseUrl()                                          │
│  • getUrlMappings()                                        │
│  • getContentTablePatterns()                               │
└────────────┬───────────────────────────────────────────────┘
             │
             │ 2. Returns config
             ▼
┌────────────────────────────────────────────────────────────┐
│        Discover Content Columns                            │
│  • Query information_schema                                │
│  • Find text columns in content tables                     │
│  • Filter by column types (text, mediumtext, longtext)     │
└────────────┬───────────────────────────────────────────────┘
             │
             │ 3. Column list
             ▼
┌────────────────────────────────────────────────────────────┐
│        Scan for AWS URLs                                   │
│  • For each column:                                        │
│    - Build WHERE clause with LIKE conditions               │
│    - Count rows containing AWS URLs                        │
│    - Track matches                                         │
└────────────┬───────────────────────────────────────────────┘
             │
             │ 4. Matches found
             ▼
┌────────────────────────────────────────────────────────────┐
│        Display Summary & Samples                           │
│  • Show affected tables/columns                            │
│  • Extract sample URLs                                     │
│  • Request user confirmation (if not dry-run)              │
└────────────┬───────────────────────────────────────────────┘
             │
             │ 5. User confirms
             ▼
┌────────────────────────────────────────────────────────────┐
│        Perform Replacements                                │
│  • For each match:                                         │
│    - For each URL mapping:                                 │
│      UPDATE table SET column = REPLACE(column, old, new)   │
│    - Track affected rows                                   │
│    - Handle errors gracefully                              │
└────────────┬───────────────────────────────────────────────┘
             │
             │ 6. Results
             ▼
┌────────────────────────────────────────────────────────────┐
│        Display Results & Generate Report                   │
│  • Show total rows updated                                 │
│  • Display errors (if any)                                 │
│  • Generate CSV report                                     │
│  • Save to @storage/logs/                                  │
└────────────────────────────────────────────────────────────┘
```

### Asset Migration Workflow (Complex)

```
┌─────────────────────────────────────────────────────────────┐
│          ImageMigrationController::actionMigrate()          │
└─────────────┬───────────────────────────────────────────────┘
              │
              │ 1. Initialize
              ▼
┌─────────────────────────────────────────────────────────────┐
│                  Initialization Phase                        │
│  • Load MigrationConfig                                     │
│  • Check for existing checkpoint                            │
│  • Initialize managers (checkpoint, changelog, recovery)    │
│  • Acquire migration lock                                   │
│  • Load or create state                                     │
└─────────────┬───────────────────────────────────────────────┘
              │
              ├─── Checkpoint exists? ──► Load saved state
              │                           Resume from last position
              │
              └─── No checkpoint? ──────► Start fresh migration
              │
              │ 2. Enumerate assets
              ▼
┌─────────────────────────────────────────────────────────────┐
│                  Asset Enumeration                           │
│  Query: SELECT id, volumeId, folderId, filename, ...        │
│  FROM assets                                                │
│  WHERE volumeId IN (source volumes)                         │
│  ORDER BY id                                                │
│                                                             │
│  Result: Total asset count, batch plan                      │
└─────────────┬───────────────────────────────────────────────┘
              │
              │ 3. Process in batches
              ▼
       ┌──────────────────┐
       │  Batch Loop       │
       │  (N = batchSize)  │
       └──────┬────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────────────┐
│              Process Single Asset                            │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ 1. Skip if already processed (resume logic)           │  │
│  └───────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ 2. Determine source & destination paths               │  │
│  │    • Source: AWS S3 path                              │  │
│  │    • Dest: DO Spaces path                             │  │
│  │    • Handle volume structure (root/subfolders/flat)   │  │
│  └───────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ 3. Copy file (with retry logic)                       │  │
│  │    • Flysystem: copy(source, dest)                    │  │
│  │    • Retry on network errors (max 3 attempts)         │  │
│  │    • Verify file size matches                         │  │
│  └───────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ 4. Update asset record                                │  │
│  │    • Change volumeId (AWS → DO)                       │  │
│  │    • Change folderId (map to DO folder structure)     │  │
│  │    • Transaction-safe update                          │  │
│  └───────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ 5. Log change (for rollback)                          │  │
│  │    • Record old values                                │  │
│  │    • Record file paths                                │  │
│  │    • Append to changelog                              │  │
│  └───────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ 6. Track progress                                     │  │
│  │    • Update counters                                  │  │
│  │    • Calculate ETA                                    │  │
│  │    • Display progress bar                             │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────┬───────────────────────────────────────────────┘
              │
              │ After every batch
              ▼
┌─────────────────────────────────────────────────────────────┐
│              Checkpoint Creation                             │
│  • Save current state to disk                               │
│  • Store processed asset IDs                                │
│  • Store configuration snapshot                             │
│  • Flush changelog buffer                                   │
└─────────────┬───────────────────────────────────────────────┘
              │
              ├─── More batches? ────► Continue loop
              │
              └─── All done? ────────► Finalize
              │
              ▼
┌─────────────────────────────────────────────────────────────┐
│                    Finalization                              │
│  • Close changelog                                          │
│  • Remove checkpoint (success)                              │
│  • Generate migration report                                │
│  • Release migration lock                                   │
│  • Display summary statistics                               │
└─────────────────────────────────────────────────────────────┘
```

---

## ⚙️ Configuration System

### Configuration Hierarchy

```
Priority (highest to lowest):
1. Runtime overrides (controller options)
2. config/migration-config.php (user customized)
3. modules/config/migration-config.php (Composer-installed template)
4. .env variables (credentials only)
5. MigrationConfig defaults
```

### Configuration Sections

#### 1. AWS Source Configuration
```php
'aws' => [
    'bucket' => 'your-aws-bucket',
    'region' => 'us-east-1',
    'urls' => [ /* auto-generated URL patterns */ ]
]
```

#### 2. DigitalOcean Target Configuration
```php
'digitalocean' => [
    'region' => 'tor1',
    'bucket' => getenv('DO_S3_BUCKET'),
    'baseUrl' => getenv('DO_S3_BASE_URL'),
    'accessKey' => getenv('DO_S3_ACCESS_KEY'),
    'secretKey' => getenv('DO_S3_SECRET_KEY')
]
```

#### 3. Filesystem Mappings
```php
'filesystemMappings' => [
    'images' => 'images_do',            // AWS handle → DO handle
    'optimisedImages' => 'optimisedImages_do',
    'documents' => 'documents_do'
]
```

#### 4. Volume Configuration
```php
'volumes' => [
    'source' => ['images', 'optimisedImages'],  // Volumes to migrate FROM
    'target' => 'images',                       // Consolidation target
    'quarantine' => 'quarantine',               // For orphaned assets

    // Structure hints (affect migration path logic)
    'atBucketRoot' => ['optimisedImages'],      // Not in DO subfolder
    'withSubfolders' => ['images'],             // Contains subfolders
    'flatStructure' => ['chartData']            // No subfolder structure
]
```

#### 5. Migration Performance Settings
```php
'migration' => [
    'batchSize' => 100,                    // Assets per batch
    'checkpointEveryBatches' => 1,         // Checkpoint frequency
    'changelogFlushEvery' => 5,            // Changelog flush frequency
    'maxRetries' => 3,                     // Retry attempts
    'checkpointRetentionHours' => 72,      // Keep checkpoints 3 days
    'maxRepeatedErrors' => 10              // Stop if too many errors
]
```

#### 6. Template & Database Scanning
```php
'templates' => [
    'extensions' => ['twig'],
    'backupSuffix' => '.backup-{timestamp}',
    'envVarName' => 'DO_S3_BASE_URL'
],
'database' => [
    'contentTables' => ['content', 'matrixcontent_%'],
    'additionalTables' => [
        ['table' => 'projectconfig', 'column' => 'value'],
        ['table' => 'revisions', 'column' => 'data']
    ],
    'columnTypes' => ['text', 'mediumtext', 'longtext']
]
```

### Environment-Specific Configuration

The system supports multiple environments through `MIGRATION_ENV` variable:

```bash
# .env.dev
MIGRATION_ENV=dev
DO_S3_BUCKET=my-bucket-dev
DO_S3_BASE_URL=https://my-bucket-dev.tor1.digitaloceanspaces.com

# .env.staging
MIGRATION_ENV=staging
DO_S3_BUCKET=my-bucket-staging
DO_S3_BASE_URL=https://my-bucket-staging.tor1.digitaloceanspaces.com

# .env.prod
MIGRATION_ENV=prod
DO_S3_BUCKET=my-bucket-prod
DO_S3_BASE_URL=https://my-bucket-prod.tor1.digitaloceanspaces.com
```

---

## 🛡️ Error Handling & Recovery

### Error Handling Strategy

#### 1. Validation (Pre-execution)
```php
// Configuration validation before any action
$errors = $this->config->validate();
if (!empty($errors)) {
    $this->stderr("Configuration errors:\n", Console::FG_RED);
    foreach ($errors as $error) {
        $this->stderr("  • $error\n", Console::FG_RED);
    }
    return ExitCode::CONFIG;
}
```

#### 2. Try-Catch with Specific Handling
```php
try {
    $result = $filesystem->copy($source, $dest);
} catch (FileNotFoundException $e) {
    // Specific error: file doesn't exist
    $this->logError("Source file not found: $source");
    return false;
} catch (FileExistsException $e) {
    // Specific error: destination exists
    // Decision: overwrite or skip?
    return $this->handleExistingFile($dest);
} catch (\Exception $e) {
    // General error: network, permissions, etc.
    $this->logError("Copy failed: " . $e->getMessage());
    return false;
}
```

#### 3. Retry Logic with Exponential Backoff
```php
private function copyFileWithRetry($source, $dest, $maxRetries = 3)
{
    $attempt = 0;
    $delay = 1000; // milliseconds

    while ($attempt < $maxRetries) {
        try {
            $filesystem->copy($source, $dest);
            return true;
        } catch (NetworkException $e) {
            $attempt++;
            if ($attempt >= $maxRetries) {
                throw $e;
            }

            // Exponential backoff: 1s, 2s, 4s
            usleep($delay * 1000);
            $delay *= 2;
        }
    }
}
```

#### 4. Transaction Safety
```php
$transaction = Craft::$app->getDb()->beginTransaction();
try {
    // Multiple related operations
    $this->updateAsset($asset);
    $this->updateReferences($asset);

    $transaction->commit();
} catch (\Exception $e) {
    $transaction->rollBack();
    throw $e;
}
```

#### 5. Checkpoint Recovery
```php
// Check for existing checkpoint
if ($checkpoint = $this->checkpointManager->loadLatest()) {
    $this->stdout("Found checkpoint from " . date('Y-m-d H:i:s', $checkpoint['timestamp']));
    $this->stdout("Resume migration? [y/n]: ");
    $input = fgets(STDIN);

    if (trim(strtolower($input)) === 'y') {
        $this->restoreFromCheckpoint($checkpoint);
    }
}
```

### Recovery Capabilities

#### 1. Resume from Checkpoint
- **Trigger**: Ctrl+C, server crash, network failure
- **Recovery**: Run same command again, system detects checkpoint
- **Data Preserved**: Processed asset IDs, progress counters, errors
- **Skip Logic**: Already-processed items skipped automatically

#### 2. Rollback from Changelog
- **Trigger**: Manual rollback command or critical error
- **Recovery**: `./craft s3-spaces-migration/image-migration/rollback`
- **Process**: Read changelog in reverse, undo all operations
- **Scope**: Can rollback entire migration or specific ranges

#### 3. Error Threshold Protection
```php
// Stop migration if too many repeated errors
if ($this->repeatedErrorCount > $this->config->getMaxRepeatedErrors()) {
    $this->stderr("Too many repeated errors. Stopping migration.");
    $this->checkpoint(); // Save state before stopping
    return ExitCode::UNSPECIFIED_ERROR;
}
```

---

## 🔌 Extension Points

### Adding New Controllers

1. **Create controller class** in `modules/console/controllers/`:
```php
<?php
namespace modules\console\controllers;

use craft\console\Controller;
use modules\helpers\MigrationConfig;

class MyCustomController extends Controller
{
    private $config;

    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();
    }

    public function actionMyAction()
    {
        // Your logic here
    }
}
```

2. **Access via CLI**: `./craft s3-spaces-migration/my-custom/my-action`

### Adding Configuration Options

1. **Add to migration-config.php**:
```php
return [
    // ...existing config...
    'myCustomSection' => [
        'option1' => 'value1',
        'option2' => 'value2'
    ]
];
```

2. **Add getter to MigrationConfig.php**:
```php
/**
 * Get custom option 1
 */
public function getCustomOption1(): string
{
    return $this->get('myCustomSection.option1', 'default');
}
```

3. **Use in controllers**:
```php
$value = $this->config->getCustomOption1();
```

### Adding New Migration Phases

1. Create new controller for the phase
2. Add phase documentation to README
3. Update `ARCHITECTURE.md` (this file) with phase details
4. Consider checkpoint/rollback requirements

### Custom Validation Rules

Add to `MigrationConfig::validate()`:
```php
public function validate(): array
{
    $errors = [];

    // ...existing validations...

    // Custom validation
    if (!$this->customCondition()) {
        $errors[] = "Custom validation failed";
    }

    return $errors;
}
```

---

## 📚 Additional Resources

- **User Guide**: `README_FR.md` (French migration guide)
- **Configuration Reference**: `CONFIG_QUICK_REFERENCE.md`
- **Quick Reference Card**: `Fiche_Reference_FR.md`
- **Example Configurations**: `modules/console/controllers/config_examples/`

---

## 🎓 Best Practices

### For Developers Extending This Module

1. **Always use MigrationConfig**: Never hardcode configuration values
2. **Follow batch processing**: For large datasets, always batch process
3. **Add checkpoints**: For long-running operations, implement checkpoints
4. **Log changes**: For reversible operations, log to changelog
5. **Validate early**: Check configuration before starting operations
6. **Transaction safety**: Use database transactions for related updates
7. **User confirmation**: For destructive operations, require confirmation
8. **Progress reporting**: Keep users informed with progress updates
9. **Error context**: Provide helpful error messages with context
10. **Dry-run mode**: Always offer a dry-run option for testing

### For Users Running Migrations

1. **Test in dev first**: Never run directly in production
2. **Backup database**: Create full database backup before migration
3. **Start small**: Test with a subset of data first
4. **Monitor closely**: Watch for errors during migration
5. **Verify thoroughly**: Check results at each phase
6. **Keep checkpoints**: Don't delete checkpoints until verified
7. **Document issues**: Note any problems for troubleshooting
8. **Plan maintenance window**: Schedule appropriate downtime
9. **Test rollback**: Verify rollback works before relying on it
10. **Keep change logs**: Preserve for audit trail

---

**Last Updated**: 2025-11-05
**Version**: 4.0.0
**Maintainer**: Christian Sabourin
