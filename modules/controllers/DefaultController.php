<?php
namespace modules\controllers;

use craft\web\Controller;

/**
 * Default Controller
 *
 * Basic web controller for the NCC Migration Module
 */
class DefaultController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * Default action - redirects to migration dashboard
     */
    public function actionIndex()
    {
        return $this->redirect('ncc-module/migration');
    }
}