<?php

/**
 * Craft CMS Application Configuration (REFERENCE ONLY)
 *
 * ⚠️ IMPORTANT: This file is for REFERENCE only when the module is installed
 * via Composer. The module is automatically registered via bootstrap.php.
 *
 * When you install this module via Composer, the bootstrap.php file is
 * automatically loaded and registers the module with Craft CMS using event
 * handlers. You do NOT need to manually copy this file to your Craft
 * installation's config/ directory.
 *
 * MANUAL INSTALLATION ONLY:
 * If you want to manually register this module without using the automatic
 * bootstrap.php registration, you can copy the configuration below to your
 * Craft installation's config/app.php file.
 *
 * @see https://craftcms.com/docs/4.x/config/app.html
 */

use craft\helpers\App;

return [
    'modules' => [
        'spaghetti-migrator' => [
            'class' => \csabourin\spaghettiMigrator\MigrationModule::class,
        ],
    ],
    'bootstrap' => [
        'spaghetti-migrator',
    ],
];
