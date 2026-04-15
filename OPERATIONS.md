# Operations Guide

This guide consolidates the operational playbooks for running the S3 → Spaces migration, including dashboard usage, live command execution, volume consolidation, and troubleshooting steps. All workflows described here are available in release 1.0.

## Control Panel Dashboard
- **Access**: Control Panel → Migration or visit `/admin/spaghetti-migrator/migration`.
- **What you get**: configuration health, checkpoint resume banner, and eleven workflow sections covering prerequisites, setup, checks, filesystem switching, file cleanup, consolidation, URL/template updates, validation, transforms, and audit diagnostics.
- **Commands**: Each module card shows duration, dry-run support, and links directly to the relevant console command.
- **Execution model**: Commands stream live output in the dashboard. Long-running migrations can detach into background monitoring without switching to a separate execution flow.

## Dashboard Execution
- **Live output**: The dashboard starts commands with streamed output so operators can review progress, warnings, and command logs in one place.
- **Detached monitoring**: Very long-running migrations can detach while the dashboard continues polling the stored migration state and consolidated logs.
- **Cancellation**: The cancel button sends a stop signal and the migration exits at the next safe interruption point or checkpoint.

## Volume Consolidation
- **Use case**: Buckets where the `optimisedImages` volume sits at bucket root and other volumes exist as subfolders.
- **Workflow**:
  1. Diagnose state: `./craft spaghetti-migrator/migration-diag/analyze` and `./craft spaghetti-migrator/volume-consolidation/status`.
  2. Merge `optimisedImages` → `images`: `./craft spaghetti-migrator/volume-consolidation/merge-optimized-to-images --dryRun=1` then `--dryRun=0` when ready. Handles database associations, renames duplicates, and keeps operations batched.
  3. Flatten subfolders: `./craft spaghetti-migrator/volume-consolidation/flatten-to-root --dryRun=1` to preview and `--dryRun=0` to apply. Moves assets from subfolders (including `/originals/`) to the root of the target volume with conflict-safe renames.
- **Dashboard support**: Consolidation commands appear in the dashboard with dry-run toggles and machine-readable exit markers for accurate status reporting.

## Originals-First Strategy
- The migration prioritizes original files whenever multiple candidates exist. Paths containing `originals/` are preferred so Craft can regenerate transforms after files move to DigitalOcean Spaces.
- Asset records updated during consolidation or migration point originals to the primary volume root to keep file organization clean.

## Troubleshooting Missing Files
Two common scenarios can surface missing files during migration. The diagnostics below help distinguish and resolve them.

### Scenario A: Files exist in bucket but scanner reports them missing
- **Cause**: Volume filesystem paths omit a bucket prefix.
- **Fix**: Update each filesystem subfolder (Craft CP → Settings → Assets → Filesystems) to include the bucket prefix such as `ncc-website-2/images`. Re-run `./craft spaghetti-migrator/migration-diag/check-volumes` to verify.
- **Environment variable option**: Define prefixed paths (e.g., `AWS_SOURCE_SUBFOLDER_IMAGES='ncc-website-2/images'`) and reference them in filesystem settings.

### Scenario B: Scanner finds every file but asset records still show missing items
- **Cause**: Asset database metadata does not line up with file locations (wrong volume, folder path, duplicates, or orphaned files).
- **Diagnostics**:
  - `./craft spaghetti-migrator/migration-diag/diagnose` for a full asset/filesystem comparison.
  - `./craft spaghetti-migrator/migration-diag/check-volumes` to confirm filesystem subfolders.
  - `./check-missing-files-sql.sh` for direct SQL output if PHP scripts are unavailable.
- **Resolutions**:
  - Correct asset `volumeId` or `folderId` via SQL when records point to the wrong volume or path.
  - Use multiple source volumes or consolidate files when duplicates exist across folders.
  - Leave orphaned files quarantined; they can be re-imported later if needed.

## Quick Reference
- **Pre-flight validation**: `./craft spaghetti-migrator/migration-check/check`
- **Dry-run migration**: `./craft spaghetti-migrator/image-migration/migrate --dryRun=1`
- **Production migration**: `./craft spaghetti-migrator/image-migration/migrate`
- **Filesystem switch**: `./craft spaghetti-migrator/filesystem-switch/to-do`
- **Diagnostics**: `./craft spaghetti-migrator/migration-diag/diagnose`

For architectural details, see `ARCHITECTURE.md`. For contribution and security policies, see `CONTRIBUTING.md` and `SECURITY.md`.
