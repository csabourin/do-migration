<?php

use craft\console\Application as ConsoleApplication;
use craft\web\Application as WebApplication;
use modules\NCCModule;
use yii\base\Event;

if (!class_exists(Event::class) || !class_exists(WebApplication::class) || !class_exists(ConsoleApplication::class)) {
    return;
}

/**
 * Automatically registers and bootstraps the NCC migration module when the
 * Craft application starts. This allows the package to be installed through
 * Composer without requiring manual edits to `config/app.php`.
 */
$registerModule = static function($event) {
    $app = $event->sender;
    $handle = 'ncc-module';

    if ($app->getModule($handle, false) === null) {
        $app->setModule($handle, [
            'class' => NCCModule::class,
        ]);
    }

    $app->moduleManager->bootstrapModule($handle);
};

Event::on(WebApplication::class, WebApplication::EVENT_INIT, $registerModule);
Event::on(ConsoleApplication::class, ConsoleApplication::EVENT_INIT, $registerModule);
