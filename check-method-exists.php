#!/usr/bin/env php
<?php
/**
 * Diagnostic script to check if MigrationConfig methods exist
 * Run this from your Craft root directory or from the module directory
 */

echo "=== MigrationConfig Method Diagnostic ===\n\n";

// Try to find the MigrationConfig.php file
$possiblePaths = [
    __DIR__ . '/modules/helpers/MigrationConfig.php',
    __DIR__ . '/do-migration/modules/helpers/MigrationConfig.php',
    dirname(__DIR__) . '/do-migration/modules/helpers/MigrationConfig.php',
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

if (class_exists('csabourin\\craftS3SpacesMigration\\helpers\\MigrationConfig')) {
    echo "✓ Class loaded successfully\n";

    $reflection = new ReflectionClass('csabourin\\craftS3SpacesMigration\\helpers\\MigrationConfig');
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    echo "\nAll public methods (" . count($methods) . " total):\n";
    foreach ($methodsToCheck as $methodName) {
        $exists = $reflection->hasMethod($methodName);
        $symbol = $exists ? '✓' : '✗';
        echo "  $symbol $methodName()\n";
    }
} else {
    echo "❌ ERROR: Class 'csabourin\\craftS3SpacesMigration\\helpers\\MigrationConfig' not found after require\n";
}

echo "\n=== End Diagnostic ===\n";
