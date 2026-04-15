<?php

namespace csabourin\spaghettiMigrator\tests\Unit\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\controllers\ExtendedUrlReplacementController;
use csabourin\spaghettiMigrator\console\controllers\TransformCleanupController;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use yii\base\Action;

class ConsoleOptionsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/console_options_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        file_put_contents($this->tempDir . '/migration-config.php', '<?php return [];');
        Craft::setAlias('@config', $this->tempDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->tempDir . '/migration-config.php');
        @rmdir($this->tempDir);

        $ref = new ReflectionClass(MigrationConfig::class);
        foreach (['config', 'settings', 'instance'] as $property) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
        $usePluginSettings = $ref->getProperty('usePluginSettings');
        $usePluginSettings->setAccessible(true);
        $usePluginSettings->setValue(null, false);

        parent::tearDown();
    }

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
