<?php

namespace csabourin\spaghettiMigrator\console\controllers;

use csabourin\spaghettiMigrator\console\BaseConsoleController;
use csabourin\spaghettiMigrator\services\MigrationProgressService;
use yii\console\ExitCode;

/**
 * Console utilities for dashboard maintenance
 */
class DashboardMaintenanceController extends BaseConsoleController
{
    /**
     * @var int Maximum age in seconds before state is purged
     */
    public int $maxAge = 604800; // 7 days

    /**
     * @var bool Force removal regardless of age
     */
    public bool $force = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'purge-state') {
            $options[] = 'maxAge';
            $options[] = 'force';
        }

        return $options;
    }

    /**
     * Purge persisted dashboard state
     */
    public function actionPurgeState(): int
    {
        $service = new MigrationProgressService();

        if ($this->force) {
            $removed = $service->purgeState();
            $this->output($removed
                ? "Removed migration dashboard state.\n"
                : "No migration dashboard state to remove.\n");

            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        $removed = $service->purgeOldState((int) $this->maxAge);
        if ($removed) {
            $this->output("Purged migration dashboard state older than {$this->maxAge} seconds.\n");
        } else {
            $this->output("No persisted migration dashboard state met purge criteria.\n");
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }
}
