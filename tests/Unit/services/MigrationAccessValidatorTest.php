<?php

namespace tests\Unit\services;

use Craft;
use PHPUnit\Framework\TestCase;
use csabourin\craftS3SpacesMigration\services\MigrationAccessValidator;
use yii\web\ForbiddenHttpException;

class MigrationAccessValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        Craft::$app = new \CraftAppStub();
    }

    public function testRequiresAdminUser(): void
    {
        Craft::$app->user->isAdmin = false;

        $validator = new MigrationAccessValidator();

        $this->expectException(ForbiddenHttpException::class);
        $validator->requireAdminUser();
    }

    public function testRequiresAdminChangesEnabled(): void
    {
        Craft::$app->config->general->allowAdminChanges = false;

        $validator = new MigrationAccessValidator();

        $this->expectException(ForbiddenHttpException::class);
        $validator->requireAdminChangesEnabled();
    }

    public function testAllowsAdminsWhenConfigIsMutable(): void
    {
        $validator = new MigrationAccessValidator();

        $validator->requireAdminUser();
        $validator->requireAdminChangesEnabled();

        $this->assertTrue(true, 'Validator allowed admin with mutable config.');
    }
}
