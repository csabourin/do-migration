# Console Module Options Mapping

**Purpose**: Comprehensive mapping of all console controllers and their CLI flags for web interface automation.

**Last Updated**: 2025-11-28

---

## Overview

This document maps ALL console controllers to their CLI options/flags, with specific focus on:
- **`--yes`** flags for non-interactive automation
- **`--dryRun`** flags and their default values
- **Other important flags** (limit, force, backup, etc.)

**Total Controllers**: 20
**Controllers with `--dryRun`**: 14
**Controllers with `--yes`**: 13

---

## Web Interface Automation Rules

### When to Inject `--yes`

The web interface should **automatically inject `--yes=1`** for commands that:
1. Prompt for user confirmation
2. Perform destructive operations
3. Need to run continuously without manual intervention

### When to Inject `--dryRun=0`

The web interface should **allow users to toggle between dry-run and live mode**:
- **Dry Run Mode** (default safe): `--dryRun=1` (preview only, no changes)
- **Live Mode** (execute): `--dryRun=0` (apply changes)

For controllers where `$dryRun = false` is the default, dry-run must be **explicitly enabled** with `--dryRun=1`.

---

## Complete Controller Mapping

### 1. ImageMigrationController

**Module IDs**: `image-migration`, `image-migration-status`, `image-migration-monitor`, `image-migration-cleanup`, `image-migration-force-cleanup`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `migrate` | ✅ | `$dryRun=false`<br>`$skipBackup=false`<br>`$skipInlineDetection=false`<br>`$resume=false`<br>`$checkpointId=null`<br>`$skipLock=false`<br>`$yes=false` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live<br>`--yes=1 --dryRun=1` for preview |
| `rollback` | | `$dryRun=false`<br>`$yes=false` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |
| `status` | | None | ❌ NO | ❌ NO | None |
| `monitor` | | None | ❌ NO | ❌ NO | None |
| `cleanup` | | `$yes=false`<br>`$olderThanHours=null` | ✅ YES | ❌ NO | `--yes=1` |
| `force-cleanup` | | `$yes=false` | ✅ YES | ❌ NO | `--yes=1` |

**Notes**:
- `migrate` is the default action
- `--skipBackup=1` can be used to skip database backup (not recommended)
- `--resume=1` to resume from last checkpoint
- `--skipLock=1` to bypass migration lock (dangerous)

---

### 2. MigrationCheckController

**Module IDs**: `migration-check`, `migration-check-analyze`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `check` | ✅ | None | ❌ NO | ❌ NO | None |
| `analyze` | | None | ❌ NO | ❌ NO | None |

**Notes**: Read-only checks, no automation flags needed.

---

### 3. FilesystemController

**Module IDs**: `filesystem`, `filesystem-list`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `create` | | `$force=false` | ❌ NO | ❌ NO | None |
| `list` | | `$force=false` | ❌ NO | ❌ NO | None |
| `delete` | | `$yes=false` | ✅ YES | ❌ NO | `--yes=1` |

**Notes**:
- `create` uses interactive prompts but does NOT have `--yes` flag (must run via CLI)
- `delete` requires explicit confirmation
- `$force` is available but rarely used

---

### 4. FilesystemSwitchController

**Module IDs**: `switch-to-do`, `switch-to-aws`, `switch-preview`, `switch-list`, `switch-test`, `switch-verify`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `preview` | ✅ | None | ❌ NO | ❌ NO | None |
| `to-do` | | `$yes=false` | ✅ YES | ❌ NO | `--yes=1` |
| `to-aws` | | `$yes=false` | ✅ YES | ❌ NO | `--yes=1` |
| `list-filesystems` | | None | ❌ NO | ❌ NO | None |
| `test-connectivity` | | None | ❌ NO | ❌ NO | None |
| `verify` | | None | ❌ NO | ❌ NO | None |

**Notes**:
- `to-do` and `to-aws` are CRITICAL operations requiring confirmation
- `preview` is safe (read-only)

---

### 5. FilesystemFixController

**Module IDs**: `filesystem-fix`, `filesystem-show`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `fix-endpoints` | ✅ | None | ❌ NO | ❌ NO | None |
| `show` | | None | ❌ NO | ❌ NO | None |

**Notes**: Automatic fixes, no confirmation needed.

---

### 6. FsDiagController

**Module IDs**: `fs-diag-list`, `fs-diag-compare`, `fs-diag-search`, `fs-diag-verify`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `list` | ✅ | None | ❌ NO | ❌ NO | None |
| `list-fs` | | `$path=''`<br>`$recursive=true`<br>`$limit=(config)` | ❌ NO | ❌ NO | None |
| `search-fs` | | `$path=''`<br>`$filename=''` | ❌ NO | ❌ NO | None |
| `compare-fs` | | `$path=''` | ❌ NO | ❌ NO | None |
| `verify-fs` | | None | ❌ NO | ❌ NO | None |

**Notes**: Read-only diagnostics, no automation flags needed.

---

### 7. VolumeConfigController

**Module IDs**: `volume-config`, `volume-config-status`, `volume-config-quarantine`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `status` | ✅ | None | ❌ NO | ❌ NO | None |
| `set-transform-filesystem` | | `$dryRun=false`<br>`$yes=false` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |
| `add-optimised-field` | | `$dryRun=false`<br>`$yes=false`<br>`$volumeHandle=null` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |
| `create-quarantine-volume` | | `$dryRun=false`<br>`$yes=false` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |
| `configure-all` | | `$dryRun=false`<br>`$yes=false` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |

**Notes**: All destructive actions have both `--yes` and `--dryRun` support.

---

### 8. VolumeConsolidationController

**Module IDs**: `volume-consolidation-status`, `volume-consolidation-merge`, `volume-consolidation-flatten`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `status` | | None | ❌ NO | ❌ NO | None |
| `merge-optimized-to-images` | ✅ | `$dryRun=false`<br>`$yes=false`<br>`$batchSize=100` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |
| `flatten-to-root` | | `$dryRun=false`<br>`$yes=false`<br>`$batchSize=100`<br>`$volumeHandle='images'` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |

**Notes**: Both operations support batch processing with configurable batch size.

---

### 9. UrlReplacementController

**Module IDs**: `url-replacement`, `url-replacement-config`, `url-replacement-verify`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `replace-s3-urls` | ✅ | `$dryRun=false`<br>`$yes=false`<br>`$newUrl=null` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |
| `show-config` | | None | ❌ NO | ❌ NO | None |
| `verify` | | None | ❌ NO | ❌ NO | None |

**Notes**:
- `replace-s3-urls` modifies database content
- `--newUrl` can override configured target URL

---

### 10. TransformDiscoveryController

**Module IDs**: `transform-discovery-all`, `transform-discovery-db`, `transform-discovery-templates`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `discover` | ✅ | None | ❌ NO | ❌ NO | None |
| `scan-database` | | None | ❌ NO | ❌ NO | None |
| `scan-templates` | | None | ❌ NO | ❌ NO | None |

**Notes**: Read-only scanning, no automation flags needed.

---

### 11. TransformPreGenerationController

**Module IDs**: `transform-pregeneration`, `transform-pregeneration-verify`, `transform-pregeneration-warmup`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `generate` | ✅ | `$dryRun=false`<br>`$yes=false`<br>`$batchSize=(config)`<br>`$maxConcurrent=(config)`<br>`$force=false`<br>`$reportFile=null` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |
| `verify` | | `$reportFile=null` | ❌ NO | ❌ NO | None |
| `warmup` | | `$yes=false` | ✅ YES | ❌ NO | `--yes=1` |

**Notes**:
- `generate` supports checkpointing for large datasets
- `--force=1` to regenerate existing transforms
- `warmup` crawls pages to trigger transforms

---

### 12. TransformCleanupController

**Module IDs**: `transform-cleanup`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `clean` | ✅ | `$dryRun=false`<br>`$volumeHandle='optimisedImages'`<br>`$volumeId=4` | ❌ NO | ✅ YES | `--dryRun=0` for live |

**Notes**:
- NO `--yes` flag (automatically cleans without prompt)
- `$dryRun` defaults to `false` but is normalized in `beforeAction()`

---

### 13. MigrationDiagController

**Module IDs**: `migration-diag`, `migration-diag-missing`, `migration-diag-move`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `analyze` | ✅ | None | ❌ NO | ❌ NO | None |
| `check-missing-files` | | None | ❌ NO | ❌ NO | None |
| `move-originals` | | `$dryRun=false` | ❌ NO | ✅ YES | `--dryRun=0` for live |

**Notes**:
- `analyze` is read-only
- `move-originals` moves files but has NO `--yes` flag

---

### 14. ProviderTestController

**Module IDs**: (not used in dashboard - testing only)

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `test-all` | ✅ | None | ❌ NO | ❌ NO | None |
| `test-source` | | None | ❌ NO | ❌ NO | None |
| `test-target` | | None | ❌ NO | ❌ NO | None |
| `list-files` | | `$limit=10`<br>`$provider='source'` | ❌ NO | ❌ NO | None |
| `copy-test` | | `$sourcePath=''`<br>`$targetPath=''` | ❌ NO | ❌ NO | None |

**Notes**: Testing controllers, no web interface exposure.

---

### 15. ExtendedUrlReplacementController

**Module IDs**: `extended-url-scan`, `extended-url`, `extended-url-json`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `scan-additional` | ✅ | None | ❌ NO | ❌ NO | None |
| `replace-additional` | | `$dryRun=false`<br>`$yes=false` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |
| `replace-json` | | `$dryRun=false`<br>`$yes=false` | ❌ NO | ✅ YES | `--dryRun=0` for live |

**Notes**:
- `replace-additional` requires confirmation
- `replace-json` has `--yes` in code but doesn't prompt (safe to omit)

---

### 16. TemplateUrlReplacementController

**Module IDs**: `template-scan`, `template-replace`, `template-verify`, `template-restore`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `scan` | ✅ | `$dryRun=false`<br>`$envVar='DO_S3_BASE_URL'`<br>`$backup=true` | ❌ NO | ✅ YES | `--dryRun=0` for live |
| `replace` | | `$dryRun=false`<br>`$yes=false`<br>`$envVar='DO_S3_BASE_URL'`<br>`$backup=true` | ✅ YES | ✅ YES | `--yes=1 --dryRun=0` for live |
| `verify` | | None | ❌ NO | ❌ NO | None |
| `restore-backups` | | `$yes=false` | ✅ YES | ❌ NO | `--yes=1` |

**Notes**:
- `replace` modifies template files (creates backups by default)
- `--backup=0` to skip creating backups (not recommended)

---

### 17. StaticAssetScanController

**Module IDs**: `static-asset-scan`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `scan` | ✅ | None | ❌ NO | ❌ NO | None |

**Notes**: Read-only scanning, no automation flags needed.

---

### 18. DashboardMaintenanceController

**Module IDs**: (not in dashboard - maintenance only)

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `purge-state` | | `$maxAge=604800`<br>`$force=false` | ❌ NO | ❌ NO | None |
| `reset-module-progress` | | None | ❌ NO | ❌ NO | None |

**Notes**: Maintenance commands, not exposed in dashboard.

---

### 19. PluginConfigAuditController

**Module IDs**: `plugin-config-audit`, `plugin-config-audit-list`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `scan` | ✅ | None | ❌ NO | ❌ NO | None |
| `list-plugins` | | None | ❌ NO | ❌ NO | None |

**Notes**: Read-only audit, no automation flags needed.

---

### 20. MissingFileFixController

**Module IDs**: `missing-file-fix-analyze`, `missing-file-fix-fix`

| Action | Default Action? | Options | Needs --yes? | Has --dryRun? | Web Interface Flags |
|--------|----------------|---------|--------------|---------------|---------------------|
| `analyze` | ✅ | `$dryRun=true`<br>`$yes=false` | ❌ NO | ✅ YES | None (read-only) |
| `fix` | | `$dryRun=true`<br>`$yes=false` | ❌ NO | ✅ YES | `--yes=1 --dryRun=0` for live |

**Notes**:
- **UNIQUE**: `$dryRun` defaults to `true` (safe by default)
- Must explicitly use `--dryRun=0` to apply changes
- Has `--yes` flag but doesn't prompt (safe to include)

---

## Web Interface Implementation Guide

### Recommended Flag Injection Logic

```php
// Pseudo-code for web interface command builder

function buildCommand($moduleId, $action, $mode = 'preview') {
    $controller = getControllerForModule($moduleId);
    $command = "./craft spaghetti-migrator/{$controller}/{$action}";

    // Check if command needs --yes for automation
    if (needsYesFlag($controller, $action)) {
        $command .= " --yes=1";
    }

    // Check if command supports dry-run
    if (supportsDryRun($controller, $action)) {
        if ($mode === 'live') {
            $command .= " --dryRun=0";
        } else {
            $command .= " --dryRun=1";
        }
    }

    return $command;
}
```

### Controllers Requiring `--yes=1` for Web Automation

1. **ImageMigrationController**
   - `migrate`, `rollback`, `cleanup`, `force-cleanup`

2. **FilesystemController**
   - `delete`

3. **FilesystemSwitchController**
   - `to-do`, `to-aws`

4. **VolumeConfigController**
   - `set-transform-filesystem`, `add-optimised-field`, `create-quarantine-volume`, `configure-all`

5. **VolumeConsolidationController**
   - `merge-optimized-to-images`, `flatten-to-root`

6. **UrlReplacementController**
   - `replace-s3-urls`

7. **ExtendedUrlReplacementController**
   - `replace-additional`

8. **TemplateUrlReplacementController**
   - `replace`, `restore-backups`

9. **TransformPreGenerationController**
   - `generate`, `warmup`

### Controllers with `--dryRun` Support

| Controller | Actions | Default | Web Interface Behavior |
|------------|---------|---------|----------------------|
| ImageMigrationController | migrate, rollback | `false` | Toggle: `--dryRun=1` (preview) / `--dryRun=0` (live) |
| VolumeConfigController | set-transform-filesystem, add-optimised-field, create-quarantine-volume, configure-all | `false` | Toggle: `--dryRun=1` / `--dryRun=0` |
| VolumeConsolidationController | merge-optimized-to-images, flatten-to-root | `false` | Toggle: `--dryRun=1` / `--dryRun=0` |
| UrlReplacementController | replace-s3-urls | `false` | Toggle: `--dryRun=1` / `--dryRun=0` |
| TransformPreGenerationController | generate | `false` | Toggle: `--dryRun=1` / `--dryRun=0` |
| TransformCleanupController | clean | `false` | Toggle: `--dryRun=1` / `--dryRun=0` |
| MigrationDiagController | move-originals | `false` | Toggle: `--dryRun=1` / `--dryRun=0` |
| ExtendedUrlReplacementController | replace-additional, replace-json | `false` | Toggle: `--dryRun=1` / `--dryRun=0` |
| TemplateUrlReplacementController | scan, replace | `false` | Toggle: `--dryRun=1` / `--dryRun=0` |
| **MissingFileFixController** | analyze, fix | **`true`** | **REVERSED**: `--dryRun=0` (live) / `--dryRun=1` (preview) |

**IMPORTANT**: MissingFileFixController is the ONLY controller where `$dryRun` defaults to `true`.

---

## Quick Reference: Module ID to Command Mapping

| Module ID | Controller | Action | Auto-inject Flags |
|-----------|-----------|--------|------------------|
| `filesystem` | filesystem | create | None |
| `filesystem-list` | filesystem | list | None |
| `filesystem-fix` | filesystem-fix | fix-endpoints | None |
| `filesystem-show` | filesystem-fix | show | None |
| `volume-config-status` | volume-config | status | None |
| `volume-config` | volume-config | configure-all | `--yes=1 --dryRun=0` |
| `volume-config-quarantine` | volume-config | create-quarantine-volume | `--yes=1 --dryRun=0` |
| `migration-check` | migration-check | check | None |
| `migration-check-analyze` | migration-check | analyze | None |
| `url-replacement-config` | url-replacement | show-config | None |
| `url-replacement` | url-replacement | replace-s3-urls | `--yes=1 --dryRun=0` |
| `url-replacement-verify` | url-replacement | verify | None |
| `extended-url-scan` | extended-url-replacement | scan-additional | None |
| `extended-url` | extended-url-replacement | replace-additional | `--yes=1 --dryRun=0` |
| `extended-url-json` | extended-url-replacement | replace-json | `--dryRun=0` |
| `template-scan` | template-url-replacement | scan | `--dryRun=0` |
| `template-replace` | template-url-replacement | replace | `--yes=1 --dryRun=0` |
| `template-verify` | template-url-replacement | verify | None |
| `template-restore` | template-url-replacement | restore-backups | `--yes=1` |
| `switch-list` | filesystem-switch | list-filesystems | None |
| `switch-test` | filesystem-switch | test-connectivity | None |
| `switch-preview` | filesystem-switch | preview | None |
| `switch-to-do` | filesystem-switch | to-do | `--yes=1` |
| `switch-verify` | filesystem-switch | verify | None |
| `switch-to-aws` | filesystem-switch | to-aws | `--yes=1` |
| `transform-cleanup` | transform-cleanup | clean | `--dryRun=0` |
| `image-migration-status` | image-migration | status | None |
| `image-migration` | image-migration | migrate | `--yes=1 --dryRun=0` |
| `image-migration-monitor` | image-migration | monitor | None |
| `image-migration-cleanup` | image-migration | cleanup | `--yes=1` |
| `image-migration-force-cleanup` | image-migration | force-cleanup | `--yes=1` |
| `migration-diag` | migration-diag | analyze | None |
| `migration-diag-missing` | migration-diag | check-missing-files | None |
| `migration-diag-move` | migration-diag | move-originals | `--dryRun=0` |
| `volume-consolidation-status` | volume-consolidation | status | None |
| `volume-consolidation-merge` | volume-consolidation | merge-optimized-to-images | `--yes=1 --dryRun=0` |
| `volume-consolidation-flatten` | volume-consolidation | flatten-to-root | `--yes=1 --dryRun=0` |
| `transform-discovery-all` | transform-discovery | discover | None |
| `transform-discovery-db` | transform-discovery | scan-database | None |
| `transform-discovery-templates` | transform-discovery | scan-templates | None |
| `transform-pregeneration` | transform-pre-generation | generate | `--yes=1 --dryRun=0` |
| `transform-pregeneration-verify` | transform-pre-generation | verify | None |
| `transform-pregeneration-warmup` | transform-pre-generation | warmup | `--yes=1` |
| `missing-file-fix-analyze` | missing-file-fix | analyze | None (read-only) |
| `missing-file-fix-fix` | missing-file-fix | fix | `--yes=1 --dryRun=0` |
| `plugin-config-audit-list` | plugin-config-audit | list-plugins | None |
| `plugin-config-audit` | plugin-config-audit | scan | None |
| `static-asset-scan` | static-asset-scan | scan | None |
| `fs-diag-list` | fs-diag | list-fs | None |
| `fs-diag-compare` | fs-diag | compare-fs | None |
| `fs-diag-search` | fs-diag | search-fs | None |
| `fs-diag-verify` | fs-diag | verify-fs | None |

---

## Testing Recommendations

### Always Test with Dry-Run First

For every command that supports `--dryRun`:
1. **First run**: `--dryRun=1` to preview changes
2. **Review output**: Check what will be changed
3. **Second run**: `--dryRun=0` to apply changes

### Web Interface UX Recommendations

1. **Toggle Switch**: Provide "Preview Mode" / "Live Mode" toggle
2. **Visual Warnings**: Show prominent warning when switching to Live Mode
3. **Confirmation Modal**: Require extra confirmation for CRITICAL operations:
   - `switch-to-do`, `switch-to-aws`
   - `image-migration/migrate`
   - `url-replacement/replace-s3-urls`
4. **Progress Tracking**: Show real-time progress for long-running commands
5. **Log Output**: Display full command output in web interface

---

## Summary

**Key Points for Web Interface Developers**:

1. ✅ **Always inject `--yes=1`** for 13 controllers that prompt for confirmation
2. ✅ **Provide dry-run toggle** for 14 controllers that support `--dryRun`
3. ✅ **Default to safe mode**: Use `--dryRun=1` by default, require explicit user action for `--dryRun=0`
4. ⚠️ **Exception**: MissingFileFixController defaults to `$dryRun=true` (already safe)
5. 🚨 **Extra confirmation**: Require double-confirmation for critical operations

**For each command execution**:
```
1. Build base command: `./craft spaghetti-migrator/{controller}/{action}`
2. If needs automation: append `--yes=1`
3. If supports dry-run: append `--dryRun=0` for live or `--dryRun=1` for preview
4. Execute and stream output to web interface
```

---

**Maintained by**: Christian Sabourin (christian@sabourin.ca)
**Repository**: https://github.com/csabourin/do-migration
**Version**: 1.0
