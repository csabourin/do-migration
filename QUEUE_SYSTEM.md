# Queue System for Long-Running Migrations

## Overview

The migration system now supports Craft's Queue system, which provides several critical advantages for long-running operations:

- **Survives Page Refresh**: Migrations continue running even if you close the browser or refresh the dashboard
- **Background Execution**: Jobs run in the background via Craft's queue runner
- **Progress Tracking**: Real-time progress updates available via API
- **Integration with Checkpoints**: Works seamlessly with the existing checkpoint/resume system
- **Robust Error Handling**: Failed jobs can be retried, and state is preserved

## Architecture

### Queue Job Classes

#### 1. `MigrationJob` (`modules/jobs/MigrationJob.php`)

Specialized job for the main image migration (`image-migration/migrate`). Features:
- Full integration with checkpoint/resume system
- Phase-aware progress tracking
- Handles all migration parameters (dryRun, skipBackup, resume, etc.)
- TTR: 48 hours (configurable)

#### 2. `ConsoleCommandJob` (`modules/jobs/ConsoleCommandJob.php`)

Generic job for any migration console command. Features:
- Works with any command path (e.g., `url-replacement/replace-s3-urls`)
- Automatic progress parsing from command output
- Optional state tracking via `MigrationStateService`
- Configurable TTR based on command type

### Web Controller Endpoints

#### 1. `POST /actions/s3-spaces-migration/migration/run-command-queue`

Dispatch a command to the queue system.

**Request:**
```json
{
  "command": "image-migration/migrate",
  "args": {
    "resume": true,
    "checkpointId": "migration-123456"
  },
  "dryRun": false
}
```

**Response:**
```json
{
  "success": true,
  "jobId": 42,
  "migrationId": "queue-1699999999-abc123",
  "message": "Command queued successfully. It will continue running even if you refresh the page."
}
```

#### 2. `GET /actions/s3-spaces-migration/migration/get-queue-status?jobId=42`

Get the status of a specific queue job.

**Response:**
```json
{
  "success": true,
  "status": "running",
  "job": {
    "id": 42,
    "description": "Migrating assets from AWS to DigitalOcean Spaces",
    "progress": 0.65,
    "progressLabel": "65.0%",
    "timePushed": 1699999999,
    "attempt": 1,
    "error": null
  }
}
```

**Possible statuses:**
- `pending` - Job is queued but not yet started
- `running` - Job is currently executing (progress > 0)
- `completed` - Job finished successfully (not in queue table)
- `failed` - Job encountered an error (fail = 1)

#### 3. `GET /actions/s3-spaces-migration/migration/get-queue-jobs`

Get all recent queue jobs.

**Response:**
```json
{
  "success": true,
  "jobs": [
    {
      "id": 42,
      "description": "Migrating assets from AWS to DigitalOcean Spaces",
      "status": "running",
      "progress": 0.65,
      "progressLabel": "65.0%",
      "timePushed": 1699999999,
      "attempt": 1,
      "error": null
    }
  ]
}
```

## Usage Examples

### Example 1: Queue a Migration from JavaScript

```javascript
// Start a migration via the queue
const response = await fetch('/actions/s3-spaces-migration/migration/run-command-queue', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    command: 'image-migration/migrate',
    args: {
      skipBackup: false,
      skipInlineDetection: false
    },
    dryRun: false
  })
});

const result = await response.json();
console.log('Job ID:', result.jobId);
console.log('Migration ID:', result.migrationId);

// Poll for progress
const jobId = result.jobId;
const interval = setInterval(async () => {
  const statusResponse = await fetch(`/actions/s3-spaces-migration/migration/get-queue-status?jobId=${jobId}`);
  const status = await statusResponse.json();

  console.log(`Progress: ${status.job.progressLabel}`);

  if (status.status === 'completed' || status.status === 'failed') {
    clearInterval(interval);
    console.log('Migration finished with status:', status.status);
  }
}, 5000); // Check every 5 seconds
```

### Example 2: Resume from Checkpoint via Queue

```javascript
const response = await fetch('/actions/s3-spaces-migration/migration/run-command-queue', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    command: 'image-migration/migrate',
    args: {
      resume: true,
      checkpointId: 'migration-1699999999-abc123' // Optional: specific checkpoint
    }
  })
});
```

### Example 3: Queue Other Long-Running Commands

```javascript
// Queue URL replacement
await fetch('/actions/s3-spaces-migration/migration/run-command-queue', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    command: 'url-replacement/replace-s3-urls',
    args: {}
  })
});

// Queue transform generation
await fetch('/actions/s3-spaces-migration/migration/run-command-queue', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    command: 'transform-pre-generation/generate',
    args: {}
  })
});
```

## Integration with Existing Systems

### Checkpoint/Resume System

The queue jobs work seamlessly with the existing checkpoint system:

1. **MigrationStateService**: Jobs save progress to the `migration_state` table
2. **CheckpointManager**: The console controller continues to save checkpoints as usual
3. **Resume Support**: If a job fails or is interrupted, you can resume from the last checkpoint:

```javascript
// Resume from last checkpoint
await fetch('/actions/s3-spaces-migration/migration/run-command-queue', {
  method: 'POST',
  headers: { /* ... */ },
  body: JSON.stringify({
    command: 'image-migration/migrate',
    args: { resume: true }
  })
});
```

### Progress Tracking

Progress is tracked at multiple levels:

1. **Queue Progress** (`progress` field): Overall job progress (0.0 to 1.0)
2. **Migration State** (`migration_state` table): Detailed progress with phase, processed count, etc.
3. **Checkpoint Files**: Complete state snapshots for recovery

The queue job automatically:
- Parses console output for progress markers
- Updates queue progress based on phase completion
- Reads from `migration_state` table for accurate counts
- Blends multiple signals for smooth progress display

## Running the Queue

### Option 1: Web-Based Queue Runner (Recommended)

Craft automatically runs queue jobs via web requests. This works out of the box with no configuration.

**Pros:**
- No setup required
- Works in all environments
- Integrated with Craft

**Cons:**
- Depends on web traffic
- Can be slower for immediate execution

### Option 2: CLI Queue Runner (Preferred for Production)

For better performance, run the queue via CLI:

```bash
# Run queue daemon (keeps running)
./craft queue/listen

# Or use a supervisor/systemd service
# See: https://craftcms.com/docs/4.x/config/app.html#queue-component
```

**Benefits:**
- Immediate execution
- Better for long-running jobs
- More reliable for large migrations

### Option 3: Cron-Based Queue Runner

Add to crontab:

```cron
* * * * * /path/to/craft queue/run
```

This runs the queue every minute.

## Backward Compatibility

The existing streaming-based execution (`stream=true` parameter) remains fully functional:

```javascript
// Still works - streams output in real-time
await fetch('/actions/s3-spaces-migration/migration/run-command', {
  method: 'POST',
  body: JSON.stringify({
    command: 'image-migration/migrate',
    stream: true
  })
});
```

**When to use streaming:**
- Debugging (see real-time output)
- Short commands
- When you want to monitor output closely

**When to use queue:**
- Long-running migrations
- When you need to close the browser
- Production environments
- When you want robust retry/recovery

## Monitoring

### Check Queue Status in Craft CP

1. Go to **Utilities** → **Queue Manager**
2. View all queued jobs
3. See progress, status, and errors
4. Retry failed jobs manually

### Monitor via API

Use the provided endpoints to build custom monitoring:

```javascript
// Get all jobs
const jobs = await fetch('/actions/s3-spaces-migration/migration/get-queue-jobs')
  .then(r => r.json());

// Get specific migration progress
const migration = await fetch('/actions/s3-spaces-migration/migration/get-migration-progress?migrationId=queue-123')
  .then(r => r.json());
```

### Monitor via Console

Use the existing monitor command:

```bash
./craft s3-spaces-migration/image-migration/monitor
```

This works for both queue-based and streaming migrations.

## Error Handling

### Job Failures

If a queue job fails:

1. **Check Queue Manager**: View error details in Craft CP
2. **Check Logs**: Review `storage/logs/web.log` and `storage/logs/queue.log`
3. **Check Migration State**: Query `migration_state` table for details
4. **Resume if Possible**: Most failures can be resumed from checkpoint

### Retry Strategy

Queue jobs are configured to **NOT auto-retry** because:
- The checkpoint system provides better recovery
- Manual intervention is often needed
- Prevents cascading failures

To retry a failed migration:

```javascript
// Resume from last checkpoint
await fetch('/actions/s3-spaces-migration/migration/run-command-queue', {
  method: 'POST',
  body: JSON.stringify({
    command: 'image-migration/migrate',
    args: { resume: true }
  })
});
```

## Best Practices

### 1. Use Queue for All Long-Running Operations

**Recommended commands for queue execution:**
- `image-migration/migrate`
- `url-replacement/replace-s3-urls`
- `transform-pre-generation/generate`
- `migration-diag/analyze`
- `extended-url-replacement/*`

### 2. Monitor Progress

Always monitor queued jobs:
- Poll `get-queue-status` endpoint
- Check Craft CP Queue Manager
- Use `image-migration/monitor` command

### 3. Handle Page Refresh

If the dashboard is refreshed during migration:
1. Job continues running in background
2. Query `get-queue-jobs` to find the running job
3. Poll `get-queue-status` with the job ID
4. Resume monitoring progress

### 4. Set Up CLI Queue Runner for Production

For production environments:
```bash
# Install supervisor or systemd service
# Run: ./craft queue/listen

# Or use cron
* * * * * /path/to/craft queue/run
```

### 5. Test with Dry Run First

Before running actual migration:
```javascript
await fetch('/actions/s3-spaces-migration/migration/run-command-queue', {
  method: 'POST',
  body: JSON.stringify({
    command: 'image-migration/migrate',
    dryRun: true  // Test without changes
  })
});
```

## Troubleshooting

### Job Stuck in Pending

**Problem**: Job shows as pending but never starts
**Solution**:
- Check if queue runner is active: `./craft queue/info`
- Run queue manually: `./craft queue/run`
- Check logs for errors

### Job Shows as Completed but Migration Incomplete

**Problem**: Queue job completed but migration shows as incomplete
**Solution**:
- Check exit code in logs
- Review `migration_state` table
- Look for checkpoints in `storage/migration-checkpoints`
- Resume from checkpoint if needed

### Progress Not Updating

**Problem**: Job shows 0% progress for extended time
**Solution**:
- Check if process is actually running: `ps aux | grep craft`
- Review logs for errors
- Query `migration_state` table directly
- Job might be in initialization phase (normal for large datasets)

### How to Cancel a Running Queue Job

**Option 1**: Via Craft CP
1. Go to **Utilities** → **Queue Manager**
2. Find the job
3. Click "Release" or "Delete"

**Option 2**: Via CLI
```bash
# Kill the specific job
./craft queue/release <job-id>

# Or kill the queue process
pkill -f "queue/listen"
```

**Option 3**: Via Database
```sql
DELETE FROM queue WHERE id = <job-id>;
```

## Security Considerations

### CSRF Protection

All queue dispatch endpoints require CSRF tokens:
```javascript
fetch('/actions/s3-spaces-migration/migration/run-command-queue', {
  headers: {
    'X-CSRF-Token': document.querySelector('[name="CSRF_TOKEN"]').value
  }
})
```

### Permission Requirements

Queue jobs inherit permissions from the dispatch request:
- User must have access to the migration dashboard
- Commands are validated against `getAllowedCommands()` list
- Jobs run with web process permissions

### Timeout Protection

Jobs have configurable TTR (Time To Reserve):
- `MigrationJob`: 48 hours (very large migrations)
- `ConsoleCommandJob`: 2-48 hours (depends on command)
- Prevents runaway jobs
- Automatically released if TTR exceeded

## Performance Considerations

### Memory Usage

Queue jobs spawn console processes:
- Memory usage depends on command
- Large migrations may use 500MB-2GB
- Monitor with: `ps aux | grep craft`

### Database Impact

Progress tracking writes to database:
- Every 5 seconds during execution
- Uses `migration_state` table
- Minimal impact (~1 row update per 5s)

### File System

Checkpoints are written periodically:
- Location: `storage/migration-checkpoints/`
- Frequency: Configurable (default: every 10 batches)
- Size: ~1-10MB per checkpoint

## Migration from Streaming to Queue

To migrate existing dashboard code:

**Before (Streaming):**
```javascript
const response = await fetch('/actions/s3-spaces-migration/migration/run-command', {
  method: 'POST',
  body: JSON.stringify({
    command: 'image-migration/migrate',
    stream: true
  })
});
```

**After (Queue):**
```javascript
// 1. Dispatch to queue
const dispatchResponse = await fetch('/actions/s3-spaces-migration/migration/run-command-queue', {
  method: 'POST',
  body: JSON.stringify({
    command: 'image-migration/migrate'
  })
});
const { jobId } = await dispatchResponse.json();

// 2. Poll for progress
const checkProgress = setInterval(async () => {
  const status = await fetch(`/actions/s3-spaces-migration/migration/get-queue-status?jobId=${jobId}`)
    .then(r => r.json());

  // Update UI with status.job.progressLabel

  if (status.status === 'completed' || status.status === 'failed') {
    clearInterval(checkProgress);
  }
}, 5000);
```

## Summary

The queue system provides a robust, production-ready solution for long-running migrations:

✅ **Survives page refreshes**
✅ **Background execution**
✅ **Progress tracking**
✅ **Integrates with checkpoints**
✅ **Backward compatible**
✅ **Easy to monitor**
✅ **Scales to large datasets**

Use the queue for all migrations in production environments to ensure reliability and recoverability.
