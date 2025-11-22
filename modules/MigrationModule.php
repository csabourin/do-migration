<?php

namespace csabourin\craftS3SpacesMigration;

use Craft;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;
use yii\base\Module as BaseModule;

class MigrationModule extends BaseModule
{
    public $controllerNamespace = 'csabourin\craftS3SpacesMigration\controllers';

    public function init()
    {
        parent::init();

        Craft::setAlias('@modules', __DIR__);
        Craft::setAlias('@modules/controllers', __DIR__ . '/controllers');
        Craft::setAlias('@csabourin/craftS3SpacesMigration', __DIR__);
        Craft::setAlias('@s3migration', __DIR__);

        // FIX: Utiliser instanceof pour détecter le mode console (plus fiable que getRequest())
        $app = Craft::$app;
        $isConsole = ($app instanceof \craft\console\Application);
        
        if ($isConsole) {
            $this->controllerNamespace = 'csabourin\\craftS3SpacesMigration\\console\\controllers';
        }

        $this->setBasePath(__DIR__);

        // Le reste de l'init uniquement si pas en console
        if (!$isConsole) {
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_CP_URL_RULES,
                function(RegisterUrlRulesEvent $event) {
                    $event->rules['s3-spaces-migration'] = 's3-spaces-migration/migration/index';
                    $event->rules['s3-spaces-migration/migration'] = 's3-spaces-migration/migration/index';
                    $event->rules['s3-spaces-migration/migration/get-status'] = 's3-spaces-migration/migration/get-status';
                    $event->rules['s3-spaces-migration/migration/update-status'] = 's3-spaces-migration/migration/update-status';
                    $event->rules['s3-spaces-migration/migration/update-module-status'] = 's3-spaces-migration/migration/update-module-status';
                    $event->rules['s3-spaces-migration/migration/run-command'] = 's3-spaces-migration/migration/run-command';
                    $event->rules['s3-spaces-migration/migration/run-command-queue'] = 's3-spaces-migration/migration/run-command-queue';
                    $event->rules['s3-spaces-migration/migration/get-queue-status'] = 's3-spaces-migration/migration/get-queue-status';
                    $event->rules['s3-spaces-migration/migration/get-queue-jobs'] = 's3-spaces-migration/migration/get-queue-jobs';
                    $event->rules['s3-spaces-migration/migration/cancel-command'] = 's3-spaces-migration/migration/cancel-command';
                    $event->rules['s3-spaces-migration/migration/get-checkpoint'] = 's3-spaces-migration/migration/get-checkpoint';
                    $event->rules['s3-spaces-migration/migration/get-logs'] = 's3-spaces-migration/migration/get-logs';
                    $event->rules['s3-spaces-migration/migration/test-connection'] = 's3-spaces-migration/migration/test-connection';
                    $event->rules['s3-spaces-migration/migration/get-changelog'] = 's3-spaces-migration/migration/get-changelog';
                    $event->rules['s3-spaces-migration/migration/get-running-migrations'] = 's3-spaces-migration/migration/get-running-migrations';
                    $event->rules['s3-spaces-migration/migration/get-migration-progress'] = 's3-spaces-migration/migration/get-migration-progress';
                }
            );

            Event::on(
                View::class,
                View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
                function(RegisterTemplateRootsEvent $event) {
                    $event->roots['s3-spaces-migration'] = __DIR__ . '/templates/s3-spaces-migration';
                }
            );

            Event::on(
                Cp::class,
                Cp::EVENT_REGISTER_CP_NAV_ITEMS,
                function(RegisterCpNavItemsEvent $event) {
                    $event->navItems[] = [
                        'url' => 's3-spaces-migration/migration',
                        'label' => 'Spaghetti Migrator',
                        'icon' => '@s3migration/icon.svg',
                        'subnav' => [
                            'dashboard' => [
                                'label' => 'Dashboard',
                                'url' => 's3-spaces-migration/migration',
                            ],
                        ],
                    ];
                }
            );
        }

        Craft::info('[Spaghetti Migrator] Module loaded. Controller namespace: ' . $this->controllerNamespace, __METHOD__);
    }
}

class_alias(MigrationModule::class, __NAMESPACE__ . '\\NCCModule');