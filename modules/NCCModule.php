<?php

namespace modules;

use Craft;
use craft\events\RegisterTemplateRootsEvent;
use craft\i18n\PhpMessageSource;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use modules\filters\FileSizeFilter;
use modules\filters\RemoveTrailingZeroFilter;
use yii\base\Event;
use yii\base\Module;

class NCCModule extends Module
{
    // Define the controller namespace
    public $controllerNamespace = 'modules\controllers';

    public function init()
    {
        parent::init();

        // Set aliases for the module
        Craft::setAlias('@modules', __DIR__);
        Craft::setAlias('@modules/controllers', __DIR__ . '/controllers');

          // Set the controllerNamespace for console requests
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'modules\\console\\controllers';
        }

        // Set the module's base path (not required but recommended)
        $this->setBasePath(__DIR__);

        // Register our custom Twig extension
        if (Craft::$app->request->getIsSiteRequest()) {
            Craft::$app->view->registerTwigExtension(new FileSizeFilter());
            Craft::$app->view->registerTwigExtension(new RemoveTrailingZeroFilter());
        }

        Craft::info('NCCModule module loaded', __METHOD__);
    }
}
