<?php

namespace tests\Unit\services;

use PHPUnit\Framework\TestCase;
use csabourin\spaghettiMigrator\services\RollbackEngine;
use Craft;
use CraftAppStub;
use craft\helpers\FileHelper;

class RollbackEngineTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/rollback_' . uniqid();
        Craft::setAlias('@storage', $this->tempDir);
        Craft::$app = new CraftAppStub();
        FileHelper::createDirectory($this->tempDir . '/migration-backups');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testRollbackViaDatabaseDryRunReturnsPlan()
    {
        $backupFile = $this->tempDir . '/migration-backups/migration_abc_db_backup.sql';
        file_put_contents($backupFile, "-- backup\nCREATE TABLE test (id INT);\n");

        $engine = new RollbackEngine(new FakeChangeLogManager());
        $plan = $engine->rollbackViaDatabase('abc', true);

        $this->assertTrue($plan['dry_run']);
        $this->assertEquals('database_restore', $plan['method']);
        $this->assertEquals($backupFile, $plan['backup_file']);
    }

    public function testRollbackDryRunRespectsPhaseFilters()
    {
        $changes = [
            ['type' => 'inline_image_linked', 'phase' => 'discovery'],
            ['type' => 'moved_asset', 'phase' => 'link_inline'],
            ['type' => 'deleted_transform', 'phase' => 'cleanup'],
        ];

        $engine = new RollbackEngine(new FakeChangeLogManager($changes));
        $report = $engine->rollback('mig', 'link_inline', 'from', true);

        $this->assertTrue($report['dry_run']);
        $this->assertEquals(2, $report['total_operations']);
        $this->assertArrayHasKey('link_inline', $report['by_phase']);
        $this->assertArrayNotHasKey('discovery', $report['by_phase']);
    }

    public function testGetPhasesSummaryAggregatesChanges()
    {
        $changes = [
            ['type' => 'inline_image_linked', 'phase' => 'discovery'],
            ['type' => 'inline_image_linked', 'phase' => 'discovery'],
            ['type' => 'deleted_transform', 'phase' => 'cleanup'],
        ];

        $engine = new RollbackEngine(new FakeChangeLogManager($changes));
        $summary = $engine->getPhasesSummary('mig');

        $this->assertEquals(2, $summary['discovery']);
        $this->assertEquals(1, $summary['cleanup']);
    }

    public function testRollbackViaDatabaseRestoresForeignKeyChecks()
    {
        $backupFile = $this->tempDir . '/migration-backups/migration_abc_db_backup.sql';
        file_put_contents($backupFile, "-- backup\nCREATE TABLE restored (id INT);\n");

        $engine = new RollbackEngine(new FakeChangeLogManager());
        $engine->rollbackViaDatabase('abc');

        $db = Craft::$app->getDb();
        $this->assertContains('SET FOREIGN_KEY_CHECKS=0', $db->executedStatements);
        $this->assertContains('SET FOREIGN_KEY_CHECKS=1', $db->executedStatements);

        $lastTransaction = end($db->transactions);
        $this->assertNotFalse($lastTransaction);
        $this->assertTrue($lastTransaction->committed);
        $this->assertFalse($lastTransaction->isActive);
    }

    public function testRollbackFailureRollsBackAndRestoresForeignKeys()
    {
        $backupFile = $this->tempDir . '/migration-backups/migration_abc_db_backup.sql';
        file_put_contents($backupFile, "-- backup\nINVALID TABLE;\n");

        $db = Craft::$app->getDb();
        $db->failOnSqlPattern = '/INVALID/';

        $engine = new RollbackEngine(new FakeChangeLogManager());

        try {
            $engine->rollbackViaDatabase('abc');
            $this->fail('Expected rollback to throw due to invalid SQL');
        } catch (\Exception $e) {
            $lastTransaction = end($db->transactions);
            $this->assertTrue($lastTransaction->rolledBack);
            $this->assertFalse($lastTransaction->isActive);
            $this->assertEquals('SET FOREIGN_KEY_CHECKS=1', end($db->executedStatements));
        }
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

class FakeChangeLogManager
{
    private $changes;

    public function __construct(array $changes = [])
    {
        $this->changes = $changes;
    }

    public function loadChanges()
    {
        return $this->changes;
    }
}
