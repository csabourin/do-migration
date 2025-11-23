<?php

namespace csabourin\spaghettiMigrator\services;

use Craft;
use yii\web\ForbiddenHttpException;

class MigrationAccessValidator
{
    /**
     * Ensure the current user is an administrator.
     *
     * @throws ForbiddenHttpException When the user is not an admin or no user session exists.
     */
    public function requireAdminUser(): void
    {
        $user = Craft::$app->getUser();

        if (!$user || !$user->getIsAdmin()) {
            throw new ForbiddenHttpException('You must be an admin to manage S3 → Spaces migrations.');
        }
    }

    /**
     * Ensure admin changes are allowed for operations that mutate configuration or assets.
     *
     * @throws ForbiddenHttpException When admin changes are disabled in config/general.php.
     */
    public function requireAdminChangesEnabled(): void
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();

        if (!$generalConfig->allowAdminChanges) {
            throw new ForbiddenHttpException(
                'Enable “allowAdminChanges” in config/general.php before running migration commands.'
            );
        }
    }
}
