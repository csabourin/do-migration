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

        $candidates = [];
        foreach ($unusedAssets as $asset) {
            $path = $asset['filePath'] ?? trim((string) ($asset['folderPath'] ?? ''), '/');
            if ($path !== '' && substr($path, -1) !== '/' && strpos($path, $asset['filename']) === false) {
                $path = trim($path, '/') . '/' . $asset['filename'];
            }

            $candidateKey = 'unused-asset-' . $asset['id'];
            $candidates[$candidateKey] = [
                'candidateKey' => $candidateKey,
                'type' => 'unused_asset',
                'volumeId' => (int) ($asset['volumeId'] ?? 0),
                'path' => trim((string) $path, '/'),
                'filename' => (string) $asset['filename'],
                'assetIds' => [(int) $asset['id']],
            ];
        }

        $evidence = $this->collectReferenceEvidence($candidates);
        $rescuedIds = [];
        foreach ($evidence as $candidateKey => $candidateEvidence) {
            if (empty($candidateEvidence['sources']) || !isset($candidates[$candidateKey])) {
                continue;
            }

            $rescuedIds = array_merge($rescuedIds, $candidates[$candidateKey]['assetIds']);
        }

        $rescuedIds = array_values(array_unique(array_map('intval', $rescuedIds)));

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
     * Collect reference evidence for quarantine candidates.
     *
     * Candidate structure:
     * [
     *   'candidateKey' => 'unique-key',
     *   'type' => 'unused_asset|orphaned_file',
     *   'path' => 'folder/file.jpg',
     *   'filename' => 'file.jpg',
     *   'assetIds' => [1, 2],
     * ]
     *
     * Returns evidence keyed by candidateKey:
     * [
     *   'candidateKey' => [
     *     'sources' => ['template' => true, 'database' => true],
     *     'matchedTerms' => ['folder/file.jpg' => true, 'file.jpg' => true],
     *     'matches' => [
     *       ['source' => 'template', 'term' => 'file.jpg'],
     *       ['source' => 'database', 'term' => 'folder/file.jpg', 'location' => 'content.field_body'],
     *     ],
     *   ],
     * ]
     *
     * @param array $candidates Candidate file records
     * @return array Reference evidence keyed by candidate key
     */
    public function collectReferenceEvidence(array $candidates): array
    {
        if (empty($candidates)) {
            return [];
        }

        $evidence = [];
        foreach ($candidates as $candidateKey => $candidate) {
            $evidence[$candidateKey] = [
                'sources' => [],
                'matchedTerms' => [],
                'matches' => [],
            ];
        }

        $termMap = $this->buildSearchTermMap($candidates);
        if (empty($termMap)) {
            return $evidence;
        }

        $this->controller->stdout("  Scanning for references to " . count($candidates) . " quarantine candidates...\n\n");

        $this->scanTemplates($termMap, $evidence);
        $this->scanDatabaseContent($termMap, $evidence);
        $this->scanStaticFiles($termMap, $evidence);

        return $evidence;
    }

    /**
     * Scan Twig templates for candidate references.
     *
     * @param array $termMap Search term map
     * @param array $evidence Reference evidence (by reference)
     */
    private function scanTemplates(array $termMap, array &$evidence): void
    {
        $this->controller->stdout("  [A] Scanning Twig templates...\n");

        $templatesPath = Craft::getAlias('@templates');
        if (!$templatesPath || !is_dir($templatesPath)) {
            $this->controller->stdout("    ⚠ Templates directory not found, skipping\n", Console::FG_YELLOW);
            return;
        }

        $twigFiles = $this->findTemplateFiles($templatesPath);
        $this->controller->stdout("    Found " . count($twigFiles) . " template files\n");

        if (empty($twigFiles)) {
            return;
        }

        $allContent = '';
        foreach ($twigFiles as $file) {
            try {
                $allContent .= file_get_contents($file) . "\n";
            } catch (\Exception $e) {
                // Skip unreadable files
            }
        }

        $matches = $this->findMatchesInText($allContent, $termMap);
        $this->applyMatches($evidence, $matches, 'template');

        $this->controller->stdout(
            "    ✓ Found " . count($matches) . " candidate matches in templates\n",
            Console::FG_GREEN
        );
    }

    /**
     * Scan database content columns for candidate references.
     *
     * Fetches all rows from each table once using simple paginated SELECTs, then
     * matches terms in PHP using strpos. This avoids issuing one SQL query per
     * term batch: LIKE '%x%' cannot use any index and forces a full table scan
     * regardless, so batched SQL queries multiply that cost needlessly. A single
     * sequential read per table is always faster.
     *
     * @param array $termMap Search term map
     * @param array $evidence Reference evidence (by reference)
     */
    private function scanDatabaseContent(array $termMap, array &$evidence): void
    {
        $this->controller->stdout("  [B] Scanning database content...\n");

        $db = Craft::$app->getDb();
        $columns = $this->discoverContentColumns($db);

        $this->controller->stdout("    Found " . count($columns) . " content columns to scan\n");

        if (empty($columns) || empty($termMap)) {
            return;
        }

        // Group columns by table so each table is read exactly once.
        $tableColumns = [];
        foreach ($columns as $col) {
            $tableColumns[$col['table_name']][] = $col['column_name'];
        }

        $matchedCandidateKeys = [];
        $tableCount = count($tableColumns);
        $tableIndex = 0;
        $startTime = microtime(true);
        $pageSize = 500;

        $this->controller->stdout(
            "    Terms: " . count($termMap) . "  Tables: {$tableCount}\n"
        );

        foreach ($tableColumns as $table => $tableCols) {
            $tableIndex++;
            $tableStart = microtime(true);
            $tableMatches = 0;
            $offset = 0;
            $pageNum = 0;
            $selectCols = implode(', ', array_map(fn($c) => "`{$c}`", $tableCols));

            while (true) {
                $pageNum++;
                $elapsed = round(microtime(true) - $startTime);
                $pct = (int) round(($tableIndex - 1) / $tableCount * 100);
                $this->controller->stdout(
                    "\r    [{$pct}%] Table {$tableIndex}/{$tableCount}: {$table}  page {$pageNum}  matches: " . count($matchedCandidateKeys) . "  elapsed: {$elapsed}s   "
                );

                try {
                    $rows = $db->createCommand(
                        "SELECT {$selectCols} FROM `{$table}` LIMIT {$pageSize} OFFSET {$offset}"
                    )->queryAll();
                } catch (\Exception $e) {
                    break;
                }

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    foreach ($tableCols as $column) {
                        $content = (string) ($row[$column] ?? '');
                        if ($content === '') {
                            continue;
                        }
                        $matches = $this->findMatchesInText($content, $termMap);
                        foreach ($matches as $candidateKey => $matchedTerms) {
                            if (!isset($matchedCandidateKeys[$candidateKey])) {
                                $tableMatches++;
                            }
                            $matchedCandidateKeys[$candidateKey] = true;
                            foreach ($matchedTerms as $term) {
                                $this->recordEvidence($evidence, $candidateKey, 'database', $term, "{$table}.{$column}");
                            }
                        }
                    }
                }

                if (count($rows) < $pageSize) {
                    break;
                }

                $offset += $pageSize;
            }

            $tableSecs = round(microtime(true) - $tableStart, 1);
            $matchNote = $tableMatches > 0 ? " (+{$tableMatches} matches)" : '';
            $this->controller->stdout(
                "\r    [table {$tableIndex}/{$tableCount}] {$table} — done in {$tableSecs}s{$matchNote}            \n"
            );
        }

        $totalSecs = round(microtime(true) - $startTime);
        $this->controller->stdout(
            "    ✓ Found " . count($matchedCandidateKeys) . " candidate matches in database content ({$totalSecs}s total)\n",
            Console::FG_GREEN
        );
    }

    /**
     * Scan static files (JS/CSS) for candidate references.
     *
     * @param array $termMap Search term map
     * @param array $evidence Reference evidence (by reference)
     */
    private function scanStaticFiles(array $termMap, array &$evidence): void
    {
        $this->controller->stdout("  [C] Scanning static asset files...\n");

        $webRoot = Craft::getAlias('@webroot');
        if (!$webRoot || !is_dir($webRoot)) {
            $this->controller->stdout("    ⚠ Web root not found, skipping\n", Console::FG_YELLOW);
            return;
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

        if (empty($staticFiles) || empty($termMap)) {
            return;
        }

        $allContent = '';
        foreach ($staticFiles as $file) {
            try {
                $allContent .= file_get_contents($file) . "\n";
            } catch (\Exception $e) {
                // Skip unreadable files
            }
        }

        $matches = $this->findMatchesInText($allContent, $termMap);
        $this->applyMatches($evidence, $matches, 'static');

        $this->controller->stdout(
            "    ✓ Found " . count($matches) . " candidate matches in static files\n",
            Console::FG_GREEN
        );
    }

    /**
     * Build a search term map keyed by literal term.
     *
     * @param array $candidates Candidate file records
     * @return array Term map: term => [candidateKey, ...]
     */
    private function buildSearchTermMap(array $candidates): array
    {
        $termMap = [];

        foreach ($candidates as $candidateKey => $candidate) {
            foreach ($this->buildSearchTerms($candidate) as $term) {
                if (!isset($termMap[$term])) {
                    $termMap[$term] = [];
                }

                $termMap[$term][] = $candidateKey;
            }
        }

        return $termMap;
    }

    /**
     * Build literal search terms for a candidate file.
     *
     * @param array $candidate Candidate file record
     * @return array Search terms
     */
    private function buildSearchTerms(array $candidate): array
    {
        $terms = [];
        $filename = trim((string) ($candidate['filename'] ?? ''));
        $path = trim((string) ($candidate['path'] ?? ''), '/');

        if ($filename !== '') {
            $terms[$filename] = true;
        }

        if ($path !== '') {
            $terms[$path] = true;
            $terms['/' . $path] = true;

            $decodedPath = rawurldecode($path);
            if ($decodedPath !== $path) {
                $terms[$decodedPath] = true;
                $terms['/' . $decodedPath] = true;
            }
        }

        return array_values(array_filter(array_keys($terms), static function(string $term): bool {
            return strlen($term) >= 3;
        }));
    }

    /**
     * Find candidate matches in a text blob.
     *
     * @param string $content Text content to search
     * @param array $termMap Term map
     * @return array Matches keyed by candidate key
     */
    private function findMatchesInText(string $content, array $termMap): array
    {
        $matches = [];

        foreach ($termMap as $term => $candidateKeys) {
            if ($term === '' || strpos($content, $term) === false) {
                continue;
            }

            foreach ($candidateKeys as $candidateKey) {
                if (!isset($matches[$candidateKey])) {
                    $matches[$candidateKey] = [];
                }

                $matches[$candidateKey][$term] = $term;
            }
        }

        return $matches;
    }

    /**
     * Apply matches to evidence with a source label.
     *
     * @param array $evidence Evidence map (by reference)
     * @param array $matches Matches keyed by candidate key
     * @param string $source Source label
     */
    private function applyMatches(array &$evidence, array $matches, string $source): void
    {
        foreach ($matches as $candidateKey => $matchedTerms) {
            foreach ($matchedTerms as $term) {
                $this->recordEvidence($evidence, $candidateKey, $source, $term);
            }
        }
    }

    /**
     * Record a single evidence hit.
     *
     * @param array $evidence Evidence map (by reference)
     * @param string $candidateKey Candidate key
     * @param string $source Source label
     * @param string $term Matched term
     * @param string|null $location Optional location details
     */
    private function recordEvidence(array &$evidence, string $candidateKey, string $source, string $term, ?string $location = null): void
    {
        if (!isset($evidence[$candidateKey])) {
            $evidence[$candidateKey] = [
                'sources' => [],
                'matchedTerms' => [],
                'matches' => [],
            ];
        }

        $evidence[$candidateKey]['sources'][$source] = true;
        $evidence[$candidateKey]['matchedTerms'][$term] = true;
        $evidence[$candidateKey]['matches'][] = array_filter([
            'source' => $source,
            'term' => $term,
            'location' => $location,
        ], static fn($value) => $value !== null && $value !== '');
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
