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

use craft\console\Application as ConsoleApplication;
use craft\web\Application as WebApplication;
use yii\base\Event;

// Note: We don't need to manually require MigrationModule.php here.
// Composer's PSR-4 autoloading will handle it when the class is needed.
// The module class path: csabourin\craftS3SpacesMigration\MigrationModule
// is mapped to modules/MigrationModule.php in composer.json autoload section.

// Bootstrap function to register the module
$bootstrap = function() {
    // Only proceed if Craft is available
    if (!class_exists('Craft')) {
        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            error_log('[S3 Migration] Craft class not available yet');
        }
        return;
    }

    $handle = 's3-spaces-migration';

    // Debug: Log that bootstrap was called
    if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
        error_log('[S3 Migration] Bootstrap function called');
    }

    // Get the Craft application instance
    $app = \Craft::$app;

    if ($app === null) {
        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            error_log('[S3 Migration] Craft app is null, skipping bootstrap');
        }
        return;
    }

    if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
        error_log('[S3 Migration] Craft app is available: ' . get_class($app));
    }

    // Register module if not already registered
    if (!$app->hasModule($handle)) {
        $app->setModule($handle, [
            'class' => 'csabourin\\craftS3SpacesMigration\\MigrationModule',
        ]);
        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            error_log('[S3 Migration] Module registered: ' . $handle);
        }
    } else {
        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            error_log('[S3 Migration] Module already registered: ' . $handle);
        }
    }

    // Ensure module is in bootstrap array so it gets initialized early
    if (!in_array($handle, $app->bootstrap, true)) {
        $app->bootstrap[] = $handle;
        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            error_log('[S3 Migration] Added to bootstrap array');
        }
    } else {
        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            error_log('[S3 Migration] Already in bootstrap array');
        }
    }

    if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
        error_log('[S3 Migration] Bootstrap complete');
    }
};

// Try to register immediately if Craft is already loaded
if (class_exists('Craft', false) && isset(\Craft::$app)) {
    if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
        error_log('[S3 Migration] Craft already initialized, registering immediately');
    }
    $bootstrap();
}

// Also register event handlers for delayed initialization
// Register for web requests - use EVENT_INIT for proper timing
if (class_exists(WebApplication::class, false)) {
    Event::on(
        WebApplication::class,
        WebApplication::EVENT_INIT,
        $bootstrap
    );
    if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
        error_log('[S3 Migration] Registered EVENT_INIT handler for WebApplication');
    }
}

// Register for console requests
if (class_exists(ConsoleApplication::class, false)) {
    Event::on(
        ConsoleApplication::class,
        ConsoleApplication::EVENT_INIT,
        $bootstrap
    );
    if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
        error_log('[S3 Migration] Registered EVENT_INIT handler for ConsoleApplication');
    }
}
