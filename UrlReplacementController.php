<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

class UrlReplacementController extends Controller
{
    public $defaultAction = 'replace-s3-urls';

    /**
     * Replace AWS S3 URLs with DigitalOcean Spaces URLs
     * 
     * @param bool $dryRun Run in dry-run mode (no changes)
     * @param string|null $newUrl Override the target URL (optional)
     */
    public function actionReplaceS3Urls($dryRun = false, $newUrl = null)
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("AWS S3 → DIGITALOCEAN SPACES URL REPLACEMENT\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);
        
        if ($dryRun) {
            $this->stdout("MODE: DRY RUN - No changes will be made\n\n", Console::FG_YELLOW);
        } else {
            $this->stdout("MODE: LIVE - Changes will be saved to database\n\n", Console::FG_RED);
            $this->stdout("⚠ This will modify your database content!\n", Console::FG_YELLOW);
            $this->stdout("Press 'y' to continue or any other key to abort: ");
            $confirm = fgets(STDIN);
            if (trim(strtolower($confirm)) !== 'y') {
                $this->stdout("Aborted.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }
        }

        $db = Craft::$app->getDb();

        try {
            // Get URL mappings
            $urlMappings = $this->getUrlMappings($newUrl);
            
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
                return ExitCode::OK;
            }

            // Display summary
            $this->displaySummary($matches);

            // Show sample URLs (helps verify correct replacement)
            $this->showSampleUrls($db, $matches, array_keys($urlMappings));

            // Perform replacements
            if (!$dryRun) {
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
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n✓ Process completed successfully!\n\n", Console::FG_GREEN);
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
        
        $oldUrls = array_keys($this->getUrlMappings());
        
        $this->stdout("Checking for remaining AWS S3 URLs in {" . count($columns) . "} columns...\n\n");
        $remaining = $this->scanForUrls($db, $columns, $oldUrls);
        
        if (empty($remaining)) {
            $this->stdout("✓ No AWS S3 URLs found. Replacement complete!\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        } else {
            $this->stdout("⚠ Still found AWS S3 URLs in database:\n\n", Console::FG_YELLOW);
            $this->displaySummary($remaining);
            $this->showSampleUrls($db, $remaining, $oldUrls);
            $this->stdout("\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Show sample URLs from the database (helps verify correct paths)
     */
    public function actionShowSamples($limit = 10)
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("SAMPLE AWS S3 URLs IN DATABASE\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $db = Craft::$app->getDb();
        $columns = $this->discoverContentColumns($db);
        $oldUrls = array_keys($this->getUrlMappings());

        $matches = $this->scanForUrls($db, $columns, $oldUrls);

        if (empty($matches)) {
            $this->stdout("No AWS S3 URLs found.\n\n");
            return ExitCode::OK;
        }

        $this->showSampleUrls($db, $matches, $oldUrls, $limit);
        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Get URL mappings (old AWS URLs → new DO URLs)
     */
    private function getUrlMappings($customNewUrl = null): array
    {
        // Default new URL (from environment)
        $newUrl = $customNewUrl ?? 'https://dev-medias-test.tor1.digitaloceanspaces.com';

        // All possible AWS S3 URL formats for your bucket
        return [
            // Standard bucket URL formats
            'https://ncc-website-2.s3.amazonaws.com' => $newUrl,
            'http://ncc-website-2.s3.amazonaws.com' => $newUrl,
            
            // Regional bucket URLs
            'https://s3.ca-central-1.amazonaws.com/ncc-website-2' => $newUrl,
            'http://s3.ca-central-1.amazonaws.com/ncc-website-2' => $newUrl,
            
            // Generic S3 URLs (less common, but possible)
            'https://s3.amazonaws.com/ncc-website-2' => $newUrl,
            'http://s3.amazonaws.com/ncc-website-2' => $newUrl,
        ];
    }

    /**
     * Discover all content columns that might contain URLs
     */
    private function discoverContentColumns($db): array
    {
        $schema = (string) $db->createCommand('SELECT DATABASE()')->queryScalar();

        // Get all text/mediumtext/longtext columns from content tables
        $columns = $db->createCommand("
            SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :schema
              AND (
                   TABLE_NAME = 'content'
                OR TABLE_NAME LIKE 'matrixcontent\\_%'
              )
              AND TABLE_NAME NOT LIKE '%backup%'
              AND TABLE_NAME NOT LIKE '%\\_tmp\\_%'
              AND DATA_TYPE IN ('text', 'mediumtext', 'longtext')
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
     */
    private function generateReport($results, $urlMappings): void
    {
        $reportPath = Craft::getAlias('@storage/logs/url-replacement-report-' . date('Y-m-d-His') . '.csv');
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