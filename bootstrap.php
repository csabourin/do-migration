<?php

use craft\console\Application as ConsoleApplication;
use craft\web\Application as WebApplication;
use csabourin\craftS3SpacesMigration\NCCModule;
use yii\base\Event;

// Only register if Craft classes are available
if (
    !class_exists(Event::class) ||
    !class_exists(WebApplication::class) ||
    !class_exists(ConsoleApplication::class)
) {
    return;
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
            'class' => NCCModule::class,
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
