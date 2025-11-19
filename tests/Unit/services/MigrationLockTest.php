<?php

namespace tests\Unit\services;

use PHPUnit\Framework\TestCase;
use csabourin\craftS3SpacesMigration\services\MigrationLock;
use Craft;
use CraftAppStub;

class MigrationLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Craft::$app = new CraftAppStub();
    }

    public function testAcquireAndReleaseCreatesLock()
    {
        $lock = new MigrationLock('mig-1');
        $this->assertTrue($lock->acquire(1));

        $locks = Craft::$app->getDb()->tables['migrationlocks'];
        $this->assertArrayHasKey('migration_lock', $locks);
        $this->assertEquals('mig-1', $locks['migration_lock']['migrationId']);

        $lock->release();
        $this->assertArrayNotHasKey('migration_lock', Craft::$app->getDb()->tables['migrationlocks']);
    }

    public function testResumeAllowsSameMigration()
    {
        $initial = new MigrationLock('mig-2');
        $initial->acquire(1);

        $resume = new MigrationLock('mig-2');
        $this->assertTrue($resume->acquire(1, true));
    }

    public function testDifferentMigrationBlocksAcquire()
    {
        Craft::$app->getDb()->tables['migrationlocks']['migration_lock'] = [
            'lockName' => 'migration_lock',
            'migrationId' => 'existing',
            'lockedAt' => date('Y-m-d H:i:s'),
            'lockedBy' => 'tester',
            'expiresAt' => date('Y-m-d H:i:s', time() + 1000),
        ];

        $lock = new MigrationLock('mig-3');
        $this->assertFalse($lock->acquire(1));
    }

    public function testRefreshExtendsExpiry()
    {
        $lock = new MigrationLock('mig-4');
        $lock->acquire(1);

        $before = Craft::$app->getDb()->tables['migrationlocks']['migration_lock']['expiresAt'];
        sleep(1);
        $this->assertTrue($lock->refresh());
        $after = Craft::$app->getDb()->tables['migrationlocks']['migration_lock']['expiresAt'];

        $this->assertNotEquals($before, $after);
    }
}
