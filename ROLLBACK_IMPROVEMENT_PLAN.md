# ImageMigrationController Rollback Improvement Plan

## Context & Key Insights

✅ **Files on AWS are MOVED, not deleted** - they exist in quarantine and can be moved back
✅ **AWS storage is never touched** - only database records change (asset volume/folder/path)
✅ **Database restore is the fastest rollback** - restores all links to AWS files instantly
✅ **Phase rollback is critical** - ability to undo specific phases quickly

## Current State Analysis

### What Works ✅
- `inline_image_linked` - Original HTML content logged, can be restored
- `moved_asset` - Original volume/folder logged, assets can be moved back
- `quarantined_unused_asset` - Assets in quarantine can be moved back
- Change log system exists and tracks operations

### Critical Gaps ❌
1. **No database backup mechanism** - Cannot do instant rollback via DB restore
2. **No phase-specific rollback** - Must rollback ALL or nothing
3. **Missing rollback handlers** - 3 change types have no rollback implementation
4. **Incomplete rollback data** - Some operations don't log enough info to reverse
5. **No rollback verification** - Can't verify rollback success
6. **Orphaned file rollback incomplete** - Tries to restore from non-existent backup

---

## Implementation Plan

### PHASE 1: Database Backup & Restore (Priority: CRITICAL)
**Goal:** Enable instant rollback via database restore as primary mechanism

#### 1.1 Add Database Backup Before Migration
**Location:** `actionMigrate()` after Phase 0 validation (around Line 306)

**Implementation:**
```php
// Phase 0: Preparation & Validation
$this->setPhase('preparation');

// ... existing validation ...

// NEW: Create database backup
if (!$this->dryRun && !$this->skipBackup) {
    $this->createDatabaseBackup();
    $this->stdout("  ✓ Database backup created\n", Console::FG_GREEN);
}
```

**New Method: `createDatabaseBackup()`**
- Use `mysqldump` or Craft's backup functionality
- Store backup with migration ID: `migration_{migrationId}_db_backup.sql`
- Store in `@storage/migration-backups/`
- Log backup location in checkpoint
- Store table list: `elements`, `assets`, `relations`, `content`, `volumefolders`
- **Estimated time:** 2-4 hours

#### 1.2 Add Database Restore Rollback Method
**Location:** New method in RollbackEngine class

**Implementation:**
```php
public function rollbackViaDatabase($migrationId, $dryRun = false)
{
    // 1. Find database backup file
    // 2. Verify backup integrity
    // 3. Show user what will be restored
    // 4. Confirm with user
    // 5. Stop all Craft processes
    // 6. Restore database from backup
    // 7. Clear Craft caches
    // 8. Verify restoration
    // 9. Report success
}
```

**Features:**
- Fast rollback (seconds vs minutes/hours)
- Atomic - all or nothing
- Safest option for complete reversal
- **Estimated time:** 3-4 hours

#### 1.3 Update `actionRollback()` with Database Restore Option
**Location:** Line 1186

**Implementation:**
```php
public function actionRollback($migrationId = null, $toPhase = null, $dryRun = false, $method = null)
{
    // ... existing code ...

    // NEW: Ask user for rollback method
    $method = $this->prompt("Select rollback method:\n" .
        "  1. Database restore (fastest, complete rollback)\n" .
        "  2. Change-by-change (selective, supports phase rollback)\n" .
        "Choice:", ['default' => '1']);

    if ($method === '1') {
        return $this->rollbackEngine->rollbackViaDatabase($migrationId, $dryRun);
    } else {
        // Existing change-by-change rollback
        return $this->rollbackEngine->rollback($migrationId, $toPhase, $dryRun);
    }
}
```
- **Estimated time:** 1-2 hours

---

### PHASE 2: Phase-Specific Rollback (Priority: HIGH)
**Goal:** Enable rollback of specific phases without undoing entire migration

#### 2.1 Add Phase Tracking to Change Log
**Location:** Line 4490 in `ChangeLogManager::logChange()`

**Implementation:**
```php
public function logChange($change)
{
    $change['sequence'] = $this->getNextSequence();
    $change['timestamp'] = date('Y-m-d H:i:s');
    $change['phase'] = $this->currentPhase; // NEW: Track which phase created this change

    // ... rest of method ...
}
```

**Update Constructor:**
```php
private $currentPhase;

public function __construct($migrationId, $currentPhase = 'unknown')
{
    $this->migrationId = $migrationId;
    $this->currentPhase = $currentPhase;
    // ... rest of constructor ...
}

public function setPhase($phase)
{
    $this->currentPhase = $phase;
}
```
- **Estimated time:** 1 hour

#### 2.2 Update ImageMigrationController to Pass Phase to ChangeLogManager
**Location:** Line 201 and throughout setPhase() calls

**Implementation:**
```php
// In __construct or init
$this->changeLogManager = new ChangeLogManager($this->migrationId);

// In setPhase() - Line 2545
private function setPhase($phase)
{
    $this->currentPhase = $phase;
    $this->changeLogManager->setPhase($phase); // NEW: Update change log manager
    // ... rest of method ...
}
```
- **Estimated time:** 1 hour

#### 2.3 Implement Phase Filtering in Rollback
**Location:** RollbackEngine::rollback() - Line 4707

**Current code:**
```php
// Filter by phase if specified
if ($toPhase) {
    $changes = array_filter($changes, fn($c) => ($c['phase'] ?? '') !== $toPhase);
}
```

**Problems with current code:**
- Logic is INVERTED - keeps changes that DON'T match phase
- No support for "rollback phases X, Y, Z"
- No "rollback from phase X onwards"

**Fixed Implementation:**
```php
/**
 * Rollback specific phases
 *
 * @param string|array $phases Single phase or array of phases to rollback
 * @param string $mode 'only' = rollback only these phases, 'from' = rollback from phase onwards
 */
public function rollback($migrationId, $phases = null, $mode = 'from', $dryRun = false)
{
    // Load all changes
    $changes = $this->changeLogManager->loadChanges();

    if (empty($changes)) {
        throw new \Exception("No changes found for migration: {$migrationId}");
    }

    // Filter by phase if specified
    if ($phases !== null) {
        $phasesToRollback = is_array($phases) ? $phases : [$phases];

        if ($mode === 'only') {
            // Rollback ONLY specified phases
            $changes = array_filter($changes, function($c) use ($phasesToRollback) {
                $phase = $c['phase'] ?? 'unknown';
                return in_array($phase, $phasesToRollback);
            });
        } else if ($mode === 'from') {
            // Rollback FROM specified phase onwards (in reverse order)
            $phaseOrder = [
                'preparation',
                'optimised_root',
                'discovery',
                'link_inline',
                'fix_links',
                'consolidate',
                'quarantine',
                'cleanup',
                'complete'
            ];

            $fromPhase = is_array($phases) ? $phases[0] : $phases;
            $fromIndex = array_search($fromPhase, $phaseOrder);

            if ($fromIndex !== false) {
                $phasesToInclude = array_slice($phaseOrder, $fromIndex);
                $changes = array_filter($changes, function($c) use ($phasesToInclude) {
                    $phase = $c['phase'] ?? 'unknown';
                    return in_array($phase, $phasesToInclude);
                });
            }
        }
    }

    // ... rest of rollback logic ...
}
```
- **Estimated time:** 2-3 hours

#### 2.4 Update actionRollback() CLI to Support Phase Selection
**Location:** Line 1186

**Implementation:**
```php
public function actionRollback($migrationId = null, $phases = null, $mode = 'from', $dryRun = false, $method = null)
{
    // ... show migrations ...

    // NEW: Show phases in selected migration
    if ($migrationId) {
        $phaseSummary = $this->rollbackEngine->getPhasesSummary($migrationId);

        $this->stdout("\nPhases in this migration:\n", Console::FG_CYAN);
        foreach ($phaseSummary as $phase => $count) {
            $this->stdout("  - {$phase}: {$count} changes\n");
        }

        $this->stdout("\nRollback options:\n");
        $this->stdout("  1. All phases (complete rollback)\n");
        $this->stdout("  2. Specific phases only\n");
        $this->stdout("  3. From phase onwards\n");

        $option = $this->prompt("Select option:", ['default' => '1']);

        if ($option === '2') {
            $phases = $this->prompt("Enter phases to rollback (comma-separated):");
            $phases = array_map('trim', explode(',', $phases));
            $mode = 'only';
        } else if ($option === '3') {
            $phases = $this->prompt("Rollback from which phase?:");
            $mode = 'from';
        }
    }

    // ... execute rollback ...
}
```
- **Estimated time:** 2-3 hours

---

### PHASE 3: Fix Missing Rollback Handlers (Priority: HIGH)
**Goal:** Implement rollback for all change types that can be reversed

#### 3.1 Fix `quarantined_orphaned_file` Rollback
**Location:** RollbackEngine::reverseChange() - Line 4780

**Current (broken) code:**
```php
case 'quarantined_orphaned_file':
    // Restore file from quarantine
    try {
        $targetVolume = Craft::$app->getVolumes()->getVolumeById($change['fromVolume']);
        if ($targetVolume) {
            $targetFs = $targetVolume->getFs();

            // If we have content backup, restore it
            if (isset($change['content_backup'])) {
                $content = base64_decode($change['content_backup']);
                $targetFs->write($change['sourcePath'], $content, []);
            } else {
                // Try to copy back from quarantine
                throw new \Exception("Cannot restore large file without backup");
            }
        }
    }
```

**Fixed implementation:**
```php
case 'quarantined_orphaned_file':
    // Restore file from quarantine (files are moved, not deleted)
    try {
        // Get quarantine volume
        $quarantineVolume = Craft::$app->getVolumes()->getVolumeByHandle('quarantine');
        if (!$quarantineVolume) {
            throw new \Exception("Quarantine volume not found");
        }
        $quarantineFs = $quarantineVolume->getFs();

        // Get original volume
        $sourceVolume = Craft::$app->getVolumes()->getVolumeByHandle($change['sourceVolume']);
        if (!$sourceVolume) {
            throw new \Exception("Source volume '{$change['sourceVolume']}' not found");
        }
        $sourceFs = $sourceVolume->getFs();

        // Move file back from quarantine to original location
        $quarantinePath = $change['targetPath']; // Where file is now
        $originalPath = $change['sourcePath'];   // Where it should go

        // Read from quarantine
        $content = $quarantineFs->read($quarantinePath);

        // Write back to original location
        $sourceFs->write($originalPath, $content, []);

        // Delete from quarantine
        $quarantineFs->deleteFile($quarantinePath);

        Craft::info("Restored orphaned file from quarantine: {$originalPath}", __METHOD__);

    } catch (\Exception $e) {
        throw new \Exception("Could not restore file from quarantine: " . $e->getMessage());
    }
    break;
```
- **Estimated time:** 1-2 hours

#### 3.2 Implement Rollback for `fixed_broken_link`
**Location:** RollbackEngine::reverseChange() - Line 4763

**Current (broken) code:**
```php
case 'fixed_broken_link':
    // Can't easily undo file copy, log only
    Craft::warning("Cannot automatically reverse fixed broken link for asset: " ...);
    break;
```

**Analysis:**
- When fixing broken link, asset record is updated with new volume/folder
- File is copied to new location via `copyFileToAsset()`
- BUT: We don't have original volumeId/folderId in change log!

**Step 1: Fix change logging (Line 2063)**
```php
// Before fix
$originalVolumeId = $asset->volumeId;
$originalFolderId = $asset->folderId;

// ... fix broken link ...

$this->changeLogManager->logChange([
    'type' => 'fixed_broken_link',
    'assetId' => $asset->id,
    'filename' => $asset->filename,
    'matchedFile' => $sourceFile['filename'],
    'sourceVolume' => $sourceFile['volumeName'],
    'sourcePath' => $sourceFile['path'],
    'matchStrategy' => $matchResult['strategy'],
    'confidence' => $matchResult['confidence'],
    // NEW: Add original location for rollback
    'originalVolumeId' => $originalVolumeId,
    'originalFolderId' => $originalFolderId,
]);
```

**Step 2: Implement rollback handler**
```php
case 'fixed_broken_link':
    // Restore asset to original broken state
    $asset = Asset::findOne($change['assetId']);
    if ($asset) {
        // Move asset back to original (broken) location
        $asset->volumeId = $change['originalVolumeId'];
        $asset->folderId = $change['originalFolderId'];

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new \Exception("Could not restore asset to original location");
        }

        Craft::info("Restored asset {$change['assetId']} to original broken state", __METHOD__);
    } else {
        Craft::warning("Asset {$change['assetId']} not found during rollback", __METHOD__);
    }
    break;
```

**Notes:**
- The file copied during fix will remain on filesystem (orphaned)
- This is acceptable since storage is cheap and files can be cleaned up later
- The important part is restoring the database state (broken link)
- Could add optional cleanup of copied files in a separate phase

- **Estimated time:** 2-3 hours

#### 3.3 Implement Rollback for `moved_from_optimised_root`
**Location:** RollbackEngine::reverseChange() + add new case

**Implementation:**
```php
case 'moved_from_optimised_root':
    // Move asset back to optimisedImages root
    $asset = Asset::findOne($change['assetId']);
    if ($asset) {
        $sourceVolume = Craft::$app->getVolumes()->getVolumeById($change['fromVolume']);
        if (!$sourceVolume) {
            throw new \Exception("Source volume not found");
        }

        // Get root folder of source volume
        $sourceRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($sourceVolume->id);
        if (!$sourceRootFolder) {
            throw new \Exception("Source root folder not found");
        }

        // Move asset back
        $asset->volumeId = $sourceVolume->id;
        $asset->folderId = $sourceRootFolder->id;

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new \Exception("Could not restore asset to optimised root");
        }

        Craft::info("Restored asset {$change['assetId']} to optimisedImages root", __METHOD__);
    }
    break;
```
- **Estimated time:** 1-2 hours

#### 3.4 Implement Rollback for `updated_asset_path`
**Location:** RollbackEngine::reverseChange() + add new case

**Analysis:** Looking at Line 2107, this change type is logged but needs original values

**Step 1: Fix change logging (Line 2106)**
```php
// Before update
$originalVolumeId = $asset->volumeId;
$originalPath = $asset->getPath();

// ... update asset ...

$this->changeLogManager->logChange([
    'type' => 'updated_asset_path',
    'assetId' => $asset->id,
    'filename' => $asset->filename,
    'path' => $path,
    'volumeId' => $volume->id,
    // NEW: Add original values
    'originalVolumeId' => $originalVolumeId,
    'originalPath' => $originalPath,
]);
```

**Step 2: Implement rollback**
```php
case 'updated_asset_path':
    // Restore asset to original volume/path
    $asset = Asset::findOne($change['assetId']);
    if ($asset) {
        $asset->volumeId = $change['originalVolumeId'];

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new \Exception("Could not restore asset path");
        }

        Craft::info("Restored asset {$change['assetId']} to original path", __METHOD__);
    }
    break;
```
- **Estimated time:** 1-2 hours

#### 3.5 Handle `deleted_transform` (Accept No Rollback)
**Decision:** Transform files are regenerated automatically by Craft
- No rollback needed
- Update case to acknowledge this explicitly:

```php
case 'deleted_transform':
    // Transforms are regenerated automatically by Craft CMS - no rollback needed
    Craft::info("Transform file was deleted but will regenerate: {$change['path']}", __METHOD__);
    break;
```
- **Estimated time:** 15 minutes

---

### PHASE 4: Improve Change Log Reliability (Priority: MEDIUM)
**Goal:** Ensure all operations are logged before crashes can occur

#### 4.1 Force Flush After Critical Operations
**Location:** Multiple locations

**Implementation:**
Add `$this->changeLogManager->flush()` after:
1. Each phase completion (Line ~410, ~458, ~468, ~500, ~505)
2. Before user confirmations (Line 380, 485)
3. After batch processing (every N items)

**Example at Line 410:**
```php
$this->saveCheckpoint(['inline_linking_complete' => true]);
$this->changeLogManager->flush(); // NEW: Ensure all changes written
```
- **Estimated time:** 1-2 hours

#### 4.2 Add Flush Threshold Configuration
**Location:** ChangeLogManager class constants

**Implementation:**
```php
// Allow smaller flush threshold for critical phases
const FLUSH_THRESHOLD_NORMAL = 100;
const FLUSH_THRESHOLD_CRITICAL = 10;

public function setCriticalMode($enabled)
{
    $this->flushThreshold = $enabled
        ? self::FLUSH_THRESHOLD_CRITICAL
        : self::FLUSH_THRESHOLD_NORMAL;
}
```

Update critical phases:
```php
// Phase 2: Fix Broken Links
$this->changeLogManager->setCriticalMode(true);
$this->fixBrokenLinksBatched(...);
$this->changeLogManager->setCriticalMode(false);
```
- **Estimated time:** 1 hour

#### 4.3 Add Signal Handler for Graceful Shutdown
**Location:** actionMigrate() initialization

**Implementation:**
```php
// Register shutdown handler to flush logs
register_shutdown_function(function() {
    if ($this->changeLogManager) {
        $this->changeLogManager->flush();
    }
});

// Handle SIGTERM/SIGINT
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() {
        $this->changeLogManager->flush();
        exit(0);
    });
    pcntl_signal(SIGINT, function() {
        $this->changeLogManager->flush();
        exit(0);
    });
}
```
- **Estimated time:** 1 hour

---

### PHASE 5: Rollback Verification & Safety (Priority: MEDIUM)
**Goal:** Ensure rollback completes successfully and leaves system in good state

#### 5.1 Add Rollback Verification
**Location:** RollbackEngine::rollback() after completion

**Implementation:**
```php
public function rollback($migrationId, $phases = null, $mode = 'from', $dryRun = false)
{
    // ... existing rollback logic ...

    if (!$dryRun) {
        // NEW: Verify rollback
        $this->verifyRollback($changes, $stats);
    }

    return $stats;
}

private function verifyRollback($changes, $stats)
{
    $verificationResults = [
        'assets_checked' => 0,
        'assets_verified' => 0,
        'assets_failed' => 0,
        'files_checked' => 0,
        'files_verified' => 0,
        'files_failed' => 0,
    ];

    // Sample 10% of changes for verification
    $samplesToCheck = array_slice($changes, 0, (int)(count($changes) * 0.1));

    foreach ($samplesToCheck as $change) {
        try {
            switch ($change['type']) {
                case 'moved_asset':
                    // Verify asset is back in original location
                    $asset = Asset::findOne($change['assetId']);
                    if ($asset &&
                        $asset->volumeId == $change['fromVolume'] &&
                        $asset->folderId == $change['fromFolder']) {
                        $verificationResults['assets_verified']++;
                    } else {
                        $verificationResults['assets_failed']++;
                    }
                    $verificationResults['assets_checked']++;
                    break;

                case 'inline_image_linked':
                    // Verify original content restored
                    $db = Craft::$app->getDb();
                    $content = $db->createCommand("
                        SELECT `{$change['column']}`
                        FROM `{$change['table']}`
                        WHERE id = :id
                    ", [':id' => $change['rowId']])->queryScalar();

                    if ($content === $change['originalContent']) {
                        $verificationResults['assets_verified']++;
                    } else {
                        $verificationResults['assets_failed']++;
                    }
                    $verificationResults['assets_checked']++;
                    break;

                // Add more verification cases...
            }
        } catch (\Exception $e) {
            $verificationResults['assets_failed']++;
            Craft::error("Verification failed: " . $e->getMessage(), __METHOD__);
        }
    }

    // Report results
    Craft::info("Rollback verification: " . json_encode($verificationResults), __METHOD__);

    return $verificationResults;
}
```
- **Estimated time:** 3-4 hours

#### 5.2 Add Dry-Run Simulation for Rollback
**Location:** RollbackEngine::rollback()

**Implementation:**
Already supports `$dryRun` parameter, but enhance it:

```php
if ($dryRun) {
    // Show detailed plan
    $this->stdout("\nDRY RUN - Would perform these rollback operations:\n\n");

    // Group by type
    $byType = [];
    foreach ($changes as $change) {
        $type = $change['type'];
        if (!isset($byType[$type])) {
            $byType[$type] = 0;
        }
        $byType[$type]++;
    }

    foreach ($byType as $type => $count) {
        $this->stdout("  {$type}: {$count} operations\n");
    }

    $this->stdout("\nEstimated time: ~" . $this->estimateRollbackTime($changes) . "\n");
    $this->stdout("\nTo execute: Remove --dry-run flag\n\n");

    return ['dry_run' => true, 'operations' => $byType];
}
```
- **Estimated time:** 1-2 hours

#### 5.3 Add Rollback Progress Reporting
**Location:** RollbackEngine::rollback() main loop

**Implementation:**
```php
// Reverse in reverse order
$total = count($changes);
$current = 0;

foreach (array_reverse($changes) as $change) {
    try {
        if (!$dryRun) {
            $this->reverseChange($change);
        }
        $stats['reversed']++;
        $current++;

        // NEW: Progress reporting
        if ($current % 50 === 0) {
            $percent = round(($current / $total) * 100);
            $this->stdout("[{$current}/{$total}] {$percent}% complete\n");
        }

    } catch (\Exception $e) {
        $stats['errors']++;
        Craft::error("Rollback error: " . $e->getMessage(), __METHOD__);
    }
}
```
- **Estimated time:** 30 minutes

---

### PHASE 6: Documentation & Testing (Priority: MEDIUM)
**Goal:** Document rollback capabilities and test thoroughly

#### 6.1 Update Rollback Command Help
**Location:** actionRollback() - Line 1186

**Add comprehensive documentation:**
```php
/**
 * Rollback a migration using change log
 *
 * Examples:
 *   # Complete rollback via database restore (fastest)
 *   ./craft ncc-module/image-migration/rollback --method=database
 *
 *   # Complete rollback via change log
 *   ./craft ncc-module/image-migration/rollback
 *
 *   # Rollback specific phases only
 *   ./craft ncc-module/image-migration/rollback --phases=quarantine,fix_links --mode=only
 *
 *   # Rollback from phase onwards
 *   ./craft ncc-module/image-migration/rollback --phases=consolidate --mode=from
 *
 *   # Dry run to see what would be rolled back
 *   ./craft ncc-module/image-migration/rollback --dry-run
 *
 * @param string|null $migrationId Migration ID to rollback (prompts if not provided)
 * @param string|array|null $phases Phase(s) to rollback (null = all phases)
 * @param string $mode 'from' (rollback from phase onwards) or 'only' (rollback specific phases)
 * @param bool $dryRun Show what would be done without executing
 * @param string|null $method 'database' (restore DB backup) or 'changeset' (use change log)
 */
public function actionRollback($migrationId = null, $phases = null, $mode = 'from', $dryRun = false, $method = null)
```
- **Estimated time:** 1 hour

#### 6.2 Add Rollback Test Suite
**Location:** New file `tests/rollback/RollbackTest.php`

**Test cases:**
1. Test database backup creation
2. Test database restore rollback
3. Test each change type rollback handler
4. Test phase-specific rollback
5. Test rollback verification
6. Test rollback with errors (partial rollback)
7. Test change log flushing
8. Test crash recovery (simulated)

- **Estimated time:** 6-8 hours

#### 6.3 Create Rollback Documentation
**Location:** New file `docs/ROLLBACK_GUIDE.md`

**Content:**
- When to use database rollback vs change-by-change rollback
- Phase rollback use cases
- Rollback verification process
- What can and cannot be rolled back
- Troubleshooting guide
- Best practices

- **Estimated time:** 2-3 hours

---

## Implementation Priority Summary

### IMMEDIATE (Critical for Safe Rollback)
1. ✅ **Database backup/restore** - Fastest, safest complete rollback (6-10 hours)
2. ✅ **Fix orphaned file rollback** - Move from quarantine (1-2 hours)
3. ✅ **Fix broken link rollback** - Restore asset to original location (2-3 hours)
4. ✅ **Phase tracking in change log** - Enable phase-specific rollback (2 hours)

**Total: 11-17 hours**

### HIGH PRIORITY (Enable Phase Rollback)
5. ✅ **Implement phase filtering** - Rollback specific phases (2-3 hours)
6. ✅ **Update CLI for phase selection** - User-friendly phase rollback (2-3 hours)
7. ✅ **Rollback for `moved_from_optimised_root`** - Complete Phase 0.5 rollback (1-2 hours)
8. ✅ **Rollback for `updated_asset_path`** - Complete fix link rollback (1-2 hours)

**Total: 6-10 hours**

### MEDIUM PRIORITY (Reliability & Safety)
9. ✅ **Force flush after critical operations** - Prevent log loss (1-2 hours)
10. ✅ **Rollback verification** - Ensure rollback success (3-4 hours)
11. ✅ **Progress reporting** - User feedback during rollback (30 min)
12. ✅ **Documentation** - Rollback guide (2-3 hours)

**Total: 6.5-9.5 hours**

### OPTIONAL (Nice to Have)
13. ⭕ **Critical mode flush threshold** - More frequent logging (1 hour)
14. ⭕ **Signal handlers** - Graceful shutdown (1 hour)
15. ⭕ **Enhanced dry-run** - Better rollback preview (1-2 hours)
16. ⭕ **Test suite** - Comprehensive rollback tests (6-8 hours)

**Total: 9-12 hours**

---

## Total Estimated Time

- **Immediate + High Priority:** 17-27 hours (minimum viable rollback)
- **+ Medium Priority:** 23.5-36.5 hours (production-ready rollback)
- **+ Optional:** 32.5-48.5 hours (fully featured rollback)

---

## Rollback Capability Matrix (After Implementation)

| Change Type | Before | After | Method |
|-------------|--------|-------|--------|
| `inline_image_linked` | ✅ Full | ✅ Full | Restore original HTML |
| `moved_asset` | ✅ Full | ✅ Full | Move asset back |
| `quarantined_unused_asset` | ✅ Full | ✅ Full | Move from quarantine |
| `fixed_broken_link` | ❌ None | ✅ Full | Restore asset to original location |
| `quarantined_orphaned_file` | ❌ None | ✅ Full | Move file from quarantine |
| `moved_from_optimised_root` | ❌ None | ✅ Full | Move asset back to root |
| `updated_asset_path` | ❌ None | ✅ Full | Restore original volume/path |
| `deleted_transform` | ❌ None | ⭕ N/A | Regenerates automatically |

**New Rollback Coverage: 100% (vs 55% before)**

---

## Quick Rollback Scenarios

### Scenario 1: Complete Rollback (Fastest)
```bash
# Use database restore - takes seconds
./craft ncc-module/image-migration/rollback --method=database
```
**Time: <1 minute**

### Scenario 2: Undo Only Quarantine Phase
```bash
# User realizes quarantine was too aggressive
./craft ncc-module/image-migration/rollback --phases=quarantine --mode=only
```
**Time: ~5-10 minutes for 1000 files**

### Scenario 3: Rollback from Fix Links Onwards
```bash
# Keep inline linking, undo everything after
./craft ncc-module/image-migration/rollback --phases=fix_links --mode=from
```
**Time: ~10-20 minutes depending on operations**

### Scenario 4: Dry Run Before Rollback
```bash
# Check what would be rolled back
./craft ncc-module/image-migration/rollback --dry-run
```
**Time: <1 minute**

---

## Success Metrics

After implementation, rollback should achieve:
- ✅ **100% change type coverage** - All operations reversible
- ✅ **<1 minute complete rollback** - Via database restore
- ✅ **Phase-specific rollback** - Selective undo capability
- ✅ **Verified rollback** - Automated verification of success
- ✅ **No data loss** - Files moved, not deleted (already achieved)
- ✅ **Clear documentation** - Users know how to rollback safely

---

## Next Steps

Please confirm:
1. **Priority**: Should we start with Immediate items (database backup + fix handlers)?
2. **Scope**: Full implementation or just Immediate + High Priority?
3. **Testing**: Should we include test suite or defer to later?
4. **Timeline**: Target completion timeframe?

Once confirmed, I'll begin implementation starting with database backup/restore mechanism.
