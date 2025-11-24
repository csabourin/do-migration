<?php

namespace csabourin\spaghettiMigrator\tests\Unit\services;

use Craft;
use CraftAppStub;
use csabourin\spaghettiMigrator\services\MigrationProgressService;
use PHPUnit\Framework\TestCase;

class MigrationProgressServiceTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/migration-progress-' . uniqid();
        Craft::$app = new CraftAppStub();
        Craft::setAlias('@storage/migration-dashboard', $this->storageDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            array_map('unlink', glob($this->storageDir . '/*'));
            rmdir($this->storageDir);
        }
    }

    public function testGetStateReturnsDefaultsWhenFileMissing(): void
    {
        $service = new MigrationProgressService($this->storageDir);

        $state = $service->getState();

        $this->assertSame([], $state['completedModules']);
        $this->assertSame([], $state['runningModules']);
        $this->assertSame([], $state['failedModules']);
        $this->assertSame([], $state['moduleStates']);
        $this->assertNull($state['updatedAt']);
    }

    public function testPersistCompletedModulesAndReloadState(): void
    {
        $service = new MigrationProgressService($this->storageDir);

        $this->assertTrue($service->persistCompletedModules(['phase-1', 'phase-2', 'phase-1']));

        $state = $service->getState();

        $this->assertEquals(['phase-1', 'phase-2'], $state['completedModules']);
        $this->assertNotNull($state['updatedAt']);
    }

    public function testUpdateModuleStatusTracksRunningCompletedAndFailed(): void
    {
        $service = new MigrationProgressService($this->storageDir);

        $service->updateModuleStatus('module-a', 'running');
        $runningState = $service->getState();
        $this->assertContains('module-a', $runningState['runningModules']);
        $this->assertSame('running', $runningState['moduleStates']['module-a']['status']);

        $service->updateModuleStatus('module-a', 'completed');
        $completedState = $service->getState();
        $this->assertContains('module-a', $completedState['completedModules']);
        $this->assertSame('completed', $completedState['moduleStates']['module-a']['status']);

        $service->updateModuleStatus('module-a', 'failed', 'boom');
        $failedState = $service->getState();
        $this->assertContains('module-a', $failedState['failedModules']);
        $this->assertSame('boom', $failedState['moduleStates']['module-a']['error']);
    }
}
