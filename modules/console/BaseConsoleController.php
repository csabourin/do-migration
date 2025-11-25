<?php

namespace csabourin\spaghettiMigrator\console;

use craft\console\Controller;

/**
 * Base Console Controller
 *
 * Custom base controller for all Spaghetti Migrator console controllers.
 *
 * NOTE: This matches Craft CMS 5.x's craft\console\Controller signature which
 * includes a string type hint on $defaultAction. For Craft CMS 4.x compatibility,
 * ensure your DDEV/local environment is using Craft 5.x to match the CI environment.
 *
 * All plugin console controllers should extend this class instead of
 * extending craft\console\Controller directly for consistency.
 *
 * @author Christian Sabourin
 * @since 5.0.0
 */
class BaseConsoleController extends Controller
{
    /**
     * @var string The default action to run when no action is specified
     */
    public string $defaultAction = 'index';
}
