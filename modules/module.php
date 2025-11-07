<?php
/**
 * Module Bootstrap File
 *
 * This file ensures the MigrationModule class is loaded during Craft's bootstrap phase,
 * particularly important for web requests where proper initialization order matters.
 *
 * The bootstrap.php file requires this to ensure the module class is available
 * before Craft attempts to instantiate it.
 */

// Load the actual module class
require_once __DIR__ . '/MigrationModule.php';
