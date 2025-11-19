<?php
/**
 * PHPUnit Bootstrap for Craft S3 Spaces Migration Module
 */

// Define project root
define('CRAFT_BASE_PATH', dirname(__DIR__));
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

// Load Composer autoloader (if available)
if (file_exists(CRAFT_VENDOR_PATH . '/autoload.php')) {
    require_once CRAFT_VENDOR_PATH . '/autoload.php';
}

// Load Craft stubs so service classes can be tested without Craft installed
require_once __DIR__ . '/Support/CraftStubs.php';

// Set environment to test
defined('CRAFT_ENVIRONMENT') || define('CRAFT_ENVIRONMENT', 'test');

// Ensure timezone is set
if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

// Note: Full Craft integration tests would require Craft CMS to be installed
// These tests focus on unit testing the service classes in isolation
