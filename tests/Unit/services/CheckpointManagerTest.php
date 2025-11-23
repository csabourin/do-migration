<?php

namespace tests\Unit\services;

use PHPUnit\Framework\TestCase;
use csabourin\spaghettiMigrator\services\CheckpointManager;
use Craft;
use craft\helpers\FileHelper;
use CraftAppStub;

class CheckpointManagerTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/checkpoint_' . uniqid();
        Craft::setAlias('@storage', $this->tempDir);
        Craft::$app = new CraftAppStub();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testSaveCheckpointPersistsFilesAndState()
    {
        $manager = new CheckpointManager('mig-1');

        $data = [
            'migration_id' => 'mig-1',
            'phase' => 'discovery',
            'processed_ids' => [1, 2, 3],
            'batch' => 2,
            'total_count' => 10,
            'stats' => ['speed' => 'fast'],
        ];

        $this->assertTrue($manager->saveCheckpoint($data));

        $checkpointPath = $this->tempDir . '/migration-checkpoints/mig-1.json';
        $this->assertFileExists($checkpointPath);
        $checkpoint = json_decode(file_get_contents($checkpointPath), true);

        $this->assertEquals('4.0', $checkpoint['checkpoint_version']);
        $this->assertEquals('discovery', $checkpoint['phase']);
        $this->assertNotEmpty($checkpoint['created_at']);

        $quickStatePath = $this->tempDir . '/migration-checkpoints/mig-1.state.json';
        $this->assertFileExists($quickStatePath);
        $quickState = json_decode(file_get_contents($quickStatePath), true);

        $this->assertEquals(3, $quickState['processed_count']);
        $this->assertEquals(2, $quickState['batch']);

        $dbState = Craft::$app->getDb()->tables['migration_state']['mig-1'] ?? null;
        $this->assertNotNull($dbState);
        $this->assertEquals('discovery', $dbState['phase']);
    }

    public function testLoadLatestCheckpointReturnsMostRecent()
    {
        $managerOne = new CheckpointManager('mig-early');
        $managerOne->saveCheckpoint(['migration_id' => 'mig-early', 'phase' => 'prep']);
        sleep(1);
        $managerTwo = new CheckpointManager('mig-late');
        $managerTwo->saveCheckpoint(['migration_id' => 'mig-late', 'phase' => 'consolidate']);

        $manager = new CheckpointManager('mig-late');
        $latest = $manager->loadLatestCheckpoint();

        $this->assertEquals('consolidate', $latest['phase']);
    }

    public function testUpdateProcessedIdsAppendsNewIds()
    {
        $manager = new CheckpointManager('mig-update');
        $manager->saveQuickState([
            'migration_id' => 'mig-update',
            'phase' => 'discovery',
            'processed_ids' => [1, 2],
            'batch' => 1,
        ]);

        $manager->updateProcessedIds([2, 3, 4]);
        $state = $manager->loadQuickState();

        sort($state['processed_ids']);
        $this->assertEquals([1, 2, 3, 4], $state['processed_ids']);
        $this->assertEquals(4, $state['processed_count']);
    }

    public function testRegisterAndMarkMigrationStates()
    {
        $manager = new CheckpointManager('mig-stateful');
        $manager->registerMigrationStart(1234, 'session-1', 'migrate-command', 50);

        $state = Craft::$app->getDb()->tables['migration_state']['mig-stateful'] ?? null;
        $this->assertEquals('running', $state['status']);
        $this->assertEquals(50, $state['totalCount']);

        $manager->markMigrationCompleted();
        $completedState = Craft::$app->getDb()->tables['migration_state']['mig-stateful'] ?? null;
        $this->assertEquals('completed', $completedState['status']);
    }

    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
