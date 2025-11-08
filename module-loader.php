<?php

declare(strict_types=1);

// Ensure the bootstrap helper is loaded, then register the module with Craft.
require_once __DIR__ . '/bootstrap.php';

if (function_exists('craft_s3_spaces_migration_register_module')) {
    craft_s3_spaces_migration_register_module();
}
