<?php

namespace csabourin\craftS3SpacesMigration;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

/**
 * S3 to Spaces Migration Plugin
 *
 * Plugin Craft pour la migration d'assets AWS S3 vers DigitalOcean Spaces
 *
 * @package csabourin\craftS3SpacesMigration
 * @author Christian Sabourin <christian@sabourin.ca>
 */
class Plugin extends BasePlugin
{
    /**
     * @var string Version du plugin
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool Le plugin a-t-il une section dans le CP?
     */
    public bool $hasCpSection = true;

    /**
     * @var bool Le plugin a-t-il des paramètres?
     */
    public bool $hasCpSettings = false;

    /**
     * Initialisation du plugin
     */
    public function init(): void
    {
        parent::init();

        // Définir les alias
        Craft::setAlias('@s3migration', $this->getBasePath());
        Craft::setAlias('@modules', $this->getBasePath());

        // Configurer les namespaces des controllers
        $this->controllerNamespace = 'csabourin\\craftS3SpacesMigration\\controllers';
        
        // En mode console, utiliser les controllers console
        if (Craft::$app instanceof \craft\console\Application) {
            $this->controllerNamespace = 'csabourin\\craftS3SpacesMigration\\console\\controllers';
        }

        // Enregistrer les routes CP (uniquement pour les requêtes web)
        if (!(Craft::$app instanceof \craft\console\Application)) {
            $this->_registerCpRoutes();
            $this->_registerTemplateRoots();
            $this->_registerCpNavItems();
        }

        Craft::info(
            'S3 to Spaces Migration plugin chargé. Controller namespace: ' . $this->controllerNamespace,
            __METHOD__
        );
    }

    /**
     * Enregistrer les routes du Control Panel
     */
    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['s3-spaces-migration'] = 's3-spaces-migration/migration/index';
                $event->rules['s3-spaces-migration/migration'] = 's3-spaces-migration/migration/index';
                $event->rules['s3-spaces-migration/migration/get-status'] = 's3-spaces-migration/migration/get-status';
                $event->rules['s3-spaces-migration/migration/run-command'] = 's3-spaces-migration/migration/run-command';
                $event->rules['s3-spaces-migration/migration/get-checkpoint'] = 's3-spaces-migration/migration/get-checkpoint';
                $event->rules['s3-spaces-migration/migration/get-logs'] = 's3-spaces-migration/migration/get-logs';
                $event->rules['s3-spaces-migration/migration/test-connection'] = 's3-spaces-migration/migration/test-connection';
            }
        );
    }

    /**
     * Enregistrer les racines de templates
     */
    private function _registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['s3-spaces-migration'] = $this->getBasePath() . '/templates/s3-spaces-migration';
            }
        );
    }

    /**
     * Enregistrer les éléments de navigation du CP
     */
    private function _registerCpNavItems(): void
    {
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function(RegisterCpNavItemsEvent $event) {
                $event->navItems[] = [
                    'url' => 's3-spaces-migration/migration',
                    'label' => 'Migration S3',
                    'icon' => '@appicons/exchange.svg',
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

    /**
     * Actions à effectuer après l'installation du plugin
     */
    public function afterInstall(): void
    {
        parent::afterInstall();
        $this->_installConfigFile();
    }

    /**
     * Actions à effectuer après la mise à jour du plugin
     */
    public function afterUpdate(): void
    {
        parent::afterUpdate();
        $this->_installConfigFile();
    }

    /**
     * Installe le fichier de configuration dans le dossier config/ de Craft
     */
    private function _installConfigFile(): void
    {
        $sourcePath = $this->getBasePath() . '/config/migration-config.php';
        $destPath = Craft::getAlias('@config/migration-config.php');

        // Vérifier si le fichier source existe
        if (!file_exists($sourcePath)) {
            Craft::warning(
                "Source config file not found at: {$sourcePath}",
                __METHOD__
            );
            return;
        }

        // Si le fichier de destination existe déjà, ne pas l'écraser
        if (file_exists($destPath)) {
            Craft::info(
                "Config file already exists at: {$destPath}. Skipping installation.",
                __METHOD__
            );
            return;
        }

        // Créer le dossier config s'il n'existe pas (normalement il existe toujours dans Craft)
        $configDir = dirname($destPath);
        if (!is_dir($configDir)) {
            if (!mkdir($configDir, 0755, true)) {
                Craft::error(
                    "Failed to create config directory: {$configDir}",
                    __METHOD__
                );
                return;
            }
        }

        // Copier le fichier
        if (copy($sourcePath, $destPath)) {
            Craft::info(
                "Successfully copied config file to: {$destPath}",
                __METHOD__
            );

            // En mode web, afficher un message à l'utilisateur
            if (!(Craft::$app instanceof \craft\console\Application)) {
                Craft::$app->getSession()->setNotice(
                    'Migration config file created at config/migration-config.php. Please configure it before running migrations.'
                );
            } else {
                echo "✓ Created config/migration-config.php - Please configure it before running migrations.\n";
            }
        } else {
            Craft::error(
                "Failed to copy config file from {$sourcePath} to {$destPath}",
                __METHOD__
            );
        }
    }
}