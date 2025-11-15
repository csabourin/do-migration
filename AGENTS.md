1. Purpose

This document defines how AI agents must behave when modifying this repository.
Agents must follow these rules unless explicitly overridden in a subdirectory AGENTS.md.

2. Core Principles

Preserve functionality: Never break existing Craft CMS integration, module loading, or the S3→Spaces migration workflow.

Minimal risk: Prefer additive changes over destructive ones.

Human primacy: If the intent of a code section is unclear, request human clarification instead of guessing.

Determinism: Produce predictable, reversible changes.

3. Allowed Agent Actions

Agents may:

Fix bugs without changing public APIs.

Improve documentation (README, ARCHITECTURE, RUNBOOK).

Add tests or improve test coverage.

Refactor code only when the behaviour is preserved and fully tested.

Update inline comments for correctness.

4. Forbidden Actions

Agents must never:

Modify bootstrap.php in a way that breaks module auto-registration.

Change Craft CMS volume configuration logic.

Edit production runbook steps without human approval (file: PRODUCTION_RUNBOOK.md 

PRODUCTION_RUNBOOK

).

Remove validation, warnings, or mandatory order constraints in the dashboard workflow.

Change file/folder names used in PSR-4 autoloading.

Introduce dependencies not already used in the project.

Modify database schemas unless the human explicitly requests a migration.

5. Repository Invariants

Agents must ensure the following are always true:

PHP & Craft CMS

All PHP follows PSR-12.

All classes conform to PSR-4 autoloading.

Module namespace is always csabourin\craftS3SpacesMigration.

Event listeners must remain compatible with both console and web contexts.

Migration Module

Dashboard phases must enforce correct ordering (Phase 4 → Phase 5).

Checkpoints and rollback logic must never be removed.

Migration logs must remain human-readable.

Dry-run mode must always be safe and side-effect-free.

Documentation

Any public-facing steps (e.g., migration procedures, validation stages) must remain accurate.

If updating behaviour, update the runbook and architecture diagrams accordingly.

6. Behaviour Under Uncertainty

If an agent is unsure how to proceed:

Default to no-op.

Add a comment requesting clarification.

Propose multiple safe alternatives rather than committing high-risk changes.

7. Commit Rules

Agents must follow:

Conventional Commits (fix:, feat:, docs:, refactor:…)

Atomic commits (one logical change per commit)

Commit messages must include:

The reason for the change

A statement of preserved behaviour

8. Directory-Specific Rules

If a subdirectory contains its own AGENTS.md, that file overrides this one for files in that subdirectory.

Without an override, these global rules apply.