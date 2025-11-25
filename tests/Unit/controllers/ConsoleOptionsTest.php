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

        $replaceJsonOptions = $controller->options('replace-json');
        $this->assertSame($replaceAdditionalOptions, $replaceJsonOptions);

        $this->assertSame([], $controller->options('scan-additional'));
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
    }
}
