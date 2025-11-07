# Agent Instructions

## Scope
These rules apply to the entire repository unless a more specific `AGENTS.md` is introduced in a subdirectory.

## General Guidelines
- Keep all file and directory names lowercase. If you add a new PHP class, ensure the filename is lowercase and matches the PSR-4 autoloading expectations defined in `composer.json`.
- Follow PSR-12/PSR-2 style for PHP: 4 spaces for indentation, braces on the same line for classes and functions, and use `use` statements for dependencies instead of fully qualified names inline.
- Do not remove the existing module bootstrap logic in `bootstrap.php`. Any changes to registration must preserve automatic loading for both web and console applications.
- When touching code that interacts with Craft CMS events or modules, double-check that aliases under `csabourin\craftS3SpacesMigration` remain valid for both web and console contexts.

## Documentation
- Documentation files use sentence case headings and may contain ASCII boxes. Preserve the existing formatting when updating these files.
- If you update any user-facing instructions, reflect the change both in `README.md` and any specialized guides (`ARCHITECTURE.md`, `DASHBOARD.md`, etc.) that cover the same area.

## Testing
- Run `php -l` on every modified PHP file.
- Run any project-specific scripts mentioned in updated documentation when feasible. Document skipped tests with a clear rationale.
