<?php

namespace csabourin\craftS3SpacesMigration;

use Craft;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\i18n\PhpMessageSource;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use csabourin\craftS3SpacesMigration\filters\FileSizeFilter;
use csabourin\craftS3SpacesMigration\filters\RemoveTrailingZeroFilter;
use yii\base\Event;
use yii\base\Module;

/**
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃  S3 to Spaces Migration Module - AWS S3 → DigitalOcean Spaces         ┃
 * ┃  Craft CMS 4/5 Module for Production-Grade Cloud Migration            ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 *
 * ╔═══════════════════════════════════════════════════════════════════════╗
 * ║                           📖 OVERVIEW                                 ║
 * ╚═══════════════════════════════════════════════════════════════════════╝
 *
 * This module provides comprehensive tooling for migrating Craft CMS assets
 * and configurations from AWS S3 to DigitalOcean Spaces. It includes:
 *
 * • 14 specialized console controllers for different migration phases
 * • Centralized configuration management (MigrationConfig helper)
 * • Production-grade features: checkpoints, rollback, error recovery
 * • Custom Twig filters for enhanced template functionality
 * • Automatic namespace switching for web vs console environments
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * 🎯 KEY FEATURES:
 *
 * 1. DUAL ENVIRONMENT SUPPORT
 *    - Web Interface: Serves web requests via csabourin\craftS3SpacesMigration\controllers
 *    - Console Commands: Handles CLI via csabourin\craftS3SpacesMigration\console\controllers
 *
 * 2. AUTOMATIC NAMESPACE ROUTING
 *    - Detects request type (web vs console)
 *    - Loads appropriate controller namespace automatically
 *
 * 3. CUSTOM TWIG FILTERS
 *    - FileSizeFilter: Format file sizes (e.g., 1.5 MB)
 *    - RemoveTrailingZeroFilter: Clean decimal display (e.g., 5.0 → 5)
 *
 * 4. ALIAS MANAGEMENT
 *    - @modules: Points to this module directory
 *    - @modules/controllers: Direct access to web controllers
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * 🚀 USAGE:
 *
 * This module is loaded automatically by Craft CMS during bootstrap.
 * No manual initialization required.
 *
 * Console Commands:
 *   ./craft s3-spaces-migration/url-replacement/replace-s3-urls
 *   ./craft s3-spaces-migration/image-migration/migrate
 *   ./craft s3-spaces-migration/filesystem-switch/to-do
 *
 * Configuration:
 *   - Central config: config/migration-config.php
 *   - Environment vars: .env (DO_S3_*, MIGRATION_ENV)
 *   - Helper class: csabourin\craftS3SpacesMigration\helpers\MigrationConfig
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * 📁 MODULE STRUCTURE:
 *
 * modules/
 * ├── NCCModule.php                  ← This file (entry point)
 * ├── controllers/                   ← Web controllers
 * │   ├── DefaultController.php
 * │   └── MigrationController.php
 * ├── console/
 * │   └── controllers/               ← Console controllers (14 files)
 * │       ├── UrlReplacementController.php
 * │       ├── ImageMigrationController.php
 * │       ├── FilesystemSwitchController.php
 * │       └── ... (11 more)
 * ├── helpers/
 * │   └── MigrationConfig.php        ← Centralized configuration
 * └── filters/                       ← Custom Twig filters
 *     ├── FileSizeFilter.php
 *     └── RemoveTrailingZeroFilter.php
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * @package csabourin\craftS3SpacesMigration
 * @author Christian Sabourin <christian@sabourin.ca>
 * @version 1.0.0
 * @since 1.0.0
 * @license MIT
 * @see csabourin\craftS3SpacesMigration\helpers\MigrationConfig Configuration management
 * @see README.md Complete migration guide
 * @see ARCHITECTURE.md Detailed architecture documentation
 */
class NCCModule extends Module
{
    /**
     * @var string Default controller namespace for web requests
     *
     * This namespace is used when the module handles web-based requests
     * through the Craft Control Panel or front-end.
     *
     * Console requests automatically override this to 'csabourin\craftS3SpacesMigration\console\controllers'
     */
    public $controllerNamespace = 'csabourin\craftS3SpacesMigration\controllers';

    /**
     * Initialize the module and configure runtime environment
     *
     * This method is called automatically by Craft CMS during the bootstrap phase.
     * It performs the following initialization tasks:
     *
     * 1. Configures module aliases for path resolution
     * 2. Detects request type (web vs console) and routes to correct namespace
     * 3. Sets module base path for resource location
     * 4. Registers CP URL rules for routing dashboard requests
     * 5. Registers template roots for Control Panel templates
     * 6. Registers CP navigation item for easy dashboard access
     * 7. Registers custom Twig filters (site requests only)
     * 8. Logs successful initialization
     *
     * WORKFLOW:
     * ┌─────────────────────────────────────────┐
     * │ Craft CMS Bootstrap                     │
     * └────────────┬────────────────────────────┘
     *              │
     *              ▼
     * ┌─────────────────────────────────────────┐
     * │ NCCModule::init() called                │
     * └────────────┬────────────────────────────┘
     *              │
     *              ├─► Set aliases (@modules, @modules/controllers)
     *              │
     *              ├─► Detect request type
     *              │   ├─ Console? → Use modules\console\controllers
     *              │   └─ Web? → Use modules\controllers
     *              │
     *              ├─► Set base path
     *              │
     *              ├─► Register CP URL rules (dashboard routing)
     *              │
     *              ├─► Register template roots (CP templates)
     *              │
     *              ├─► Register CP navigation (Migration menu)
     *              │
     *              ├─► Register Twig filters (if site request)
     *              │   ├─ FileSizeFilter
     *              │   └─ RemoveTrailingZeroFilter
     *              │
     *              └─► Log initialization complete
     *
     * @return void
     * @throws \yii\base\InvalidConfigException If module cannot be initialized
     */
    public function init()
    {
        parent::init();

        // ─────────────────────────────────────────────────────────────────
        // STEP 1: Configure Module Aliases
        // ─────────────────────────────────────────────────────────────────
        // Aliases allow using @modules shorthand in Craft instead of full paths
        // Example: Craft::getAlias('@modules/helpers') resolves to full path

        Craft::setAlias('@modules', __DIR__);
        Craft::setAlias('@modules/controllers', __DIR__ . '/controllers');

        // Also expose the module path using the namespace-style alias Craft
        // expects when resolving controller paths (e.g. when building the
        // `@csabourin/craftS3SpacesMigration/console/controllers` alias).
        Craft::setAlias('@csabourin/craftS3SpacesMigration', __DIR__);

        // ─────────────────────────────────────────────────────────────────
        // STEP 2: Route to Correct Controller Namespace
        // ─────────────────────────────────────────────────────────────────
        // Console requests (CLI commands) need different controllers than
        // web requests (Control Panel). Automatically detect and switch.

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            // CLI mode: ./craft s3-spaces-migration/...
            $this->controllerNamespace = 'csabourin\\craftS3SpacesMigration\\console\\controllers';
        }
        // Otherwise, use default: 'csabourin\craftS3SpacesMigration\controllers' (for web requests)

        // ─────────────────────────────────────────────────────────────────
        // STEP 3: Set Module Base Path
        // ─────────────────────────────────────────────────────────────────
        // Tells Yii where this module's files are located
        // Not strictly required but follows best practices

        $this->setBasePath(__DIR__);

        // ─────────────────────────────────────────────────────────────────
        // STEP 4: Register CP URL Rules
        // ─────────────────────────────────────────────────────────────────
        // Register custom routes for the Control Panel

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

        // ─────────────────────────────────────────────────────────────────
        // STEP 5: Register Template Root
        // ─────────────────────────────────────────────────────────────────
        // Register our templates directory so Craft can find our templates

        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['s3-spaces-migration'] = __DIR__ . '/templates/s3-spaces-migration';
            }
        );

        // ─────────────────────────────────────────────────────────────────
        // STEP 6: Register CP Navigation Item
        // ─────────────────────────────────────────────────────────────────
        // Add a navigation item in the Control Panel for easy access

        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function(RegisterCpNavItemsEvent $event) {
                $event->navItems[] = [
                    'url' => 's3-spaces-migration/migration',
                    'label' => 'Migration',
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

        // ─────────────────────────────────────────────────────────────────
        // STEP 7: Register Custom Twig Filters
        // ─────────────────────────────────────────────────────────────────
        // Only register Twig extensions for site (front-end) requests
        // Not needed for console or Control Panel requests
        //
        // Available Filters:
        // • filesize: {{ asset.size|filesize }} → "1.5 MB"
        // • removeTrailingZero: {{ number|removeTrailingZero }} → "5" not "5.0"
        //
        // Note: These filters are optional and only loaded if they exist

        if (Craft::$app->request->getIsSiteRequest()) {
            if (class_exists(FileSizeFilter::class)) {
                Craft::$app->view->registerTwigExtension(new FileSizeFilter());
            }
            if (class_exists(RemoveTrailingZeroFilter::class)) {
                Craft::$app->view->registerTwigExtension(new RemoveTrailingZeroFilter());
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // STEP 8: Log Successful Initialization
        // ─────────────────────────────────────────────────────────────────
        // Helps with debugging - confirms module loaded successfully

        Craft::info('S3 to Spaces Migration module loaded successfully', __METHOD__);
    }
}
