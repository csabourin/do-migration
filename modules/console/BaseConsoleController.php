<?php

namespace csabourin\spaghettiMigrator\console;

use craft\console\Controller;

/**
 * Base Console Controller
 *
 * Custom base controller for all Spaghetti Migrator console controllers.
 *
 * NOTE: We cannot use typed properties for $defaultAction because:
 * 1. Older versions of Craft 4 and Yii2 don't have typed $defaultAction
 * 2. Our test stubs need to remain compatible with both old and new versions
 * 3. PHP doesn't allow child classes to add/change type declarations on inherited properties
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
    public $defaultAction = 'index';
}
