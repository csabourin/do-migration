<?php
namespace modules\externalapidata;

use Craft;
use yii\base\Module;

class ExternalApiData extends Module
{
    public function init()
    {
        parent::init();
        Craft::setAlias('@modules/externalapidata', __DIR__);

        $this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
            ? 'modules\\externalapidata\\console\\controllers'
            : 'modules\\externalapidata\\controllers';
    }
}
