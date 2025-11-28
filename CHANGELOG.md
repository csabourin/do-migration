# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Rebranded to "Spaghetti Migrator" with playful icon and messaging that reflects the plugin's ability to untangle nested folder structures
- Comprehensive web dashboard for migration orchestration
  - Real-time progress tracking with progress bars and ETA
  - Live command output streaming
  - Visual workflow stepper showing migration phases
  - Automatic workflow validation to prevent out-of-order execution
  - Resume capability with checkpoint detection banner
  - Accessibility improvements including keyboard navigation and ARIA labels
- Persistent dashboard progress tracking service
- Machine-readable exit status markers for reliable command status detection
- Comprehensive progress indicator with ETA for asset file verification
- Ability to interrupt long-running modules from web interface
- Debug logging for migration command handlers
- Automatic config file installation on plugin install/update
- Repository-wide agent guidance (AGENTS.md)
- Production Migration Runbook with detailed procedures and troubleshooting

### Changed
- Corrected critical workflow order: filesystem switch now happens before file migration
- Improved production readiness with enhanced backup and test coverage
- Reordered environment configuration step with better error handling
- Updated all CLI commands to use `spaghetti-migrator` namespace
- Streamlined module bootstrap registration for better compatibility
- Fixed route registration in Plugin.php (actual entry point)
- Stabilized exit code detection for streamed commands
- Enhanced web UX with workflow validation and confirmation dialogs

### Fixed
- **CRITICAL**: Unbounded database query in quarantine file lookup causing memory exhaustion with large quarantine volumes
- **HIGH**: Mass assignment vulnerability in settings import using explicit safe attribute handling
- **HIGH**: Enhanced deadlock detection in migration lock with MySQL/PostgreSQL-specific error codes and random backoff
- Broken pipe error causing ImageMigrationController to fail silently
- PHP parse errors in ImageMigrationController
- LazyAssetLookup class not found error
- Exit code detection for successful commands appearing as failed
- Migrate Files to DO buttons - allow dry runs to bypass workflow validation
- skipConfirmation option error in MigrationController
- Streaming session tracking to avoid header errors
- Auto-confirm flags for streaming CLI commands
- Namespace references for CheckpointManager and service classes
- Streaming command errors by adjusting Accept header requirements
- S3 Spaces migration route and error detection
- Dashboard template error with missing configuration keys
- Config file path issue with automatic config installation
- Module bootstrap registration for Craft CMS
- ChangeLogManager constructor missing parameter
- optimisedImagesField command references

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
- `PRODUCTION_RUNBOOK.md` for production deployment
- `CONTRIBUTING.md` with contribution guidelines
- `SECURITY.md` with security best practices
- Configuration quick reference guide
- French language documentation (`README_FR.md`, `Fiche_Reference_FR.md`)

#### Developer Experience
- **PSR-4 autoloading** for easy installation
- **Automatic bootstrap** via Yii2 BootstrapInterface - no manual configuration needed
- **Twig filter support** (filesize, removeTrailingZero)
- **Idempotent operations** - safe to run multiple times
- **Extensive inline documentation** throughout codebase

### Technical Specifications
- PHP 8.0+ support
- Craft CMS 4.x and 5.x compatibility
- MIT License for open-source use
- Composer-installable plugin
- Zero dependencies beyond Craft CMS

### Security
- No hardcoded credentials or API keys
- Environment variable-based configuration
- Input validation on all user-facing endpoints
- Secure file handling with proper permissions
- CSRF protection on all dashboard actions

---

## Future Roadmap

See [GitHub Issues](https://github.com/csabourin/do-migration/issues) for planned features and improvements.

[Unreleased]: https://github.com/csabourin/do-migration/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/csabourin/do-migration/releases/tag/v1.0.0
