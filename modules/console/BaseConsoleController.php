<?php

namespace csabourin\spaghettiMigrator\console;

use craft\console\Controller;

/**
 * Base Console Controller
 *
 * Custom base controller for all Spaghetti Migrator console controllers.
 * This class properly declares properties with type hints that cannot be
 * declared in Craft's console Controller due to backward compatibility.
 *
 * All plugin console controllers should extend this class instead of
 * extending craft\console\Controller directly.
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
