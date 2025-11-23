# 🍝 Spaghetti Migrator v2.0 for Craft CMS

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-4%20%7C%205-orange)](https://craftcms.com/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://www.php.net/)
[![Version](https://img.shields.io/badge/Version-2.0-green)](https://github.com/csabourin/do-migration)

**Untangle your asset spaghetti like a pro chef! Now with multi-cloud support!**

Spaghetti Migrator is a production-grade Craft CMS 4/5 plugin that untangles nested subfolders and migrates assets between **any cloud storage providers**. Whether you're dealing with a tangled mess of nested directories or moving between AWS S3, Google Cloud Storage, Azure, Backblaze B2, Wasabi, Cloudflare R2, DigitalOcean Spaces, or local filesystems, this tool helps you straighten it all out with checkpoint/resume, rollback capabilities, and zero-downtime support.

## 🆕 What's New in v2.0

**Multi-Provider Architecture** - Migrate between **any storage providers**:
- ✅ **AWS S3** → Google Cloud Storage, Azure, Backblaze, Wasabi, R2, DO Spaces, Local
- ✅ **8 Supported Providers**: S3, GCS, Azure Blob, Backblaze B2, Wasabi, Cloudflare R2, DO Spaces, Local Filesystem
- ✅ **64 Migration Combinations**: Any provider to any provider
- ✅ **Local Filesystem Reorganization**: Untangle nested folders on your computer
- ✅ **Flexible URL Strategies**: Simple, regex, and multi-mapping transformations
- ✅ **Provider-Agnostic API**: Clean, unified interface for all storage backends

**New in v2.0:**
- 🌐 **Multi-Cloud Migrations** - Not just S3 → DO anymore!
- 📁 **Local Filesystem Support** - Reorganize messy nested folders
- 🔄 **Flexible URL Replacement** - Regex patterns, multi-domain consolidation
- 🎯 **Provider Capabilities** - Auto-detects and optimizes for each provider
- 🚀 **Production-Ready** - All the reliability you expect, now universal

## ✨ Highlights

- **Untangle the Mess**: Turn your nested folder spaghetti into a well-organized plate
- **Production-Ready**: Battle-tested migration toolkit with enterprise-grade reliability
- **Checkpoint/Resume System**: Survive interruptions and resume from exactly where you left off
- **Complete Rollback**: Full rollback capabilities - because sometimes you need to put the spaghetti back
- **Zero Dependencies**: Works with just Craft CMS - no additional packages required
- **Auto-Bootstrap**: PSR-4 autoloaded - no manual configuration in `config/app.php`
- **Memory Efficient**: Handles 100,000+ assets with intelligent batch processing
- **Comprehensive Dashboard**: Web-based Control Panel for orchestration and monitoring

## 🎯 Key Features

### Migration Capabilities
- **18 Specialized Controllers** for different migration phases
- **Batch Processing** with configurable batch sizes for memory efficiency
- **Dry Run Mode** to test migrations without making changes
- **Progress Tracking** with real-time ETA and throughput metrics
- **Error Recovery** with automatic retry logic and health checks
- **Idempotent Operations** - safe to run multiple times

### Control Panel Dashboard
- Web-based interface for migration orchestration
- Real-time status monitoring
- Command execution from the browser
- Checkpoint inspection tools
- Live log streaming
- Connection testing

### Developer Experience
- Centralized configuration via `MigrationConfig` helper
- 40+ type-safe configuration methods
- Comprehensive inline documentation
- Extensive architecture documentation
- Custom Twig filters included

## 📋 Requirements

- **PHP**: 8.0 or higher
- **Craft CMS**: 4.0+ or 5.0+
- **Storage Provider**: At least one cloud storage account or local filesystem
  - AWS S3, Google Cloud Storage, Azure Blob, Backblaze B2, Wasabi, Cloudflare R2, DigitalOcean Spaces, or Local

## 🌍 Supported Storage Providers

| Provider | Type | Cost | Speed | Notes |
|----------|------|------|-------|-------|
| **AWS S3** | Cloud | $$ | Fast | Industry standard |
| **Google Cloud Storage** | Cloud | $$ | Fast | GCP integration |
| **Azure Blob Storage** | Cloud | $$ | Fast | Azure integration |
| **DigitalOcean Spaces** | Cloud | $ | Fast | Simple pricing |
| **Backblaze B2** | Cloud | $ | Fast | 80% cheaper than S3 |
| **Wasabi** | Cloud | $ | Fast | No egress fees |
| **Cloudflare R2** | Cloud | $ | Fast | Zero egress fees |
| **Local Filesystem** | Local | Free | Very Fast | Reorganization/backup |

## 🚀 Installation

### 1. Install via Composer

```bash
composer require csabourin/spaghetti-migrator
```

The plugin will auto-install and automatically create `config/migration-config.php` if it doesn't exist.

### 2. Configure Environment Variables

Add these to your `.env` file:

```env
# Migration Environment
MIGRATION_ENV=dev

# DigitalOcean Spaces Credentials
DO_S3_ACCESS_KEY=your_spaces_access_key
DO_S3_SECRET_KEY=your_spaces_secret_key
DO_S3_BUCKET=your-bucket-name
DO_S3_BASE_URL=https://your-bucket.tor1.digitaloceanspaces.com
DO_S3_BASE_ENDPOINT=https://tor1.digitaloceanspaces.com
```

See [.env.example](.env.example) for all available options.

### 3. Update Migration Config

Edit `config/migration-config.php`:

```php
$awsSource = [
    'bucket' => 'your-aws-bucket',      // ← Update this
    'region' => 'us-east-1',             // ← Update this
];
```

### 4. Verify Installation

```bash
./craft spaghetti-migrator/migration-check/check
```

## 📖 Usage

### Console Commands

#### Pre-Flight Check
```bash
./craft spaghetti-migrator/migration-check/check
```

#### Migrate Assets (Dry Run)
```bash
./craft spaghetti-migrator/image-migration/migrate --dryRun=1
```

#### Migrate Assets (Production)
```bash
./craft spaghetti-migrator/image-migration/migrate
```

#### Replace URLs in Database
```bash
./craft spaghetti-migrator/url-replacement/replace-s3-urls
```

#### Switch Filesystems
```bash
./craft spaghetti-migrator/filesystem-switch/to-do
```

#### Post-Migration Diagnostics
```bash
./craft spaghetti-migrator/migration-diag/diagnose
```

### Web Dashboard

Access the migration dashboard in your Control Panel:

```
Control Panel → Migration → Dashboard
```

The dashboard provides:
- Step-by-step migration guidance
- Real-time progress monitoring
- Command execution interface
- Configuration validation
- Checkpoint management

## 🗺️ Migration Workflow

The complete migration process follows these phases:

1. **Pre-Flight Validation** - Verify configuration and connectivity
2. **Database URL Replacement** - Update S3 URLs in content tables
3. **Template URL Replacement** - Update hardcoded URLs in Twig templates
4. **File Migration** - Copy physical assets from AWS to DigitalOcean
5. **Filesystem Switch** - Switch volumes to use DigitalOcean Spaces
6. **Configuration Updates** - Update plugin configs and field settings
7. **Transform Management** - Discover and pre-generate image transforms
8. **Post-Migration Verification** - Validate successful migration

Each phase is modular and can be executed independently or in sequence.

### OptimisedImages transform cleanup

Before running the file migration or cleanup commands, purge stale transform files that live inside underscore-prefixed folders (for example `_1200x800/hero.jpg`) within the **Optimised Images** volume (ID 4). This prevents copying millions of auto-generated transforms to the new filesystem and keeps the migration delta small.

```bash
# Preview everything that would be removed
./craft spaghetti-migrator/transform-cleanup/clean --dryRun=1

# Execute the cleanup
./craft spaghetti-migrator/transform-cleanup/clean --dryRun=0
```

Each run saves a JSON report under `storage/runtime/transform-cleanup/` so you can audit which files were targeted.

## 📂 Module Structure

```
modules/
├── module.php                     # Module entry point
├── controllers/                   # Web controllers
│   ├── DefaultController.php
│   └── MigrationController.php    # Dashboard controller
├── console/controllers/           # 18 console controllers
│   ├── MigrationCheckController.php
│   ├── ImageMigrationController.php
│   ├── FilesystemSwitchController.php
│   └── ... (11 more)
├── helpers/
│   └── MigrationConfig.php        # Centralized configuration
└── templates/spaghetti-migrator/
    └── dashboard.twig             # Control Panel interface
```

## 📚 Documentation

### v2.0 Guides
- **[MIGRATION_GUIDE.md](MIGRATION_GUIDE.md)** - **START HERE** - Complete migration guide for all providers
- **[docs/PROVIDER_EXAMPLES.md](docs/PROVIDER_EXAMPLES.md)** - Configuration examples for each provider
- **[MULTI_PROVIDER_ARCHITECTURE.md](MULTI_PROVIDER_ARCHITECTURE.md)** - v2.0 architecture and design
- **[config/migration-config-v2.php](config/migration-config-v2.php)** - v2.0 configuration template

### Core Documentation
- **[OPERATIONS.md](OPERATIONS.md)** - Day-to-day usage, queue execution, consolidation, and troubleshooting
- **[PRODUCTION_OPERATIONS.md](PRODUCTION_OPERATIONS.md)** - Production deployment, monitoring, and troubleshooting
- **[ARCHITECTURE.md](ARCHITECTURE.md)** - System design and technical details
- **[CLAUDE.md](CLAUDE.md)** - AI assistant guide with comprehensive codebase reference
- **[AGENTS.md](AGENTS.md)** - AI agent rules and constraints
- **[CHANGELOG.md](CHANGELOG.md)** - Version history
- **[CONTRIBUTING.md](CONTRIBUTING.md)** - Contribution guidelines
- **[SECURITY.md](SECURITY.md)** - Security policy and best practices

## 🧪 Diagnostics

- `php check-method-exists.php` — smoke check to verify `MigrationConfig` is discoverable under the `csabourin\spaghettiMigrator` namespace and that the DigitalOcean environment variable helpers are present.

## 🔧 Configuration

The module uses a centralized configuration system with three layers:

1. **Environment Variables** (`.env`) - Credentials and secrets
2. **Configuration File** (`config/migration-config.php`) - Migration settings
3. **Helper Class** (`MigrationConfig`) - Type-safe access to config values

### Key Configuration Options

```php
return [
    'aws' => [
        'bucket' => 'your-source-bucket',
        'region' => 'us-east-1',
    ],
    'do' => [
        'bucket' => App::env('DO_S3_BUCKET'),
        'region' => 'tor1',
        'accessKey' => App::env('DO_S3_ACCESS_KEY'),
        'secretKey' => App::env('DO_S3_SECRET_KEY'),
    ],
    'migration' => [
        'batchSize' => 100,
        'checkpointFrequency' => 50,
        'maxRetries' => 3,
    ],
];
```

## 🛡️ Safety Features

- **Dry Run Mode**: Test all operations before execution
- **Checkpoint System**: Automatic progress saving every N items
- **Rollback Capability**: Complete undo with change logs
- **Health Checks**: Continuous validation during migration
- **Error Recovery**: Automatic retry with exponential backoff
- **Audit Logging**: Comprehensive logs of all operations

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

### Development Setup

1. Fork and clone the repository
2. Install dependencies: `composer install`
3. Set up a test Craft CMS installation
4. Configure test AWS S3 and DigitalOcean Spaces accounts
5. Run pre-flight checks: `./craft spaghetti-migrator/migration-check/check`

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- Built for [Craft CMS](https://craftcms.com/) by Pixel & Tonic
- Inspired by real-world enterprise migration needs
- Tested on production sites with millions of assets

## 💬 Support

- **Issues**: [GitHub Issues](https://github.com/csabourin/do-migration/issues)
- **Discussions**: [GitHub Discussions](https://github.com/csabourin/do-migration/discussions)
- **Documentation**: [Architecture Guide](ARCHITECTURE.md)

## 🔗 Links

- **Repository**: https://github.com/csabourin/do-migration
- **Packagist**: https://packagist.org/packages/csabourin/spaghetti-migrator
- **Craft CMS**: https://craftcms.com/
- **DigitalOcean Spaces**: https://www.digitalocean.com/products/spaces

---

Made with ❤️ (and a touch of humor) for the Craft CMS community by the Spaghetti Migrator team
