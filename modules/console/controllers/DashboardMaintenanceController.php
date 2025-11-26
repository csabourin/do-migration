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
     * Verify and fix database schema for migration_state table
     */
    public function actionVerifySchema(): int
    {
        $this->output("Verifying migration_state table schema...\n");

        $db = \Craft::$app->getDb();
        $tableName = '{{%migration_state}}';

        // Check if table exists
        if (!$db->tableExists($tableName)) {
            $this->output("❌ Table migration_state does not exist!\n");
            $this->output("Run: ./craft install/plugin spaghetti-migrator\n");
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->output("✓ Table exists\n\n");

        // Get current schema
        $schema = $db->getTableSchema($tableName);
        $columns = array_keys($schema->columns);

        $this->output("Current columns (" . count($columns) . "):\n");
        foreach ($columns as $col) {
            $this->output("  - $col\n");
        }
        $this->output("\n");

        // Check for output column
        if (!isset($schema->columns['output'])) {
            $this->output("❌ Missing 'output' column - adding it now...\n");

            try {
                $db->createCommand()
                    ->addColumn($tableName, 'output', $db->getSchema()->createColumnSchemaBuilder('mediumtext')->after('checkpointFile'))
                    ->execute();

                $this->output("✓ Successfully added 'output' column\n");
            } catch (\Exception $e) {
                $this->output("❌ Failed to add 'output' column: " . $e->getMessage() . "\n");
                $this->stdout("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } else {
            $this->output("✓ 'output' column exists\n");
        }

        $this->output("\nSchema verification complete!\n");
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
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
