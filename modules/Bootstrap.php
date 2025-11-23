<?php

namespace csabourin\spaghettiMigrator;

use Craft;
use yii\base\BootstrapInterface;

/**
 * Bootstrap class for auto-loading the S3 Spaces Migration module
 *
 * This class implements Yii's BootstrapInterface, which allows it to be
 * automatically executed during Craft's bootstrap phase when registered
 * via Composer's autoload files.
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * Bootstrap method called by Yii during application initialization
     *
     * @param \yii\base\Application $app The application instance
     */
    public function bootstrap($app)
    {
        $handle = 'spaghetti-migrator';

        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            Craft::info('[S3 Migration] Bootstrap::bootstrap() called for ' . get_class($app), __METHOD__);
        }

        // Register module if not already registered
        if (!$app->hasModule($handle)) {
            $app->setModule($handle, [
                'class' => MigrationModule::class,
            ]);

            if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
                Craft::info('[S3 Migration] Module registered: ' . $handle, __METHOD__);
            }
        }

        // Ensure module is in bootstrap array so it gets initialized early
        if (!in_array($handle, $app->bootstrap, true)) {
            $app->bootstrap[] = $handle;

            if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
                Craft::info('[S3 Migration] Added to bootstrap array', __METHOD__);
            }
        }

        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
            Craft::info('[S3 Migration] Bootstrap complete', __METHOD__);
        }
    }
}
