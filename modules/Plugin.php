<?php

namespace csabourin\spaghettiMigrator;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;
use csabourin\spaghettiMigrator\models\Settings;
use yii\base\Event;

/**
 * Spaghetti Migrator Plugin
 *
 * Untangles your nested subfolders and migrates assets between cloud services
 * Plugin Craft pour démêler les dossiers imbriqués et migrer les assets entre services cloud
 *
 * @package csabourin\spaghettiMigrator
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
    public bool $hasCpSettings = true;

    /**
     * Initialisation du plugin
     */
    public function init(): void
    {
        parent::init();

        // Définir les alias
        Craft::setAlias('@s3migration', $this->getBasePath());
        Craft::setAlias('@modules', $this->getBasePath());

        // Register service components (v2.0)
        $this->setComponents([
            'providerRegistry' => \csabourin\spaghettiMigrator\services\ProviderRegistry::class,
        ]);

        // Configurer les namespaces des controllers
        $this->controllerNamespace = 'csabourin\\spaghettiMigrator\\controllers';

        // En mode console, utiliser les controllers console
        if (Craft::$app instanceof \craft\console\Application) {
            $this->controllerNamespace = 'csabourin\\spaghettiMigrator\\console\\controllers';
        }

        // Enregistrer les routes CP (uniquement pour les requêtes web)
        if (!(Craft::$app instanceof \craft\console\Application)) {
            $this->_registerCpRoutes();
            $this->_registerTemplateRoots();
            $this->_registerCpNavItems();
        }

        // Import settings from migration-config.php if not already configured
        $this->_importConfigFileIfNeeded();

        Craft::info(
            'Spaghetti Migrator plugin loaded (v2.0). Controller namespace: ' . $this->controllerNamespace,
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

                // Settings import/export routes
                $event->rules['s3-spaces-migration/settings/export'] = 's3-spaces-migration/settings/export';
                $event->rules['s3-spaces-migration/settings/import'] = 's3-spaces-migration/settings/import';
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

    /**
     * Créer le modèle de paramètres
     */
    protected function createSettingsModel(): ?Settings
    {
        return new Settings();
    }

    /**
     * Retourner le HTML des paramètres
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(
            's3-spaces-migration/settings',
            [
                'settings' => $this->getSettings(),
            ]
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

    /**
     * Import settings from migration-config.php if it exists and database settings are not configured
     * This provides backward compatibility for existing installations
     */
    private function _importConfigFileIfNeeded(): void
    {
        try {
            // Check if migration-config.php exists
            $configPath = Craft::getAlias('@config/migration-config.php');
            if (!file_exists($configPath)) {
                return;
            }

            // Get current settings
            $settings = $this->getSettings();

            // Check if settings are already configured in database
            // We consider settings configured if awsBucket is set (required field)
            if (!empty(\craft\helpers\App::parseEnv($settings->awsBucket))) {
                // Settings already configured, skip import
                return;
            }

            // Load config file
            $config = require $configPath;

            // Import settings from config
            $settings->importFromConfig($config);

            // Save to database
            if (Craft::$app->getPlugins()->savePluginSettings($this, $settings->toArray())) {
                Craft::info(
                    "Successfully imported settings from migration-config.php to database",
                    __METHOD__
                );

                // Show notice to user in web mode
                if (!(Craft::$app instanceof \craft\console\Application)) {
                    Craft::$app->getSession()->setNotice(
                        'Plugin settings have been imported from migration-config.php. You can now manage them in the Control Panel.'
                    );
                }
            } else {
                Craft::warning(
                    "Failed to save imported settings to database",
                    __METHOD__
                );
            }
        } catch (\Exception $e) {
            Craft::warning(
                "Error importing config file: " . $e->getMessage(),
                __METHOD__
            );
        }
    }
}