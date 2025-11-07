<?php
/**
 * S3 to Spaces Migration Module Bootstrap
 *
 * Automatically registers and bootstraps the migration module when Craft starts.
 * This allows installation via Composer without manual config/app.php edits.
 */

// Debug: Confirm this file is being loaded
if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
    error_log('[S3 Migration] bootstrap.php file loaded from: ' . __FILE__);
}

use csabourin\craftS3SpacesMigration\MigrationModule;

// Ensure module class is available for Craft to load
// Note: The actual module registration happens in config/app.php
// This file just ensures the class is autoloaded when needed
if (!class_exists(MigrationModule::class, false)) {
    $modulePath = __DIR__ . '/modules/MigrationModule.php';
    if (is_file($modulePath)) {
        require_once $modulePath;
    }
}

// Debug: Confirm bootstrap.php was loaded
if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
    error_log('[S3 Migration] bootstrap.php loaded - module class available for config/app.php');
}
