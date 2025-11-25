<?php

namespace csabourin\spaghettiMigrator\console;

use craft\console\Controller;

/**
 * Base Console Controller
 *
 * Custom base controller for all Spaghetti Migrator console controllers.
 *
 * NOTE: We cannot use typed properties for $defaultAction because the parent
 * yii\base\Controller class defines it without a type hint, and PHP does not
 * allow adding type declarations to inherited properties. We use PHPDoc
 * annotations instead for type documentation.
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
