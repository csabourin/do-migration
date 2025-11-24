<?php

namespace csabourin\spaghettiMigrator\tests\Unit\controllers;

use Craft;
use CraftAppStub;
use csabourin\spaghettiMigrator\controllers\DefaultController;
use PHPUnit\Framework\TestCase;

class DefaultControllerTest extends TestCase
{
    protected function setUp(): void
    {
        Craft::$app = new CraftAppStub();
    }

    public function testIndexRedirectsToMigrationDashboard(): void
    {
        $controller = new DefaultController('default', null);

        $response = $controller->actionIndex();

        $this->assertEquals('spaghetti-migrator/migration', $response->data['redirect']);
    }
}
