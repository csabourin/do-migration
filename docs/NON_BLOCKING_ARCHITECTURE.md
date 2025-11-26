# Non-Blocking Architecture

## Overview

As of version 5.1+, **Spaghetti Migrator uses a non-blocking queue + polling architecture** for all console commands executed through the web dashboard. This prevents PHP worker starvation and ensures the Control Panel remains fully responsive during long-running operations.

## The Problem (Pre-5.1)

### Blocking SSE Streaming Approach

Previously, the dashboard used Server-Sent Events (SSE) to stream real-time output:

```javascript
// OLD APPROACH (BLOCKING) ❌
fetch('/run-command', { stream: true })
  .then(response => response.body.getReader())
  .then(reader => {
    // Read stream continuously...
    // PHP worker blocked for entire duration!
  });
```

**Issues:**
- **Blocked PHP-FPM workers** for entire migration duration (hours)
- **Site/CP became unresponsive** if PHP-FPM had limited workers
- **Single point of failure** - browser close = lost connection
- **No resume capability** - refresh page = start over
- **Not scalable** - each command consumed a worker

## The Solution (5.1+)

### Non-Blocking Queue + Polling Architecture

All commands now use Craft's queue system with periodic polling:

```javascript
// NEW APPROACH (NON-BLOCKING) ✅
// Step 1: Queue command (returns immediately)
fetch('/run-command-queue', { command: 'image-migration/migrate' })
  .then(response => response.json())
  .then(data => {
    const { jobId, migrationId } = data;

    // Step 2: Poll for progress every 2 seconds
    setInterval(() => {
      fetch(`/get-migration-progress?migrationId=${migrationId}`)
        .then(response => response.json())
        .then(progress => updateUI(progress));
    }, 2000);
  });
```

**Benefits:**
- ✅ **Non-blocking** - PHP worker returns immediately
- ✅ **Site remains responsive** - no worker starvation
- ✅ **Survives page refresh** - progress persists in database
- ✅ **Scalable** - multiple users can run commands simultaneously
- ✅ **Resumable** - built-in checkpoint system
- ✅ **Better UX** - users can work while migrations run

---

## Architecture Components

### 1. Backend: Queue System

#### Queue Endpoint
**File:** `modules/controllers/MigrationController.php:392`
**Endpoint:** `POST /actions/spaghetti-migrator/migration/run-command-queue`

```php
public function actionRunCommandQueue(): Response
{
    // Validate command
    // Create queue job
    $jobId = Craft::$app->getQueue()->push(new ConsoleCommandJob([
        'command' => $command,
        'args' => $args,
        'migrationId' => $migrationId,
    ]));

    // Return immediately (non-blocking!)
    return $this->asJson([
        'success' => true,
        'jobId' => $jobId,
        'migrationId' => $migrationId,
    ]);
}
```

#### Background Job
**File:** `modules/jobs/ConsoleCommandJob.php`

Runs commands in background via Craft Queue:
- Updates `MigrationStateService` with progress
- Writes to `migration_state` database table
- Survives PHP-FPM restarts
- Built-in retry logic

#### State Persistence
**File:** `modules/services/MigrationStateService.php`

Stores progress in database:
```php
$state = [
    'migrationId' => $migrationId,
    'phase' => 'consolidation',
    'status' => 'running',
    'processedCount' => 150,
    'totalCount' => 1000,
    'stats' => [...],
];
```

### 2. Frontend: Polling System

#### Dashboard JavaScript
**File:** `modules/templates/spaghetti-migrator/js/dashboard.js`

```javascript
runCommand: function(command, args = {}) {
    // 1. Queue the command (non-blocking)
    this.runCommandQueue(moduleCard, command, args);
}

runCommandQueue: function(moduleCard, command, args) {
    // Queue job
    fetch(this.config.runCommandQueueUrl, { ... })
        .then(data => {
            const { jobId, migrationId } = data;

            // Start polling
            this.pollQueueJobProgress(moduleCard, command, jobId, migrationId);
        });
}

pollQueueJobProgress: function(moduleCard, command, jobId, migrationId) {
    const pollInterval = 2000; // 2 seconds

    const poll = () => {
        // Poll queue status
        fetch(`/get-queue-status?jobId=${jobId}`)
            .then(data => {
                if (data.status === 'completed') {
                    // Stop polling
                    return;
                }

                // Update UI with progress
                this.updateModuleProgress(moduleCard, data.progress);

                // Also poll migration state for details
                this.updateMigrationProgress(moduleCard, migrationId);

                // Continue polling
                setTimeout(poll, pollInterval);
            });
    };

    poll(); // Start polling
}
```

### 3. Progress Endpoints

#### Queue Status
**Endpoint:** `GET /actions/spaghetti-migrator/migration/get-queue-status?jobId={id}`
**Returns:** Craft queue job status

```json
{
  "success": true,
  "status": "running",
  "job": {
    "id": 123,
    "progress": 0.45,
    "description": "Processing 450/1000"
  }
}
```

#### Migration Progress
**Endpoint:** `GET /actions/spaghetti-migrator/migration/get-migration-progress?migrationId={id}`
**Returns:** Detailed migration state

```json
{
  "success": true,
  "migration": {
    "migrationId": "queue-1234-abc",
    "phase": "consolidation",
    "status": "running",
    "processedCount": 450,
    "totalCount": 1000,
    "isProcessRunning": true,
    "stats": {
      "moved": 420,
      "skipped": 30
    }
  }
}
```

---

## Polling Strategy

### Frequency
- **Initial:** Poll every **2 seconds**
- **Max duration:** 48 hours (86,400 polls)
- **Backoff on error:** Double interval (4s, 8s, 16s...)

### What Gets Polled

1. **Craft Queue Status** (`/get-queue-status`)
   - Job completion status
   - Overall progress percentage
   - Error messages

2. **Migration State** (`/get-migration-progress`)
   - Current phase
   - Processed/total counts
   - Detailed stats
   - Process PID status

### UI Updates

```javascript
// Update progress bar
this.updateModuleProgress(moduleCard, 45, 'Processing 450/1000');

// Show output
this.showModuleOutput(moduleCard, 'Progress: 450/1000 - consolidation');

// Update workflow stepper
this.updateWorkflowStepper();
```

---

## Comparison: Streaming vs Queue

| Feature | SSE Streaming (Old) | Queue + Polling (New) |
|---------|---------------------|----------------------|
| **PHP Worker** | Blocked for hours | Returns immediately |
| **Site Responsive** | ❌ Can block | ✅ Always responsive |
| **Page Refresh** | ❌ Loses progress | ✅ Survives refresh |
| **Multiple Users** | ⚠️ Limited | ✅ Unlimited |
| **Resume Capability** | ❌ No | ✅ Yes (checkpoints) |
| **Real-time Updates** | ✅ Instant | ⚠️ 2s delay |
| **Scalability** | ❌ Poor | ✅ Excellent |
| **Server Load** | ⚠️ High | ✅ Low |

---

## Migration Guide

### For Users

**No changes required!** The dashboard works exactly the same:
1. Click "Run" button
2. See progress in real-time (2s updates)
3. Can refresh page without losing progress

**New capabilities:**
- ✅ Can open other admin pages during migration
- ✅ Multiple users can run commands simultaneously
- ✅ Site remains fast even during migrations

### For Developers

If you've customized the dashboard JavaScript:

```javascript
// OLD CODE (deprecated)
this.runCommandStreaming(moduleCard, command, args);

// NEW CODE (use this)
this.runCommandQueue(moduleCard, command, args);
```

The streaming methods are marked `@deprecated` but kept for backward compatibility.

---

## Performance Characteristics

### Resource Usage

**Before (SSE Streaming):**
- 1 PHP-FPM worker per active migration
- Worker blocked for entire duration
- Memory usage: ~50-100MB per worker
- Max concurrent migrations: Limited by PHP-FPM pool

**After (Queue + Polling):**
- PHP-FPM worker released immediately
- Queue worker handles background execution
- Polling requests: ~1-2ms each
- Memory usage: Polling adds ~1MB overhead
- Max concurrent migrations: Limited only by server resources

### Network Traffic

**Polling overhead:**
- Request frequency: 2 seconds
- Request size: ~1-2KB per poll
- Response size: ~2-5KB per poll
- Total: ~1.5-3.5KB/s or ~13-32KB/hour

**Comparison:**
- SSE streaming: Continuous connection (~50-100KB over lifetime)
- Queue polling: ~13-32KB/hour per active migration
- **Winner:** Queue polling (lower total bandwidth)

---

## Troubleshooting

### Polling Not Working

**Check browser console:**
```javascript
// Should see:
Starting polling for job: { jobId: 123, migrationId: 'queue-...' }
Queue status: { status: 'running', progress: 0.45 }
```

**Check endpoint:**
```bash
curl 'http://your-site.test/actions/spaghetti-migrator/migration/get-queue-status?jobId=123'
```

### Queue Job Not Running

**Check Craft queue:**
```bash
./craft queue/info
./craft queue/run
```

**Check migration state table:**
```sql
SELECT * FROM migration_state WHERE migrationId = 'queue-...';
```

### Progress Not Updating

**Verify state updates:**
```bash
# Run this while migration is running
watch -n 2 'mysql -e "SELECT processedCount, totalCount FROM migration_state ORDER BY id DESC LIMIT 1"'
```

---

## Future Enhancements

Potential improvements for future versions:

### WebSockets (Optional)
Replace polling with WebSockets for true real-time updates:
- Instant progress updates (no 2s delay)
- Lower server load (no constant polling)
- Complexity: Requires WebSocket server setup

### Progressive Enhancement
- Use WebSockets when available
- Fall back to polling for compatibility
- Best of both worlds

### Adaptive Polling
- Fast polling when active (2s)
- Slow down when idle (10s, 30s, 60s)
- Reduces server load for long-running jobs

---

## References

### Key Files

**Backend:**
- `modules/controllers/MigrationController.php` - Queue endpoints
- `modules/jobs/ConsoleCommandJob.php` - Background job
- `modules/services/MigrationStateService.php` - State persistence
- `modules/services/MigrationProgressService.php` - Progress tracking
- `modules/services/CommandExecutionService.php` - Command execution

**Frontend:**
- `modules/templates/spaghetti-migrator/js/dashboard.js` - Dashboard logic
- `modules/templates/spaghetti-migrator/dashboard.twig` - Dashboard UI

**Documentation:**
- `docs/NON_BLOCKING_ARCHITECTURE.md` - This document
- `ARCHITECTURE.md` - Overall system architecture
- `CLAUDE.md` - AI assistant guide

---

## Conclusion

The queue + polling architecture provides a **production-ready, scalable solution** for long-running operations without blocking the Control Panel or PHP workers.

**Key Takeaways:**
- ✅ Non-blocking by design
- ✅ Survives page refreshes
- ✅ Scales to multiple users
- ✅ Simple to maintain
- ✅ Battle-tested (Craft Queue)

The 2-second polling interval provides near-real-time updates while keeping server load minimal. Users won't notice the difference, but the server will thank you!

---

**Version:** 5.1+
**Last Updated:** 2025-11-26
**Author:** Christian Sabourin
