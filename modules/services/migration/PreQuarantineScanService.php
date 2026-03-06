<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\migration\MigrationReporter;

/**
 * Pre-Quarantine Scan Service
 *
 * Scans templates and database content for asset references BEFORE quarantine.
 * Assets that are referenced in templates, database content, or static files
 * are "rescued" from quarantine even if they have zero Craft relations.
 *
 * This addresses the gap where the quarantine decision relied solely on Craft's
 * `relations` table, missing assets referenced via:
 * - Hardcoded URLs in Twig templates
 * - Raw URLs in database content columns (not just RTE <img> tags)
 * - Asset filenames in CSS/JS static files
 * - Broader HTML elements (<a href>, background-image, <source>, <video poster>)
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class PreQuarantineScanService
{
    /**
     * @var Controller Controller instance for output
     */
    private $controller;

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * @var MigrationReporter Reporter for output
     */
    private $reporter;

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param MigrationConfig $config Configuration helper
     * @param MigrationReporter $reporter Reporter for output
     */
    public function __construct(
        Controller $controller,
        MigrationConfig $config,
        MigrationReporter $reporter
    ) {
        $this->controller = $controller;
        $this->config = $config;
        $this->reporter = $reporter;
    }

    /**
     * Scan for references to assets that are marked as "unused"
     *
     * Checks templates and database content for any references to the
     * given unused assets. Returns an array of asset IDs that were found
     * to be referenced and should NOT be quarantined.
     *
     * @param array $unusedAssets Assets currently marked as unused
     * @param array $assetInventory Full asset inventory
     * @return array Asset IDs that should be rescued from quarantine
     */
    public function scanForReferences(array $unusedAssets, array $assetInventory): array
    {
        if (empty($unusedAssets)) {
            return [];
        }

        $rescuedIds = [];

        // Build lookup of unused asset filenames => asset IDs
        $unusedFilenames = [];
        foreach ($unusedAssets as $asset) {
            $filename = $asset['filename'];
            if (!isset($unusedFilenames[$filename])) {
                $unusedFilenames[$filename] = [];
            }
            $unusedFilenames[$filename][] = $asset['id'];
        }

        $this->controller->stdout("  Scanning for references to " . count($unusedAssets) . " unused assets...\n\n");

        // A) Scan Twig templates
        $templateRescued = $this->scanTemplates($unusedFilenames);
        $rescuedIds = array_merge($rescuedIds, $templateRescued);

        // B) Scan database content columns
        $dbRescued = $this->scanDatabaseContent($unusedFilenames);
        $rescuedIds = array_merge($rescuedIds, $dbRescued);

        // C) Scan static asset files (JS/CSS)
        $staticRescued = $this->scanStaticFiles($unusedFilenames);
        $rescuedIds = array_merge($rescuedIds, $staticRescued);

        $rescuedIds = array_unique($rescuedIds);

        if (!empty($rescuedIds)) {
            $this->controller->stdout(
                "  ✓ Rescued " . count($rescuedIds) . " assets from quarantine (found references)\n\n",
                Console::FG_GREEN
            );
        } else {
            $this->controller->stdout(
                "  ✓ No additional references found for unused assets\n\n",
                Console::FG_GREEN
            );
        }

        return $rescuedIds;
    }

    /**
     * Scan Twig templates for asset filename references
     *
     * @param array $unusedFilenames Map of filename => [asset IDs]
     * @return array Asset IDs found in templates
     */
    private function scanTemplates(array $unusedFilenames): array
    {
        $this->controller->stdout("  [A] Scanning Twig templates...\n");

        $templatesPath = Craft::getAlias('@templates');
        if (!$templatesPath || !is_dir($templatesPath)) {
            $this->controller->stdout("    ⚠ Templates directory not found, skipping\n", Console::FG_YELLOW);
            return [];
        }

        $twigFiles = $this->findTemplateFiles($templatesPath);
        $this->controller->stdout("    Found " . count($twigFiles) . " template files\n");

        if (empty($twigFiles)) {
            return [];
        }

        $rescuedIds = [];
        $rescuedCount = 0;

        // Load all template content into a single string for efficient searching
        $allContent = '';
        foreach ($twigFiles as $file) {
            try {
                $allContent .= file_get_contents($file) . "\n";
            } catch (\Exception $e) {
                // Skip unreadable files
            }
        }

        foreach ($unusedFilenames as $filename => $assetIds) {
            // Check if filename appears anywhere in templates
            if (strpos($allContent, $filename) !== false) {
                $rescuedIds = array_merge($rescuedIds, $assetIds);
                $rescuedCount++;
            }
        }

        $this->controller->stdout(
            "    ✓ Found {$rescuedCount} asset filenames referenced in templates\n",
            Console::FG_GREEN
        );

        return $rescuedIds;
    }

    /**
     * Scan database content columns for asset filename references
     *
     * Uses the same column discovery pattern as UrlReplacementController
     * to find all text columns, then searches for unused asset filenames.
     *
     * @param array $unusedFilenames Map of filename => [asset IDs]
     * @return array Asset IDs found in database content
     */
    private function scanDatabaseContent(array $unusedFilenames): array
    {
        $this->controller->stdout("  [B] Scanning database content...\n");

        $db = Craft::$app->getDb();
        $columns = $this->discoverContentColumns($db);

        $this->controller->stdout("    Found " . count($columns) . " content columns to scan\n");

        if (empty($columns)) {
            return [];
        }

        $rescuedIds = [];
        $rescuedCount = 0;

        // Process filenames in batches to avoid excessively long SQL queries
        $filenameBatches = array_chunk(array_keys($unusedFilenames), 50, true);

        foreach ($columns as $col) {
            $table = $col['table_name'];
            $column = $col['column_name'];

            foreach ($filenameBatches as $batch) {
                try {
                    $conditions = [];
                    $params = [];

                    foreach ($batch as $idx => $filename) {
                        $conditions[] = "`{$column}` LIKE :fn{$idx}";
                        $params[":fn{$idx}"] = "%{$filename}%";
                    }

                    $whereClause = implode(' OR ', $conditions);

                    // Just check if ANY row contains one of these filenames
                    $matchingRows = $db->createCommand("
                        SELECT `{$column}` as content
                        FROM `{$table}`
                        WHERE {$whereClause}
                        LIMIT 100
                    ", $params)->queryAll();

                    if (!empty($matchingRows)) {
                        // Check which specific filenames were found
                        foreach ($matchingRows as $row) {
                            $content = $row['content'] ?? '';
                            foreach ($batch as $filename) {
                                if (strpos($content, $filename) !== false) {
                                    if (isset($unusedFilenames[$filename])) {
                                        $rescuedIds = array_merge($rescuedIds, $unusedFilenames[$filename]);
                                        $rescuedCount++;
                                        // Remove from future searches
                                        unset($unusedFilenames[$filename]);
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Skip columns that cause errors
                    continue;
                }
            }
        }

        $this->controller->stdout(
            "    ✓ Found {$rescuedCount} asset filenames referenced in database content\n",
            Console::FG_GREEN
        );

        return $rescuedIds;
    }

    /**
     * Scan static files (JS/CSS) for asset filename references
     *
     * @param array $unusedFilenames Map of filename => [asset IDs]
     * @return array Asset IDs found in static files
     */
    private function scanStaticFiles(array $unusedFilenames): array
    {
        $this->controller->stdout("  [C] Scanning static asset files...\n");

        $webRoot = Craft::getAlias('@webroot');
        if (!$webRoot || !is_dir($webRoot)) {
            $this->controller->stdout("    ⚠ Web root not found, skipping\n", Console::FG_YELLOW);
            return [];
        }

        $staticDirs = ['assets', 'dist', 'build', 'css', 'js'];
        $extensions = ['js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'css', 'scss', 'sass', 'less', 'vue', 'svelte'];

        $staticFiles = [];
        foreach ($staticDirs as $dir) {
            $fullPath = $webRoot . '/' . $dir;
            if (is_dir($fullPath)) {
                $staticFiles = array_merge($staticFiles, $this->findFilesByExtension($fullPath, $extensions));
            }
        }

        $this->controller->stdout("    Found " . count($staticFiles) . " static files\n");

        if (empty($staticFiles)) {
            return [];
        }

        $rescuedIds = [];
        $rescuedCount = 0;

        // Load all static content
        $allContent = '';
        foreach ($staticFiles as $file) {
            try {
                $allContent .= file_get_contents($file) . "\n";
            } catch (\Exception $e) {
                // Skip unreadable files
            }
        }

        foreach ($unusedFilenames as $filename => $assetIds) {
            if (strpos($allContent, $filename) !== false) {
                $rescuedIds = array_merge($rescuedIds, $assetIds);
                $rescuedCount++;
            }
        }

        $this->controller->stdout(
            "    ✓ Found {$rescuedCount} asset filenames referenced in static files\n",
            Console::FG_GREEN
        );

        return $rescuedIds;
    }

    /**
     * Find all Twig and HTML template files recursively
     *
     * @param string $dir Directory to search
     * @return array File paths
     */
    private function findTemplateFiles(string $dir): array
    {
        $files = [];
        $templateExtensions = ['twig', 'html', 'htm'];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), $templateExtensions, true)) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Exception $e) {
            Craft::warning("Could not scan templates directory: " . $e->getMessage(), __METHOD__);
        }

        return $files;
    }

    /**
     * Find files by extension recursively
     *
     * @param string $dir Directory to search
     * @param array $extensions Allowed extensions
     * @return array File paths
     */
    private function findFilesByExtension(string $dir, array $extensions): array
    {
        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Exception $e) {
            // Skip inaccessible directories
        }

        return $files;
    }

    /**
     * Discover content columns in database
     *
     * Replicates the column discovery from UrlReplacementController
     * using centralized config for table patterns and column types.
     *
     * @param $db Database connection
     * @return array Content columns [{table_name, column_name}, ...]
     */
    private function discoverContentColumns($db): array
    {
        $schema = (string) $db->createCommand('SELECT DATABASE()')->queryScalar();

        $tablePatterns = $this->config->getContentTablePatterns();
        $columnTypes = $this->config->getColumnTypes();

        $tableConditions = [];
        foreach ($tablePatterns as $pattern) {
            if (strpos($pattern, '%') !== false) {
                $tableConditions[] = "TABLE_NAME LIKE " . $db->quoteValue($pattern);
            } else {
                $tableConditions[] = "TABLE_NAME = " . $db->quoteValue($pattern);
            }
        }
        $tableWhere = '(' . implode(' OR ', $tableConditions) . ')';

        try {
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
        } catch (\Exception $e) {
            Craft::warning("Could not discover content columns: " . $e->getMessage(), __METHOD__);
            return [];
        }

        return $columns;
    }
}
