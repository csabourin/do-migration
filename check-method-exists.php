#!/usr/bin/env php
<?php
/**
 * Diagnostic script to check if MigrationConfig methods exist
 * Run this from your Craft root directory or from the module directory
 */

echo "=== MigrationConfig Method Diagnostic ===\n\n";

$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$autoloadLoaded = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (is_readable($autoloadPath)) {
        require_once $autoloadPath;
        echo "✓ Loaded Composer autoloader:\n  $autoloadPath\n\n";
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    echo "⚠️  Composer autoloader not found, falling back to direct include.\n\n";
}

// Try to find the MigrationConfig.php file
$possiblePaths = [
    __DIR__ . '/modules/helpers/MigrationConfig.php',
    dirname(__DIR__) . '/modules/helpers/MigrationConfig.php',
    __DIR__ . '/vendor/csabourin/spaghetti-migrator/modules/helpers/MigrationConfig.php',
];

$configPath = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $configPath = $path;
        break;
    }
}

if (!$configPath) {
    echo "❌ ERROR: Could not find MigrationConfig.php\n";
    echo "Searched in:\n";
    foreach ($possiblePaths as $path) {
        echo "  - $path\n";
    }
    exit(1);
}

echo "✓ Found MigrationConfig.php at:\n  $configPath\n\n";

// Check file size and modification time
$size = filesize($configPath);
$mtime = filemtime($configPath);
echo "File info:\n";
echo "  Size: " . number_format($size) . " bytes\n";
echo "  Modified: " . date('Y-m-d H:i:s', $mtime) . "\n\n";

// Read the file and check for methods
$content = file_get_contents($configPath);

$methodsToCheck = [
    'getDoEnvVarAccessKey',
    'getDoEnvVarSecretKey',
    'getDoEnvVarBucket',
    'getDoEnvVarBaseUrl',
    'getDoEnvVarEndpoint',
];

echo "Checking for required methods:\n";
foreach ($methodsToCheck as $method) {
    if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $content)) {
        echo "  ✓ $method() - FOUND\n";
    } else {
        echo "  ✗ $method() - MISSING!\n";
    }
}

echo "\n";

// Try to load the class
require_once $configPath;

$className = 'csabourin\\spaghettiMigrator\\helpers\\MigrationConfig';

if (class_exists($className)) {
    echo "✓ Class loaded successfully: $className\n";

    $reflection = new ReflectionClass($className);
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    echo "\nAll public methods (" . count($methods) . " total):\n";
    foreach ($methodsToCheck as $methodName) {
        $exists = $reflection->hasMethod($methodName);
        $symbol = $exists ? '✓' : '✗';
        echo "  $symbol $methodName()\n";
    }
} else {
    echo "❌ ERROR: Class '$className' not found after require\n";
}

echo "\n=== End Diagnostic ===\n";
