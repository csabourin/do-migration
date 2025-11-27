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

        // Register service components (v5.0)
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
            'Spaghetti Migrator plugin loaded (v5.0). Controller namespace: ' . $this->controllerNamespace,
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
                $event->rules['spaghetti-migrator/migration/get-live-monitor'] = 'spaghetti-migrator/migration/get-live-monitor';
                $event->rules['spaghetti-migrator/migration/analyze-missing-files'] = 'spaghetti-migrator/migration/analyze-missing-files';
                $event->rules['spaghetti-migrator/migration/fix-missing-files'] = 'spaghetti-migrator/migration/fix-missing-files';

                // SSE Streaming routes
                $event->rules['spaghetti-migrator/migration/test-route'] = 'spaghetti-migrator/migration/test-route';
                $event->rules['spaghetti-migrator/migration/test-sse'] = 'spaghetti-migrator/migration/test-sse';
                $event->rules['spaghetti-migrator/migration/stream-migration'] = 'spaghetti-migrator/migration/stream-migration';
                $event->rules['spaghetti-migrator/migration/cancel-streaming-migration'] = 'spaghetti-migrator/migration/cancel-streaming-migration';

                // Settings import/export routes
                $event->rules['spaghetti-migrator/settings/export'] = 'spaghetti-migrator/settings/export';
                $event->rules['spaghetti-migrator/settings/import'] = 'spaghetti-migrator/settings/import';
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
                $event->roots['spaghetti-migrator'] = $this->getBasePath() . '/templates/spaghetti-migrator';
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
            'spaghetti-migrator/settings',
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