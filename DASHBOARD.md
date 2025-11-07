# Migration Dashboard Documentation

## Overview

The Migration Dashboard provides a user-friendly Control Panel interface for orchestrating the complete AWS S3 to DigitalOcean Spaces migration. It offers step-by-step guidance through all 14 migration modules with real-time progress tracking and comprehensive status monitoring.

## Accessing the Dashboard

1. **Via CP Navigation**: Click on **Migration** in the main Control Panel navigation menu
2. **Direct URL**: Navigate to `/admin/s3-spaces-migration/migration`

## Dashboard Features

### 1. Configuration Status Panel

The top section displays your current configuration status:

- **DO Credentials** - DigitalOcean Spaces access credentials
- **DO Bucket** - Target bucket name
- **AWS Config** - Source AWS S3 configuration
- **DO Base URL** - DigitalOcean Spaces base URL

**Actions:**
- **Test DO Connection** - Validates your DigitalOcean credentials
- **View Filesystems** - Quick link to Craft's filesystem settings

### 2. Resume Banner

If a previous migration was interrupted, you'll see a resume banner with:
- Information about the checkpoint location
- **Resume Migration** button - Continue from where you left off
- **View Checkpoint** button - Inspect checkpoint details

### 3. Migration Phases (8 Phases)

The dashboard organizes the 14 modules into 8 logical phases:

#### Phase 0: Setup & Configuration
- **Create DO Filesystems** - Create DigitalOcean Spaces filesystem configurations
- **Configure Volumes** - Set transform filesystem and configure volume layouts

#### Phase 1: Pre-Flight Checks
- **Run Pre-Flight Checks** - Validate configuration and environment (10 automated checks)

#### Phase 2: URL Replacement
- **Replace Database URLs** - Update AWS URLs in content tables
- **Extended URL Replacement** - Update URLs in additional tables and JSON fields

#### Phase 3: Template Updates
- **Scan Templates** - Find hardcoded AWS URLs in Twig templates
- **Replace Template URLs** - Replace with environment variables

#### Phase 4: File Migration
- **Migrate Files** - Copy all files from AWS S3 to DigitalOcean Spaces
  - Supports **Dry Run** - Test without making changes
  - Supports **Resume** - Continue interrupted migrations
  - Shows real-time progress

#### Phase 5: Filesystem Switch
- **Preview Switch** - Preview filesystem switch operations
- **Switch to DO** - Switch all volumes to DigitalOcean Spaces

#### Phase 6: Validation
- **Verify Migration** - Validate migration success and asset integrity

#### Phase 7: Image Transforms
- **Discover Transforms** - Find all image transformations
- **Pre-Generate Transforms** - Generate transforms on DO to prevent broken images

#### Phase 8: Audit & Diagnostics
- **Audit Plugins** - Scan plugin configs for hardcoded URLs
- **Scan Static Assets** - Scan JS/CSS/SCSS for hardcoded URLs
- **Filesystem Diagnostics** - Compare and analyze filesystems

### 4. Module Cards

Each module is displayed as a card with:

**Header:**
- Status indicator (pending/running/completed)
- Module title with critical badge if applicable
- Description of what the module does
- Estimated duration

**Actions:**
- **Dry Run** (if supported) - Test the operation without making changes
- **Run [Module]** - Execute the module
- **View Logs** - Display output from previous runs

**Progress Section** (shown during execution):
- Progress bar with percentage
- Current status text
- Estimated time remaining

**Output Section** (expandable):
- Command output in a console-style view
- Scrollable with syntax highlighting
- Clear button to reset output

### 5. Rollback Section

Located at the bottom of the dashboard:

- **Rollback Migration** - Undo migration changes
  - Can rollback to a specific phase
  - Shows warning about irreversibility
- **View Change Log** - Access detailed change logs

## Using the Dashboard

### Starting a New Migration

1. **Verify Configuration**
   - Check that all configuration items show ✓
   - Click "Test DO Connection" to verify credentials

2. **Follow Phase Order**
   - Start with Phase 0 (Setup & Configuration)
   - Progress through phases sequentially
   - Critical modules are marked with a red badge

3. **Use Dry Run**
   - Before running critical operations, use "Dry Run"
   - Review the output to ensure expected behavior
   - Then run the actual command

4. **Monitor Progress**
   - Watch the progress bar during long operations
   - Check the output console for detailed logs
   - Status indicators show completion (✓) or running state

### Resuming an Interrupted Migration

1. Look for the yellow resume banner at the top
2. Click "View Checkpoint" to inspect the saved state
3. Click "Resume Migration" on the appropriate module
4. The migration will continue from where it stopped

### Handling Errors

If a module fails:

1. **Check Output** - Click "View Logs" to see error details
2. **Review Configuration** - Ensure all settings are correct
3. **Check Logs** - Access full logs in `storage/logs/`
4. **Retry or Rollback** - Either fix and retry, or rollback

### Rolling Back

If you need to undo the migration:

1. Click "Rollback Migration" in the rollback section
2. Optionally select a specific phase to rollback to
3. Confirm the operation (this cannot be undone)
4. Monitor the rollback progress

## Real-Time Features

### Progress Tracking
- Live progress bars during execution
- Percentage completion
- Status text updates

### State Persistence
- Module completion status is saved
- Survives page refreshes
- Stored in browser localStorage

### AJAX Operations
- Commands run asynchronously
- Page remains responsive
- Real-time output streaming

## Keyboard Shortcuts

- **ESC** - Close open modals
- **Click outside modal** - Close modal

## Tips for Best Results

1. **Run Pre-Flight Checks First**
   - Always start with Phase 1 pre-flight checks
   - Fix any issues before proceeding

2. **Use Dry Run for Critical Operations**
   - Test URL replacements before applying
   - Verify template changes before committing

3. **Monitor Long Operations**
   - File migration can take hours
   - Keep the browser tab open
   - If interrupted, use resume functionality

4. **Keep Backups**
   - Backup your database before URL replacement
   - Ensure AWS files remain until validation passes

5. **Validate Thoroughly**
   - Run Phase 6 validation after migration
   - Test your site thoroughly
   - Check image transforms load correctly

6. **Document Custom Changes**
   - Note any manual fixes needed
   - Keep track of plugin-specific updates

## Troubleshooting

### Dashboard Not Showing

- Ensure the module is properly loaded
- Check `storage/logs/web.log` for errors
- Verify template files are in correct location

### Commands Not Executing

- Check CSRF token is valid
- Ensure you have proper permissions
- Look for JavaScript errors in browser console

### Progress Not Updating

- Some commands run fully before returning
- Check the output section for results
- Long operations may appear frozen

### CSS/JS Not Loading

- Clear Craft's cache: `./craft clear-caches/all`
- Hard refresh browser: Ctrl+Shift+R (Cmd+Shift+R on Mac)
- Check file permissions on template directories

## Architecture

### Controller: `MigrationController.php`

Located in `modules/controllers/MigrationController.php`

**Endpoints:**
- `actionIndex()` - Render dashboard
- `actionGetStatus()` - Get migration status (AJAX)
- `actionRunCommand()` - Execute console command (AJAX)
- `actionGetCheckpoint()` - Get checkpoint info (AJAX)
- `actionGetLogs()` - Retrieve log entries (AJAX)
- `actionTestConnection()` - Test DO connection (AJAX)

### Template: `dashboard.twig`

Located in `modules/templates/s3-spaces-migration/dashboard.twig`

**Sections:**
- Header with phase indicator
- Configuration status grid
- Resume banner (conditional)
- Phase sections with module cards
- Rollback controls
- Modals for output and checkpoints

### JavaScript: `dashboard.js`

Located in `modules/templates/s3-spaces-migration/js/dashboard.js`

**Features:**
- Event handling for all interactions
- AJAX calls to controller endpoints
- Progress tracking and updates
- State management with localStorage
- Modal controls

### CSS: `dashboard.css`

Located in `modules/templates/s3-spaces-migration/css/dashboard.css`

**Styling:**
- Gradient headers
- Card-based layout
- Progress bars and animations
- Modal designs
- Responsive breakpoints

## Security Considerations

- All requests use CSRF tokens
- Anonymous access is disabled by default
- Commands are validated against allowlist
- Output is sanitized before display

## Performance Notes

- Commands execute via shell exec
- Long operations may timeout (adjust PHP settings if needed)
- Checkpoints prevent data loss
- State saved locally for quick resumption

## Future Enhancements

Potential improvements for future versions:

- WebSocket support for real-time streaming
- Email notifications on completion
- Scheduled migrations
- Multi-environment support
- Migration templates/presets
- Detailed analytics and reporting

## Support

For issues or questions:

1. Check `storage/logs/` for detailed error logs
2. Review console output for specific errors
3. Consult `ARCHITECTURE.md` for system details
4. Review `ROLLBACK_IMPROVEMENT_PLAN.md` for recovery options

## Related Documentation

- `ARCHITECTURE.md` - Complete system architecture
- `ROLLBACK_IMPROVEMENT_PLAN.md` - Rollback system details
- `modules/console/controllers/CONFIG_QUICK_REFERENCE.md` - Configuration guide
- `modules/console/controllers/README_FR.md` - French migration guide
