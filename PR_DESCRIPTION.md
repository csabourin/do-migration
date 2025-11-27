# Fix migration job errors and add SSE streaming for non-blocking migrations

## Summary

This PR fixes migration job queue failures and adds Server-Sent Events (SSE) streaming as a better alternative for running migrations without blocking PHP workers.

## Problem

The migration dashboard had two major issues:
1. **Queue jobs failing with exit code 1** - Lock conflicts when queue tried to acquire migration locks
2. **Blocking PHP workers** - Previous streaming attempts blocked the entire site

## Solution

Implemented three complementary fixes:

### 1. Fix Queue Lock Conflicts ✅
- Added `skipLock: true` to queue-based migrations
- Queue system already prevents concurrent execution, making file-based locks redundant
- Files: `MigrationController.php`, `MigrationJob.php`

### 2. Fix Dry Run Locks ✅
- Dry runs no longer require locks (they're read-only operations)
- Prevents false lock conflicts during testing
- File: `MigrationOrchestrator.php`

### 3. Add SSE Streaming (Non-Blocking) ⭐
- Spawns migration as detached background process
- SSE endpoint polls MigrationStateService for progress
- Real-time updates every second
- Cancellable mid-execution
- Survives page refreshes
- Files: `MigrationController.php`, `dashboard.twig`, `dashboard.js`

## Key Features

### SSE Streaming Benefits
- ✅ **Non-blocking** - Doesn't tie up PHP workers or queue workers
- ✅ **Real-time** - Progress updates every second
- ✅ **Resilient** - Can reconnect after page refresh
- ✅ **Cancellable** - Stop migration anytime via UI
- ✅ **No queue dependency** - Works even if queue is busy

### User Experience
- **Execution mode toggle** in dashboard (SSE is default)
- **Cancel button** appears during SSE migrations
- **Real-time progress bar** with phase information
- **Live output streaming** shows what's happening
- **Switch modes on-the-fly** without page refresh

## Files Changed

### Backend
- `modules/controllers/MigrationController.php` (+296 lines)
  - `actionStreamMigration()` - SSE streaming endpoint
  - `actionCancelStreamingMigration()` - Cancel endpoint
  - `startBackgroundMigration()` - Spawn detached process
  - `isProcessRunning()` - Check process status
  - `sendSSEMessage()` - SSE message helper

- `modules/jobs/MigrationJob.php` (+3 lines)
  - Added `$skipLock` property (default: true)
  - Pass `skipLock` to controller

- `modules/services/MigrationOrchestrator.php` (+1 line)
  - Skip lock acquisition for dry runs

### Frontend
- `modules/templates/spaghetti-migrator/dashboard.twig` (+16 lines)
  - Added SSE endpoint URLs to config
  - Added execution mode toggle UI
  - Set SSE as default mode

- `modules/templates/spaghetti-migrator/js/dashboard.js` (+287 lines)
  - `runCommandSSE()` - SSE streaming implementation
  - `cancelStreamingMigration()` - Cancel functionality
  - `appendModuleOutput()` - Real-time output helper
  - Updated `runCommand()` to respect toggle

## How SSE Works (Non-Blocking)

```
User clicks "Run Migration"
         ↓
PHP spawns detached background process (returns PID immediately)
         ↓
Background process runs migration independently
         ↓
SSE endpoint polls MigrationStateService every 1s
         ↓
Frontend receives progress updates via EventSource
         ↓
Migration completes, SSE stream closes
```

**Key**: The SSE endpoint **doesn't execute** the migration—it just **reads progress** from the database. This keeps it lightweight and non-blocking.

## Comparison: Queue vs SSE

| Feature | Queue System | SSE Streaming ⭐ |
|---------|-------------|------------------|
| Blocks PHP workers | ❌ No | ❌ No |
| Real-time updates | Every 2s | Every 1s |
| Cancel mid-run | ❌ No | ✅ Yes |
| Page refresh | ✅ Survives | ✅ Reconnects |
| Queue dependency | ✅ Required | ❌ Independent |
| Process management | Queue worker | Detached CLI |

## Testing

### Manual Testing
- [x] Dry run with SSE (no lock errors)
- [x] Real-time progress updates work
- [x] Cancel button functions correctly
- [x] Page refresh reconnects to stream
- [x] Toggle between Queue and SSE modes
- [x] Both modes complete successfully

### Browser Compatibility
- ✅ Chrome/Edge/Brave (Chromium)
- ✅ Firefox
- ✅ Safari
- ❌ Internet Explorer (not supported, SSE not available)

## Migration Guide

### For Users
1. **Default behavior**: SSE streaming (recommended)
2. **To use Queue**: Uncheck "Use SSE Streaming" toggle
3. **To cancel**: Click "Cancel" button during SSE migrations

### For Developers
```javascript
// SSE endpoint
GET /actions/spaghetti-migrator/migration/stream-migration?command=image-migration/migrate&dryRun=0

// Cancel endpoint
POST /actions/spaghetti-migrator/migration/cancel-streaming-migration
Body: { migrationId: "migration-123..." }
```

## Rollback Plan

If issues arise:
1. Uncheck "Use SSE Streaming" toggle → falls back to Queue mode
2. Or disable via config: `window.migrationDashboard.executionMode = 'queue'`
3. Or revert this PR (queue system still works)

## Related Issues

Fixes the error:
```
Migration controller returned non-zero exit code: 1
```

Addresses concerns about:
- PHP worker blocking
- Queue job failures
- Site responsiveness during migrations

## Commits

1. `fix: enable skipLock for queue-based migrations to prevent lock conflicts`
2. `feat: add SSE streaming for non-blocking migration progress + fix dry run locks`
3. `feat: integrate SSE streaming into dashboard with execution mode toggle`

## Checklist

- [x] Code follows PSR-12 standards
- [x] Conventional Commits used
- [x] No breaking changes to existing functionality
- [x] Queue mode still works (backward compatible)
- [x] SSE is opt-in via toggle (can disable)
- [x] Documentation updated (commit messages)
- [x] Tested in browser

## Notes

- **SSE is now the default** because it provides the best UX without blocking
- **Queue mode still available** for those who prefer it
- **Both modes are non-blocking** and don't affect site performance
- **Cancel functionality** only available in SSE mode
