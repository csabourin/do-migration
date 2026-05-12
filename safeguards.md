# Migration and Cleanup Safeguards

This file summarizes the safeguards documented across the Spaghetti Migrator
guides that are intended to prevent data loss while migrating, reorganizing, and
cleaning up Craft CMS assets.

## Operational Safeguards

1. **Development and staging validation first**
   - Migrations should be tested outside production before a live cutover.
   - Dry runs, rollback tests, and small-dataset tests are recommended before a
     full migration.

2. **Maintenance window planning**
   - Production migrations should run during a planned maintenance window.
   - Operators should monitor logs, progress state, queue status, and system
     resources during the migration.

3. **Database backups before mutation**
   - A full database backup is required before production migration work.
   - The migration command creates an automatic backup unless `skipBackup=1` is
     explicitly used.
   - Production documentation warns not to use `skipBackup=1` in production.

4. **Configuration backups**
   - Current migration configuration should be backed up before changing provider
     settings or URL mappings.

5. **Asset-processing plugins disabled during migration**
   - Image optimization, resizing, transform, compression, and similar plugins
     should be disabled until the image-transform phase is complete.
   - This prevents third-party plugins from mutating assets while the migrator is
     reconciling files and records.

6. **External source retained during cutover**
   - The AWS source remains available as the rollback source during the
     transition.
   - The CAB/RFC process documents temporary static-file redirects from AWS to
     the target provider during the transition period.

## Pre-Execution Safeguards

1. **Pre-flight validation**
   - `migration-check/check` validates configuration, provider connectivity,
     credentials, permissions, volume settings, PHP/Craft environment, transform
     filesystem configuration, and related prerequisites.
   - Critical errors must be resolved before proceeding.

2. **Provider connection tests**
   - `provider-test/test-source`, `provider-test/test-target`, and
     `provider-test/test-all` verify source and target provider access before
     migration.
   - `provider-test/copy-test` verifies a single copy path before large-scale
     transfer.

3. **Filesystem diagnostics**
   - `fs-diag` and `migration-diag` commands compare filesystem state, detect
     missing files, and confirm volume paths before mutation.

4. **Filesystem switch preview**
   - `filesystem-switch/preview` shows volume switch changes before applying
     them.
   - `filesystem-switch/verify` and `filesystem-switch/test-connectivity`
     confirm the switched configuration.

5. **URL replacement preview and verification**
   - URL replacement commands support dry-run behavior and expose configuration
     and verification commands before and after database changes.

6. **Template scan before replacement**
   - Template URL replacement can scan for hardcoded URLs before modifying Twig
     files.
   - Template replacement creates backups by default.

7. **Separate quarantine filesystem enforcement**
   - The migration validation service fails if the quarantine volume uses the
     same filesystem handle as the target volume.
   - This keeps quarantined files physically separated from the final target
     asset filesystem.

8. **Live filesystem write probe**
   - The health check writes and deletes a temporary test file on the target
     filesystem before migration proceeds.
   - A target filesystem that can list files but cannot write files is treated as
     a critical failure.

9. **Target root-folder validation**
   - The orchestrator fails during preparation if Craft cannot resolve the root
     folder for the target volume.

## Dry-Run and Confirmation Safeguards

1. **Dry-run mode**
   - Destructive or mutating commands support `--dryRun=1` previews where
     documented, including migration, rollback, volume configuration, volume
     consolidation, URL replacement, transform generation, transform cleanup,
     template replacement, diagnostics moves, extended URL replacement, and
     missing-file fixes.
   - Dry-run mode is required to remain side-effect-free.

2. **Safe defaults for missing-file fixes**
   - `MissingFileFixController` defaults to `dryRun=true`, so fixes must be
     explicitly applied with `--dryRun=0`.

3. **Explicit confirmations**
   - Critical commands use confirmation flags such as `--yes=1` or
     `--confirm=1`.
   - The dashboard requires extra confirmation for critical operations such as
     filesystem switches, file migration, and URL replacement.

4. **Dashboard live-mode warnings**
   - The web interface is expected to default to preview mode for commands with
     dry-run support and require explicit action for live mode.

## Workflow-Ordering Safeguards

1. **Mandatory dashboard phase ordering**
   - The dashboard validates workflow dependencies and prevents out-of-order
     execution.
   - The critical documented order is filesystem switch before file migration in
     the dashboard workflow.

2. **Phase-specific verification**
   - Operators are instructed to verify each critical phase before moving to the
     next one.
   - File locations should be finalized through migration and consolidation
     before database and template URLs are rewritten.

3. **Cutover sequencing**
   - The documented safe sequence is prepare, validate, externally sync, switch
     Craft to the target filesystem, reconcile and clean files on the target,
     then replace URLs and regenerate transforms.

## Runtime Migration Safeguards

1. **Migration lock**
   - The main migration acquires a lock so two migrations cannot run at the same
     time.
   - Deadlock handling and backoff are documented for lock acquisition.

2. **Checkpoint and resume**
   - Checkpoints store progress, processed asset IDs, processed file keys,
     failed operations, and configuration snapshots.
   - Interrupted migrations can resume from the latest checkpoint instead of
     restarting.
   - Already processed items are skipped during resume.

3. **Quick-resume state**
   - Migration state is persisted so long-running commands can survive
     interruption and dashboard refreshes.

4. **Change logs for rollback**
   - Mutating operations are logged to changelogs.
   - Changelogs capture old and new asset values, file paths, volume/folder
     changes, database changes, and enough context to reverse operations.

5. **Database transactions**
   - Related database updates are documented as transaction-protected so partial
     failures can roll back the current operation.

6. **Batch processing**
   - Large asset sets are processed in batches to avoid memory exhaustion and to
     create regular recovery points.

7. **Retry logic with backoff**
   - Recoverable provider, network, and copy errors are retried before being
     marked failed.

8. **Error thresholds**
   - Error categories and thresholds stop the migration when unexpected or
     critical failures exceed configured limits.
   - Known missing files can be accounted for separately from unexpected
     failures.

9. **File integrity checks**
   - The migration verifies copied files, including size checks and documented
     checksum/integrity validation expectations.

10. **Idempotent operations**
    - Migration operations are documented as safe to run multiple times, with
      resume and skip logic preventing duplicate work.

11. **Progress tracking and monitoring**
    - Progress, ETA, processed counts, failures, retries, and logs are visible
      from CLI and dashboard monitoring.

12. **Non-blocking dashboard execution**
    - Dashboard commands run through Craft Queue plus polling so the Control
      Panel remains responsive and progress survives page refreshes.

13. **Dry-run lock bypass**
    - Dry runs skip migration-lock acquisition because they are intended to be
      read-only.

14. **Skipped-backup delay warning**
    - If a live migration explicitly skips the automatic backup, the
      orchestrator prints a high-severity warning and waits 10 seconds before
      continuing, giving the operator a chance to cancel.

15. **Fatal-error checkpoint first**
    - On fatal errors, the orchestrator attempts to save a resumable checkpoint
      before writing normal console output.
    - If checkpoint saving itself fails, the original and checkpoint errors are
      still written to Craft logs.

16. **Migration-specific error log**
    - File operation errors are appended to
      `@storage/migration-errors-<migrationId>.log` with operation type, message,
      context, and timestamp.

17. **Temporary file cleanup**
    - Temporary local files created during manual moves and cross-volume moves
      are tracked and removed in `finally` blocks.
    - An emergency cleanup method exists to release any remaining tracked temp
      files.

18. **Source deletion only after successful copy**
    - Source files are considered for deletion only after the destination asset
      has been saved successfully.
    - Deletion failures are logged as warnings and do not invalidate an already
      successful copy.

19. **Duplicate-copy suppression**
    - A source file already copied for one asset is tracked and skipped for
      later assets that reference the same source key.

20. **Safe source-deletion gate**
    - Source deletion is skipped when a duplicate record is not in a safe state,
      or when the source and destination point to the same physical file.

21. **Nested-filesystem temp-file path**
    - When source and target filesystems may overlap physically, the
      nested-filesystem service uses a local temporary file as an intermediary
      instead of direct remote-to-remote moves.
    - The temp file is validated to live under the system temp directory and is
      unlinked on success or failure.

22. **Optimised-images sync guard**
    - Phase 0.5 aborts live execution if it finds zero physical files for all
      optimised-images assets, which usually means the external sync was missed
      or incomplete.
    - Partial physical-file matches are surfaced as warnings before continuing.

23. **Post-write existence verification**
    - File moves that write to a target filesystem verify the target path exists
      after the write before treating the file operation as complete.

## Cleanup and Quarantine Safeguards

1. **Transform cleanup preview**
   - Transform cleanup supports `--dryRun=1` to preview files under
     underscore-prefixed transform folders before deletion.

2. **Transform cleanup audit reports**
   - Each transform cleanup run writes a JSON report under
     `storage/runtime/transform-cleanup/` so operators can audit targeted files.

3. **Transforms are treated as regenerable**
   - Cleanup targets generated transform files, not original source assets.
   - Transforms are regenerated after migration through discovery, generation,
     verification, and warmup commands.

4. **Originals-first strategy**
   - When multiple file candidates exist, paths containing `originals/` are
     preferred so Craft can regenerate transforms after migration.

5. **Canonical usage manifest before quarantine**
   - Before quarantine is allowed, the orchestrator writes a persistent usage
     manifest at `@storage/migration-usage-manifests/<migrationId>.json`.
   - The manifest combines Craft asset/file inventory, relation usage, Twig
     references, configured database content-column references, and static asset
     bundle references.

6. **Manifest persistence gate**
   - If the canonical usage manifest cannot be persisted, quarantine must not
     proceed.

7. **Protection for referenced files**
   - Files referenced outside Craft relations are marked protected before
     quarantine filtering runs.
   - Referenced files without Craft asset records are indexed only when safely
     identifiable by path.
   - Ambiguous or failed indexing cases remain protected and are flagged for
     manual review instead of being quarantined.

8. **Quarantine instead of deletion**
   - Unused assets and orphaned files are quarantined on a separate filesystem
     rather than immediately destroyed.
   - Quarantined files can be reviewed and re-imported later if needed.

9. **Duplicate-safe consolidation**
   - Volume consolidation commands run in batches, support dry runs, and use
     conflict-safe renames.
   - Duplicate asset merges preserve asset-owned metadata where documented and
     log scalar conflicts rather than silently discarding differences.

10. **Missing-file diagnostics before repair**
    - Missing-file workflows provide analysis and dry-run behavior before any fix
      is applied.

11. **Transform cleanup keeps linked asset files**
    - Transform cleanup builds an index of Craft asset paths in the target volume.
    - Files inside underscore-prefixed folders are kept when they are linked to
      an asset record.

12. **Transform cleanup deletes only empty directories**
    - Empty transform directories are considered for deletion only when no linked
      files were found in that directory.
    - The code checks that a directory has no remaining contents immediately
      before deleting it.

13. **Quarantine source-existence check**
    - Orphaned files are checked with `fileExists()` before read/write/delete
      quarantine work begins.
    - Missing files are counted and logged instead of causing blind delete
      attempts.

14. **Quarantine filename preservation**
    - The quarantine service records the original filename before moving an
      unused asset.
    - If Craft renames the file during the move, the service attempts to restore
      the original filename when the target path is available.

15. **Duplicate staging with hashes**
    - Duplicate physical files are copied to a migration-specific temp location
      in the quarantine filesystem before duplicate asset records are removed.
    - The duplicate record stores the temp path, file size, and MD5 hash for
      resumability and verification.

16. **Duplicate safety verification gate**
    - Duplicate cleanup fails with a critical error if any duplicate group is not
      staged or the staged file is missing.
    - Unsafe duplicate groups stop the migration before duplicate deletion can
      proceed.

17. **Primary asset selection rules**
    - Duplicate resolution chooses the primary asset using priority folder
      patterns, priority volume names, relation count, and finally first asset as
      a fallback.

18. **Referenced duplicate assets are kept**
    - Non-primary duplicate asset records are deleted only when they have no
      relations.
    - Assets with relations are kept rather than removed.

19. **Larger duplicate file preservation**
    - When a duplicate loser has a larger physical file than the winner, the
      service attempts to copy that larger file to the winner before deleting
      the loser asset record.

20. **Shared-file deletion awareness**
    - Before deleting a duplicate asset record, the service counts other asset
      records that reference the same physical file and logs shared-file
      preservation details.

21. **Flattenable-volume guard**
    - `flatten-to-root` is blocked unless the volume handle is explicitly listed
      in configured flattenable volumes.
    - A `--force=1` override exists, but it prints a warning that folder
      structure will be destroyed.

22. **Consolidation by database ownership**
    - Optimised-images consolidation filters by Craft `volumeId`, not just by
      files present in a filesystem directory.
    - This prevents files belonging to other volumes from being moved merely
      because they share the same bucket/root filesystem.

23. **Multi-location file lookup during consolidation**
    - Consolidation checks source, target, and quarantine locations before
      deciding a physical file is missing.
    - If the file is already present at the target, the copy is skipped instead
      of overwriting unnecessarily.

## Rollback Safeguards

1. **Fast filesystem rollback**
   - `filesystem-switch/to-aws` can switch Craft volumes back to the AWS
     filesystem if the target cutover fails.

2. **Database restore rollback**
   - Full database restore is the recommended fast rollback path when a complete
     revert is required and a backup exists.

3. **Change-by-change rollback**
   - Changelog-based rollback can preview and reverse individual migration
     operations.

4. **Rollback dry-run**
   - Rollback supports dry-run mode so operators can inspect planned rollback
     changes before applying them.

5. **Post-rollback validation**
   - Rollback procedures include checks for admin access, Asset Manager access,
     sample asset URLs, transforms, front-end 404s, database integrity, and logs.

6. **Backup file integrity validation**
   - Database rollback verifies that the backup file exists, is non-empty, is
     inside the expected `migration-backups` directory, has a `.sql` extension,
     and contains SQL-looking statements.

7. **Suspicious rollback SQL rejection**
   - Rollback rejects backup files containing suspicious SQL patterns such as
     file-read/write or command-execution functions.

8. **Transactional database restore wrapper**
   - Database rollback disables foreign key checks inside a controlled restore
     path, re-enables them in success and failure paths, and wraps restore work
     in a database transaction where available.

9. **Temporary credential file hardening**
   - MySQL rollback credentials are written to a temporary config file with
     `0600` permissions before content is written.
   - The temporary credential file is overwritten with zero bytes before it is
     removed.

## Post-Migration Verification Safeguards

1. **Diagnostic suite**
   - `migration-diag` validates migration results, checks for missing files, and
     produces diagnostic reports.

2. **Asset count checks**
   - Operators are instructed to compare asset counts across volumes.

3. **Sample asset testing**
   - Operators should manually test random sample assets and pages with many
     images.

4. **Asset Manager smoke tests**
   - Operators should verify that Asset Manager browsing, uploads, transforms,
     and deletion of a test asset work after migration.

5. **URL verification**
   - Database, template, and static asset scans verify that old provider URLs are
     removed or accounted for.

6. **Transform verification**
   - Transform discovery, generation, verification, and warmup commands confirm
     transformed images can be regenerated on the target provider.

7. **Log monitoring window**
   - Production guidance recommends monitoring application and migration logs for
     asset, image, and 404 errors for 24-48 hours after cutover.

8. **Checkpoint and changelog retention**
   - Checkpoints and changelogs should be kept until the migration is verified.
   - Changelogs are useful for rollback and audit trails.

## Security and Access Safeguards

1. **Environment-variable credentials**
   - Cloud credentials should come from environment variables and must not be
     committed.

2. **Least-privilege provider access**
   - IAM or provider keys should use only the permissions needed for migration.

3. **Authorized dashboard access**
   - Dashboard access should be limited to authorized administrators.

4. **CSRF and request validation**
   - Control Panel endpoints rely on Craft's CSRF protection and validate
     command requests.

5. **Command allowlisting**
   - Dashboard command execution validates commands against an allowlist before
     queueing or running them.
   - Direct command execution also validates argument names, sizes, and value
     types before running.

6. **Audit logging**
   - Migration operations, reports, checkpoints, and changelogs provide an audit
     trail for operational review.

7. **Admin changes gate**
   - Dashboard actions that mutate configuration or assets require Craft's
     `allowAdminChanges` setting to be enabled.

8. **Anonymous dashboard access disabled**
   - The migration dashboard controller does not allow anonymous access.

9. **Request payload limits**
   - Dashboard status and command endpoints validate JSON payloads, cap module
     arrays, cap argument arrays, validate ID/name formats, and limit string
     lengths.

10. **Shell-expansion avoidance**
    - Console commands are built as argument arrays for process execution rather
      than interpolated through a shell.
    - Argument names must match a strict pattern before execution.

11. **Sensitive command logging redaction**
    - Command logs redact password, key, secret, token, AWS credential, and
      DigitalOcean credential patterns before writing command strings to logs.

12. **Machine-readable exit markers**
    - Long-running command output includes `__CLI_EXIT_CODE_*__` markers and
      progress markers so the dashboard can distinguish successful completion
      from ambiguous process status.

## Safeguard Checklist

- Run provider tests and pre-flight checks.
- Back up the database and current migration configuration.
- Disable asset-processing plugins until transforms are complete.
- Run dry-run previews for every mutating command that supports them.
- Review transform cleanup reports before live cleanup.
- Confirm filesystem switch order and verify the switch before file migration.
- Keep AWS/source storage available during the transition.
- Monitor progress, logs, retries, and error thresholds during migration.
- Do not proceed with quarantine unless the canonical usage manifest is written.
- Quarantine uncertain files instead of deleting them.
- Keep checkpoints and changelogs until post-migration validation passes.
- Verify asset counts, sample assets, URLs, transforms, and logs after cutover.
