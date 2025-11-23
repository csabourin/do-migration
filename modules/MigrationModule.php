<?php

namespace csabourin\spaghettiMigrator;

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
    public $controllerNamespace = 'csabourin\spaghettiMigrator\controllers';

    public function init()
    {
        parent::init();

        Craft::setAlias('@modules', __DIR__);
        Craft::setAlias('@modules/controllers', __DIR__ . '/controllers');
        Craft::setAlias('@csabourin/spaghettiMigrator', __DIR__);
        Craft::setAlias('@s3migration', __DIR__);

        // FIX: Utiliser instanceof pour détecter le mode console (plus fiable que getRequest())
        $app = Craft::$app;
        $isConsole = ($app instanceof \craft\console\Application);
        
        if ($isConsole) {
            $this->controllerNamespace = 'csabourin\\spaghettiMigrator\\console\\controllers';
        }

        $this->setBasePath(__DIR__);

        // Le reste de l'init uniquement si pas en console
        if (!$isConsole) {
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_CP_URL_RULES,
                function(RegisterUrlRulesEvent $event) {
                    $event->rules['spaghetti-migrator'] = 'spaghetti-migrator/migration/index';
                    $event->rules['spaghetti-migrator/migration'] = 'spaghetti-migrator/migration/index';
                    $event->rules['spaghetti-migrator/migration/get-status'] = 'spaghetti-migrator/migration/get-status';
                    $event->rules['spaghetti-migrator/migration/update-status'] = 'spaghetti-migrator/migration/update-status';
                    $event->rules['spaghetti-migrator/migration/update-module-status'] = 'spaghetti-migrator/migration/update-module-status';
                    $event->rules['spaghetti-migrator/migration/run-command'] = 'spaghetti-migrator/migration/run-command';
                    $event->rules['spaghetti-migrator/migration/run-command-queue'] = 'spaghetti-migrator/migration/run-command-queue';
                    $event->rules['spaghetti-migrator/migration/get-queue-status'] = 'spaghetti-migrator/migration/get-queue-status';
                    $event->rules['spaghetti-migrator/migration/get-queue-jobs'] = 'spaghetti-migrator/migration/get-queue-jobs';
                    $event->rules['spaghetti-migrator/migration/cancel-command'] = 'spaghetti-migrator/migration/cancel-command';
                    $event->rules['spaghetti-migrator/migration/get-checkpoint'] = 'spaghetti-migrator/migration/get-checkpoint';
                    $event->rules['spaghetti-migrator/migration/get-logs'] = 'spaghetti-migrator/migration/get-logs';
                    $event->rules['spaghetti-migrator/migration/test-connection'] = 'spaghetti-migrator/migration/test-connection';
                    $event->rules['spaghetti-migrator/migration/get-changelog'] = 'spaghetti-migrator/migration/get-changelog';
                    $event->rules['spaghetti-migrator/migration/get-running-migrations'] = 'spaghetti-migrator/migration/get-running-migrations';
                    $event->rules['spaghetti-migrator/migration/get-migration-progress'] = 'spaghetti-migrator/migration/get-migration-progress';
                }
            );

            Event::on(
                View::class,
                View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
                function(RegisterTemplateRootsEvent $event) {
                    $event->roots['spaghetti-migrator'] = __DIR__ . '/templates/spaghetti-migrator';
                }
            );

            Event::on(
                Cp::class,
                Cp::EVENT_REGISTER_CP_NAV_ITEMS,
                function(RegisterCpNavItemsEvent $event) {
                    $event->navItems[] = [
                        'url' => 'spaghetti-migrator/migration',
                        'label' => 'Spaghetti Migrator',
                        'icon' => '@s3migration/icon.svg',
                        'subnav' => [
                            'dashboard' => [
                                'label' => 'Dashboard',
                                'url' => 'spaghetti-migrator/migration',
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