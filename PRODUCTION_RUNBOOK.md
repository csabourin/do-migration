# Production Migration Runbook
## AWS S3 → DigitalOcean Spaces Migration for Craft CMS

**Version:** 1.1
**Last Updated:** 2025-11-10
**Status:** Production Ready (with test coverage pending)

---

## Table of Contents

1. [Pre-Migration Checklist](#pre-migration-checklist)
2. [Migration Day Procedure](#migration-day-procedure)
3. [Monitoring & Progress Tracking](#monitoring--progress-tracking)
4. [Handling Errors](#handling-errors)
5. [Rollback Procedures](#rollback-procedures)
6. [Post-Migration Validation](#post-migration-validation)
7. [Troubleshooting Guide](#troubleshooting-guide)
8. [Emergency Contacts](#emergency-contacts)

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

- [ ] **Test rollback procedures** (see Rollback section)
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

3. **Create automatic backup** (module does this, but verify)
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

10. **Run post-migration validation** (see Post-Migration section)

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
```

### Log Locations

- **Migration logs:** `storage/logs/craft-migration.log`
- **Craft logs:** `storage/logs/web.log`
- **Checkpoints:** `storage/migration-checkpoints/*.json`
- **Change logs (for rollback):** `storage/migration-changelogs/*.jsonl`
- **Backups:** `storage/migration-backups/migration_*.sql`

---

## Handling Errors

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
   | "Lock already acquired" | Previous migration stuck | Run cleanup command (see below) |
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
# - Visit several asset URLs in browser
# - Check admin panel Asset section
# - Verify transforms are working

# Step 5: Disable maintenance mode
./craft on
```

#### Method 2: Change-by-Change Rollback (Slower but Granular)

**Duration:** 30 minutes - 2 hours
**Use when:** Partial rollback needed, or database restore not available

```bash
# Step 1: Review changes that would be reverted
./craft s3-spaces-migration/image-migration/rollback \
  migrationId=[migration-id] \
  method=change-log \
  dryRun=1

# Step 2: Execute rollback
./craft s3-spaces-migration/image-migration/rollback \
  migrationId=[migration-id] \
  method=change-log

# Step 3: Verify (same as Method 1)
```

#### Method 3: Manual Database Restore (Last Resort)

**Duration:** 15-30 minutes
**Use when:** Migration module not accessible

```bash
# Step 1: Stop web server
sudo systemctl stop nginx  # or apache2

# Step 2: Restore database from backup
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

**Critical checks after rollback:**

- [ ] Can access admin panel
- [ ] Assets appear in Asset Manager
- [ ] Sample asset URLs load correctly
- [ ] Transforms generate properly
- [ ] No 404 errors on front-end
- [ ] Database integrity check passes
- [ ] Application logs show no errors

**Verification commands:**
```bash
# Check asset URLs
./craft s3-spaces-migration/migration-diag/verify-urls

# Check database integrity
./craft s3-spaces-migration/migration-diag/db-integrity

# Test filesystem access
./craft s3-spaces-migration/migration-check/test-fs
```

---

## Post-Migration Validation

### Immediate Validation (15-30 minutes)

Run immediately after migration completes:

1. **Run diagnostic suite**
   ```bash
   ./craft s3-spaces-migration/migration-diag/index
   ```
   - All checks should pass (green ✓)
   - Address any warnings

2. **Verify asset counts**
   ```bash
   # Compare source vs target volumes
   ./craft s3-spaces-migration/migration-diag/asset-counts
   ```
   - Counts should match (within 1-2 for edge cases)

3. **Test sample assets**
   - Pick 10-20 random assets from different folders
   - Open in browser: `https://your-cdn.com/path/to/asset.jpg`
   - Verify images load correctly
   - Check transforms: `https://your-cdn.com/path/to/asset.jpg?w=300`

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

Monitor for issues over next 1-2 days:

6. **Monitor error logs**
   ```bash
   # Check for asset-related errors
   grep -i "asset\|404\|image" storage/logs/web.log | tail -50
   ```

7. **Performance monitoring**
   - Image load times (should be similar or better)
   - Transform generation time
   - Admin panel responsiveness

8. **User feedback**
   - Solicit feedback from content editors
   - Monitor support tickets for asset issues

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

# Check logs for last activity
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

**Diagnosis:**
```bash
# Review errors
grep "ERROR\|FAILED" storage/logs/craft-migration.log | tail -100
```

**Common causes:**
- **Network instability:** Errors contain "timeout", "connection"
  - **Solution:** Retry migration, errors should decrease
- **Permission issues:** Errors contain "permission denied", "403"
  - **Solution:** Check DO Spaces credentials and permissions
- **Invalid assets:** Errors contain "not found", "does not exist"
  - **Solution:** Review source volume for orphaned database records

### Problem: Out of Disk Space

**Symptoms:**
- "No space left on device" error
- Migration fails during file transfer

**Immediate fix:**
```bash
# Clear Craft caches
./craft clear-caches/all
rm -rf storage/runtime/cache/*

# Clear old migration backups (if safe)
ls -lh storage/migration-backups/
# Remove old backups manually if needed

# Resume migration
./craft s3-spaces-migration/image-migration/migrate resume=1
```

### Problem: Memory Limit Exceeded

**Symptoms:**
- "Allowed memory size exhausted"
- PHP fatal error in logs

**Fix:**
```ini
# Edit php.ini
memory_limit = 512M  # or higher

# Restart PHP-FPM
sudo systemctl restart php8.1-fpm  # adjust PHP version

# Resume migration
./craft s3-spaces-migration/image-migration/migrate resume=1
```

### Problem: Lock Already Acquired

**Symptoms:**
- "Another migration is currently running"
- Previous migration crashed without cleanup

**Fix:**
```bash
# Force cleanup the lock
./craft s3-spaces-migration/image-migration/force-cleanup

# Start new migration
./craft s3-spaces-migration/image-migration/migrate
```

### Problem: Assets Not Loading After Migration

**Symptoms:**
- 404 errors for asset URLs
- Broken images on front-end

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
1. **Wrong base URL:** Check volume configuration base URL
2. **CDN not configured:** Enable CDN in DO Spaces
3. **CORS issues:** Configure CORS in DO Spaces settings
4. **Cache issues:** Clear browser cache, CDN cache

---

## Performance Optimization Tips

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

3. **Disable unnecessary modules/plugins** during migration

### During Migration

4. **Run during off-peak hours**
   - Less competition for resources
   - Better network bandwidth

5. **Use dedicated server/container** if possible
   - Avoid resource contention

6. **Monitor and adjust batch sizes** (if experiencing issues)
   - Default: 100 assets/batch
   - Lower if memory issues: 50
   - Higher if system handles well: 200

### After Migration

7. **Enable CDN** for faster delivery
8. **Configure browser caching** for assets
9. **Consider image optimization** tools

---

## Emergency Contacts

### Escalation Path

**Tier 1 - Self-Service:**
- Review this runbook
- Check logs and dashboard
- Attempt basic troubleshooting

**Tier 2 - Technical Lead:**
- Contact: [Your technical lead name]
- Email: [email]
- Phone: [phone]
- Available: [hours]

**Tier 3 - Developer:**
- Contact: [Module developer/maintainer]
- Email: [email]
- GitHub: https://github.com/csabourin/do-migration/issues
- Available: [hours]

### Support Resources

- **Documentation:** See README.md, ARCHITECTURE.md, SECURITY.md
- **GitHub Issues:** https://github.com/csabourin/do-migration/issues
- **Craft CMS Support:** https://craftcms.com/support
- **DigitalOcean Support:** https://www.digitalocean.com/support

---

## Decision Tree

```
Migration Started
    ├─ Progress Normal?
    │   ├─ Yes → Continue Monitoring
    │   └─ No → Check Logs → Troubleshooting Guide
    │
    ├─ Errors < 5%?
    │   ├─ Yes → Continue, Note for Review
    │   └─ No → Investigate Root Cause
    │           ├─ Network Issues → Wait/Retry
    │           ├─ Config Issues → Fix, Resume
    │           └─ Data Issues → Consider Rollback
    │
    ├─ Critical Error?
    │   ├─ Yes → Stop, Investigate, Decide:
    │   │       ├─ Fixable → Fix, Resume
    │   │       └─ Not Fixable → Rollback
    │   └─ No → Continue
    │
    └─ Migration Complete?
        ├─ Success → Post-Migration Validation
        │           ├─ Pass → Celebrate! 🎉
        │           └─ Fail → Troubleshoot or Rollback
        └─ Failed → Review Logs → Decide:
                    ├─ Resume Possible → Fix, Resume
                    └─ Not Recoverable → Rollback
```

---

## Appendix A: Configuration Reference

### Key Configuration Files

- **Module Config:** `config/migration-config.php`
- **Environment:** `.env` (DO credentials)
- **PHP Config:** `php.ini` or `.user.ini`
- **Volume Config:** Craft Admin → Settings → Assets → Volumes

### Important Settings

| Setting | Default | Production Recommended |
|---------|---------|----------------------|
| memory_limit | 256M | 512M+ |
| max_execution_time | 300s | 3600s |
| Batch Size | 100 | 100 (adjust if needed) |
| Checkpoint Interval | Every 100 items | Default OK |
| Lock Timeout | 12 hours | Default OK |

---

## Appendix B: Command Quick Reference

```bash
# Pre-migration
./craft s3-spaces-migration/migration-check/index

# Start migration
./craft s3-spaces-migration/image-migration/migrate

# Dry run
./craft s3-spaces-migration/image-migration/migrate dryRun=1

# Resume after interruption
./craft s3-spaces-migration/image-migration/migrate resume=1

# List checkpoints
./craft s3-spaces-migration/image-migration/list-checkpoints

# Force cleanup stuck migration
./craft s3-spaces-migration/image-migration/force-cleanup

# Rollback (database method)
./craft s3-spaces-migration/image-migration/rollback migrationId=[id] method=database

# Rollback (change-log method)
./craft s3-spaces-migration/image-migration/rollback migrationId=[id] method=change-log

# Post-migration diagnostics
./craft s3-spaces-migration/migration-diag/index

# Verify assets
./craft s3-spaces-migration/migration-diag/verify-urls
```

---

## Document History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 1.0 | 2025-11-07 | Initial release | Module Team |
| 1.1 | 2025-11-10 | Added troubleshooting, optimizations | Claude AI |

---

## Feedback

Found an issue with this runbook? Have suggestions for improvement?

- **GitHub:** https://github.com/csabourin/do-migration/issues
- **Label:** `documentation`

---

**END OF RUNBOOK**
