<?php

namespace tests\Unit\services;

use PHPUnit\Framework\TestCase;
use csabourin\craftS3SpacesMigration\services\ChangeLogManager;
use Craft;
use CraftAppStub;

class ChangeLogManagerTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/changelog_' . uniqid();
        Craft::setAlias('@storage', $this->tempDir);
        Craft::$app = new CraftAppStub();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testLogChangeFlushesAtThreshold()
    {
        $manager = new ChangeLogManager('mig-1', 2);
        $manager->setPhase('discovery');
        $manager->logChange(['type' => 'inline_image_linked', 'rowId' => 1]);
        $manager->logChange(['type' => 'inline_image_linked', 'rowId' => 2]);

        $logPath = $this->tempDir . '/migration-changelogs/mig-1.jsonl';
        $this->assertFileExists($logPath);

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines);

        $entry = json_decode($lines[0], true);
        $this->assertEquals('discovery', $entry['phase']);
        $this->assertEquals(1, $entry['sequence']);
    }

    public function testLoadChangesReturnsStructuredEntries()
    {
        $manager = new ChangeLogManager('mig-2', 10);
        $manager->setPhase('cleanup');
        $manager->logChange(['type' => 'deleted_transform', 'path' => 'img/1']);
        $manager->flush();

        $changes = $manager->loadChanges();
        $this->assertCount(1, $changes);
        $this->assertEquals('deleted_transform', $changes[0]['type']);
        $this->assertEquals('cleanup', $changes[0]['phase']);
    }

    public function testListMigrationsReturnsMetadata()
    {
        $manager = new ChangeLogManager('mig-3', 1);
        $manager->setPhase('prep');
        $manager->logChange(['type' => 'inline_image_linked', 'rowId' => 10]);

        $migrations = $manager->listMigrations();
        $this->assertNotEmpty($migrations);
        $this->assertEquals('mig-3', $migrations[0]['id']);
        $this->assertEquals(1, $migrations[0]['change_count']);
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
