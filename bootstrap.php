<?php

declare(strict_types=1);

use craft\base\ApplicationTrait;
use csabourin\craftS3SpacesMigration\Bootstrap;
use yii\base\Event;

/**
 * S3 to Spaces Migration Module Bootstrap File
 *
 * This file is loaded automatically by Composer's autoload mechanism. In
 * earlier iterations we relied on Composer's `extra.bootstrap` support to
 * execute the `csabourin\\craftS3SpacesMigration\\Bootstrap` class. Some Craft
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

if (!function_exists('craft_s3_spaces_migration_register_module')) {
    /**
     * Register the migration module with Craft's application bootstrap cycle.
     */
    function craft_s3_spaces_migration_register_module(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $registered = true;

        if (!class_exists(ApplicationTrait::class)) {
            return;
        }

        Event::on(
            ApplicationTrait::class,
            ApplicationTrait::EVENT_INIT,
            static function () {
                $bootstrap = new Bootstrap();
                $bootstrap->bootstrap(Craft::$app);

                if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
                    Craft::info('[S3 Migration] bootstrap.php initialised module via ApplicationTrait::EVENT_INIT', __METHOD__);
                }
            }
        );
    }
}
