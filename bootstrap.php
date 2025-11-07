<?php

use csabourin\craftS3SpacesMigration\NCCModule;
use yii\base\Application as BaseApplication;
use yii\base\Event;

// Only register if Craft classes are available
if (
    !class_exists(Event::class) ||
    !class_exists(BaseApplication::class)
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

// Hook into the base Yii application init event so we cover both web and console contexts
Event::on(BaseApplication::class, BaseApplication::EVENT_INIT, $registerModule);
