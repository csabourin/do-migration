<?php

namespace modules;

use Craft;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\web\twig\variables\Cp;
use craft\i18n\PhpMessageSource;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use modules\filters\FileSizeFilter;
use modules\filters\RemoveTrailingZeroFilter;
use yii\base\Event;
use yii\base\Module;

/**
 * ┏━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
 * ┃  NCC Migration Module - AWS S3 → DigitalOcean Spaces                  ┃
 * ┃  Custom Craft CMS 4 Module for Production-Grade Cloud Migration       ┃
 * ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
 *
 * ╔═══════════════════════════════════════════════════════════════════════╗
 * ║                           📖 OVERVIEW                                 ║
 * ╚═══════════════════════════════════════════════════════════════════════╝
 *
 * This module provides comprehensive tooling for migrating Craft CMS assets
 * and configurations from AWS S3 to DigitalOcean Spaces. It includes:
 *
 * • 13 specialized console controllers for different migration phases
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
 *    - Web Interface: Serves web requests via modules\controllers
 *    - Console Commands: Handles CLI via modules\console\controllers
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
 *   ./craft ncc-module/url-replacement/replace-s3-urls
 *   ./craft ncc-module/image-migration/migrate
 *   ./craft ncc-module/filesystem-switch/to-do
 *
 * Configuration:
 *   - Central config: config/migration-config.php
 *   - Environment vars: .env (DO_S3_*, MIGRATION_ENV)
 *   - Helper class: modules\helpers\MigrationConfig
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * 📁 MODULE STRUCTURE:
 *
 * modules/
 * ├── NCCModule.php                  ← This file (entry point)
 * ├── controllers/                   ← Web controllers (minimal)
 * │   └── DefaultController.php
 * ├── console/
 * │   └── controllers/               ← Console controllers (13 files)
 * │       ├── UrlReplacementController.php
 * │       ├── ImageMigrationController.php
 * │       ├── FilesystemSwitchController.php
 * │       └── ... (10 more)
 * ├── helpers/
 * │   └── MigrationConfig.php        ← Centralized configuration
 * └── filters/                       ← Custom Twig filters
 *     ├── FileSizeFilter.php
 *     └── RemoveTrailingZeroFilter.php
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * @package modules
 * @author Christian Sabourin <christian@example.com>
 * @version 4.0.0
 * @since 1.0.0
 * @see modules\helpers\MigrationConfig Configuration management
 * @see README_FR.md Complete migration guide (French)
 * @see CONFIG_QUICK_REFERENCE.md Configuration reference
 */
class NCCModule extends Module
{
    /**
     * @var string Default controller namespace for web requests
     *
     * This namespace is used when the module handles web-based requests
     * through the Craft Control Panel or front-end.
     *
     * Console requests automatically override this to 'modules\console\controllers'
     */
    public $controllerNamespace = 'modules\controllers';

    /**
     * Initialize the module and configure runtime environment
     *
     * This method is called automatically by Craft CMS during the bootstrap phase.
     * It performs the following initialization tasks:
     *
     * 1. Configures module aliases for path resolution
     * 2. Detects request type (web vs console) and routes to correct namespace
     * 3. Sets module base path for resource location
     * 4. Registers template roots for Control Panel templates
     * 5. Registers CP navigation item for easy dashboard access
     * 6. Registers custom Twig filters (site requests only)
     * 7. Logs successful initialization
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

        // ─────────────────────────────────────────────────────────────────
        // STEP 2: Route to Correct Controller Namespace
        // ─────────────────────────────────────────────────────────────────
        // Console requests (CLI commands) need different controllers than
        // web requests (Control Panel). Automatically detect and switch.

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            // CLI mode: ./craft ncc-module/...
            $this->controllerNamespace = 'modules\\console\\controllers';
        }
        // Otherwise, use default: 'modules\controllers' (for web requests)

        // ─────────────────────────────────────────────────────────────────
        // STEP 3: Set Module Base Path
        // ─────────────────────────────────────────────────────────────────
        // Tells Yii where this module's files are located
        // Not strictly required but follows best practices

        $this->setBasePath(__DIR__);

        // ─────────────────────────────────────────────────────────────────
        // STEP 4: Register Template Root
        // ─────────────────────────────────────────────────────────────────
        // Register our templates directory so Craft can find our templates

        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['ncc-module'] = __DIR__ . '/templates/ncc-module';
            }
        );

        // ─────────────────────────────────────────────────────────────────
        // STEP 5: Register CP Navigation Item
        // ─────────────────────────────────────────────────────────────────
        // Add a navigation item in the Control Panel for easy access

        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function(RegisterCpNavItemsEvent $event) {
                $event->navItems[] = [
                    'url' => 'ncc-module/migration',
                    'label' => 'Migration',
                    'icon' => '@appicons/exchange.svg',
                    'subnav' => [
                        'dashboard' => [
                            'label' => 'Dashboard',
                            'url' => 'ncc-module/migration',
                        ],
                    ],
                ];
            }
        );

        // ─────────────────────────────────────────────────────────────────
        // STEP 6: Register Custom Twig Filters
        // ─────────────────────────────────────────────────────────────────
        // Only register Twig extensions for site (front-end) requests
        // Not needed for console or Control Panel requests
        //
        // Available Filters:
        // • filesize: {{ asset.size|filesize }} → "1.5 MB"
        // • removeTrailingZero: {{ number|removeTrailingZero }} → "5" not "5.0"

        if (Craft::$app->request->getIsSiteRequest()) {
            Craft::$app->view->registerTwigExtension(new FileSizeFilter());
            Craft::$app->view->registerTwigExtension(new RemoveTrailingZeroFilter());
        }

        // ─────────────────────────────────────────────────────────────────
        // STEP 7: Log Successful Initialization
        // ─────────────────────────────────────────────────────────────────
        // Helps with debugging - confirms module loaded successfully

        Craft::info('NCCModule migration module loaded successfully', __METHOD__);
    }
}
