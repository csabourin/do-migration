# Codebase Review: Dead Code, Obsolete Comments, and Design Smells

## Scope and Approach
This review scanned the Craft S3→Spaces migration module and supporting scripts for functions, settings, and diagnostics that are defined but unused, have obsolete annotations, or reveal ineffective design patterns.

## Findings

### Unused duplicate-file discovery helper
- `DuplicateResolver::findAssetsPointingToSameFile()` builds a full in-memory map of all assets but is never referenced anywhere else in the repository, leaving the code idle and risking unnecessary resource use if called inadvertently.
- Consider removing the method or introducing a targeted usage that streams results to avoid loading all assets at once.

### Unused migration configuration accessors
- `MigrationConfig` still exposes deprecated or redundant accessors (`getRootLevelVolumeHandles()`, `volumeHasSubfolders()`, and related helpers) that are not called elsewhere in the codebase. Keeping these unused methods expands the public surface without providing functionality.
- Either remove them or document the external integration that requires them; otherwise they increase maintenance cost and mislead about supported configuration paths.

### Ineffective error-threshold option
- `ImageMigrationController` reads `errorThreshold` from configuration during initialization but never consults it during migration. Only `criticalErrorThreshold` drives halt logic, so the general threshold currently has no effect.
- If the general threshold is meant to gate non-critical failures, wire the property into the error-handling paths or remove the unused option to avoid false expectations in configuration and tests.

### Obsolete diagnostic script namespace
- `check-method-exists.php` requires `MigrationConfig.php` but checks for the non-existent `modules\helpers\MigrationConfig` namespace, so the diagnostic cannot succeed even when the file is present. The script also relies on hard-coded relative paths that do not align with the current `csabourin\craftS3SpacesMigration` namespace.
- Update the namespace it validates or retire the script to prevent confusion during diagnostics.
