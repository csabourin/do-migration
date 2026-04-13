# User Story: Safe Asset Migration and Cleanup

## Scope Note

The provider layer in this plugin supports multiple storage backends in v5.0, but the implemented dashboard journey in this repository is still primarily written around an AWS S3 -> DigitalOcean Spaces cutover. This story reflects that actual code path and the surrounding safety model.

## Primary User Story

As a Craft CMS administrator responsible for a live site whose assets currently live in AWS S3 and whose asset library has become difficult to manage, I want to pre-seed DigitalOcean Spaces, switch Craft to the new filesystem at the right moment, reorganize and clean the files there, replace old AWS URLs, and verify the result, so that I can complete the migration with minimal downtime, preserve a fast rollback path, and end up with a clean, reliable asset setup.

## Why the User Is Doing This

- They need to move asset storage from AWS S3 to DigitalOcean Spaces, or to a new provider with a similar workflow.
- Their current asset estate is "spaghetti": nested folders, duplicate files, broken asset-to-file relationships, inline images that are not properly linked, orphaned files, and hardcoded old URLs.
- They need a production-safe process: dry runs first, strict phase ordering, backups, checkpoint/resume, quarantine instead of risky deletion, and rollback if the cutover goes wrong.

## What the User Tries to Do

The user follows the plugin as an operational runbook, mostly through the Control Panel dashboard and backed by console commands.

1. They install and configure the plugin, the DigitalOcean Spaces support, credentials, and `rclone`.
2. They run an initial bulk sync outside Craft from AWS to DigitalOcean Spaces. In this codebase, that external sync is the real cross-provider file copy.
3. They create backups and disable other asset-processing plugins so nothing mutates files during migration.
4. They open the dashboard, which shows the required phases and tracks progress.
5. They create the target filesystems in Craft, configure volumes, and ensure there is a separate quarantine volume.
6. They run pre-flight checks to confirm configuration, connectivity, filesystem access, write permissions, and other prerequisites.
7. Just before cutover, they run a second `rclone` sync so recently uploaded files are also present in the target bucket.
8. They switch Craft's filesystems from AWS to DigitalOcean Spaces. This is the cutover point: AWS is frozen as a rollback source, while Craft now points at DO Spaces.
9. They run the main migration command. In this implementation, that command is not the AWS -> DO copy. It is an in-place cleanup and reconciliation process inside the target storage:
   - discover assets and files
   - link inline images
   - stage and resolve duplicates safely
   - fix broken asset-file links
   - consolidate files into final locations
   - rescue false positives before quarantine
   - quarantine unused assets and orphaned files on a separate filesystem
   - verify results and finalize filesystem subfolder settings
10. They run any required volume-consolidation tasks before URL replacement so file locations are final.
11. They replace old AWS URLs in the database and then update templates so references match the new storage and final file paths.
12. They run post-migration validation, required Craft maintenance commands, transform discovery/generation/verification, and optional audits for anything that still needs attention.

## What Happens in the System

- Access is restricted to administrators, and mutating actions require config changes to be allowed.
- The dashboard persists progress, warns about workflow order, and keeps the Control Panel responsive by using queue jobs or streaming for long-running commands.
- Commands are validated against an allowlist, and arguments are validated before execution.
- Critical steps require explicit confirmation.
- Dry-run mode is treated as analysis-only and avoids side effects.
- The main migration acquires a lock so two migrations cannot run at the same time.
- The migration services validate the source, target, and quarantine volumes and verify the target and quarantine filesystems are accessible and writable.
- A backup is created unless the operator explicitly skips it.
- The orchestrator keeps checkpoints, quick-resume state, and change logs so long migrations can survive interruptions.
- If the migration stops mid-stream, the user can resume from the saved phase instead of restarting from scratch.
- Unused assets and orphaned files are quarantined rather than immediately destroyed, which lowers recovery risk.
- Verification queues asset reindexing, checks that files exist where Craft expects them, and records any remaining issues for review.
- If the cutover fails badly, the user can switch filesystems back to AWS quickly. More detailed rollback paths also exist through database backups and change-by-change rollback support.

## What the User Experiences

From the user's point of view, this plugin is a guarded migration cockpit rather than a one-click copier. The user is trying to move a live Craft CMS asset estate to a new storage backend without losing files, breaking references, or painting themselves into a corner.

The plugin makes that possible by forcing an order of operations:

1. Prepare and validate.
2. Copy externally.
3. Cut Craft over.
4. Reconcile and clean the files already in the new location.
5. Only then rewrite URLs and regenerate transforms.

If everything goes well, the site ends with Craft pointed at the new storage, files organized into their intended structure, duplicate and broken references cleaned up, old URLs replaced, and follow-up diagnostics available. If something goes wrong, the user is meant to pause, inspect logs and checkpoints, resume safely, quarantine suspicious data, or roll back.

## Code Areas This Story Was Derived From

- `modules/controllers/MigrationController.php`
- `modules/services/ModuleDefinitionProvider.php`
- `modules/services/MigrationStateManager.php`
- `modules/templates/spaghetti-migrator/dashboard.twig`
- `modules/templates/spaghetti-migrator/js/dashboard.js`
- `modules/console/controllers/ImageMigrationController.php`
- `modules/services/MigrationOrchestrator.php`
- `modules/services/migration/ValidationService.php`
- `modules/services/migration/VerificationService.php`
- `modules/services/CheckpointManager.php`
- `modules/services/RollbackEngine.php`
