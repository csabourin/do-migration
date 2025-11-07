<?php

use craft\console\Application as ConsoleApplication;
use craft\web\Application as WebApplication;
use csabourin\craftS3SpacesMigration\MigrationModule;
use yii\base\Event;
use yii\base\Module as YiiModule;

// Only register if Craft classes are available
if (
    !class_exists(Event::class) ||
    !class_exists(WebApplication::class) ||
    !class_exists(ConsoleApplication::class)
) {
    return;
}

// Ensure the module class can be autoloaded when Craft attempts to bootstrap it
// (e.g. when the console help command inspects registered modules). Guard this
// with `class_exists(..., false)` so we only include the file when the autoloader
// has not already loaded it, and to play nicely with installations that rely on
// Composer's optimized/authoritative classmap settings.
if (!class_exists(MigrationModule::class, false) && is_file(__DIR__ . '/modules/module.php')) {
    require_once __DIR__ . '/modules/module.php';
}

/**
 * Automatically registers and bootstraps the S3 to Spaces migration module when the
 * Craft application starts. This allows the package to be installed through
 * Composer without requiring manual edits to `config/app.php`.
 */
$registerModule = static function($event) {
    $app = $event->sender;
    $handle = 's3-spaces-migration';

    if ($app->getModule($handle, false) === null) {
        $app->setModule($handle, [
            'class' => MigrationModule::class,
        ]);
    }

    // Ensure the module is part of the bootstrap sequence so it is loaded for
    // both web and console requests (required for CP nav + CLI commands).
    if (!in_array($handle, $app->bootstrap, true)) {
        $app->bootstrap[] = $handle;
    }
};

// Use the event name string directly for broad Yii compatibility.
Event::on(WebApplication::class, 'init', $registerModule);
Event::on(ConsoleApplication::class, 'init', $registerModule);
