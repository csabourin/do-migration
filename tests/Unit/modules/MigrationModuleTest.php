<?php

namespace csabourin\spaghettiMigrator\tests\Unit\modules;

use Craft;
use CraftAppStub;
use craft\console\Application as ConsoleApplication;
use csabourin\spaghettiMigrator\MigrationModule;
use PHPUnit\Framework\TestCase;

class MigrationModuleTest extends TestCase
{
    protected function setUp(): void
    {
        Craft::$aliases = [];
    }

    public function testInitSetsWebControllerNamespaceAndAliases(): void
    {
        Craft::$app = new CraftAppStub();

        $module = new MigrationModule('spaghetti-migrator');
        $module->init();

        $this->assertSame('csabourin\\spaghettiMigrator\\controllers', $module->controllerNamespace);
        $this->assertNotEmpty(Craft::getAlias('@modules'));
        $this->assertNotEmpty(Craft::getAlias('@s3migration'));
    }

    public function testInitSwitchesToConsoleNamespace(): void
    {
        Craft::$app = new ConsoleApplication();

        $module = new MigrationModule('spaghetti-migrator');
        $module->init();

        $this->assertSame('csabourin\\spaghettiMigrator\\console\\controllers', $module->controllerNamespace);
    }
}
