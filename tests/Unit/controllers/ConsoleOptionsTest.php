<?php

namespace csabourin\spaghettiMigrator\tests\Unit\controllers;

use csabourin\spaghettiMigrator\console\controllers\ExtendedUrlReplacementController;
use csabourin\spaghettiMigrator\console\controllers\TransformCleanupController;
use PHPUnit\Framework\TestCase;
use yii\base\Action;

class ConsoleOptionsTest extends TestCase
{
    public function testExtendedUrlReplacementOptionsExposeFlags(): void
    {
        $controller = new ExtendedUrlReplacementController('extended-url', null);

        $replaceAdditionalOptions = $controller->options('replace-additional');
        $this->assertContains('dryRun', $replaceAdditionalOptions);
        $this->assertContains('yes', $replaceAdditionalOptions);
        $this->assertContains('migrationId', $replaceAdditionalOptions); // BaseConsoleController adds this

        $replaceJsonOptions = $controller->options('replace-json');
        $this->assertSame($replaceAdditionalOptions, $replaceJsonOptions);

        // scan-additional has no custom options, but inherits migrationId from base
        $this->assertSame(['migrationId'], $controller->options('scan-additional'));
    }

    public function testTransformCleanupNormalizesBooleanAndIntegerFlags(): void
    {
        $controller = new TransformCleanupController('transform-cleanup', null);
        $controller->dryRun = 'true';
        $controller->volumeId = '5';

        $action = new Action('clean');
        $this->assertTrue($controller->beforeAction($action));
        $this->assertTrue($controller->dryRun);
        $this->assertSame(5, $controller->volumeId);

        $cleanOptions = $controller->options('clean');
        $this->assertContains('dryRun', $cleanOptions);
        $this->assertContains('volumeHandle', $cleanOptions);
        $this->assertContains('volumeId', $cleanOptions);
        $this->assertContains('migrationId', $cleanOptions); // BaseConsoleController adds this
    }
}
