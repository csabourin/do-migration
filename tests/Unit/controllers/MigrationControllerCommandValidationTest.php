<?php

namespace csabourin\spaghettiMigrator\tests\Unit\controllers;

use Craft;
use CraftAppStub;
use csabourin\spaghettiMigrator\controllers\MigrationController;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use yii\base\Action;

class MigrationControllerCommandValidationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Craft::$app = new CraftAppStub();
        $this->tempDir = sys_get_temp_dir() . '/migration_controller_test_' . uniqid();
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

    public function testDashboardCommandValidationRejectsInvalidOrUnlistedCommands(): void
    {
        $controller = new MigrationController('migration', null);
        $validator = new ReflectionMethod($controller, 'validateDashboardCommand');
        $validator->setAccessible(true);

        $this->assertNull($validator->invoke($controller, 'image-migration/migrate'));
        $this->assertSame('Invalid command format', $validator->invoke($controller, '../../bad'));
        $this->assertSame('Command not allowed', $validator->invoke($controller, 'image-migration/not-real'));
        $this->assertSame('Command is required and must be a string', $validator->invoke($controller, null));
    }

    public function testStreamMigrationRequiresAdminChanges(): void
    {
        $controller = new MigrationController('migration', null);
        $requiresAdminChanges = new ReflectionMethod($controller, 'requiresAdminChanges');
        $requiresAdminChanges->setAccessible(true);

        $this->assertTrue($requiresAdminChanges->invoke($controller, new Action('stream-migration')));
        $this->assertFalse($requiresAdminChanges->invoke($controller, new Action('index')));
    }
}
