<?php
/**
 * S3 to Spaces Migration Module Bootstrap File
 *
 * This file is loaded automatically by Composer's autoload mechanism.
 * The actual bootstrap logic is handled by:
 * - csabourin\craftS3SpacesMigration\Bootstrap class (implements BootstrapInterface)
 * - Registered in composer.json extra.bootstrap for automatic Yii2/Craft loading
 *
 * NOTE: If the automatic bootstrap via composer.json doesn't work with your
 * Craft installation, you can manually register the module by adding this to
 * your Craft installation's config/app.php:
 *
 * return [
 *     'modules' => [
 *         's3-spaces-migration' => [
 *             'class' => \csabourin\craftS3SpacesMigration\MigrationModule::class,
 *         ],
 *     ],
 *     'bootstrap' => ['s3-spaces-migration'],
 * ];
 */

if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
    error_log('[S3 Migration] bootstrap.php loaded - module bootstrap handled by Bootstrap class');
}
