<?php
namespace csabourin\craftS3SpacesMigration\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use csabourin\craftS3SpacesMigration\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * URL Replacement Controller (Refactored with Centralized Config)
 *
 * This is an example of how to refactor existing controllers to use
 * the centralized MigrationConfig helper.
 *
 * Key Changes:
 * 1. Use MigrationConfig::getInstance() to get config
 * 2. Replace hardcoded values with config methods
 * 3. All environment-specific values come from config file
 */
class UrlReplacementController extends Controller
{
    public $defaultAction = 'replace-s3-urls';

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
     * Initialize controller
     */
    public function init(): void
    {
        parent::init();

        // Load configuration
        try {
            $this->config = MigrationConfig::getInstance();
        } catch (\Exception $e) {
            $this->stderr("\nConfiguration Error: " . $e->getMessage() . "\n\n", Console::FG_RED);
            exit(ExitCode::CONFIG);
        }
    }

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'replace-s3-urls') {
            $options[] = 'dryRun';
            $options[] = 'yes';
        }
        return $options;
    }

    /**
     * Replace AWS S3 URLs with DigitalOcean Spaces URLs
     *
     * @param string|null $newUrl Override the target URL (optional)
     */
    public function actionReplaceS3Urls($newUrl = null)
    {
        // Validate configuration
        $errors = $this->config->validate();
        if (!empty($errors)) {
            $this->stderr("\nConfiguration errors:\n", Console::FG_RED);
            foreach ($errors as $error) {
                $this->stderr("  • {$error}\n", Console::FG_RED);
            }
            $this->stderr("\nPlease fix configuration and try again.\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::CONFIG;
        }

        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("AWS S3 → DIGITALOCEAN SPACES URL REPLACEMENT\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // Display environment info
        $this->stdout("Environment: " . strtoupper($this->config->getEnvironment()) . "\n", Console::FG_YELLOW);
        $this->stdout("AWS Bucket: " . $this->config->getAwsBucket() . "\n", Console::FG_GREY);
        $this->stdout("DO Bucket: " . $this->config->getDoBucket() . "\n", Console::FG_GREY);
        $this->stdout("DO Base URL: " . $this->config->getDoBaseUrl() . "\n\n", Console::FG_GREY);

        if ($this->dryRun) {
            $this->stdout("MODE: DRY RUN - No changes will be made\n\n", Console::FG_YELLOW);
        } else {
            $this->stdout("MODE: LIVE - Changes will be saved to database\n\n", Console::FG_RED);

            if (!$this->yes) {
                $this->stdout("⚠ This will modify your database content!\n", Console::FG_YELLOW);
                $this->stdout("Press 'y' to continue or any other key to abort: ");
                $confirm = fgets(STDIN);
                if (trim(strtolower($confirm)) !== 'y') {
                    $this->stdout("Aborted.\n", Console::FG_YELLOW);
                    $this->stdout("__CLI_EXIT_CODE_0__\n");
                    return ExitCode::OK;
                }
            } else {
                $this->stdout("⚠ Auto-confirmed (--yes flag)\n\n", Console::FG_YELLOW);
            }
        }

        $db = Craft::$app->getDb();

        try {
            // BEFORE: Hardcoded URL mappings in getUrlMappings()
            // AFTER: Get from centralized config
            $urlMappings = $this->config->getUrlMappings($newUrl);

            $this->stdout("URL Mappings:\n", Console::FG_CYAN);
            foreach ($urlMappings as $old => $new) {
                $this->stdout("  {$old}\n", Console::FG_GREY);
                $this->stdout("    → {$new}\n", Console::FG_GREEN);
            }
            $this->stdout("\n");

            // Discover all content columns
            $this->stdout("Discovering content columns...\n");
            $columns = $this->discoverContentColumns($db);
            $this->stdout("  Found " . count($columns) . " content columns to scan\n\n");

            // Scan for occurrences
            $this->stdout("Scanning for AWS S3 URLs...\n");
            $matches = $this->scanForUrls($db, $columns, array_keys($urlMappings));

            if (empty($matches)) {
                $this->stdout("\n✓ No AWS S3 URLs found. Nothing to replace!\n", Console::FG_GREEN);
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;
            }

            // Display summary
            $this->displaySummary($matches);

            // Show sample URLs (helps verify correct replacement)
            $this->showSampleUrls($db, $matches, array_keys($urlMappings));

            // Perform replacements
            if (!$this->dryRun) {
                $this->stdout("\nPerforming replacements...\n");
                $results = $this->performReplacements($db, $matches, $urlMappings);
                $this->displayResults($results);

                // Generate report
                $this->generateReport($results, $urlMappings);
            } else {
                $this->stdout("\n" . str_repeat("-", 80) . "\n", Console::FG_YELLOW);
                $this->stdout("DRY RUN complete. Run with --dryRun=0 to apply changes.\n", Console::FG_YELLOW);
                $this->stdout(str_repeat("-", 80) . "\n\n", Console::FG_YELLOW);
            }

        } catch (\Exception $e) {
            $this->stderr("\nError: " . $e->getMessage() . "\n", Console::FG_RED);
            $this->stderr("Stack trace:\n" . $e->getTraceAsString() . "\n\n", Console::FG_GREY);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n✓ Process completed successfully!\n\n", Console::FG_GREEN);
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Verify that no AWS S3 URLs remain in the database
     */
    public function actionVerify()
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("VERIFICATION - Checking for Remaining AWS S3 URLs\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $db = Craft::$app->getDb();
        $columns = $this->discoverContentColumns($db);

        // BEFORE: Hardcoded in getUrlMappings()
        // AFTER: Get from config
        $oldUrls = $this->config->getAwsUrls();

        $this->stdout("Checking for remaining AWS S3 URLs in {" . count($columns) . "} columns...\n\n");
        $remaining = $this->scanForUrls($db, $columns, $oldUrls);

        if (empty($remaining)) {
            $this->stdout("✓ No AWS S3 URLs found. Replacement complete!\n\n", Console::FG_GREEN);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        } else {
            $this->stdout("⚠ Still found AWS S3 URLs in database:\n\n", Console::FG_YELLOW);
            $this->displaySummary($remaining);
            $this->showSampleUrls($db, $remaining, $oldUrls);
            $this->stdout("\n");
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Show current configuration
     */
    public function actionShowConfig(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("MIGRATION CONFIGURATION\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $this->stdout($this->config->displaySummary() . "\n");

        // Validate
        $errors = $this->config->validate();
        if (!empty($errors)) {
            $this->stdout("\n" . str_repeat("-", 80) . "\n", Console::FG_YELLOW);
            $this->stdout("⚠ Configuration Issues:\n", Console::FG_YELLOW);
            foreach ($errors as $error) {
                $this->stdout("  • {$error}\n", Console::FG_RED);
            }
            $this->stdout("\n");
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::CONFIG;
        } else {
            $this->stdout("\n✓ Configuration is valid\n\n", Console::FG_GREEN);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }
    }

    /**
     * Discover all content columns that might contain URLs
     *
     * BEFORE: Hardcoded table patterns
     * AFTER: Uses config->getContentTablePatterns()
     */
    private function discoverContentColumns($db): array
    {
        $schema = (string) $db->createCommand('SELECT DATABASE()')->queryScalar();

        // Get table patterns from config
        $tablePatterns = $this->config->getContentTablePatterns();
        $columnTypes = $this->config->getColumnTypes();

        // Build WHERE conditions
        $tableConditions = [];
        foreach ($tablePatterns as $pattern) {
            if (strpos($pattern, '%') !== false) {
                // Pattern with wildcard
                $tableConditions[] = "TABLE_NAME LIKE " . $db->quoteValue($pattern);
            } else {
                // Exact match
                $tableConditions[] = "TABLE_NAME = " . $db->quoteValue($pattern);
            }
        }
        $tableWhere = '(' . implode(' OR ', $tableConditions) . ')';

        // Get all text columns from content tables
        $columns = $db->createCommand("
            SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :schema
              AND {$tableWhere}
              AND TABLE_NAME NOT LIKE '%backup%'
              AND TABLE_NAME NOT LIKE '%\\_tmp\\_%'
              AND DATA_TYPE IN (" . implode(',', array_map([$db, 'quoteValue'], $columnTypes)) . ")
              AND COLUMN_NAME LIKE 'field\\_%'
            ORDER BY TABLE_NAME, COLUMN_NAME
        ", [':schema' => $schema])->queryAll();

        return $columns;
    }

    /**
     * Scan for URLs in content columns
     */
    private function scanForUrls($db, $columns, $oldUrls): array
    {
        $schema = (string) $db->createCommand('SELECT DATABASE()')->queryScalar();
        $matches = [];

        $progress = 0;
        $total = count($columns);

        foreach ($columns as $col) {
            $progress++;
            $table = $col['table_name'];
            $column = $col['column_name'];
            $fqn = sprintf('`%s`.`%s`', str_replace('`', '', $schema), str_replace('`', '', $table));

            $this->stdout(sprintf("  [%d/%d] Scanning %s.%s... ", $progress, $total, $table, $column));

            try {
                // Count rows containing any of the old URLs
                $conditions = [];
                $params = [];
                foreach ($oldUrls as $idx => $url) {
                    $conditions[] = "`{$column}` LIKE :url{$idx}";
                    $params[":url{$idx}"] = "%{$url}%";
                }
                $whereClause = implode(' OR ', $conditions);

                $count = (int) $db->createCommand("
                    SELECT COUNT(*)
                    FROM {$fqn}
                    WHERE {$whereClause}
                ", $params)->queryScalar();

                if ($count > 0) {
                    $matches[] = [
                        'table' => $table,
                        'column' => $column,
                        'count' => $count
                    ];
                    $this->stdout("{$count} rows\n", Console::FG_GREEN);
                } else {
                    $this->stdout("0 rows\n", Console::FG_GREY);
                }

            } catch (\Throwable $e) {
                $this->stdout("SKIPPED (error)\n", Console::FG_YELLOW);
            }
        }

        return $matches;
    }

    /**
     * Show sample URLs to verify replacement will work correctly
     */
    private function showSampleUrls($db, $matches, $oldUrls, $limit = 5): void
    {
        if (empty($matches)) {
            return;
        }

        $this->stdout("\n" . str_repeat("-", 80) . "\n", Console::FG_CYAN);
        $this->stdout("SAMPLE URLS (showing up to {$limit} examples)\n", Console::FG_CYAN);
        $this->stdout(str_repeat("-", 80) . "\n");

        $schema = (string) $db->createCommand('SELECT DATABASE()')->queryScalar();
        $sampleCount = 0;

        foreach ($matches as $match) {
            if ($sampleCount >= $limit) {
                break;
            }

            $table = $match['table'];
            $column = $match['column'];
            $fqn = sprintf('`%s`.`%s`', str_replace('`', '', $schema), str_replace('`', '', $table));

            // Build WHERE clause
            $conditions = [];
            $params = [];
            foreach ($oldUrls as $idx => $url) {
                $conditions[] = "`{$column}` LIKE :url{$idx}";
                $params[":url{$idx}"] = "%{$url}%";
            }
            $whereClause = implode(' OR ', $conditions);

            try {
                $rows = $db->createCommand("
                    SELECT `{$column}`
                    FROM {$fqn}
                    WHERE {$whereClause}
                    LIMIT 2
                ", $params)->queryAll();

                foreach ($rows as $row) {
                    if ($sampleCount >= $limit) {
                        break 2;
                    }

                    $content = $row[$column];

                    // Extract URLs from content
                    foreach ($oldUrls as $oldUrl) {
                        if (strpos($content, $oldUrl) !== false) {
                            // Find a sample URL (first occurrence)
                            preg_match('#' . preg_quote($oldUrl, '#') . '[^\s"\'<>]*#', $content, $urlMatches);

                            if (!empty($urlMatches[0])) {
                                $this->stdout("\n  From: {$table}.{$column}\n", Console::FG_GREY);
                                $this->stdout("  " . substr($urlMatches[0], 0, 100) . "\n", Console::FG_YELLOW);
                                $sampleCount++;
                                break;
                            }
                        }
                    }
                }

            } catch (\Throwable $e) {
                // Skip on error
            }
        }

        if ($sampleCount === 0) {
            $this->stdout("\n  (Could not extract sample URLs)\n");
        }

        $this->stdout("\n");
    }

    /**
     * Display summary of scan results
     */
    private function displaySummary($matches): void
    {
        $totalRows = array_sum(array_column($matches, 'count'));

        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("SCAN RESULTS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n");
        $this->stdout("Found AWS S3 URLs in {$totalRows} rows across " . count($matches) . " columns:\n\n");

        foreach ($matches as $match) {
            $this->stdout(sprintf(
                "  %-40s %-30s %6d rows\n",
                $match['table'],
                $match['column'],
                $match['count']
            ));
        }
    }

    /**
     * Perform the actual URL replacements
     */
    private function performReplacements($db, $matches, $urlMappings): array
    {
        $schema = (string) $db->createCommand('SELECT DATABASE()')->queryScalar();
        $results = [];

        $progress = 0;
        $total = count($matches);

        foreach ($matches as $match) {
            $progress++;
            $table = $match['table'];
            $column = $match['column'];
            $fqn = sprintf('`%s`.`%s`', str_replace('`', '', $schema), str_replace('`', '', $table));

            $this->stdout(sprintf("  [%d/%d] Updating %s.%s... ", $progress, $total, $table, $column));

            try {
                $totalAffected = 0;

                // Process each old URL → new URL mapping
                foreach ($urlMappings as $oldUrl => $newUrl) {
                    $affected = $db->createCommand("
                        UPDATE {$fqn}
                        SET `{$column}` = REPLACE(`{$column}`, :oldUrl, :newUrl)
                        WHERE `{$column}` LIKE :pattern
                    ", [
                        ':oldUrl' => $oldUrl,
                        ':newUrl' => $newUrl,
                        ':pattern' => "%{$oldUrl}%"
                    ])->execute();

                    $totalAffected += $affected;
                }

                $results[] = [
                    'table' => $table,
                    'column' => $column,
                    'rows_updated' => $totalAffected
                ];

                $this->stdout("{$totalAffected} rows updated\n", Console::FG_GREEN);

            } catch (\Throwable $e) {
                $this->stdout("FAILED: " . $e->getMessage() . "\n", Console::FG_RED);
                $results[] = [
                    'table' => $table,
                    'column' => $column,
                    'rows_updated' => 0,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Display replacement results
     */
    private function displayResults($results): void
    {
        $totalUpdated = array_sum(array_column($results, 'rows_updated'));
        $errors = array_filter($results, function($r) { return isset($r['error']); });

        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("REPLACEMENT RESULTS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n");

        $this->stdout("Total rows updated: {$totalUpdated}\n", Console::FG_GREEN);

        if (!empty($errors)) {
            $this->stdout("\nErrors encountered:\n", Console::FG_RED);
            foreach ($errors as $error) {
                $this->stdout("  {$error['table']}.{$error['column']}: {$error['error']}\n", Console::FG_RED);
            }
        }

        $this->stdout("\nBreakdown by table/column:\n");
        foreach ($results as $result) {
            if (!isset($result['error'])) {
                $this->stdout(sprintf(
                    "  %-40s %-30s %6d rows\n",
                    $result['table'],
                    $result['column'],
                    $result['rows_updated']
                ));
            }
        }
    }

    /**
     * Generate CSV report
     *
     * BEFORE: Hardcoded path
     * AFTER: Uses config->getLogsPath()
     */
    private function generateReport($results, $urlMappings): void
    {
        $logsPath = $this->config->getLogsPath();
        $reportPath = $logsPath . '/url-replacement-report-' . date('Y-m-d-His') . '.csv';

        $this->stdout("\nGenerating report: {$reportPath}\n", Console::FG_CYAN);

        $fp = fopen($reportPath, 'w');

        // Header
        fputcsv($fp, ['Table', 'Column', 'Rows Updated', 'Status', 'Error']);

        // Results
        foreach ($results as $result) {
            fputcsv($fp, [
                $result['table'],
                $result['column'],
                $result['rows_updated'],
                isset($result['error']) ? 'FAILED' : 'SUCCESS',
                $result['error'] ?? ''
            ]);
        }

        // URL mappings reference
        fputcsv($fp, []);
        fputcsv($fp, ['URL Mappings']);
        foreach ($urlMappings as $old => $new) {
            fputcsv($fp, [$old, '→', $new]);
        }

        fclose($fp);
        $this->stdout("Report saved successfully.\n", Console::FG_GREEN);
    }
}
