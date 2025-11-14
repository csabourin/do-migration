# Web Interface Integration for Volume Consolidation

## Overview

The volume consolidation commands are now fully integrated with the web dashboard interface, allowing users to run consolidation operations with dry run and full execution modes at the press of a button.

## Changes Made

### 1. Web Controller Updates (`MigrationController.php`)

#### Added Commands to Allowed List

```php
// volume-consolidation
'volume-consolidation/merge-optimized-to-images',
'volume-consolidation/flatten-to-root',
'volume-consolidation/status',
```

#### Added to Auto-Yes Commands

Both commands support the `--yes` flag for automation, bypassing interactive confirmations:

```php
's3-spaces-migration/volume-consolidation/merge-optimized-to-images',
's3-spaces-migration/volume-consolidation/flatten-to-root',
```

#### Added Dashboard Module Definitions

Three new modules added to the "Post-Migration Validation" phase:

1. **Check Consolidation Status**
   - Command: `volume-consolidation/status`
   - Shows if consolidation is needed
   - Duration: 1-2 min
   - No dry run support

2. **Merge OptimisedImages → Images**
   - Command: `volume-consolidation/merge-optimized-to-images`
   - Moves ALL assets from OptimisedImages to Images
   - Duration: 10-60 min
   - **Supports Dry Run**: Yes ✅

3. **Flatten Subfolders → Root**
   - Command: `volume-consolidation/flatten-to-root`
   - Moves ALL assets from subfolders to root
   - Duration: 10-60 min
   - **Supports Dry Run**: Yes ✅

### 2. Volume Consolidation Controller Updates

#### Machine-Readable Exit Markers

All commands now output machine-readable markers for the web interface:

**Success**: `__CLI_EXIT_CODE_0__`
**Failure**: `__CLI_EXIT_CODE_1__`

This allows the web streaming interface to properly detect:
- ✅ Success (green check)
- ❌ Failure (red X)
- 🔄 In Progress (spinner)

#### Output to stdout (not stderr)

Changed all error messages to output to `stdout` instead of `stderr` to ensure the web interface can capture and display them in the streaming output.

**Before**:
```php
$this->stderr("✗ Source volume 'optimisedImages' not found\n\n", Console::FG_RED);
```

**After**:
```php
$this->stdout("✗ Source volume 'optimisedImages' not found\n\n", Console::FG_RED);
```

### 3. Dashboard UI Features

#### Button Controls

Each consolidation module now has:

**Dry Run Button**:
- Runs with `--dryRun=1`
- Shows preview of what would be changed
- No actual changes made
- Safe to run multiple times

**Run Button**:
- Runs with `--dryRun=0 --yes`
- Applies actual changes
- Auto-confirms (no interactive prompts)
- Shows progress in real-time

#### Real-Time Streaming Output

The web interface uses Server-Sent Events (SSE) to stream output:

```
Event Types:
- start: Command started
- output: Line of output
- progress: Machine-readable progress (if available)
- complete: Command finished (includes success/exitCode)
- error: Error occurred
- cancelled: User cancelled
```

#### Progress Indicators

Visual feedback:
- 🔄 Loading spinner while running
- 📊 Progress bar (if command reports progress)
- ✅ Green checkmark on success
- ❌ Red X on failure
- 🟡 Yellow dot for warnings

### 4. OptimisedImages Transform Cleanup (New Module)

- Added `transform-cleanup/clean` to the allowed command list and dashboard definitions (Phase 5 → File Migration).
- Default behavior runs in dry-run mode; pass `--dryRun=0` (web UI "Run" button) to delete files and empty directories.
- Streams human-readable output along with the standard `__CLI_EXIT_CODE_0__` / `__CLI_EXIT_CODE_1__` markers so the web interface can reliably detect success or failure.
- Every execution writes a JSON report under `storage/runtime/transform-cleanup/` so operators can audit which files were targeted.

## Usage from Web Dashboard

### Step-by-Step Workflow

1. **Navigate to Dashboard**
   - Go to Craft CP → Utilities → S3 Migration Dashboard
   - Scroll to "Post-Migration Validation" section

2. **Check Status First**
   - Click "Run" button on "Check Consolidation Status"
   - Review output to see what needs consolidation

3. **Run Dry Run (Recommended)**
   - Click "Dry Run" button on desired consolidation command
   - Review what would be changed
   - No actual changes are made

4. **Execute Migration**
   - Click "Run" button to apply changes
   - Monitor real-time progress
   - Wait for completion

5. **Verify Results**
   - Run "Check Consolidation Status" again
   - Should show all assets consolidated

### Example Workflow for Edge Case

For the bucket-root edge case described in the issue:

```
1. Check Consolidation Status
   → Shows: 1,305 assets in OptimisedImages
   → Shows: 4,905 assets in subfolders

2. Merge OptimisedImages → Images (Dry Run)
   → Preview: Would move 1,305 assets
   → Shows: Renamed files for duplicates

3. Merge OptimisedImages → Images (Run)
   → Executes: Moves 1,305 assets
   → Shows: Progress every 50 assets
   → Completes: ✅ Success

4. Flatten Subfolders → Root (Dry Run)
   → Preview: Would move 4,905 assets
   → Shows: Which folders would be flattened

5. Flatten Subfolders → Root (Run)
   → Executes: Moves 4,905 assets
   → Shows: Progress every 50 assets
   → Completes: ✅ Success

6. Check Consolidation Status
   → Shows: ✓ All assets consolidated
   → Shows: 10,581+ assets in Images root
```

## Technical Details

### Command Parameters

Both consolidation commands support these parameters:

```php
// merge-optimized-to-images
./craft s3-spaces-migration/volume-consolidation/merge-optimized-to-images \
  --dryRun=0 \
  --yes \
  --batchSize=100

// flatten-to-root
./craft s3-spaces-migration/volume-consolidation/flatten-to-root \
  --dryRun=0 \
  --yes \
  --batchSize=100 \
  --volumeHandle=images
```

**Parameters**:
- `dryRun`: 0 = execute, 1 = preview (default: 1)
- `yes`: Skip confirmation prompts (default: false)
- `batchSize`: Assets per batch (default: 100)
- `volumeHandle`: Target volume (default: 'images')

### Web Controller Auto-Flags

When run from the web interface, these flags are automatically added:

```php
// Dry Run Button
$args['dryRun'] = '1';

// Run Button
$args['dryRun'] = '0';
$args['yes'] = true;  // Auto-confirmed
```

### Exit Code Detection

The web interface detects success/failure using multiple methods:

1. **Machine-readable markers** (most reliable):
   - `__CLI_EXIT_CODE_0__` → Success
   - `__CLI_EXIT_CODE_1__` → Failure

2. **Process exit code**:
   - Exit code 0 → Success
   - Non-zero → Failure

3. **Output pattern matching** (fallback):
   - Success indicators: "Done", "Success", "✓"
   - Error indicators: "Error:", "Exception:", "Failed"

### Streaming Implementation

The web controller uses PHP's `proc_open()` with non-blocking streams:

```php
$process = proc_open($command, $descriptorSpec, $pipes);
stream_set_blocking($pipes[1], false);  // stdout
stream_set_blocking($pipes[2], false);  // stderr

while (process is running) {
    // Read from stdout
    $chunk = fread($pipes[1], 8192);

    // Send to browser via SSE
    echo "event: output\ndata: " . json_encode(['line' => $line]) . "\n\n";
    flush();
}
```

## Benefits

### User Experience

✅ **No CLI required** - Run from browser
✅ **Real-time feedback** - See progress live
✅ **Safe preview** - Dry run before execution
✅ **Auto-resume** - Can refresh page without losing progress
✅ **Visual indicators** - Clear success/failure states

### Developer Experience

✅ **Consistent pattern** - Same as other migration commands
✅ **Machine-readable** - Easy to parse output
✅ **Error handling** - Graceful degradation
✅ **Logging** - All output captured in logs
✅ **Debugging** - Full stack traces in dev mode

## Testing Checklist

- [ ] Dry run shows correct preview
- [ ] Run button executes changes
- [ ] Progress indicators update
- [ ] Success state shows green checkmark
- [ ] Error state shows red X
- [ ] Output streams in real-time
- [ ] Can cancel running operation
- [ ] Page refresh doesn't lose state
- [ ] Works with large asset counts (10,000+)
- [ ] Duplicate filename handling works
- [ ] Exit codes detected correctly

## Troubleshooting

### Issue: Command never completes

**Solution**: Check for:
- PHP max_execution_time (increase if needed)
- Lock files in storage/migration-locks
- Zombie processes (check `ps aux | grep craft`)

### Issue: No output shown

**Solution**:
- Check Content-Type header is text/event-stream
- Verify output buffering is disabled
- Check browser console for errors

### Issue: Exit code not detected

**Solution**:
- Verify `__CLI_EXIT_CODE_0__` in output
- Check command actually returns ExitCode::OK
- Review web interface logs for detection logic

## Future Enhancements

Potential improvements:

1. **Progress bar integration** - Parse "X/Y" patterns for progress
2. **Cancel mid-operation** - Kill process from UI
3. **Schedule consolidation** - Queue for background processing
4. **Batch operations** - Run multiple consolidations sequentially
5. **Email notifications** - Notify when complete
6. **Rollback support** - Undo consolidation if needed

## References

- Web Controller: `modules/controllers/MigrationController.php`
- Console Controller: `modules/console/controllers/VolumeConsolidationController.php`
- User Guide: `VOLUME_CONSOLIDATION.md`
- Technical Details: `CONSOLIDATION_ENHANCEMENTS.md`
