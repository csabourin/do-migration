<?php
/**
 * S3 to Spaces Migration Module Bootstrap File
 *
 * This file is loaded automatically by Composer's autoload mechanism. In
 * earlier iterations we relied on Composer's `extra.bootstrap` support to
 * execute the `csabourin\craftS3SpacesMigration\Bootstrap` class. Some Craft
 * installations (especially when installed as a Composer dependency) never
 * triggered that bootstrap hook, which meant the module was registered in the
 * vendor directory but never loaded by Craft. As a result the module wouldn't
 * appear in the Control Panel navigation and its console commands were
 * missing from `./craft help` output.
 *
 * To make the bootstrap deterministic across all environments we now hook into
 * Craft's application initialisation lifecycle directly from this file. Once
 * Craft has constructed the application instance (web or console) we delegate
 * to the Bootstrap class so it can register the module and ensure it runs
 * during the application's bootstrap sequence.
 */
require_once __DIR__ . '/modules/module.php';

$bootstrap = new Bootstrap();

use craft\base\ApplicationTrait;
use csabourin\craftS3SpacesMigration\Bootstrap;
use yii\base\Event;

$bootstrap = new Bootstrap();

Event::on(
    ApplicationTrait::class,
    ApplicationTrait::EVENT_INIT,
    static function() use ($bootstrap) {
        $bootstrap->bootstrap(Craft::$app);

use craft\Craft;
use craft\console\Application as ConsoleApplication;
use craft\web\Application as WebApplication;
use csabourin\craftS3SpacesMigration\Bootstrap;
use yii\base\Event;

// Manually require the module and bootstrap classes so they are always
// available even if Composer's autoloader has not yet been fully
// initialised for PSR-4 lookups (which can occur while `autoload/files`
// entries are being included).
require_once __DIR__ . '/modules/module.php';
require_once __DIR__ . '/modules/Bootstrap.php';

$bootstrap = new Bootstrap();

$bootstrapModule = static function($app) use ($bootstrap): void {
    if ($app === null) {
        return;
    }

    $bootstrap->bootstrap($app);

    if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
        Craft::info('[S3 Migration] bootstrap.php ensured module registration for ' . get_class($app), __FILE__);
    }
};

if (Craft::$app !== null) {
    $bootstrapModule(Craft::$app);
}

Event::on(
    WebApplication::class,
    WebApplication::EVENT_BEFORE_REQUEST,
    static function(Event $event) use ($bootstrapModule): void {
        $bootstrapModule($event->sender);
    }
);

Event::on(
    ConsoleApplication::class,
    ConsoleApplication::EVENT_BEFORE_REQUEST,
    static function(Event $event) use ($bootstrapModule): void {
        $bootstrapModule($event->sender);
    }
);
