<?php
namespace csabourin\craftS3SpacesMigration\controllers;

use craft\web\Controller;

/**
 * Default Controller
 *
 * Basic web controller for the S3 to Spaces Migration Module
 */
class DefaultController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * Default action - redirects to migration dashboard
     */
    public function actionIndex()
    {
        return $this->redirect('s3-spaces-migration/migration');
    }
}