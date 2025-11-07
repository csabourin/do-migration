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
use csabourin\craftS3SpacesMigration\MigrationModule;
use yii\base\Event;

// Ensure module class is available
if (!class_exists(MigrationModule::class, false)) {
    $modulePath = __DIR__ . '/modules/MigrationModule.php';
    if (is_file($modulePath)) {
        require_once $modulePath;
    }
}

// Bootstrap function to register the module
$bootstrap = function() {
    $handle = 's3-spaces-migration';

    // Debug: Log that bootstrap was called
    if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
        error_log('[S3 Migration] Bootstrap function called for ' . get_class(\Craft::$app));
    }

    // Get the Craft application instance
    $app = \Craft::$app;

    if ($app === null) {
        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            error_log('[S3 Migration] Craft app is null, skipping bootstrap');
        }
        return;
    }

    // Register module if not already registered
    if (!$app->hasModule($handle)) {
        $app->setModule($handle, [
            'class' => MigrationModule::class,
        ]);
        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            error_log('[S3 Migration] Module registered with class config');
        }
    }

    // Ensure module is in bootstrap array so it gets initialized early
    if (!in_array($handle, $app->bootstrap, true)) {
        $app->bootstrap[] = $handle;
        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            error_log('[S3 Migration] Added to bootstrap array');
        }
    }

    if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
        error_log('[S3 Migration] Bootstrap complete');
    }
};

// Register for web requests - use EVENT_BEFORE_REQUEST for earlier initialization
if (class_exists(WebApplication::class)) {
    Event::on(
        WebApplication::class,
        WebApplication::EVENT_BEFORE_REQUEST,
        $bootstrap
    );
}

// Register for console requests
if (class_exists(ConsoleApplication::class)) {
    Event::on(
        ConsoleApplication::class,
        ConsoleApplication::EVENT_BEFORE_REQUEST,
        $bootstrap
    );
}
