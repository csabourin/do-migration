<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\BaseConsoleController;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Extended URL Replacement Controller
 *
 * Covers additional tables beyond content tables:
 * - projectconfig (plugin settings)
 * - elements_sites (metadata)
 * - JSON fields (table fields, etc.)
 *
 * Usage:
 *   ./craft spaghetti-migrator/extended-url-replacement/scan-additional
 *   ./craft spaghetti-migrator/extended-url-replacement/replace-additional --dryRun=1
 *   ./craft spaghetti-migrator/extended-url-replacement/replace-json --dryRun=1
 */
class ExtendedUrlReplacementController extends BaseConsoleController
{
    public $defaultAction = 'scan-additional';

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * @var bool Whether to run in dry-run mode
     */
    public $dryRun = false;

    /**
     * @var bool Skip all confirmation prompts (for automation)
     */
    public $yes = false;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();
    }

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if (in_array($actionID, ['replace-additional', 'replace-json'])) {
            $options[] = 'dryRun';
            $options[] = 'yes';
        }
        return $options;
    }

    /**
     * Scan additional database tables for AWS S3 URLs
     */
    public function actionScanAdditional(): int
    {
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("EXTENDED URL SCAN - Additional Tables\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $db = Craft::$app->getDb();
        $oldUrls = $this->getOldUrls();

        // Tables to scan
        $tables = [
            ['table' => 'projectconfig', 'column' => 'value'],
            ['table' => 'elements_sites', 'column' => 'metadata'],
            ['table' => 'revisions', 'column' => 'data'],
            ['table' => 'changedattributes', 'column' => 'attribute'],
        ];

        $totalMatches = 0;

        foreach ($tables as $tableInfo) {
            $table = $tableInfo['table'];
            $column = $tableInfo['column'];

            $this->output("Scanning {$table}.{$column}... ", Console::FG_YELLOW);

            try {
                // Check if table exists
                $tableSchema = $db->getTableSchema($table);
                if (!$tableSchema) {
                    $this->output("SKIPPED (table not found)\n", Console::FG_GREY);
                    continue;
                }

                // Check if column exists
                if (!isset($tableSchema->columns[$column])) {
                    $this->output("SKIPPED (column '{$column}' not found)\n", Console::FG_GREY);
                    continue;
                }

                // Build search condition
                $conditions = [];
                foreach ($oldUrls as $url) {
                    $conditions[] = "`{$column}` LIKE '%" . addslashes($url) . "%'";
                }
                $whereClause = implode(' OR ', $conditions);

                $count = (int) $db->createCommand("
                    SELECT COUNT(*)
                    FROM `{$table}`
                    WHERE {$whereClause}
                ")->queryScalar();

                if ($count > 0) {
                    $this->output("{$count} rows\n", Console::FG_GREEN);
                    $totalMatches += $count;

                    // Show sample
                    $sample = $db->createCommand("
                        SELECT `{$column}`
                        FROM `{$table}`
                        WHERE {$whereClause}
                        LIMIT 1
                    ")->queryScalar();

                    if ($sample) {
                        $preview = substr($sample, 0, 100);
                        $this->output("  Sample: {$preview}...\n", Console::FG_GREY);
                    }
                } else {
                    $this->output("0 rows\n", Console::FG_GREY);
                }

            } catch (\Throwable $e) {
                $this->output("ERROR: {$e->getMessage()}\n", Console::FG_RED);
            }
        }

        $this->output("\n" . str_repeat("-", 80) . "\n");
        $this->output("Total matches: {$totalMatches}\n", Console::FG_CYAN);
        $this->output(str_repeat("-", 80) . "\n\n");

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Replace AWS S3 URLs in additional tables
     */
    public function actionReplaceAdditional(): int
    {
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("EXTENDED URL REPLACEMENT - Additional Tables\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->output("MODE: DRY RUN\n\n", Console::FG_YELLOW);
        } else {
            $this->output("MODE: LIVE\n\n", Console::FG_RED);

            if (!$this->yes && !$this->confirm("This will modify additional database tables. Continue?")) {
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            } elseif ($this->yes) {
                $this->output("⚠ Auto-confirmed (--yes flag)\n\n", Console::FG_YELLOW);
            }
        }

        $db = Craft::$app->getDb();
        $urlMappings = $this->getUrlMappings();

        // Simpler tables (non-JSON)
        $simpleTables = [
            ['table' => 'changedattributes', 'column' => 'attribute'],
        ];

        foreach ($simpleTables as $tableInfo) {
            $table = $tableInfo['table'];
            $column = $tableInfo['column'];

            $tableSchema = $db->getTableSchema($table);
            if (!$tableSchema) {
                continue;
            }

            // Check if column exists
            if (!isset($tableSchema->columns[$column])) {
                $this->output("⊘ Column {$table}.{$column} not found\n", Console::FG_GREY);
                continue;
            }

            $this->output("Processing {$table}.{$column}... ");

            $totalAffected = 0;
            foreach ($urlMappings as $oldUrl => $newUrl) {
                if (!$this->dryRun) {
                    $affected = $db->createCommand("
                        UPDATE `{$table}`
                        SET `{$column}` = REPLACE(`{$column}`, :oldUrl, :newUrl)
                        WHERE `{$column}` LIKE :pattern
                    ", [
                        ':oldUrl' => $oldUrl,
                        ':newUrl' => $newUrl,
                        ':pattern' => "%{$oldUrl}%"
                    ])->execute();
                    $totalAffected += $affected;
                }
            }

            if ($this->dryRun) {
                $this->output("Would update rows\n", Console::FG_YELLOW);
            } else {
                $this->output("{$totalAffected} rows updated\n", Console::FG_GREEN);
            }
        }

        $this->output("\n✓ Complete\n\n", Console::FG_GREEN);
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Replace URLs in JSON fields
     */
    public function actionReplaceJson(): int
    {
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("JSON FIELD URL REPLACEMENT\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->output("MODE: DRY RUN\n\n", Console::FG_YELLOW);
        }

        $db = Craft::$app->getDb();
        $urlMappings = $this->getUrlMappings();

        // JSON fields to process
        $jsonTables = [
            ['table' => 'projectconfig', 'column' => 'value', 'idColumn' => 'path'],
            ['table' => 'elements_sites', 'column' => 'metadata', 'idColumn' => 'id'],
            ['table' => 'revisions', 'column' => 'data', 'idColumn' => 'id'],
        ];

        foreach ($jsonTables as $tableInfo) {
            $table = $tableInfo['table'];
            $column = $tableInfo['column'];
            $idColumn = $tableInfo['idColumn'];

            $tableSchema = $db->getTableSchema($table);
            if (!$tableSchema) {
                $this->output("⊘ Table {$table} not found\n", Console::FG_GREY);
                continue;
            }

            // Check if column exists
            if (!isset($tableSchema->columns[$column])) {
                $this->output("⊘ Column {$table}.{$column} not found\n", Console::FG_GREY);
                continue;
            }

            $this->output("Processing {$table}.{$column}...\n", Console::FG_YELLOW);

            // Find rows with S3 URLs
            $conditions = [];
            foreach ($this->getOldUrls() as $url) {
                $conditions[] = "`{$column}` LIKE '%" . addslashes($url) . "%'";
            }
            $whereClause = implode(' OR ', $conditions);

            try {
                $rows = $db->createCommand("
                    SELECT `{$idColumn}`, `{$column}`
                    FROM `{$table}`
                    WHERE {$whereClause}
                ")->queryAll();

                if (empty($rows)) {
                    $this->output("  No matches\n", Console::FG_GREY);
                    continue;
                }

                $this->output("  Found " . count($rows) . " rows\n", Console::FG_GREEN);
                $updatedCount = 0;

                foreach ($rows as $row) {
                    $id = $row[$idColumn];
                    $original = $row[$column];

                    // Try to decode JSON
                    $data = @json_decode($original, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // Not JSON, treat as string
                        $updated = $original;
                        foreach ($urlMappings as $oldUrl => $newUrl) {
                            $updated = str_replace($oldUrl, $newUrl, $updated);
                        }
                    } else {
                        // JSON - recursive replace
                        $updated = $this->replaceUrlsInArray($data, $urlMappings);
                        $updated = json_encode($updated);
                    }

                    if ($updated !== $original) {
                        $this->output("  • ID {$id}: ", Console::FG_GREY);

                        if (!$this->dryRun) {
                            $db->createCommand()->update(
                                $table,
                                [$column => $updated],
                                [$idColumn => $id]
                            )->execute();
                            $this->output("UPDATED\n", Console::FG_GREEN);
                        } else {
                            $this->output("Would update\n", Console::FG_YELLOW);
                        }

                        $updatedCount++;
                    }
                }

                $this->output("  Total updated: {$updatedCount}\n\n", Console::FG_CYAN);

            } catch (\Throwable $e) {
                $this->stderr("  ERROR: {$e->getMessage()}\n\n", Console::FG_RED);
            }
        }

        $this->output("✓ Complete\n\n", Console::FG_GREEN);
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Recursively replace URLs in nested arrays
     */
    private function replaceUrlsInArray($data, $urlMappings)
    {
        if (is_string($data)) {
            foreach ($urlMappings as $oldUrl => $newUrl) {
                $data = str_replace($oldUrl, $newUrl, $data);
            }
            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->replaceUrlsInArray($value, $urlMappings);
            }
            return $data;
        }

        return $data;
    }

    /**
     * Get old AWS S3 URLs from centralized config
     */
    private function getOldUrls(): array
    {
        return $this->config->getAwsUrls();
    }

    /**
     * Get URL mappings from centralized config
     */
    private function getUrlMappings(): array
    {
        return $this->config->getUrlMappings();
    }
}