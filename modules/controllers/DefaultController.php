<?php
namespace modules\controllers;

use craft\console\Controller;

class DefaultController extends Controller
{
    protected $allowAnonymous = true;
    
    public function actionIndex()
    {
        return $this->renderTemplate('ncc-module/index');
    }
}