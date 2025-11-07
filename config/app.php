<?php

/**
 * Craft CMS Application Configuration
 *
 * This file registers the S3 Spaces Migration module with Craft CMS.
 * It ensures the module is loaded early in the bootstrap process so that
 * CP navigation and other events are registered properly.
 *
 * @see https://craftcms.com/docs/4.x/config/app.html
 */

use craft\helpers\App;

return [
    'modules' => [
        's3-spaces-migration' => [
            'class' => \csabourin\craftS3SpacesMigration\MigrationModule::class,
        ],
    ],
    'bootstrap' => [
        's3-spaces-migration',
    ],
];
