<?php
/**
 * PHPUnit Bootstrap for Craft S3 Spaces Migration Module
 */

// Define project root
define('CRAFT_BASE_PATH', dirname(__DIR__));
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

// Load Craft stubs so service classes can be tested without Craft installed
require_once __DIR__ . '/Support/CraftWebStubs.php';
require_once __DIR__ . '/Support/CraftStubs.php';

// Load Composer autoloader (if available) after stubs so Craft classes resolve to test doubles
if (file_exists(CRAFT_VENDOR_PATH . '/autoload.php')) {
    require_once CRAFT_VENDOR_PATH . '/autoload.php';
}

// Load Craft stubs so service classes can be tested without Craft installed
require_once __DIR__ . '/Support/CraftStubs.php';
require_once __DIR__ . '/Support/CraftWebStubs.php';

// Minimal PSR-4 autoloader for the plugin namespace (when Composer autoload is unavailable)
spl_autoload_register(function($class) {
    $prefix = 'csabourin\\spaghettiMigrator\\';
    $baseDir = dirname(__DIR__) . '/modules/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Set environment to test
defined('CRAFT_ENVIRONMENT') || define('CRAFT_ENVIRONMENT', 'test');

// Ensure timezone is set
if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

// Note: Full Craft integration tests would require Craft CMS to be installed
// These tests focus on unit testing the service classes in isolation
