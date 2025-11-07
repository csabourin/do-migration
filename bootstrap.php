<?php

use csabourin\craftS3SpacesMigration\NCCModule;
use yii\base\Component;
use yii\base\Event;

// Only register if Craft classes are available
if (
    !class_exists(Event::class) ||
    !class_exists(WebApplication::class) ||
    !class_exists(ConsoleApplication::class) ||
    !class_exists(Component::class)
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

    $app->moduleManager->bootstrapModule($handle);
};

// Use the base component event constant to avoid referencing Craft-specific constants
Event::on(WebApplication::class, Component::EVENT_INIT, $registerModule);
Event::on(ConsoleApplication::class, Component::EVENT_INIT, $registerModule);
