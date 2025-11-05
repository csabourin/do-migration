# Manager Extraction Guide - Step-by-Step Implementation

**Goal:** Extract 4 manager classes from ImageMigrationController.php to separate service files
**Time:** 2-3 hours
**Risk:** Very Low
**Benefit:** Better organization, reusable components

---

## 📋 Pre-Flight Checklist

Before starting:

- [ ] Commit current work: `git add -A && git commit -m "feat: Pre-extraction checkpoint"`
- [ ] Create backup: `cp ImageMigrationController.php ImageMigrationController.php.backup`
- [ ] Create branch: `git checkout -b refactor/extract-migration-managers`
- [ ] Review current structure: `grep -n "^class" ImageMigrationController.php`

---

## 🏗️ Step 1: Create Directory Structure (2 minutes)

```bash
# Create services directory
mkdir -p modules/services/migration

# Verify structure
tree modules/
# Should show:
# modules/
# ├── console/
# │   └── controllers/
# │       └── ImageMigrationController.php
# └── services/
#     └── migration/
```

---

## 🔨 Step 2: Extract CheckpointManager (30 minutes)

### **2.1 Find Class Boundaries**

```bash
# Find start line
grep -n "^class CheckpointManager" ImageMigrationController.php
# Output: 4168:class CheckpointManager

# Find end line (next class or end of file)
grep -n "^class ChangeLogManager" ImageMigrationController.php
# Output: 4445:class ChangeLogManager

# So CheckpointManager is lines 4168-4444 (277 lines)
```

### **2.2 Extract to File**

```bash
# Extract lines 4168-4444
sed -n '4168,4444p' ImageMigrationController.php > modules/services/migration/CheckpointManager.php
```

### **2.3 Add Namespace and Fix**

Edit `modules/services/migration/CheckpointManager.php`:

```php
<?php
namespace modules\services\migration;

use Craft;

/**
 * Checkpoint Manager
 *
 * Handles migration state persistence for resumable operations.
 * Manages checkpoint creation, validation, and cleanup.
 *
 * Extracted from ImageMigrationController for reusability across
 * multiple migration controllers.
 *
 * @package modules\services\migration
 */
class CheckpointManager
{
    // ... rest of the class (already extracted)
}
```

### **2.4 Remove from Original**

```bash
# Delete lines 4168-4444 from ImageMigrationController.php
sed -i '4168,4444d' ImageMigrationController.php
```

### **2.5 Test**

```bash
# Check syntax
php -l modules/services/migration/CheckpointManager.php
# Should show: No syntax errors detected
```

---

## 🔨 Step 3: Extract ChangeLogManager (20 minutes)

### **3.1 Find Class (recalculate line numbers after previous deletion)**

```bash
# Lines shifted after previous extraction, so search again
grep -n "^class ChangeLogManager" ImageMigrationController.php
# New line number will be ~277 less (4445 - 277 = 4168)
```

### **3.2 Extract**

```bash
# Find range (ChangeLogManager is 138 lines)
# From new line number to ErrorRecoveryManager start

sed -n 'START,ENDp' ImageMigrationController.php > modules/services/migration/ChangeLogManager.php
```

### **3.3 Add Namespace**

```php
<?php
namespace modules\services\migration;

use Craft;

/**
 * Change Log Manager
 *
 * Tracks all migration changes for potential rollback.
 * Maintains detailed log of moved assets, renamed files, etc.
 *
 * @package modules\services\migration
 */
class ChangeLogManager
{
    // ... extracted code
}
```

### **3.4 Remove & Test**

```bash
sed -i 'START,ENDd' ImageMigrationController.php
php -l modules/services/migration/ChangeLogManager.php
```

---

## 🔨 Step 4: Extract ErrorRecoveryManager (20 minutes)

Repeat same process:

```bash
# Find class
grep -n "^class ErrorRecoveryManager" ImageMigrationController.php

# Extract
sed -n 'START,ENDp' ImageMigrationController.php > modules/services/migration/ErrorRecoveryManager.php

# Add namespace
```

```php
<?php
namespace modules\services\migration;

/**
 * Error Recovery Manager
 *
 * Handles error detection, tracking, and recovery strategies.
 * Detects repeated errors and determines when to abort operations.
 *
 * @package modules\services\migration
 */
class ErrorRecoveryManager
{
    // ... extracted code
}
```

```bash
# Remove & Test
sed -i 'START,ENDd' ImageMigrationController.php
php -l modules/services/migration/ErrorRecoveryManager.php
```

---

## 🔨 Step 5: Extract RollbackEngine (20 minutes)

```bash
# Find class
grep -n "^class RollbackEngine" ImageMigrationController.php

# Extract
sed -n 'START,ENDp' ImageMigrationController.php > modules/services/migration/RollbackEngine.php

# Add namespace
```

```php
<?php
namespace modules\services\migration;

use Craft;

/**
 * Rollback Engine
 *
 * Handles reversal of migration operations using change logs.
 * Can rollback entire migration or specific phases.
 *
 * @package modules\services\migration
 */
class RollbackEngine
{
    // ... extracted code
}
```

```bash
# Remove & Test
sed -i 'START,ENDd' ImageMigrationController.php
php -l modules/services/migration/RollbackEngine.php
```

---

## 🔧 Step 6: Update Controller (30 minutes)

### **6.1 Add Use Statements**

At top of `ImageMigrationController.php` (after namespace):

```php
<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use yii\console\ExitCode;

// Add these new imports
use modules\services\migration\CheckpointManager;
use modules\services\migration\ChangeLogManager;
use modules\services\migration\ErrorRecoveryManager;
use modules\services\migration\RollbackEngine;

class ImageMigrationController extends Controller
{
    // ... rest of controller
}
```

### **6.2 Update Initialization**

Find the `init()` method and update manager instantiation:

**Before:**
```php
public function init(): void
{
    parent::init();

    // Initialize managers
    $storagePath = Craft::getAlias('@storage/migration');
    $this->checkpointManager = new CheckpointManager($storagePath, $this->migrationId);
    // ... etc
}
```

**After:**
```php
public function init(): void
{
    parent::init();

    // Initialize managers (now from services namespace)
    $storagePath = Craft::getAlias('@storage/migration');
    $this->checkpointManager = new CheckpointManager($storagePath, $this->migrationId);
    $this->changeLogManager = new ChangeLogManager($storagePath, $this->migrationId);
    $this->errorRecoveryManager = new ErrorRecoveryManager();
    $this->rollbackEngine = new RollbackEngine(Craft::$app);
}
```

**Note:** The code is identical - just the class is now imported from a different namespace!

### **6.3 Check for Direct Instantiation**

Search for any other places where managers are instantiated:

```bash
grep -n "new CheckpointManager\|new ChangeLogManager\|new ErrorRecoveryManager\|new RollbackEngine" ImageMigrationController.php
```

Update any found instances to use the imported class.

---

## ✅ Step 7: Verify Extraction (30 minutes)

### **7.1 File Structure Check**

```bash
# Verify all files exist
ls -lh modules/services/migration/
# Should show:
# CheckpointManager.php
# ChangeLogManager.php
# ErrorRecoveryManager.php
# RollbackEngine.php

# Verify controller reduced in size
wc -l ImageMigrationController.php
# Should be ~4,300 lines (down from 5,043)
```

### **7.2 Syntax Check**

```bash
# Check all new files
php -l modules/services/migration/CheckpointManager.php
php -l modules/services/migration/ChangeLogManager.php
php -l modules/services/migration/ErrorRecoveryManager.php
php -l modules/services/migration/RollbackEngine.php

# Check updated controller
php -l ImageMigrationController.php
```

### **7.3 Composer Autoload (if needed)**

If using Composer autoloading, regenerate:

```bash
composer dump-autoload
```

### **7.4 Test Help Command**

```bash
./craft help ncc-module/image-migration
# Should show controller help without errors
```

---

## 🧪 Step 8: Functional Testing (30 minutes)

### **Test 1: Dry Run**

```bash
./craft ncc-module/image-migration/migrate --dryRun=1
```

**Expected:** Should complete dry-run analysis without errors

### **Test 2: Small Batch Migration**

```bash
# Test with small batch to verify managers work
./craft ncc-module/image-migration/migrate --batchSize=5 --dryRun=1
```

**Expected:** Should show batch processing working

### **Test 3: Checkpoint Creation**

```bash
# Start migration (will create checkpoint)
./craft ncc-module/image-migration/migrate --batchSize=10

# Interrupt with Ctrl+C after a few seconds

# Check checkpoint was created
ls -la storage/migration/checkpoints/
```

**Expected:** Should see checkpoint file created

### **Test 4: Resume from Checkpoint**

```bash
# Resume interrupted migration
./craft ncc-module/image-migration/migrate

# Should detect checkpoint and ask to resume
```

**Expected:** Should offer to resume from checkpoint

### **Test 5: Change Log**

```bash
# Check change log was created
ls -la storage/migration/migration-changes-*.json
```

**Expected:** Should see change log file

### **Test 6: Error Recovery**

```bash
# Test error handling (artificially create error by using invalid path)
# ... check logs for error recovery messages
```

**Expected:** Error recovery manager should track errors

---

## 📊 Step 9: Validation Checklist

After extraction, verify:

- [ ] All 4 manager classes in `modules/services/migration/`
- [ ] Each file has proper namespace (`modules\services\migration`)
- [ ] Each file has PHP docblock with description
- [ ] Controller has proper `use` statements
- [ ] Controller file size reduced by ~700 lines
- [ ] No syntax errors in any file
- [ ] `craft help ncc-module/image-migration` works
- [ ] Dry-run test passes
- [ ] Checkpoint creation works
- [ ] Resume from checkpoint works
- [ ] Change log generation works
- [ ] Error tracking works
- [ ] No errors in console logs

---

## 🐛 Troubleshooting

### **Issue: Class not found**

```
Error: Class 'CheckpointManager' not found
```

**Solution:** Add proper `use` statement:
```php
use modules\services\migration\CheckpointManager;
```

### **Issue: Namespace error**

```
Error: Cannot declare class modules\services\migration\CheckpointManager
```

**Solution:** Check that namespace matches directory structure:
- File: `modules/services/migration/CheckpointManager.php`
- Namespace: `namespace modules\services\migration;`

### **Issue: Autoload not working**

**Solution:** Regenerate Composer autoload:
```bash
composer dump-autoload
```

Or add to `composer.json`:
```json
{
    "autoload": {
        "psr-4": {
            "modules\\": "modules/"
        }
    }
}
```

### **Issue: Properties not accessible**

```
Error: Call to private property CheckpointManager::$storagePath
```

**Solution:** Check that class uses proper encapsulation:
- Private properties accessed via methods
- Public methods provide interface

---

## 🎯 Quick Extraction Script

For convenience, here's a bash script to do most of the work:

**File: `extract-managers.sh`**

```bash
#!/bin/bash

# Backup original
cp ImageMigrationController.php ImageMigrationController.php.backup

# Create directory
mkdir -p modules/services/migration

# Extract each manager class
# Note: Line numbers must be adjusted after each extraction!

echo "Extracting CheckpointManager..."
# Manual extraction required - line numbers shift after each deletion

echo "Extracting ChangeLogManager..."
# Manual extraction required

echo "Extracting ErrorRecoveryManager..."
# Manual extraction required

echo "Extracting RollbackEngine..."
# Manual extraction required

echo "Done! Now manually:"
echo "1. Add namespaces to each extracted file"
echo "2. Add use statements to controller"
echo "3. Test with: ./craft ncc-module/image-migration/migrate --dryRun=1"
```

**Note:** Due to shifting line numbers after each extraction, manual extraction is safer than automated script.

---

## 📈 Before & After Comparison

### **Before**

```
ImageMigrationController.php (5,043 lines)
├── Main controller logic (4,315 lines)
│   └── 120 methods
└── Internal classes (728 lines)
    ├── CheckpointManager (277 lines)
    ├── ChangeLogManager (138 lines)
    ├── ErrorRecoveryManager (88 lines)
    └── RollbackEngine (225 lines)
```

### **After**

```
ImageMigrationController.php (4,315 lines)
└── Main controller logic
    └── 120 methods (uses external managers)

modules/services/migration/
├── CheckpointManager.php (277 lines)
├── ChangeLogManager.php (138 lines)
├── ErrorRecoveryManager.php (88 lines)
└── RollbackEngine.php (225 lines)
```

**Benefits:**
- ✅ Main file 14% smaller (5,043 → 4,315 lines)
- ✅ 4 reusable service classes
- ✅ Same functionality, better organization
- ✅ Easier to test managers independently
- ✅ Can be used by other controllers

---

## 🎓 Key Lessons

### **What Worked Well**

1. **Classes already had clean interfaces** - Easy to extract
2. **Minimal coupling** - Managers don't depend on each other
3. **Clear responsibilities** - Each manager has one job
4. **No behavior changes** - Pure refactoring

### **What to Watch For**

1. **Line numbers shift** - After each extraction, recalculate
2. **Namespace consistency** - Must match directory structure
3. **Import statements** - Easy to forget `use` statements
4. **Testing thoroughness** - Test all manager functionality

### **Future Improvements**

Once extracted, you could:
- Add unit tests for each manager
- Use managers in other migration controllers
- Add dependency injection for better testability
- Create interfaces for mockability

---

## ✅ Success Criteria

Extraction is successful when:

1. ✅ All tests pass (dry-run, checkpoint, resume, rollback)
2. ✅ No syntax errors in any file
3. ✅ Controller file is ~4,300 lines
4. ✅ 4 manager files exist with proper namespaces
5. ✅ Code behavior is identical to before
6. ✅ Git diff shows only file organization changes (no logic changes)

---

## 🚀 Next Steps After Extraction

Once managers are extracted:

1. **Create unit tests** for each manager class
2. **Document public APIs** with PHPDoc comments
3. **Consider interfaces** if you need mockability
4. **Use in other controllers** if building more migration types
5. **Add to configuration** if managers need settings

---

## 📝 Commit Message Template

After successful extraction:

```bash
git add -A
git commit -m "refactor: Extract manager classes from ImageMigrationController

Extracted 4 manager classes to separate service files for better
organization and reusability:

- CheckpointManager (277 lines) - Migration state persistence
- ChangeLogManager (138 lines) - Change tracking for rollback
- ErrorRecoveryManager (88 lines) - Error detection and handling
- RollbackEngine (225 lines) - Operation reversal

Benefits:
- Main controller reduced from 5,043 to 4,315 lines
- Managers now reusable across other controllers
- Easier to unit test managers independently
- Better code organization

Changes:
- Created modules/services/migration/ directory
- Moved 4 classes to separate files with proper namespaces
- Updated controller to import and use external classes
- No behavior changes - pure refactoring

Testing:
- All migration operations tested and working
- Checkpoint/resume functionality verified
- Change logging verified
- Error recovery verified
- Rollback functionality verified"
```

---

**Total Time:** 2-3 hours
**Risk Level:** Very Low
**Impact:** Immediate improvement in code organization

**Ready to extract? Follow the steps above and you'll have cleaner, more maintainable code in an afternoon!** 🚀

---

**Version:** 1.0
**Date:** 2025-11-05
