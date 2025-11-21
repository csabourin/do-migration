# Production Migration Workbook
## CLI Command Reference for S3 to Spaces Migration

> **Purpose**: This workbook provides a complete CLI command reference for executing the migration when web-based dashboard is unavailable or for automated deployments.

---

## Table of Contents

1. [Quick Start](#quick-start)
2. [Pre-Migration Phase](#pre-migration-phase)
3. [Migration Preparation](#migration-preparation)
4. [Core Migration](#core-migration)
5. [Post-Migration](#post-migration)
6. [Rollback & Recovery](#rollback--recovery)
7. [Monitoring & Diagnostics](#monitoring--diagnostics)
8. [Complete CLI Reference](#complete-cli-reference)

---

## Quick Start

### Minimal Production Migration

```bash
# 1. Pre-flight check
./craft s3-spaces-migration/migration-check/check

# 2. Clean up transforms (CRITICAL - reduces migration time)
./craft s3-spaces-migration/transform-cleanup/clean --dryRun=1
./craft s3-spaces-migration/transform-cleanup/clean

# 3. Run migration (dry run first)
./craft s3-spaces-migration/image-migration/migrate --dryRun=1

# 4. Run actual migration
./craft s3-spaces-migration/image-migration/migrate --yes

# 5. Monitor progress (in separate terminal)
watch -n 2 './craft s3-spaces-migration/image-migration/monitor'

# 6. Replace URLs in database
./craft s3-spaces-migration/url-replacement/replace-s3-urls

# 7. Replace URLs in templates
./craft s3-spaces-migration/template-url-replacement/scan
./craft s3-spaces-migration/template-url-replacement/replace

# 8. Switch filesystems to DigitalOcean
./craft s3-spaces-migration/filesystem-switch/to-do --confirm=1

# 9. Verify migration
./craft s3-spaces-migration/filesystem-switch/verify
```

---

## Pre-Migration Phase

### 1. Configuration Validation

```bash
# Check all prerequisites and configuration
./craft s3-spaces-migration/migration-check/check

# Analyze current state
./craft s3-spaces-migration/migration-check/analyze

# List all filesystems
./craft s3-spaces-migration/filesystem/list

# Test connectivity to both AWS and DO
./craft s3-spaces-migration/filesystem-switch/test-connectivity

# Check volume configuration
./craft s3-spaces-migration/volume-config/status
```

### 2. Volume Setup (if needed)

```bash
# Configure all volumes automatically
./craft s3-spaces-migration/volume-config/configure-all

# Create quarantine volume
./craft s3-spaces-migration/volume-config/create-quarantine-volume

# Set transform filesystem
./craft s3-spaces-migration/volume-config/set-transform-filesystem

# Add optimised field
./craft s3-spaces-migration/volume-config/add-optimised-field
```

### 3. Filesystem Setup

```bash
# Create new filesystem
./craft s3-spaces-migration/filesystem/create

# List existing filesystems
./craft s3-spaces-migration/filesystem/list

# Update optimised images subfolder
./craft s3-spaces-migration/filesystem/update-optimised-images-subfolder
```

---

## Migration Preparation

### 1. Clean Up Transforms (CRITICAL)

**This step is essential** to avoid migrating millions of auto-generated transform files.

```bash
# Preview what will be deleted (safe)
./craft s3-spaces-migration/transform-cleanup/clean --dryRun=1

# Review the report
cat storage/runtime/transform-cleanup/transform-cleanup-YYYY-MM-DD-*.json

# Execute cleanup
./craft s3-spaces-migration/transform-cleanup/clean

# Verify cleanup
./craft s3-spaces-migration/migration-check/check
```

**What this does:**
- Removes all files in underscore-prefixed directories (e.g., `_1200x800/`, `_thumbnail/`)
- Saves detailed JSON report of deleted files
- Dramatically reduces migration time and size
- Transforms will be regenerated on-demand after migration

### 2. Discover Required Transforms (Optional)

```bash
# Discover transforms used in templates
./craft s3-spaces-migration/transform-discovery/discover

# Scan templates for transform usage
./craft s3-spaces-migration/transform-discovery/scan-templates

# Scan database for transform references
./craft s3-spaces-migration/transform-discovery/scan-database
```

---

## Core Migration

### 1. Image Migration (Main Process)

```bash
# DRY RUN FIRST (no changes made)
./craft s3-spaces-migration/image-migration/migrate --dryRun=1

# Review the dry run output, then run actual migration
./craft s3-spaces-migration/image-migration/migrate --yes

# Run without prompts (for automation)
./craft s3-spaces-migration/image-migration/migrate --yes --skipBackup=1
```

**Migration Flags:**
- `--dryRun=1` - Simulate migration without making changes
- `--yes` - Auto-confirm all prompts
- `--skipBackup=1` - Skip backup step (not recommended)
- `--skipInlineDetection=1` - Skip inline image detection
- `--resume` - Resume from last checkpoint

**Migration Phases (automatic):**
1. **Preparation** - Initialize migration, create checkpoints
2. **Discovery** - Scan all volumes and build asset inventory
3. **Optimised Root** - Handle optimised images at bucket root
4. **Link Inline** - Detect and link inline images
5. **Safe Duplicates** - Stage duplicate files safely
6. **Resolve Duplicates** - Merge duplicate asset records
7. **Fix Links** - Repair broken asset-file links
8. **Consolidate** - Move files to correct locations
9. **Quarantine** - Move unused files to quarantine
10. **Cleanup** - Final verification and cleanup

### 2. Resume from Checkpoint

```bash
# Check current status
./craft s3-spaces-migration/image-migration/status

# Resume from last checkpoint
./craft s3-spaces-migration/image-migration/migrate --resume

# Resume from specific checkpoint
./craft s3-spaces-migration/image-migration/migrate --resume --checkpoint=<checkpoint-id>
```

### 3. Monitor Progress

```bash
# One-time status check
./craft s3-spaces-migration/image-migration/status

# Real-time monitoring (updates every 2 seconds)
watch -n 2 './craft s3-spaces-migration/image-migration/monitor'

# Alternative: manual refresh loop
while true; do
  clear
  ./craft s3-spaces-migration/image-migration/monitor
  sleep 2
done
```

**Monitor Output:**
- Migration ID
- Current phase
- Process status (running/stopped)
- Progress (items processed/total)
- Statistics
- Recent errors

---

## Post-Migration

### 1. URL Replacement

#### Database URL Replacement

```bash
# Show current configuration
./craft s3-spaces-migration/url-replacement/show-config

# Replace S3 URLs with new base URL
./craft s3-spaces-migration/url-replacement/replace-s3-urls

# Specify custom URL
./craft s3-spaces-migration/url-replacement/replace-s3-urls \
  "https://your-bucket.tor1.digitaloceanspaces.com"

# Verify URL replacement
./craft s3-spaces-migration/url-replacement/verify
```

**Tables Updated:**
- `matrixcontent_*` - Matrix field content
- `entries` - Entry content
- `content` - General content fields
- All content tables containing S3 URLs

#### Template URL Replacement

```bash
# Scan templates for hardcoded S3 URLs
./craft s3-spaces-migration/template-url-replacement/scan

# Replace URLs in templates (creates backups)
./craft s3-spaces-migration/template-url-replacement/replace

# Verify template changes
./craft s3-spaces-migration/template-url-replacement/verify

# Restore from backups if needed
./craft s3-spaces-migration/template-url-replacement/restore-backups
```

**Template Locations Scanned:**
- `templates/` directory
- Module templates
- Plugin templates

#### Extended URL Replacement (JSON/Additional Fields)

```bash
# Scan additional fields (JSON, Table fields, etc.)
./craft s3-spaces-migration/extended-url-replacement/scan-additional

# Replace URLs in additional fields
./craft s3-spaces-migration/extended-url-replacement/replace-additional

# Replace URLs in JSON fields specifically
./craft s3-spaces-migration/extended-url-replacement/replace-json
```

### 2. Filesystem Switch

```bash
# Preview filesystem switch (no changes)
./craft s3-spaces-migration/filesystem-switch/preview

# List all filesystems
./craft s3-spaces-migration/filesystem-switch/list-filesystems

# Switch to DigitalOcean (with confirmation prompt)
./craft s3-spaces-migration/filesystem-switch/to-do

# Switch to DigitalOcean (auto-confirm)
./craft s3-spaces-migration/filesystem-switch/to-do --confirm=1

# Switch back to AWS (if needed)
./craft s3-spaces-migration/filesystem-switch/to-aws --confirm=1

# Verify filesystem configuration
./craft s3-spaces-migration/filesystem-switch/verify

# Test connectivity after switch
./craft s3-spaces-migration/filesystem-switch/test-connectivity
```

### 3. Transform Management

```bash
# Discover required transforms
./craft s3-spaces-migration/transform-pre-generation/discover

# Generate transforms from discovery report
./craft s3-spaces-migration/transform-pre-generation/generate

# Generate from specific report file
./craft s3-spaces-migration/transform-pre-generation/generate storage/transforms-report.json

# Verify transform generation
./craft s3-spaces-migration/transform-pre-generation/verify

# Warm up transforms (pre-generate commonly used ones)
./craft s3-spaces-migration/transform-pre-generation/warmup
```

### 4. Post-Migration Diagnostics

```bash
# Run full diagnostic analysis
./craft s3-spaces-migration/migration-diag/analyze

# Check for missing files
./craft s3-spaces-migration/migration-diag/check-missing-files

# Move originals to correct location (if needed)
./craft s3-spaces-migration/migration-diag/move-originals
```

---

## Rollback & Recovery

### 1. Rollback Migration

```bash
# Check rollback status
./craft s3-spaces-migration/image-migration/status

# Rollback specific phases (from a phase backwards)
./craft s3-spaces-migration/image-migration/rollback --mode=from --phases=consolidate

# Rollback only specific phases
./craft s3-spaces-migration/image-migration/rollback --mode=only --phases=consolidate,quarantine

# Rollback with dry run
./craft s3-spaces-migration/image-migration/rollback --dryRun=1

# Rollback specific migration ID
./craft s3-spaces-migration/image-migration/rollback --migrationId=20240315_123456

# Full rollback all phases
./craft s3-spaces-migration/image-migration/rollback --mode=from --phases=preparation
```

**Rollback Modes:**
- `from` - Rollback from specified phase backwards to beginning
- `only` - Rollback only specified phases
- `to` - Rollback up to specified phase

**Rollback Methods:**
- `recreate` - Recreate deleted assets
- `restore` - Restore from backup
- `repair` - Repair broken links

### 2. Cleanup Migration State

```bash
# Normal cleanup (safe)
./craft s3-spaces-migration/image-migration/cleanup

# Force cleanup (removes locks and state)
./craft s3-spaces-migration/image-migration/force-cleanup

# Purge dashboard state
./craft s3-spaces-migration/dashboard-maintenance/purge-state
```

---

## Monitoring & Diagnostics

### 1. Migration Monitoring

```bash
# Check migration status
./craft s3-spaces-migration/image-migration/status

# Monitor in real-time
./craft s3-spaces-migration/image-migration/monitor

# Watch with auto-refresh (updates every 2 seconds)
watch -n 2 './craft s3-spaces-migration/image-migration/monitor'
```

### 2. Filesystem Diagnostics

```bash
# List files in a filesystem
./craft s3-spaces-migration/fs-diag/list-fs <fsHandle> [path] [recursive] [limit]

# Examples:
./craft s3-spaces-migration/fs-diag/list-fs images_do "" true 100
./craft s3-spaces-migration/fs-diag/list-fs images_do "subfolder/" false 50

# Search for specific file
./craft s3-spaces-migration/fs-diag/search-fs <fsHandle> <filename> [path]

# Example:
./craft s3-spaces-migration/fs-diag/search-fs images_do "logo.png"
./craft s3-spaces-migration/fs-diag/search-fs images_do "hero.jpg" "uploads/"

# Verify file exists
./craft s3-spaces-migration/fs-diag/verify-fs <fsHandle> <filePath>

# Example:
./craft s3-spaces-migration/fs-diag/verify-fs images_do "uploads/hero.jpg"

# Compare two filesystems
./craft s3-spaces-migration/fs-diag/compare-fs <fs1Handle> <fs2Handle> [path]

# Example:
./craft s3-spaces-migration/fs-diag/compare-fs images_aws images_do
```

### 3. Plugin & Configuration Audit

```bash
# List all installed plugins
./craft s3-spaces-migration/plugin-config-audit/list-plugins

# Scan plugin configurations for S3 references
./craft s3-spaces-migration/plugin-config-audit/scan
```

### 4. Static Asset Scanning

```bash
# Scan templates and static files for asset references
./craft s3-spaces-migration/static-asset-scan/scan
```

---

## Complete CLI Reference

### Migration Check Controller

```bash
# Pre-flight validation
./craft s3-spaces-migration/migration-check/check

# Detailed analysis
./craft s3-spaces-migration/migration-check/analyze
```

### Image Migration Controller

```bash
# Main migration command
./craft s3-spaces-migration/image-migration/migrate [options]

# Options:
#   --dryRun=1            Simulate without changes
#   --yes                 Auto-confirm all prompts
#   --skipBackup=1        Skip backup creation
#   --skipInlineDetection=1  Skip inline image detection
#   --resume              Resume from checkpoint

# Monitor progress
./craft s3-spaces-migration/image-migration/monitor

# Check status
./craft s3-spaces-migration/image-migration/status

# Rollback changes
./craft s3-spaces-migration/image-migration/rollback [options]

# Options:
#   --migrationId=<id>    Specific migration to rollback
#   --phases=<phases>     Comma-separated phase names
#   --mode=<mode>         from|only|to
#   --dryRun=1           Simulate rollback
#   --method=<method>    recreate|restore|repair

# Cleanup state
./craft s3-spaces-migration/image-migration/cleanup

# Force cleanup (removes locks)
./craft s3-spaces-migration/image-migration/force-cleanup
```

### Volume Config Controller

```bash
# Check volume status
./craft s3-spaces-migration/volume-config/status

# Set transform filesystem
./craft s3-spaces-migration/volume-config/set-transform-filesystem

# Add optimised field to volumes
./craft s3-spaces-migration/volume-config/add-optimised-field

# Create quarantine volume
./craft s3-spaces-migration/volume-config/create-quarantine-volume

# Configure all volumes
./craft s3-spaces-migration/volume-config/configure-all
```

### Filesystem Controller

```bash
# Create new filesystem
./craft s3-spaces-migration/filesystem/create

# List all filesystems
./craft s3-spaces-migration/filesystem/list

# Delete filesystem
./craft s3-spaces-migration/filesystem/delete

# Update optimised images subfolder
./craft s3-spaces-migration/filesystem/update-optimised-images-subfolder
```

### Filesystem Switch Controller

```bash
# Preview filesystem switch
./craft s3-spaces-migration/filesystem-switch/preview

# Switch to DigitalOcean
./craft s3-spaces-migration/filesystem-switch/to-do [--confirm=1]

# Switch to AWS
./craft s3-spaces-migration/filesystem-switch/to-aws [--confirm=1]

# Verify filesystem configuration
./craft s3-spaces-migration/filesystem-switch/verify

# Test connectivity
./craft s3-spaces-migration/filesystem-switch/test-connectivity

# List all filesystems
./craft s3-spaces-migration/filesystem-switch/list-filesystems
```

### URL Replacement Controller

```bash
# Replace S3 URLs in database
./craft s3-spaces-migration/url-replacement/replace-s3-urls [newUrl]

# Verify URL replacement
./craft s3-spaces-migration/url-replacement/verify

# Show current configuration
./craft s3-spaces-migration/url-replacement/show-config
```

### Template URL Replacement Controller

```bash
# Scan templates for S3 URLs
./craft s3-spaces-migration/template-url-replacement/scan

# Replace URLs in templates
./craft s3-spaces-migration/template-url-replacement/replace

# Verify template changes
./craft s3-spaces-migration/template-url-replacement/verify

# Restore from backups
./craft s3-spaces-migration/template-url-replacement/restore-backups
```

### Extended URL Replacement Controller

```bash
# Scan additional fields (JSON, Table, etc.)
./craft s3-spaces-migration/extended-url-replacement/scan-additional

# Replace URLs in additional fields
./craft s3-spaces-migration/extended-url-replacement/replace-additional

# Replace URLs in JSON fields
./craft s3-spaces-migration/extended-url-replacement/replace-json
```

### Transform Cleanup Controller

```bash
# Clean up transform files
./craft s3-spaces-migration/transform-cleanup/clean [--dryRun=1]
```

### Transform Pre-Generation Controller

```bash
# Discover required transforms
./craft s3-spaces-migration/transform-pre-generation/discover

# Generate transforms
./craft s3-spaces-migration/transform-pre-generation/generate [reportFile]

# Verify transform generation
./craft s3-spaces-migration/transform-pre-generation/verify [reportFile]

# Warmup commonly used transforms
./craft s3-spaces-migration/transform-pre-generation/warmup
```

### Transform Discovery Controller

```bash
# Discover all transforms
./craft s3-spaces-migration/transform-discovery/discover

# Scan templates for transform usage
./craft s3-spaces-migration/transform-discovery/scan-templates

# Scan database for transform references
./craft s3-spaces-migration/transform-discovery/scan-database
```

### Migration Diag Controller

```bash
# Run diagnostic analysis
./craft s3-spaces-migration/migration-diag/analyze

# Move originals to correct location
./craft s3-spaces-migration/migration-diag/move-originals

# Check for missing files
./craft s3-spaces-migration/migration-diag/check-missing-files
```

### Filesystem Diag Controller

```bash
# List files in filesystem
./craft s3-spaces-migration/fs-diag/list-fs <fsHandle> [path] [recursive] [limit]

# Search for file
./craft s3-spaces-migration/fs-diag/search-fs <fsHandle> <filename> [path]

# Verify file exists
./craft s3-spaces-migration/fs-diag/verify-fs <fsHandle> <filePath>

# Compare two filesystems
./craft s3-spaces-migration/fs-diag/compare-fs <fs1Handle> <fs2Handle> [path]
```

### Volume Consolidation Controller

```bash
# Merge optimized to images
./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images

# Flatten to root
./craft s3-spaces-migration/volume-consolidation/flatten-to-root

# Check status
./craft s3-spaces-migration/volume-consolidation/status
```

### Plugin Config Audit Controller

```bash
# List all plugins
./craft s3-spaces-migration/plugin-config-audit/list-plugins

# Scan plugin configs for S3 references
./craft s3-spaces-migration/plugin-config-audit/scan
```

### Static Asset Scan Controller

```bash
# Scan for static asset references
./craft s3-spaces-migration/static-asset-scan/scan
```

### Dashboard Maintenance Controller

```bash
# Purge dashboard state
./craft s3-spaces-migration/dashboard-maintenance/purge-state
```

### Filesystem Fix Controller

```bash
# Fix filesystem endpoints
./craft s3-spaces-migration/filesystem-fix/fix-endpoints

# Show filesystem configuration
./craft s3-spaces-migration/filesystem-fix/show
```

---

## Migration Phases Deep Dive

### Phase 1: Preparation
- Initialize migration ID
- Create checkpoint manager
- Set up change log
- Acquire migration lock
- Validate configuration

### Phase 2: Discovery
- Build asset inventory from all volumes
- Build file inventory from filesystems
- Categorize assets by status
- Identify duplicates and orphans

### Phase 3: Optimised Root (if applicable)
- Handle assets at bucket root
- Move to correct subfolder
- Update filesystem configuration
- Delete transforms

### Phase 4: Link Inline
- Detect inline images in content
- Create asset records for inline images
- Link to existing files
- Update content references

### Phase 5: Safe Duplicates
- Analyze file duplicates (multiple assets → one file)
- Stage files to temp location
- Determine primary assets
- Verify file safety

### Phase 6: Resolve Duplicates
- Merge duplicate asset records
- Delete unused duplicates
- Rebuild asset inventory
- Update references

### Phase 7: Fix Links
- Repair broken asset-file links
- Search for missing files
- Update asset paths
- Export missing files report

### Phase 8: Consolidate
- Move files to correct locations
- Update asset records
- Verify file integrity
- Clean up source locations

### Phase 9: Quarantine
- Move unused files to quarantine volume
- Update asset status
- Create quarantine report
- Preserve files for review

### Phase 10: Cleanup
- Verify all operations
- Generate final reports
- Clean up temp files
- Release locks

---

## Checkpoint & Resume System

### Checkpoint Files

Checkpoints are saved to: `storage/migration-checkpoints/`

**Checkpoint contains:**
- Migration ID
- Current phase
- Processed asset IDs
- Statistics
- Configuration
- Timestamp

### Resume Process

```bash
# Auto-resume from latest checkpoint
./craft s3-spaces-migration/image-migration/migrate --resume

# Resume from specific checkpoint
./craft s3-spaces-migration/image-migration/migrate --resume --checkpoint=20240315_123456_consolidate

# Check available checkpoints
ls -la storage/migration-checkpoints/
```

### Quick State

Quick state file: `storage/migration-checkpoints/quick-state.json`

**Contains:**
- Migration ID
- Current phase
- Processed count
- Last update timestamp
- Process ID

---

## Logs & Reports

### Log Files

```bash
# Migration logs
storage/logs/migration-<migration-id>.log

# Error logs
storage/migration-errors-<migration-id>.log

# Change logs (for rollback)
storage/migration-changelog-<migration-id>.jsonl
```

### Report Files

```bash
# Transform cleanup reports
storage/runtime/transform-cleanup/transform-cleanup-*.json

# Missing files report
storage/migration-missing-files-<migration-id>.csv

# Orphaned files report
storage/root-orphans-<migration-id>.json

# Transform discovery reports
storage/transforms-report.json
```

---

## Troubleshooting

### Migration Stuck or Frozen

```bash
# Check process status
./craft s3-spaces-migration/image-migration/monitor

# Check if process is actually running
ps aux | grep "s3-spaces-migration"

# Force cleanup if stuck
./craft s3-spaces-migration/image-migration/force-cleanup

# Resume from checkpoint
./craft s3-spaces-migration/image-migration/migrate --resume
```

### Database Lock Issues

```bash
# Force cleanup to release locks
./craft s3-spaces-migration/image-migration/force-cleanup

# Check database locks
# MySQL:
mysql -e "SHOW OPEN TABLES WHERE In_use > 0;"

# PostgreSQL:
psql -c "SELECT * FROM pg_locks WHERE granted = false;"
```

### Out of Memory

```bash
# Reduce batch size in config/migration-config.php
'batchSize' => 50,  // Reduce from 100

# Resume with smaller batches
./craft s3-spaces-migration/image-migration/migrate --resume
```

### Files Not Migrating

```bash
# Run diagnostics
./craft s3-spaces-migration/migration-diag/analyze

# Check missing files
./craft s3-spaces-migration/migration-diag/check-missing-files

# Verify filesystem connectivity
./craft s3-spaces-migration/filesystem-switch/test-connectivity

# Check specific file
./craft s3-spaces-migration/fs-diag/verify-fs images_do "path/to/file.jpg"
```

### Rollback Failed

```bash
# Try dry run first
./craft s3-spaces-migration/image-migration/rollback --dryRun=1

# Check changelog exists
ls -la storage/migration-changelog-*.jsonl

# Try different rollback method
./craft s3-spaces-migration/image-migration/rollback --method=recreate
```

---

## Best Practices

### Before Migration

1. **Backup everything** - Database and files
2. **Run dry run** - Test migration without changes
3. **Clean transforms** - Remove auto-generated transforms
4. **Test connectivity** - Verify AWS and DO access
5. **Check disk space** - Ensure adequate storage

### During Migration

1. **Monitor progress** - Use real-time monitoring
2. **Check logs** - Watch for errors
3. **Don't interrupt** - Let phases complete
4. **Keep terminal alive** - Use `tmux` or `screen`
5. **Document issues** - Note any errors for rollback

### After Migration

1. **Verify URLs** - Check URL replacement
2. **Test uploads** - Upload new assets
3. **Regenerate transforms** - Pre-generate key transforms
4. **Monitor performance** - Check load times
5. **Keep backups** - Retain for 30 days

---

## Production Deployment Checklist

- [ ] Environment variables configured
- [ ] Migration config updated
- [ ] Pre-flight check passed
- [ ] Transforms cleaned up
- [ ] Dry run successful
- [ ] Backups created
- [ ] Monitoring in place
- [ ] Rollback plan ready
- [ ] Team notified
- [ ] Maintenance window scheduled

---

## Quick Reference Card

```bash
# Essential Commands
./craft s3-spaces-migration/migration-check/check
./craft s3-spaces-migration/transform-cleanup/clean --dryRun=1
./craft s3-spaces-migration/image-migration/migrate --dryRun=1
./craft s3-spaces-migration/image-migration/migrate --yes
./craft s3-spaces-migration/image-migration/monitor
./craft s3-spaces-migration/url-replacement/replace-s3-urls
./craft s3-spaces-migration/filesystem-switch/to-do --confirm=1

# Emergency Commands
./craft s3-spaces-migration/image-migration/status
./craft s3-spaces-migration/image-migration/migrate --resume
./craft s3-spaces-migration/image-migration/rollback
./craft s3-spaces-migration/image-migration/force-cleanup
```

---

## Support

For issues and questions:
- GitHub Issues: https://github.com/csabourin/do-migration/issues
- Documentation: See ARCHITECTURE.md and OPERATIONS.md
- Security: See SECURITY.md

---

**Last Updated**: 2024-03-15
**Module Version**: See CHANGELOG.md
