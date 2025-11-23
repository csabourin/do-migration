# Production Operations Guide
## Spaghetti Migrator: AWS S3 → DigitalOcean Spaces

**Version:** 2.0
**Last Updated:** 2025-11-23
**Status:** Production Ready

---

## Table of Contents

1. [Quick Start](#quick-start)
2. [Pre-Migration Checklist](#pre-migration-checklist)
3. [Dashboard Operations](#dashboard-operations)
4. [Command Line Operations](#command-line-operations)
5. [Migration Day Procedure](#migration-day-procedure)
6. [Monitoring & Progress Tracking](#monitoring--progress-tracking)
7. [Error Handling](#error-handling)
8. [Rollback Procedures](#rollback-procedures)
9. [Post-Migration Validation](#post-migration-validation)
10. [Troubleshooting Guide](#troubleshooting-guide)
11. [CLI Command Reference](#cli-command-reference)

---

## Quick Start

### Minimal Production Migration (CLI)

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

### Dashboard Access

**URL:** `https://your-site.com/admin/s3-spaces-migration/dashboard`

The web dashboard provides a guided workflow with built-in validation and safety checks.

---

## Pre-Migration Checklist

### T-7 Days: Planning Phase

- [ ] **Review migration scope**
  - Document total number of assets to migrate
  - Estimate migration duration (rule of thumb: ~100-500 assets/minute)
  - Plan maintenance window (recommend 2x estimated time)

- [ ] **Prepare DigitalOcean Spaces**
  - [ ] Create Spaces bucket
  - [ ] Configure CORS settings
  - [ ] Enable CDN if required
  - [ ] Document bucket name, region, endpoint

- [ ] **Configure environment variables**
  ```bash
  DO_SPACES_BUCKET="your-bucket-name"
  DO_SPACES_REGION="nyc3"
  DO_SPACES_KEY="your-access-key"
  DO_SPACES_SECRET="your-secret-key"
  DO_SPACES_ENDPOINT="https://nyc3.digitaloceanspaces.com"
  ```

- [ ] **Run pre-flight checks**
  ```bash
  ./craft s3-spaces-migration/migration-check/index
  ```
  - All checks must pass (green ✓)
  - Address any warnings or errors

### T-3 Days: Dry Run

- [ ] **Perform dry-run migration**
  ```bash
  ./craft s3-spaces-migration/image-migration/migrate dryRun=1
  ```
  - Review output for warnings
  - Verify estimated time and disk space requirements
  - Check for any asset path issues

- [ ] **Test rollback procedures**
  - Ensure rollback commands work
  - Verify backup restoration

### T-1 Day: Final Preparation

- [ ] **Create full database backup**
  ```bash
  # MySQL
  mysqldump -u [user] -p [database] > backup_pre_migration_$(date +%Y%m%d).sql

  # PostgreSQL
  pg_dump -U [user] [database] > backup_pre_migration_$(date +%Y%m%d).sql
  ```

- [ ] **Disable asset management plugins** ⚠️ **CRITICAL**
  - Go to: **Settings → Plugins** in Craft Control Panel
  - Disable ALL asset processing plugins including:
    - **Image Optimize** - optimizes/transforms images on save
    - **ImageResizer** - auto-resizes images on upload
    - **Imager-X** - generates image transforms
    - **Image Toolbox** - processes images automatically
    - **Transcoder** - transforms media files
    - **TinyImage** - compresses images
    - **Focal Point Field** - may trigger image processing
    - Any other plugins that automatically process assets
  - **IMPORTANT:** Keep these disabled until AFTER Phase 7 (Image Transforms) is complete

- [ ] **Verify disk space**
  - Local temp storage: 20% more than total asset size
  - Target Spaces: 150% of total asset size (for transforms)

- [ ] **Schedule maintenance window**
  - Post announcement to users
  - Enable maintenance mode if possible
  - Prepare rollback decision timeline

- [ ] **Prepare monitoring**
  - Open dashboard: `https://your-site.com/admin/s3-spaces-migration/dashboard`
  - Have log viewer ready: `tail -f storage/logs/web.log`
  - Test alerting mechanisms

---

## Dashboard Operations

### Access the Dashboard

**URL:** `https://your-site.com/admin/s3-spaces-migration/dashboard`

### Dashboard Phases Overview

The dashboard guides you through 8 sequential phases with built-in validation:

| Phase | Title | Critical | Description |
|-------|-------|----------|-------------|
| **Phase 0** | Setup & Configuration | ⚠️ | Create filesystems and configure volumes |
| **Phase 1** | Pre-Flight Checks | ⚠️ | Validate environment (10 automated checks) |
| **Phase 2** | URL Replacement | ⚠️ | Replace AWS URLs in database with DO URLs |
| **Phase 3** | Template Updates | Optional | Replace hardcoded URLs in Twig templates |
| **Phase 4** | **Filesystem Switch** | ⚠️ CRITICAL | **Switch volumes to point to DO** |
| **Phase 5** | **File Migration** | ⚠️ CRITICAL | **Migrate files from AWS to DO** |
| **Phase 6** | Post-Migration Validation | ⚠️ | Verify migration success |
| **Phase 7** | Image Transforms | Optional | Generate image transforms |

### ⚠️ CRITICAL WORKFLOW ORDER

**Phase 4 MUST be completed BEFORE Phase 5!**

```
❌ WRONG ORDER:
1. Migrate files (Phase 5)
2. Switch filesystems (Phase 4)
↳ Result: Files go to AWS, volumes still point to AWS!

✅ CORRECT ORDER:
1. Switch filesystems (Phase 4)
2. Migrate files (Phase 5)
↳ Result: Volumes point to DO, files migrate to DO!
```

### Dashboard Features

**1. Workflow Stepper**
- Visual progress indicator showing current phase
- Green checkmarks for completed phases
- Warning indicators on critical phases (4 & 5)

**2. Automatic Validation**
- Prevents out-of-order execution
- Shows warning if dependencies not met
- Example: Cannot run Phase 5 until Phase 4 is complete

**3. Confirmation Dialogs**
- Critical operations require confirmation
- Shows checklist of prerequisites
- Prevents accidental execution

**4. Real-Time Progress**
- Live progress bars for long-running operations
- Items/second rate display
- ETA calculations
- Cancel button for running operations

**5. Resume Capability**
- Detects interrupted migrations
- Shows resume banner
- One-click resume from checkpoint

### Using the Dashboard: Step-by-Step

**Phase 0: Setup & Configuration**
```
1. Run "Create DO Filesystems"
2. Run "Configure All Volumes"
3. Run "Create Quarantine Volume"
4. ✓ Verify all setup modules are green
```

**Phase 1: Pre-Flight Checks**
```
1. Click "Run Pre-Flight Checks"
2. ✓ Verify all 10 checks pass
3. Fix any failing checks before proceeding
```

**Phase 2: URL Replacement**
```
1. RECOMMENDED: Run "Dry Run" first
2. Click "Replace Database URLs"
3. Wait for completion (10-60 minutes)
4. ✓ Run "Verify URL Replacement"
```

**Phase 3: Template Updates** (Optional)
```
1. Run "Scan Templates" to find hardcoded URLs
2. Run "Replace Template URLs" if needed
```

**Phase 4: Filesystem Switch** ⚠️ **CRITICAL**
```
1. Click "Preview Switch" to see what will change
2. Click "Switch to DO Spaces"
3. Confirm in dialog after reviewing checklist
4. ✓ Run "Verify Filesystem Setup"
5. **Do NOT proceed to Phase 5 if this fails!**
```

**Phase 5: File Migration** ⚠️ **CRITICAL**
```
1. **VERIFY Phase 4 is complete first!**
2. Dashboard will prevent execution if Phase 4 not done
3. RECOMMENDED: Run "Dry Run" first
4. Click "Migrate Files to DO"
5. Confirm in dialog
6. Monitor progress (may take hours)
7. **Can be resumed if interrupted**
```

**Phase 6: Post-Migration Validation**
```
1. Run "Analyze Migration State"
2. Check for missing files
3. Run post-migration commands
```

**Phase 7: Image Transforms** (Optional)
```
1. Run "Discover ALL Transforms"
2. Generate transforms as needed
```

---

## Command Line Operations

### Configuration Validation

```bash
# Check all prerequisites and configuration
./craft s3-spaces-migration/migration-check/check

# Analyze current state
./craft s3-spaces-migration/migration-check/analyze

# List all filesystems
./craft s3-spaces-migration/filesystem/list

# Test connectivity
./craft s3-spaces-migration/filesystem-switch/test-connectivity

# Check volume configuration
./craft s3-spaces-migration/volume-config/status
```

### Transform Cleanup (CRITICAL - Do this first!)

```bash
# Preview what will be deleted (safe)
./craft s3-spaces-migration/transform-cleanup/clean --dryRun=1

# Review the report
cat storage/runtime/transform-cleanup/transform-cleanup-YYYY-MM-DD-*.json

# Execute cleanup
./craft s3-spaces-migration/transform-cleanup/clean
```

**What this does:**
- Removes all files in underscore-prefixed directories (e.g., `_1200x800/`, `_thumbnail/`)
- Saves detailed JSON report of deleted files
- Dramatically reduces migration time and size
- Transforms will be regenerated on-demand after migration

### Core Migration

```bash
# DRY RUN FIRST (no changes made)
./craft s3-spaces-migration/image-migration/migrate --dryRun=1

# Run actual migration
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

### Resume from Checkpoint

```bash
# Check current status
./craft s3-spaces-migration/image-migration/status

# Resume from last checkpoint
./craft s3-spaces-migration/image-migration/migrate --resume

# Resume from specific checkpoint
./craft s3-spaces-migration/image-migration/migrate --resume --checkpoint=<checkpoint-id>
```

### URL Replacement

```bash
# Database URL Replacement
./craft s3-spaces-migration/url-replacement/show-config
./craft s3-spaces-migration/url-replacement/replace-s3-urls
./craft s3-spaces-migration/url-replacement/verify

# Template URL Replacement
./craft s3-spaces-migration/template-url-replacement/scan
./craft s3-spaces-migration/template-url-replacement/replace
./craft s3-spaces-migration/template-url-replacement/verify

# Extended URL Replacement (JSON/Additional Fields)
./craft s3-spaces-migration/extended-url-replacement/scan-additional
./craft s3-spaces-migration/extended-url-replacement/replace-additional
./craft s3-spaces-migration/extended-url-replacement/replace-json
```

### Filesystem Switch

```bash
# Preview filesystem switch (no changes)
./craft s3-spaces-migration/filesystem-switch/preview

# Switch to DigitalOcean
./craft s3-spaces-migration/filesystem-switch/to-do --confirm=1

# Switch back to AWS (if needed)
./craft s3-spaces-migration/filesystem-switch/to-aws --confirm=1

# Verify filesystem configuration
./craft s3-spaces-migration/filesystem-switch/verify

# Test connectivity after switch
./craft s3-spaces-migration/filesystem-switch/test-connectivity
```

---

## Migration Day Procedure

### Phase 0: Pre-Migration (15-30 minutes)

**Time:** Start of maintenance window

1. **Enable maintenance mode** (if available)
   ```bash
   ./craft off
   ```

2. **Verify system health**
   ```bash
   ./craft s3-spaces-migration/migration-check/index
   ```
   - All checks must be green ✓
   - Cancel migration if critical errors exist

3. **Create automatic backup**
   - Backup is created automatically unless `skipBackup=1` is used
   - **NEVER use skipBackup=1 in production**

### Phase 1: Start Migration

4. **Start migration with monitoring**
   ```bash
   # Terminal 1: Run migration
   ./craft s3-spaces-migration/image-migration/migrate

   # Terminal 2: Monitor progress
   watch -n 5 'tail -20 storage/logs/craft-migration.log'
   ```

5. **Open web dashboard**
   - Navigate to: `https://your-site.com/admin/s3-spaces-migration/dashboard`
   - Monitor real-time progress
   - Watch for error indicators (red)

### Phase 2: Monitor Progress (Duration varies)

6. **Expected phases and durations:**
   ```
   Phase 0: Preparation & Validation        (5-10 min)
   Phase 1: Asset Inventory                 (5-15 min)
   Phase 2: File Migration                  (bulk of time)
   Phase 3: Inline Image Detection          (10-30 min)
   Phase 4: Orphan Detection                (5-10 min)
   Phase 5: Cleanup & Verification          (5-10 min)
   ```

7. **Normal progress indicators:**
   - ✓ Green checkmarks for completed items
   - Progress bars moving steadily
   - Items/second rate between 1-10 (varies by asset size)
   - ETA decreasing steadily

8. **Warning signs (but not critical):**
   - ⚠ Yellow warnings about retries (normal for network hiccups)
   - Temporary slowdowns (DigitalOcean rate limiting)
   - "Skipping already migrated" messages (indicates resume)

### Phase 3: Completion

9. **Migration completes successfully when:**
   ```
   ✓ All phases completed
   ✓ Final statistics displayed
   ✓ No critical errors in output
   ✓ Dashboard shows "COMPLETED" status
   ```

10. **Run post-migration validation**

---

## Monitoring & Progress Tracking

### Dashboard Monitoring

**URL:** `https://your-site.com/admin/s3-spaces-migration/dashboard`

**Key Metrics to Watch:**

| Metric | What it Means | Action if Abnormal |
|--------|---------------|-------------------|
| **Phase** | Current migration phase | Normal progression |
| **Progress %** | Overall completion | Should increase steadily |
| **Items/sec** | Processing speed | If drops to 0, check logs |
| **ETA** | Est. time remaining | Should decrease steadily |
| **Errors** | Failed operations | If > 50, investigate |
| **Retries** | Retry attempts | If > 100, check network |

### Command Line Monitoring

```bash
# Watch real-time progress
tail -f storage/logs/craft-migration.log

# Check checkpoint status
./craft s3-spaces-migration/image-migration/list-checkpoints

# View quick state
cat storage/migration-checkpoints/*.state.json | jq

# Monitor system resources
htop  # or top
df -h  # disk space

# One-time status check
./craft s3-spaces-migration/image-migration/status

# Real-time monitoring (updates every 2 seconds)
watch -n 2 './craft s3-spaces-migration/image-migration/monitor'
```

### Log Locations

- **Migration logs:** `storage/logs/craft-migration.log`
- **Craft logs:** `storage/logs/web.log`
- **Checkpoints:** `storage/migration-checkpoints/*.json`
- **Change logs (for rollback):** `storage/migration-changelogs/*.jsonl`
- **Backups:** `storage/migration-backups/migration_*.sql`

---

## Error Handling

### Non-Critical Errors (Continue Migration)

**Symptoms:**
- ⚠ Yellow warnings in console
- Retry messages
- Individual asset failures (< 1% of total)

**Action:**
- Note error count
- Continue monitoring
- Address after migration completes
- Review failed assets in logs

### Critical Errors (Stop and Investigate)

**Symptoms:**
- ✗ Red error messages
- "CRITICAL" in output
- Migration halts/crashes
- Database connection lost
- Filesystem not accessible

**Immediate Actions:**

1. **Do NOT force-quit the migration**
   - Let it save checkpoint first
   - Press Ctrl+C once (graceful shutdown)
   - Wait for "Checkpoint saved" message

2. **Review the error**
   ```bash
   tail -100 storage/logs/craft-migration.log
   ```

3. **Common issues and fixes:**

   | Error | Cause | Solution |
   |-------|-------|----------|
   | "Database connection lost" | DB timeout | Increase `wait_timeout` in MySQL |
   | "Filesystem not accessible" | Network/credentials | Check DO credentials, network |
   | "Lock already acquired" | Previous migration stuck | Run cleanup command |
   | "Out of memory" | PHP memory limit | Increase in php.ini |
   | "Disk full" | Insufficient space | Clear temp files, increase volume |

4. **Recovery commands:**
   ```bash
   # Force cleanup stuck lock
   ./craft s3-spaces-migration/image-migration/force-cleanup

   # Resume after fixing issue
   ./craft s3-spaces-migration/image-migration/migrate resume=1
   ```

---

## Rollback Procedures

### When to Rollback

**Rollback if:**
- Migration fails with unrecoverable errors
- Data integrity issues detected
- Performance issues post-migration
- Business requirement to revert
- Within 24-48 hours of migration (while backups are fresh)

**DO NOT ROLLBACK if:**
- Minor warnings that don't affect functionality
- Individual asset failures (< 5%)
- Temporary network issues (migration can resume)

### Rollback Methods

#### Method 1: Database Restore (Recommended - Fastest)

**Duration:** 5-15 minutes
**Use when:** Full rollback required, database backup available

```bash
# Step 1: Enable maintenance mode
./craft off

# Step 2: Run database rollback command
./craft s3-spaces-migration/image-migration/rollback \
  migrationId=[migration-id] \
  method=database

# Step 3: Verify rollback
./craft s3-spaces-migration/migration-diag/index

# Step 4: Test asset access

# Step 5: Disable maintenance mode
./craft on
```

#### Method 2: Change-by-Change Rollback

**Duration:** 30 minutes - 2 hours
**Use when:** Partial rollback needed

```bash
# Step 1: Review changes
./craft s3-spaces-migration/image-migration/rollback \
  migrationId=[migration-id] \
  method=change-log \
  dryRun=1

# Step 2: Execute rollback
./craft s3-spaces-migration/image-migration/rollback \
  migrationId=[migration-id] \
  method=change-log
```

#### Method 3: Manual Database Restore (Last Resort)

```bash
# Step 1: Stop web server
sudo systemctl stop nginx

# Step 2: Restore database
mysql -u [user] -p [database] < storage/migration-backups/migration_[id]_db_backup.sql

# Step 3: Clear caches
rm -rf storage/runtime/cache/*
rm -rf storage/runtime/compiled_templates/*

# Step 4: Start web server
sudo systemctl start nginx

# Step 5: Rebuild Craft caches
./craft clear-caches/all
```

### Post-Rollback Verification

**Critical checks:**

- [ ] Can access admin panel
- [ ] Assets appear in Asset Manager
- [ ] Sample asset URLs load correctly
- [ ] Transforms generate properly
- [ ] No 404 errors on front-end
- [ ] Database integrity check passes
- [ ] Application logs show no errors

---

## Post-Migration Validation

### Immediate Validation (15-30 minutes)

1. **Run diagnostic suite**
   ```bash
   ./craft s3-spaces-migration/migration-diag/index
   ```

2. **Verify asset counts**
   ```bash
   ./craft s3-spaces-migration/migration-diag/asset-counts
   ```

3. **Test sample assets**
   - Pick 10-20 random assets
   - Open in browser
   - Verify images load correctly
   - Check transforms work

4. **Check front-end pages**
   - Visit key pages with many images
   - Verify no broken images (404s)
   - Check browser console for errors

5. **Admin panel check**
   - Open Asset Manager
   - Navigate through folders
   - Upload a test asset
   - Generate a test transform
   - Delete test asset

### Extended Validation (24-48 hours)

6. **Monitor error logs**
   ```bash
   grep -i "asset\|404\|image" storage/logs/web.log | tail -50
   ```

7. **Performance monitoring**
   - Image load times
   - Transform generation time
   - Admin panel responsiveness

8. **User feedback**
   - Solicit feedback from content editors
   - Monitor support tickets

### Validation Checklist

- [ ] Diagnostic suite passes all checks
- [ ] Asset counts match across volumes
- [ ] Sample assets load correctly
- [ ] Transforms generate properly
- [ ] No 404 errors on front-end
- [ ] Admin Asset Manager functional
- [ ] Upload new asset works
- [ ] CDN delivers assets quickly
- [ ] No spike in error logs
- [ ] User feedback is positive

---

## Troubleshooting Guide

### Problem: Migration Stuck / No Progress

**Symptoms:**
- Dashboard shows same percentage for > 15 minutes
- No console output for > 5 minutes
- Items/sec is 0

**Diagnosis:**
```bash
# Check if process is running
ps aux | grep craft

# Check system resources
htop
df -h

# Check logs
tail -50 storage/logs/craft-migration.log
```

**Solutions:**
1. **If network issue:** Wait 5-10 minutes for timeout/retry
2. **If memory issue:** Increase PHP memory_limit, restart migration
3. **If disk full:** Clear space, resume migration
4. **If truly stuck:** Ctrl+C, review logs, resume with `resume=1`

### Problem: High Error Rate

**Symptoms:**
- Error count > 50 or > 5% of assets
- Many red ✗ in console

**Common causes:**
- **Network instability:** Contains "timeout", "connection" → Retry migration
- **Permission issues:** Contains "permission denied", "403" → Check DO credentials
- **Invalid assets:** Contains "not found", "does not exist" → Review source volume

### Problem: Out of Disk Space

```bash
# Clear Craft caches
./craft clear-caches/all
rm -rf storage/runtime/cache/*

# Clear old migration backups (if safe)
ls -lh storage/migration-backups/

# Resume migration
./craft s3-spaces-migration/image-migration/migrate resume=1
```

### Problem: Memory Limit Exceeded

```ini
# Edit php.ini
memory_limit = 512M

# Restart PHP-FPM
sudo systemctl restart php8.1-fpm

# Resume migration
./craft s3-spaces-migration/image-migration/migrate resume=1
```

### Problem: Lock Already Acquired

```bash
# Force cleanup the lock
./craft s3-spaces-migration/image-migration/force-cleanup

# Start new migration
./craft s3-spaces-migration/image-migration/migrate
```

### Problem: Assets Not Loading After Migration

**Diagnosis:**
```bash
# Check volume configuration
./craft s3-spaces-migration/fs-diag/volumes

# Verify filesystem settings
./craft s3-spaces-migration/migration-check/test-fs

# Check asset URLs
./craft s3-spaces-migration/migration-diag/verify-urls
```

**Common causes:**
1. **Wrong base URL:** Check volume configuration
2. **CDN not configured:** Enable CDN in DO Spaces
3. **CORS issues:** Configure CORS in DO Spaces
4. **Cache issues:** Clear browser cache, CDN cache

---

## CLI Command Reference

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

---

## Performance Optimization

### Before Migration

1. **Increase PHP limits:**
   ```ini
   memory_limit = 512M
   max_execution_time = 3600
   max_input_time = 600
   ```

2. **Optimize database:**
   ```sql
   OPTIMIZE TABLE assets;
   OPTIMIZE TABLE elements;
   OPTIMIZE TABLE relations;
   ```

3. **Disable unnecessary modules/plugins**

### During Migration

4. **Run during off-peak hours**
5. **Use dedicated server/container** if possible
6. **Monitor and adjust batch sizes** (default: 100)

### After Migration

7. **Enable CDN** for faster delivery
8. **Configure browser caching**
9. **Consider image optimization** tools

---

## Support Resources

### Documentation

- **ARCHITECTURE.md** - System architecture and design
- **CLAUDE.md** - AI assistant guide
- **SECURITY.md** - Security policies
- **CONTRIBUTING.md** - Contribution guidelines
- **CHANGELOG.md** - Version history

### Getting Help

- **GitHub Issues:** https://github.com/csabourin/do-migration/issues
- **Craft CMS Support:** https://craftcms.com/support
- **DigitalOcean Support:** https://www.digitalocean.com/support

---

**END OF OPERATIONS GUIDE**
