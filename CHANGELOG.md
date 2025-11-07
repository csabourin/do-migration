# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-11-07

### Added

#### Core Migration Features
- **14 specialized console controllers** for comprehensive migration workflow
- **Checkpoint/resume system** - survive interruptions and resume from last checkpoint
- **Change log system** - complete rollback capability for all operations
- **Batch processing** - memory-efficient handling of 100,000+ assets
- **Dry run mode** - test migrations without making actual changes
- **Error recovery** - automatic retry logic with health checks
- **Progress tracking** - real-time progress display with ETA and items/sec metrics

#### Migration Controllers
- `MigrationCheckController` - Pre-flight validation and connectivity testing
- `ImageMigrationController` - File migration with checkpoint/resume support
- `FilesystemController` - Create and delete filesystem configurations
- `FilesystemSwitchController` - Switch volumes between AWS S3 and DigitalOcean Spaces
- `VolumeConfigController` - Configure volume settings
- `UrlReplacementController` - Replace S3 URLs in database content
- `ExtendedUrlReplacementController` - Advanced URL replacement with pattern matching
- `TemplateUrlReplacementController` - Update hardcoded URLs in Twig templates
- `FsDiagController` - Filesystem diagnostics and validation
- `MigrationDiagController` - Post-migration verification
- `TransformDiscoveryController` - Discover image transforms in use
- `TransformPreGenerationController` - Pre-generate image transforms
- `PluginConfigAuditController` - Audit plugin configurations
- `StaticAssetScanController` - Scan JavaScript/CSS for hardcoded S3 URLs

#### Control Panel Features
- **Web-based dashboard** for migration orchestration
- **Real-time status monitoring** via AJAX API endpoints
- **Command execution** from Control Panel
- **Checkpoint inspection** tools
- **Log streaming** for debugging
- **Connection testing** for DigitalOcean Spaces

#### Configuration System
- **Centralized configuration** via `MigrationConfig` helper class
- **40+ configuration methods** with type safety
- **Environment variable support** for credentials
- **Validation methods** for pre-flight checks
- **Dot-notation support** for nested configuration values

#### Documentation
- Comprehensive `ARCHITECTURE.md` with system design details
- `DASHBOARD.md` with Control Panel usage guide
- `SOLUTION.md` troubleshooting guide
- Configuration quick reference guide
- French language documentation (`README_FR.md`, `Fiche_Reference_FR.md`)

#### Developer Experience
- **PSR-4 autoloading** for easy installation
- **Automatic bootstrap** via `bootstrap.php` - no manual configuration needed
- **Twig filter support** (filesize, removeTrailingZero)
- **Idempotent operations** - safe to run multiple times
- **Extensive inline documentation** throughout codebase

### Technical Specifications
- PHP 8.0+ support
- Craft CMS 4.x and 5.x compatibility
- MIT License for open-source use
- Composer-installable module
- Zero dependencies beyond Craft CMS

### Security
- No hardcoded credentials or API keys
- Environment variable-based configuration
- Input validation on all user-facing endpoints
- Secure file handling with proper permissions

---

## Future Roadmap

See [GitHub Issues](https://github.com/csabourin/do-migration/issues) for planned features and improvements.

[1.0.0]: https://github.com/csabourin/do-migration/releases/tag/v1.0.0
