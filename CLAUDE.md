# CLAUDE.md - AI Assistant Guide

> **For AI Assistants**: This document provides comprehensive guidance for understanding and working with the Spaghetti Migrator codebase. Read this first before making any changes.

---

## 📋 Table of Contents

1. [Project Overview](#-project-overview)
2. [Critical Rules & Constraints](#-critical-rules--constraints)
3. [Codebase Structure](#-codebase-structure)
4. [Key Architectural Patterns](#-key-architectural-patterns)
5. [Configuration System](#-configuration-system)
6. [Development Workflows](#-development-workflows)
7. [Testing Guidelines](#-testing-guidelines)
8. [Common Tasks](#-common-tasks)
9. [File Reference Guide](#-file-reference-guide)
10. [Dos and Don'ts](#-dos-and-donts)

---

## 🎯 Project Overview

### What is Spaghetti Migrator?

**Spaghetti Migrator** (formerly Craft S3 Spaces Migration) is a production-grade Craft CMS 4/5 plugin that:

- **Migrates assets** between cloud storage providers (AWS S3, Google Cloud, Azure, DigitalOcean Spaces, Backblaze B2, Wasabi, Cloudflare R2, local filesystem)
- **Untangles nested folder structures** ("spaghetti") into organized layouts
- **Provides enterprise-grade reliability** with checkpoints, rollback, and error recovery
- **Handles 100,000+ assets** with memory-efficient batch processing
- **Supports 64 migration combinations** (any provider to any provider)

### Key Statistics

- **Package Name**: `csabourin/spaghetti-migrator`
- **Namespace**: `csabourin\spaghettiMigrator`
- **Plugin Handle**: `s3-spaces-migration` (legacy, but still used in routes)
- **PHP**: 8.0+ required
- **Craft CMS**: 4.0+ or 5.0+
- **Controllers**: 22 total (19 console + 3 web)
- **Services**: 28+ specialized services
- **Storage Adapters**: 8 providers
- **Lines of Code**: ~15,000+

### Version History

- **v1.0**: Original S3 → DigitalOcean Spaces migration
- **v2.0**: Multi-provider architecture (8 providers)
- **v5.0**: MigrationOrchestrator refactor

---

## 🚨 Critical Rules & Constraints

### Must Follow (from AGENTS.md)

1. **Never break existing functionality**
   - Preserve Craft CMS integration
   - Maintain module loading
   - Keep S3→Spaces migration workflow intact

2. **Forbidden Actions**
   - ❌ DO NOT modify `Bootstrap.php` (breaks auto-registration)
   - ❌ DO NOT change Craft CMS volume configuration logic
   - ❌ DO NOT edit `PRODUCTION_RUNBOOK.md` without approval
   - ❌ DO NOT remove validation, warnings, or dashboard workflow constraints
   - ❌ DO NOT change file/folder names used in PSR-4 autoloading
   - ❌ DO NOT introduce new dependencies
   - ❌ DO NOT modify database schemas without explicit request

3. **Repository Invariants (Always True)**
   - All PHP follows PSR-12 coding standards
   - All classes conform to PSR-4 autoloading
   - Namespace is always `csabourin\spaghettiMigrator`
   - Dashboard phases enforce correct ordering (Phase 1 → Phase 8)
   - Checkpoints and rollback logic never removed
   - Migration logs remain human-readable
   - Dry-run mode is always side-effect-free

4. **Commit Rules**
   - Use Conventional Commits (`feat:`, `fix:`, `docs:`, `refactor:`)
   - Atomic commits (one logical change per commit)
   - Include reason + statement of preserved behavior

### Allowed Actions

✅ Fix bugs without changing public APIs
✅ Improve documentation (README, ARCHITECTURE, RUNBOOK)
✅ Add tests or improve test coverage
✅ Refactor code (only when behavior preserved and tested)
✅ Update inline comments for correctness

### Behavior Under Uncertainty

If unsure:
1. **Default to no-op** (do nothing)
2. **Add a comment** requesting clarification
3. **Propose alternatives** rather than committing high-risk changes

---

## 📂 Codebase Structure

### High-Level Organization

```
do-migration/
├── modules/                    # Main plugin code (PSR-4: csabourin\spaghettiMigrator)
│   ├── Plugin.php             # Plugin entry point
│   ├── Bootstrap.php          # Auto-loader (DO NOT MODIFY)
│   ├── MigrationModule.php    # Module definition
│   │
│   ├── adapters/              # Storage provider adapters (8 providers)
│   ├── interfaces/            # Contracts & abstractions
│   ├── strategies/            # URL replacement strategies
│   ├── console/controllers/   # 19 console controllers
│   ├── controllers/           # 3 web controllers
│   ├── helpers/               # MigrationConfig & utilities
│   ├── services/              # 28+ core services
│   ├── models/                # 7 data models
│   ├── jobs/                  # Background queue jobs
│   └── templates/             # Twig templates
│
├── config/                    # Configuration templates
│   ├── migration-config.php   # Main config (v1.0)
│   └── migration-config-v2.php # v2.0 multi-provider
│
├── tests/                     # Test suite
│   ├── Unit/                  # Unit tests
│   └── Integration/           # Integration tests
│
└── docs/                      # Documentation
```

### Console Controllers (19 Total)

Located in `modules/console/controllers/`:

#### Primary Controllers
- **ImageMigrationController.php** (680 lines) - Main migration orchestrator
- **MigrationCheckController.php** (849 lines) - Pre-flight validation

#### Filesystem Management
- **FilesystemController.php** - Create filesystems
- **FilesystemSwitchController.php** - Switch volumes between filesystems
- **FilesystemFixController.php** - Fix filesystem issues
- **FsDiagController.php** - Filesystem diagnostics

#### Volume Management
- **VolumeConfigController.php** - Configure volumes
- **VolumeConsolidationController.php** (872 lines) - Consolidate volumes

#### URL Replacement
- **UrlReplacementController.php** - Database URL replacement
- **ExtendedUrlReplacementController.php** - Extended URL strategies
- **TemplateUrlReplacementController.php** - Twig template updates

#### Transform Management
- **TransformDiscoveryController.php** - Discover image transforms
- **TransformPreGenerationController.php** - Pre-generate transforms
- **TransformCleanupController.php** - Clean up old transforms

#### Diagnostics & Testing
- **MigrationDiagController.php** - Post-migration diagnostics
- **ProviderTestController.php** - Test storage providers (v2.0)
- **StaticAssetScanController.php** - Scan static assets
- **PluginConfigAuditController.php** - Audit plugin configs
- **DashboardMaintenanceController.php** - Dashboard utilities

### Web Controllers (3 Total)

Located in `modules/controllers/`:

- **MigrationController.php** (24KB) - Dashboard & 14 API endpoints
- **SettingsController.php** - Settings import/export
- **DefaultController.php** - Default route handler

### Core Services (28+ Total)

Located in `modules/services/`:

#### State Management
- **CheckpointManager.php** - Checkpoint/resume system
- **MigrationStateService.php** - State persistence
- **MigrationProgressService.php** - Progress tracking

#### Error Handling & Recovery
- **ErrorRecoveryManager.php** - Error handling & retries
- **RollbackEngine.php** - Rollback operations
- **ChangeLogManager.php** - Change tracking

#### Migration Orchestration
- **MigrationOrchestrator.php** - Main orchestrator (v5.0)
- **MigrationLock.php** - Lock management
- **MigrationAccessValidator.php** - Access control

#### Specialized Services (`services/migration/`)
- **BackupService.php** - Database backups
- **ConsolidationService.php** - File consolidation
- **DuplicateResolutionService.php** - Duplicate handling
- **FileOperationsService.php** - File operations
- **InlineLinkingService.php** - Inline image detection
- **InventoryBuilder.php** - Asset inventory
- **ValidationService.php** - Pre-flight validation
- **VerificationService.php** - Post-migration verification
- ...and 11 more

#### Provider Management
- **ProviderRegistry.php** - Storage provider registry (v2.0)
- **CommandExecutionService.php** - CLI execution
- **ProcessManager.php** - Process management

### Storage Provider Adapters (8 Total)

Located in `modules/adapters/`:

All implement `StorageProviderInterface`:

- **S3StorageAdapter.php** - AWS S3
- **DOSpacesStorageAdapter.php** - DigitalOcean Spaces
- **GCSStorageAdapter.php** - Google Cloud Storage
- **AzureBlobStorageAdapter.php** - Azure Blob Storage
- **BackblazeB2StorageAdapter.php** - Backblaze B2
- **WasabiStorageAdapter.php** - Wasabi
- **CloudflareR2StorageAdapter.php** - Cloudflare R2
- **LocalFilesystemAdapter.php** - Local filesystem

### URL Replacement Strategies (3 Total)

Located in `modules/strategies/`:

All implement `UrlReplacementStrategyInterface`:

- **SimpleUrlReplacementStrategy.php** - Direct string replacement
- **RegexUrlReplacementStrategy.php** - Pattern-based replacement
- **MultiMappingUrlReplacementStrategy.php** - Multi-domain consolidation

---

## 🏗️ Key Architectural Patterns

### 1. Singleton Configuration Pattern

**ALWAYS use `MigrationConfig::getInstance()` - NEVER read config file directly**

```php
// ✅ CORRECT
$config = MigrationConfig::getInstance();
$bucket = $config->getAwsBucket();
$batchSize = $config->getBatchSize();

// ❌ WRONG - DO NOT DO THIS
$config = require Craft::getAlias('@config/migration-config.php');
$bucket = $config['aws']['bucket'];
```

**Why**: `MigrationConfig` provides:
- Type-safe getter methods
- Validation
- Default values
- Environment variable parsing
- Database settings integration

**Location**: `modules/helpers/MigrationConfig.php` (1,410 lines)

### 2. Controller Structure Pattern

**Standard controller initialization:**

```php
class ExampleController extends Controller
{
    public $defaultAction = 'action-name';
    private $config;  // MigrationConfig instance

    public function init(): void {
        parent::init();
        $this->config = MigrationConfig::getInstance();
    }

    public function actionDoSomething() {
        // 1. Validate configuration
        $errors = $this->config->validate();
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->stderr("✗ $error\n", Console::FG_RED);
            }
            return ExitCode::CONFIG;
        }

        // 2. Get config values via helper
        $batchSize = $this->config->getBatchSize();
        $awsBucket = $this->config->getAwsBucket();

        // 3. Perform operation
        // ... implementation ...

        // 4. Output with Console helpers
        $this->stdout("✓ Success\n", Console::FG_GREEN);

        // 5. Return exit code
        return ExitCode::OK;
    }
}
```

### 3. Error Handling Pattern

**Three-tier error handling:**

```php
// 1. Configuration Errors - Exit before starting
$errors = $this->config->validate();
if (!empty($errors)) {
    // Display and exit
    return ExitCode::CONFIG;
}

// 2. Recoverable Errors - Retry with exponential backoff
$errorManager = new ErrorRecoveryManager($maxRetries, $retryDelay);
$result = $errorManager->executeWithRetry(function() {
    // Potentially failing operation
    return $this->performOperation();
}, $context);

// 3. Critical Errors - Stop and rollback
if ($criticalError) {
    $this->rollbackEngine->rollback();
    return ExitCode::UNSPECIFIED_ERROR;
}
```

### 4. Checkpoint/Resume Pattern

**Checkpoint every N batches:**

```php
$checkpointManager = new CheckpointManager($migrationId);
$checkpointFreq = $this->config->getCheckpointEveryBatches();

// Resume from checkpoint if exists
$checkpoint = $checkpointManager->loadLatestCheckpoint();
$processedIds = $checkpoint['processed_ids'] ?? [];

// Process in batches
foreach ($batches as $batchNum => $batch) {
    foreach ($batch as $asset) {
        // Skip if already processed
        if (in_array($asset->id, $processedIds)) {
            continue;
        }

        // Process asset
        $this->processAsset($asset);
        $processedIds[] = $asset->id;
    }

    // Save checkpoint every N batches
    if ($batchNum % $checkpointFreq === 0) {
        $checkpointManager->saveCheckpoint([
            'migration_id' => $migrationId,
            'phase' => 'consolidation',
            'processed_ids' => $processedIds,
            'batch' => $batchNum,
            'total_count' => $totalCount,
            'stats' => $stats
        ]);
    }
}
```

### 5. Batch Processing Pattern

**Memory-efficient batch processing:**

```php
$batchSize = $this->config->getBatchSize();

// Get assets
$assets = Asset::find()
    ->volumeId($volumeId)
    ->all();

// Process in chunks
$batches = array_chunk($assets, $batchSize);

foreach ($batches as $batchNum => $batch) {
    foreach ($batch as $asset) {
        // Process individual asset
        $this->processAsset($asset);
    }

    // Free memory
    gc_collect_cycles();
}
```

### 6. Change Logging Pattern (for Rollback)

**Log every operation for rollback:**

```php
$changeLogManager = new ChangeLogManager($migrationId, $flushFrequency);

// Log each operation
$changeLogManager->logOperation([
    'type' => 'move_file',
    'phase' => 'consolidation',
    'asset_id' => $asset->id,
    'old_path' => $oldPath,
    'new_path' => $newPath,
    'old_volume_id' => $oldVolumeId,
    'new_volume_id' => $newVolumeId,
    'timestamp' => time()
]);

// Later: Rollback uses this log in reverse
$rollbackEngine = new RollbackEngine($changeLogManager, $migrationId);
$rollbackEngine->rollback();
```

### 7. Provider Pattern (v2.0)

**Using storage providers:**

```php
// Get provider instance from registry
$registry = new ProviderRegistry();
$provider = $registry->createProvider('s3', [
    'bucket' => 'my-bucket',
    'region' => 'us-east-1',
    'accessKey' => getenv('AWS_ACCESS_KEY_ID'),
    'secretKey' => getenv('AWS_SECRET_ACCESS_KEY'),
]);

// Test connection
$result = $provider->testConnection();
if (!$result->isSuccessful()) {
    throw new \Exception($result->getErrorMessage());
}

// List objects
$iterator = $provider->listObjects('/path/', [
    'recursive' => true,
    'maxKeys' => 1000
]);

foreach ($iterator as $object) {
    $metadata = $object->getMetadata();
    echo $object->getKey() . " (" . $metadata->getSize() . " bytes)\n";
}
```

---

## ⚙️ Configuration System

### Three-Layer Configuration

```
┌─────────────────────────────────────────┐
│ 1. Environment Variables (.env)         │
│    Secrets & credentials                │
│    DO_S3_BUCKET, DO_S3_ACCESS_KEY, etc. │
└─────────────┬───────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│ 2. Configuration File                   │
│    config/migration-config.php          │
│    Migration settings, mappings, etc.   │
└─────────────┬───────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│ 3. MigrationConfig Helper (singleton)   │
│    Type-safe access, validation         │
└─────────────────────────────────────────┘
```

### Configuration Priority

1. **Plugin Settings (database)** - If configured via Control Panel
2. **Config File** - `config/migration-config.php`
3. **Default Values** - Hardcoded in `MigrationConfig.php`

### Key Configuration Sections

1. **AWS Source Configuration** - Bucket, region, credentials
2. **DigitalOcean Target Configuration** - Spaces settings
3. **Filesystem Mappings** - Map source FS to target FS
4. **Volume Behavior** - Subfolder handling, consolidation
5. **Filesystem Definitions** - Create new filesystems
6. **Migration Performance** - Batch size, checkpoints, retries
7. **Field Configuration** - Optimized images field
8. **Transform Settings** - Transform filesystem, cleanup
9. **Template & Database Scanning** - Path patterns
10. **URL Replacement** - Strategy configuration
11. **Diagnostics** - Validation settings
12. **Dashboard** - UI preferences

### Important MigrationConfig Methods

**AWS Configuration:**
```php
$config->getAwsBucket()
$config->getAwsRegion()
$config->getAwsUrls()  // Returns array of S3 URLs
```

**DigitalOcean Configuration:**
```php
$config->getDoBucket()
$config->getDoRegion()
$config->getDoBaseUrl()
$config->getDoBaseEndpoint()
```

**v2.0 Multi-Provider:**
```php
$config->getSourceProvider()  // Returns ['type' => 's3', 'config' => [...]]
$config->getTargetProvider()  // Returns provider config
$config->getMigrationMode()   // 'provider-to-provider' or 'filesystem-only'
```

**Filesystem & Volume:**
```php
$config->getFilesystemMappings()      // ['aws_fs' => 'do_fs']
$config->getSourceVolumeHandles()     // ['volume1', 'volume2']
$config->getTargetVolumeHandle()      // 'target_volume'
$config->volumeHasSubfolders($handle) // true/false
```

**Performance:**
```php
$config->getBatchSize()              // Default: 100
$config->getMaxRetries()             // Default: 3
$config->getCheckpointEveryBatches() // Default: 10
```

**Validation:**
```php
$errors = $config->validate();       // Returns array of error messages
if (!empty($errors)) {
    // Handle errors
}
```

---

## 🔄 Development Workflows

### Adding a New Console Controller

1. **Create controller file** in `modules/console/controllers/`:
   ```php
   namespace csabourin\spaghettiMigrator\console\controllers;

   use Craft;
   use craft\console\Controller;
   use yii\console\ExitCode;
   use csabourin\spaghettiMigrator\helpers\MigrationConfig;

   class MyNewController extends Controller
   {
       public $defaultAction = 'index';
       private $config;

       public function init(): void {
           parent::init();
           $this->config = MigrationConfig::getInstance();
       }

       public function actionIndex() {
           // Implementation
           return ExitCode::OK;
       }
   }
   ```

2. **Test via CLI**:
   ```bash
   ./craft s3-spaces-migration/my-new/index
   ```

3. **Add to dashboard** (if needed) in `modules/controllers/MigrationController.php`

### Adding a New Storage Provider

1. **Create adapter** in `modules/adapters/`:
   ```php
   namespace csabourin\spaghettiMigrator\adapters;

   use csabourin\spaghettiMigrator\interfaces\StorageProviderInterface;

   class MyProviderAdapter implements StorageProviderInterface
   {
       // Implement all interface methods
   }
   ```

2. **Register in ProviderRegistry** (`modules/services/ProviderRegistry.php`):
   ```php
   $this->registerProvider('my-provider', MyProviderAdapter::class);
   ```

3. **Add configuration support** in `MigrationConfig.php`

4. **Update documentation** in `docs/PROVIDER_EXAMPLES.md`

### Adding a New Configuration Option

1. **Add to config file** (`config/migration-config.php`):
   ```php
   'myNewOption' => [
       'enabled' => true,
       'value' => 'default',
   ],
   ```

2. **Add getter to MigrationConfig** (`modules/helpers/MigrationConfig.php`):
   ```php
   public function getMyNewOption(): string
   {
       return $this->get('myNewOption.value', 'default');
   }
   ```

3. **Add validation** (if needed):
   ```php
   private function validateMyNewOption(): ?string
   {
       $value = $this->getMyNewOption();
       if (empty($value)) {
           return 'myNewOption.value cannot be empty';
       }
       return null;
   }
   ```

4. **Update documentation** in config comments and README

### Adding a New Service

1. **Create service file** in `modules/services/` or `modules/services/migration/`:
   ```php
   namespace csabourin\spaghettiMigrator\services;

   class MyNewService
   {
       private $dependency;

       public function __construct(DependencyService $dependency)
       {
           $this->dependency = $dependency;
       }

       public function doSomething(): bool
       {
           // Implementation
           return true;
       }
   }
   ```

2. **Register in Plugin** (`modules/Plugin.php`) if needed as a component

3. **Inject into controllers** via constructor or create instances as needed

---

## 🧪 Testing Guidelines

### Test Structure

```
tests/
├── bootstrap.php           # PHPUnit bootstrap
├── Support/
│   └── CraftStubs.php     # Mock Craft CMS
├── Unit/                   # Fast, isolated tests
│   ├── helpers/
│   ├── services/
│   └── controllers/
└── Integration/            # Slower, E2E tests
    └── MigrationFlowTest.php
```

### Writing Unit Tests

**Example:**
```php
namespace Tests\Unit\services;

use PHPUnit\Framework\TestCase;
use csabourin\spaghettiMigrator\services\CheckpointManager;

class CheckpointManagerTest extends TestCase
{
    private $tempDir;
    private $manager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_' . uniqid();
        mkdir($this->tempDir);
        $this->manager = new CheckpointManager('test_migration');
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        array_map('unlink', glob("$this->tempDir/*"));
        rmdir($this->tempDir);
    }

    public function testSaveAndLoadCheckpoint()
    {
        $data = ['processed_ids' => [1, 2, 3]];
        $this->manager->saveCheckpoint($data);

        $loaded = $this->manager->loadLatestCheckpoint();
        $this->assertEquals($data, $loaded);
    }
}
```

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test
./vendor/bin/phpunit tests/Unit/services/CheckpointManagerTest.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/
```

### Testing Console Commands

**Manual testing pattern:**
```bash
# 1. Test with dry run
./craft s3-spaces-migration/my-controller/action --dryRun=1

# 2. Check output for errors
# Look for exit code marker: __CLI_EXIT_CODE_0__

# 3. Test with small dataset
./craft s3-spaces-migration/my-controller/action --limit=10

# 4. Test with full dataset
./craft s3-spaces-migration/my-controller/action
```

---

## 📋 Common Tasks

### Task 1: Run a Migration

```bash
# 1. Pre-flight checks
./craft s3-spaces-migration/migration-check/check

# 2. Test with dry run
./craft s3-spaces-migration/image-migration/migrate --dryRun=1

# 3. Run actual migration
./craft s3-spaces-migration/image-migration/migrate

# 4. Monitor progress (in another terminal)
./craft s3-spaces-migration/image-migration/monitor

# 5. Post-migration diagnostics
./craft s3-spaces-migration/migration-diag/diagnose
```

### Task 2: Resume After Interruption

```bash
# Migration automatically resumes from last checkpoint
./craft s3-spaces-migration/image-migration/migrate

# Check migration status
./craft s3-spaces-migration/image-migration/status

# View checkpoint data
./craft s3-spaces-migration/image-migration/checkpoint
```

### Task 3: Rollback a Migration

```bash
# Rollback entire migration
./craft s3-spaces-migration/image-migration/rollback

# Or rollback specific phase
./craft s3-spaces-migration/image-migration/rollback --phase=consolidation
```

### Task 4: Clean Up Old Transforms

```bash
# Preview what will be deleted
./craft s3-spaces-migration/transform-cleanup/clean --dryRun=1

# Execute cleanup
./craft s3-spaces-migration/transform-cleanup/clean --dryRun=0
```

### Task 5: Test Provider Connection

```bash
# Test source provider
./craft s3-spaces-migration/provider-test/test-source

# Test target provider
./craft s3-spaces-migration/provider-test/test-target

# Test specific provider
./craft s3-spaces-migration/provider-test/test --provider=s3
```

### Task 6: Replace URLs in Database

```bash
# Dry run first
./craft s3-spaces-migration/url-replacement/replace-s3-urls --dryRun=1

# Execute with backup
./craft s3-spaces-migration/url-replacement/replace-s3-urls --backup=1

# Verify changes
./craft s3-spaces-migration/url-replacement/verify
```

### Task 7: Switch Filesystem

```bash
# Switch to DigitalOcean
./craft s3-spaces-migration/filesystem-switch/to-do

# Switch back to AWS
./craft s3-spaces-migration/filesystem-switch/to-aws

# Switch specific volume
./craft s3-spaces-migration/filesystem-switch/switch-volume --volumeId=1 --filesystemHandle=doSpacesFs
```

---

## 📖 File Reference Guide

### Must-Read Files

1. **modules/Plugin.php** (308 lines)
   - Plugin entry point
   - Service registration
   - Auto-installs config file

2. **modules/helpers/MigrationConfig.php** (1,410 lines)
   - **MOST IMPORTANT FILE**
   - Single source of truth for configuration
   - 40+ getter methods
   - Read this first!

3. **modules/console/controllers/ImageMigrationController.php** (680 lines)
   - Main migration orchestrator
   - Entry point for migrations

4. **config/migration-config.php** (518 lines)
   - Configuration template
   - Comprehensive documentation
   - Copy this to understand all options

### Key Service Files

5. **modules/services/MigrationOrchestrator.php**
   - Coordinates all migration phases
   - Delegates to specialized services

6. **modules/services/CheckpointManager.php**
   - Checkpoint/resume implementation
   - Critical for understanding recovery

7. **modules/services/ErrorRecoveryManager.php**
   - Retry logic
   - Error categorization

8. **modules/services/RollbackEngine.php**
   - Rollback implementation
   - Uses change log

### Provider Architecture Files (v2.0)

9. **modules/interfaces/StorageProviderInterface.php** (163 lines)
   - Provider contract
   - All adapters implement this

10. **modules/services/ProviderRegistry.php** (150 lines)
    - Provider registration
    - Provider instantiation

11. **modules/adapters/S3StorageAdapter.php**
    - Reference implementation
    - Template for other adapters

### Dashboard Files

12. **modules/controllers/MigrationController.php** (24KB)
    - Dashboard backend
    - 14+ API endpoints

13. **modules/templates/s3-spaces-migration/dashboard.twig**
    - Dashboard UI
    - Phase workflow

### Documentation Files

14. **README.md** - Project overview, installation
15. **ARCHITECTURE.md** - System design, patterns
16. **MIGRATION_GUIDE.md** - Step-by-step migration guide
17. **OPERATIONS.md** - Day-to-day operations
18. **PRODUCTION_RUNBOOK.md** - Production deployment
19. **AGENTS.md** - **THIS FILE** - AI agent rules
20. **CONTRIBUTING.md** - Contribution guidelines

---

## ✅ Dos and Don'ts

### ✅ DO

1. **Always use MigrationConfig helper** for configuration access
2. **Follow PSR-12 coding standards** (4 spaces, meaningful names)
3. **Validate configuration** before starting operations
4. **Use checkpoint/resume pattern** for long operations
5. **Log all operations** for rollback capability
6. **Provide dry-run mode** for all destructive operations
7. **Use Console output helpers** for colored output
8. **Return proper exit codes** (ExitCode::OK, ExitCode::CONFIG, etc.)
9. **Add PHPDoc blocks** for all methods
10. **Test with small datasets first** before full migration
11. **Use dependency injection** for services
12. **Follow existing patterns** visible in the codebase
13. **Update CHANGELOG.md** for user-facing changes
14. **Add inline comments** for complex logic
15. **Use Conventional Commits** for commit messages

### ❌ DON'T

1. **Don't modify Bootstrap.php** - Breaks auto-registration
2. **Don't read config file directly** - Use MigrationConfig helper
3. **Don't skip validation** - Always validate before operations
4. **Don't remove checkpoints** - Required for production use
5. **Don't remove rollback logic** - Critical safety feature
6. **Don't change PSR-4 paths** - Breaks autoloading
7. **Don't introduce new dependencies** - Keep zero-dependency
8. **Don't modify database schemas** without explicit approval
9. **Don't remove warnings** - They're there for safety
10. **Don't skip dry-run testing** - Always test first
11. **Don't hardcode values** - Use configuration
12. **Don't use tabs** - Always use 4 spaces
13. **Don't use echo** in controllers - Use $this->stdout()
14. **Don't create singletons** (except MigrationConfig which already is)
15. **Don't add emojis** unless in user-facing messages (and sparingly)

### Common Mistakes to Avoid

**Mistake 1: Reading config directly**
```php
// ❌ WRONG
$config = require Craft::getAlias('@config/migration-config.php');
$bucket = $config['aws']['bucket'];

// ✅ CORRECT
$config = MigrationConfig::getInstance();
$bucket = $config->getAwsBucket();
```

**Mistake 2: Not handling errors**
```php
// ❌ WRONG
public function actionMigrate() {
    $this->performMigration();
    return ExitCode::OK;
}

// ✅ CORRECT
public function actionMigrate() {
    try {
        $errors = $this->config->validate();
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->stderr("✗ $error\n", Console::FG_RED);
            }
            return ExitCode::CONFIG;
        }

        $this->performMigration();
        return ExitCode::OK;
    } catch (\Exception $e) {
        $this->stderr("Error: " . $e->getMessage() . "\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }
}
```

**Mistake 3: Not using checkpoints**
```php
// ❌ WRONG - No checkpoint, can't resume
foreach ($assets as $asset) {
    $this->processAsset($asset);
}

// ✅ CORRECT - With checkpoint
$checkpointManager = new CheckpointManager('migration');
$processedIds = $checkpointManager->loadLatestCheckpoint()['processed_ids'] ?? [];

foreach ($assets as $i => $asset) {
    if (in_array($asset->id, $processedIds)) continue;

    $this->processAsset($asset);
    $processedIds[] = $asset->id;

    if ($i % 50 === 0) {
        $checkpointManager->saveCheckpoint(['processed_ids' => $processedIds]);
    }
}
```

---

## 🔍 Quick Reference

### Console Output Patterns

```php
// Headers
$this->stdout(str_repeat("=", 80) . "\n", Console::FG_CYAN);
$this->stdout("SECTION TITLE\n", Console::FG_CYAN);
$this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

// Success
$this->stdout("✓ Operation successful\n", Console::FG_GREEN);

// Warning
$this->stdout("⚠ Warning message\n", Console::FG_YELLOW);

// Error
$this->stderr("✗ Error message\n", Console::FG_RED);

// Info
$this->stdout("ℹ Information\n", Console::FG_CYAN);

// Progress
$this->stdout("Processing $current/$total...\n");
```

### Exit Codes

```php
use yii\console\ExitCode;

return ExitCode::OK;                    // 0 - Success
return ExitCode::UNSPECIFIED_ERROR;     // 1 - General error
return ExitCode::CONFIG;                // 78 - Configuration error
```

### MigrationConfig Quick Reference

```php
$config = MigrationConfig::getInstance();

// AWS
$config->getAwsBucket()
$config->getAwsRegion()
$config->getAwsUrls()

// DigitalOcean
$config->getDoBucket()
$config->getDoRegion()
$config->getDoBaseUrl()

// Performance
$config->getBatchSize()
$config->getMaxRetries()
$config->getCheckpointEveryBatches()

// Validation
$config->validate()
```

### Service Instantiation

```php
// With dependencies
$service = new MyService(
    new DependencyService(),
    MigrationConfig::getInstance()
);

// Using Craft's service container (if registered)
$service = \Craft::$app->get('myService');
```

---

## 📚 Additional Resources

### Documentation

- **README.md** - Start here for overview
- **ARCHITECTURE.md** - Deep dive into system design
- **MIGRATION_GUIDE.md** - Step-by-step migration instructions
- **MULTI_PROVIDER_ARCHITECTURE.md** - v2.0 multi-provider design
- **OPERATIONS.md** - Day-to-day operations guide
- **PRODUCTION_RUNBOOK.md** - Production deployment guide
- **SECURITY.md** - Security best practices
- **CONTRIBUTING.md** - How to contribute
- **CHANGELOG.md** - Version history

### Code Examples

- **modules/console/controllers/config_examples/** - Configuration examples
- **modules/console/controllers/config_examples/scenarios/** - Common scenarios
- **docs/PROVIDER_EXAMPLES.md** - Provider configuration examples

### Testing

- **tests/Unit/** - Unit test examples
- **tests/Integration/** - Integration test examples
- **tests/Support/CraftStubs.php** - Craft CMS mocking utilities

---

## 🎓 Learning Path

### If you're new to this codebase:

1. **Read this file (CLAUDE.md)** - You're doing it! ✓
2. **Read README.md** - Understand what the plugin does
3. **Read AGENTS.md** - Understand constraints
4. **Read modules/helpers/MigrationConfig.php** - Understand configuration
5. **Read a simple controller** - e.g., `MigrationCheckController.php`
6. **Read ARCHITECTURE.md** - Understand system design
7. **Explore services/** - Understand service architecture
8. **Try running a migration** - Follow MIGRATION_GUIDE.md

### If you're making changes:

1. **Identify the affected area** (controller, service, adapter, etc.)
2. **Read existing code** in that area to understand patterns
3. **Check AGENTS.md** for restrictions
4. **Make minimal changes** that preserve behavior
5. **Test thoroughly** with dry-run first
6. **Update documentation** if needed
7. **Commit with Conventional Commit** message

---

## 📞 Getting Help

### When Uncertain

1. **Check AGENTS.md** - Are you allowed to do this?
2. **Check existing code** - How is this done elsewhere?
3. **Check documentation** - Is this documented?
4. **Ask for clarification** - Don't guess on critical changes
5. **Propose alternatives** - Present multiple safe options

### Questions to Ask Yourself

- ✅ Am I preserving existing functionality?
- ✅ Am I following existing patterns?
- ✅ Am I using MigrationConfig helper?
- ✅ Am I validating configuration?
- ✅ Am I handling errors properly?
- ✅ Am I testing with dry-run first?
- ✅ Am I updating documentation?
- ✅ Am I following PSR-12?
- ✅ Am I using Conventional Commits?

---

## 🏁 Summary

**Key Takeaways:**

1. **Spaghetti Migrator** is a production-grade, enterprise-level migration tool
2. **Always use MigrationConfig.php** - Never read config files directly
3. **Follow existing patterns** - Controller structure, error handling, checkpoints
4. **Preserve safety features** - Checkpoints, rollback, dry-run, validation
5. **Don't modify core files** - Bootstrap.php, module loading, PSR-4 paths
6. **Test thoroughly** - Dry-run first, small datasets, then full migration
7. **Update documentation** - Keep docs in sync with code changes
8. **Follow PSR-12** - Coding standards matter
9. **Use Conventional Commits** - Clear, atomic commits
10. **When in doubt, ask** - Don't guess on critical changes

---

**Version**: 2.0
**Last Updated**: 2025-11-23
**Maintainer**: Christian Sabourin (christian@sabourin.ca)
**Repository**: https://github.com/csabourin/do-migration
