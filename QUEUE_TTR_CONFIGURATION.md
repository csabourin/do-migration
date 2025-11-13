# Queue TTR (Time to Reserve) Configuration

## Issue: Jobs Timing Out at 300 Seconds

If you see migration jobs being queued successfully but then becoming "pending" and disappearing after ~5 minutes (300 seconds), this is due to Craft's default queue TTR (Time to Reserve) configuration.

### What is TTR?

TTR is the maximum amount of time a queue worker will reserve a job before releasing it back to the queue. If a job takes longer than the TTR to complete, it will be released and may appear as "pending" or disappear from the active jobs list.

### Default Behavior

- **Craft's default TTR**: 300 seconds (5 minutes)
- **Migration job TTR requirements**: Up to 48 hours for large migrations

## Solution: Configure Queue TTR in Your Craft Installation

You need to add queue configuration to your **Craft installation's** `config/app.php` file (NOT the module's config file).

### Steps to Fix

1. **Locate your Craft installation's config directory**:
   ```
   /path/to/your/craft/config/app.php
   ```

2. **Add or update the queue configuration**:

```php
<?php

use craft\helpers\App;

return [
    'id' => App::env('CRAFT_APP_ID') ?: 'CraftCMS',
    'modules' => [
        // ... your existing modules
    ],
    'bootstrap' => [
        // ... your existing bootstrap
    ],
    'components' => [
        'queue' => [
            'ttr' => 48 * 60 * 60, // 48 hours in seconds (172,800)
            // Alternative: Set a reasonable maximum, e.g., 12 hours
            // 'ttr' => 12 * 60 * 60, // 43,200 seconds
        ],
    ],
];
```

3. **Alternative: Per-Environment Configuration**

If you want different TTR values for different environments:

```php
<?php

use craft\helpers\App;

$ttr = match(App::env('CRAFT_ENVIRONMENT')) {
    'production' => 48 * 60 * 60,  // 48 hours
    'staging' => 12 * 60 * 60,     // 12 hours
    default => 2 * 60 * 60,        // 2 hours for dev
};

return [
    'components' => [
        'queue' => [
            'ttr' => $ttr,
        ],
    ],
];
```

4. **Clear Craft's caches**:
   ```bash
   php craft clear-caches/all
   ```

5. **Restart the queue workers** (if running as daemons):
   ```bash
   # If using supervisord or similar
   supervisorctl restart craft-queue

   # Or if using Craft's queue daemon
   php craft queue/listen --verbose
   ```

## Verification

After applying the configuration:

1. **Check that the configuration is loaded**:
   ```bash
   php craft queue/info
   ```

2. **Queue a migration job** from the web interface

3. **Check the queue status**:
   - The job should show "Time to reserve: 172800 seconds" (or your configured value)
   - The job should continue running beyond 5 minutes
   - Progress should continue updating throughout the migration

## Migration Job TTR Values

The migration module automatically sets appropriate TTR values:

- **MigrationJob** (image-migration/migrate): 48 hours
- **ConsoleCommandJob** (long-running commands): 48 hours
- **ConsoleCommandJob** (other commands): 2 hours (default)

However, these values are **only effective** if your Craft queue configuration allows them. If the queue's `ttr` is set lower, it will override the job's TTR.

## Troubleshooting

### Jobs still timing out?

1. **Check if config is being loaded**:
   ```php
   // Add to a test controller action:
   $ttr = Craft::$app->queue->ttr;
   var_dump($ttr); // Should show your configured value
   ```

2. **Check for queue configuration in other locations**:
   - `config/app.php`
   - `config/app.web.php`
   - `config/app.console.php`
   - Environment variables (`.env` file)

3. **Check queue driver**:
   Some queue drivers (like Redis, SQS) may have their own TTR configurations that override Craft's settings.

4. **Server timeouts**:
   Ensure your web server (Nginx, Apache) and PHP don't have timeouts that conflict:
   - PHP `max_execution_time`
   - Nginx `fastcgi_read_timeout`
   - Apache `TimeOut`

## Alternative: Run Migrations via CLI

If you cannot modify the queue configuration, you can run migrations directly via CLI:

```bash
# Run migration directly (not queued)
php craft s3-spaces-migration/image-migration/migrate

# With resume capability
php craft s3-spaces-migration/image-migration/migrate --resume
```

CLI execution bypasses the queue TTR limitations entirely.

## Related Documentation

- [Craft Queue Documentation](https://craftcms.com/docs/4.x/extend/queue-jobs.html)
- [Craft App Configuration](https://craftcms.com/docs/4.x/config/app.html)
- [Module QUEUE_SYSTEM.md](./QUEUE_SYSTEM.md) - Details about the queue implementation
