<?php

namespace tests\Integration;

use PHPUnit\Framework\TestCase;
use csabourin\spaghettiMigrator\services\CheckpointManager;
use csabourin\spaghettiMigrator\services\ChangeLogManager;
use csabourin\spaghettiMigrator\services\MigrationLock;
use csabourin\spaghettiMigrator\services\RollbackEngine;
use csabourin\spaghettiMigrator\strategies\MultiMappingUrlReplacementStrategy;
use csabourin\spaghettiMigrator\strategies\RegexUrlReplacementStrategy;
use Craft;
use CraftAppStub;
use craft\helpers\FileHelper;

class MigrationFlowTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/integration_' . uniqid();
        Craft::setAlias('@storage', $this->tempDir);
        Craft::$app = new CraftAppStub();

        FileHelper::createDirectory($this->tempDir . '/migration-backups');
        FileHelper::createDirectory($this->tempDir . '/migration-checkpoints');
        FileHelper::createDirectory($this->tempDir . '/migration-changelogs');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testEndToEndCheckpointAndRollbackFlow()
    {
        $lock = new MigrationLock('flow-mig');
        $this->assertTrue($lock->acquire(1));

        $checkpoints = new CheckpointManager('flow-mig');
        $checkpoints->registerMigrationStart(999, 'session-flow', 'console migrate', 3);
        $checkpoints->saveQuickState([
            'migration_id' => 'flow-mig',
            'phase' => 'discovery',
            'processed_ids' => [1],
            'batch' => 1,
        ]);
        $checkpoints->saveCheckpoint([
            'migration_id' => 'flow-mig',
            'phase' => 'cleanup',
            'processed_ids' => [1, 2, 3],
            'batch' => 2,
            'total_count' => 3,
        ]);

        $changeLog = new ChangeLogManager('flow-mig', 1);
        $changeLog->setPhase('cleanup');
        $changeLog->logChange(['type' => 'deleted_transform', 'path' => 'asset/1']);

        $rollback = new RollbackEngine($changeLog, 'flow-mig');
        $dryRun = $rollback->rollback('flow-mig', null, 'from', true);
        $this->assertEquals(1, $dryRun['total_operations']);

        $stats = $rollback->rollback('flow-mig');
        $this->assertEquals(1, $stats['reversed']);

        $latest = $checkpoints->loadLatestCheckpoint();
        $this->assertEquals('cleanup', $latest['phase']);

        $lock->release();
        $this->assertArrayNotHasKey('migration_lock', Craft::$app->getDb()->tables['migrationlocks']);
    }

    public function testRollbackViaDatabaseDryRunIntegration()
    {
        $backupFile = $this->tempDir . '/migration-backups/migration_flow-mig_db_backup.sql';
        file_put_contents($backupFile, "-- backup\nCREATE TABLE demo (id INT);\n");

        $engine = new RollbackEngine(new ChangeLogManager('flow-mig')); 
        $plan = $engine->rollbackViaDatabase('flow-mig', true);

        $this->assertTrue($plan['dry_run']);
        $this->assertEquals($backupFile, $plan['backup_file']);
        $this->assertStringContainsString('MB', $plan['backup_size']);
    }

    public function testUrlRewriteAndMultiProviderChangelogFlow(): void
    {
        $strategy = new MultiMappingUrlReplacementStrategy([
            'https://cdn.old-1.example.com' => 'https://cdn.new.example.com',
            'https://cdn.old-2.example.com' => 'https://cdn.new.example.com',
        ]);

        $regexStrategy = new RegexUrlReplacementStrategy('#https://media\\.example\\.com/(.*)#', 'https://edge.example.com/$1');

        $content = 'https://cdn.old-1.example.com/assets/img.jpg and https://media.example.com/video.mp4';
        $rewritten = $regexStrategy->replace($strategy->replace($content));

        $this->assertStringContainsString('https://cdn.new.example.com/assets/img.jpg', $rewritten);
        $this->assertStringContainsString('https://edge.example.com/video.mp4', $rewritten);

        $changeLog = new ChangeLogManager('flow-mig', 2);
        $changeLog->setPhase('copy');
        $changeLog->logChange([
            'type' => 'copied_object',
            'source_provider' => 's3',
            'target_provider' => 'spaces',
            'path' => 'assets/img.jpg',
        ]);
        $changeLog->logChange([
            'type' => 'copied_object',
            'source_provider' => 'gcs',
            'target_provider' => 'spaces',
            'path' => 'video.mp4',
        ]);

        $changeLog->setPhase('rewrite');
        $changeLog->logChange([
            'type' => 'url_rewrite',
            'from' => $content,
            'to' => $rewritten,
        ]);

        $changeLog->flush();
        $entries = $changeLog->loadChanges();

        $this->assertCount(3, $entries);
        $this->assertSame('copy', $entries[0]['phase']);
        $this->assertSame('copy', $entries[1]['phase']);
        $this->assertSame('rewrite', $entries[2]['phase']);
        $this->assertSame('url_rewrite', $entries[2]['type']);
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
