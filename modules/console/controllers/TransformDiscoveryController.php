<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Transform Discovery Controller (ENHANCED)
 * 
 * Scans BOTH database content AND Twig templates to find all transform usage
 * 
 * This is critical for zero-downtime migration because:
 * - Transforms in DB are regenerated on page load
 * - Transforms in Twig (background-image, srcset) are NOT - they break immediately
 * 
 * Usage:
 *   1. ./craft s3-spaces-migration/transform-discovery/discover - Full scan (DB + Twig)
 *   2. ./craft s3-spaces-migration/transform-discovery/scan-templates - Templates only
 *   3. ./craft s3-spaces-migration/transform-discovery/scan-database - Database only
 */
class TransformDiscoveryController extends Controller
{
    public $defaultAction = 'discover';

    /**
     * Discover ALL transforms (database + templates)
     */
    public function actionDiscover(): int
    {
        $this->printHeader("COMPREHENSIVE TRANSFORM DISCOVERY");
        
        $this->stdout("This will scan:\n", Console::FG_YELLOW);
        $this->stdout("  1. Database content fields\n");
        $this->stdout("  2. Twig template files\n\n");

        $results = [
            'database' => [],
            'templates' => [],
            'combined' => [],
        ];

        // Scan database
        $this->stdout("═══════════════════════════════════════════════════════════════════════\n", Console::FG_CYAN);
        $this->stdout("PHASE 1: DATABASE SCAN\n", Console::FG_CYAN);
        $this->stdout("═══════════════════════════════════════════════════════════════════════\n\n", Console::FG_CYAN);
        
        $results['database'] = $this->scanDatabase();

        // Scan templates
        $this->stdout("\n═══════════════════════════════════════════════════════════════════════\n", Console::FG_CYAN);
        $this->stdout("PHASE 2: TEMPLATE SCAN\n", Console::FG_CYAN);
        $this->stdout("═══════════════════════════════════════════════════════════════════════\n\n", Console::FG_CYAN);
        
        $results['templates'] = $this->scanTemplates();

        // Combine and analyze
        $this->stdout("\n═══════════════════════════════════════════════════════════════════════\n", Console::FG_CYAN);
        $this->stdout("PHASE 3: ANALYSIS\n", Console::FG_CYAN);
        $this->stdout("═══════════════════════════════════════════════════════════════════════\n\n", Console::FG_CYAN);
        
        $results['combined'] = $this->analyzeResults($results['database'], $results['templates']);

        // Display report
        $this->displayReport($results);

        // Save report
        $reportPath = $this->saveReport($results);
        
        $this->stdout("\n✓ Discovery complete!\n", Console::FG_GREEN);
        $this->stdout("✓ Report saved to: {$reportPath}\n\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Scan only Twig templates
     */
    public function actionScanTemplates(): int
    {
        $this->printHeader("TEMPLATE TRANSFORM SCAN");
        
        $results = $this->scanTemplates();
        
        $this->displayTemplateResults($results);
        
        return ExitCode::OK;
    }

    /**
     * Scan only database
     */
    public function actionScanDatabase(): int
    {
        $this->printHeader("DATABASE TRANSFORM SCAN");
        
        $results = $this->scanDatabase();
        
        $this->displayDatabaseResults($results);
        
        return ExitCode::OK;
    }

    /**
     * Scan database for transforms
     */
    private function scanDatabase(): array
    {
        $db = Craft::$app->getDb();
        $transforms = [];

        // Find content tables
        $contentTables = $this->findContentTables($db);
        $this->stdout("Found " . count($contentTables) . " content tables\n\n");

        foreach ($contentTables as $table) {
            $this->stdout("  Scanning {$table}... ");

            try {
                $columns = $db->getTableSchema($table)->columnNames;
                $contentColumns = array_filter($columns, function($col) {
                    return !in_array($col, ['id', 'elementId', 'siteId', 'dateCreated', 'dateUpdated', 'uid']);
                });

                foreach ($contentColumns as $column) {
                    // Search for transform patterns
                    $results = $db->createCommand("
                        SELECT `{$column}`, `elementId`
                        FROM `{$table}`
                        WHERE `{$column}` LIKE '%_transform%'
                           OR `{$column}` LIKE '%background-image%'
                           OR `{$column}` LIKE '%/_[0-9]%'
                           OR `{$column}` LIKE '%srcset%'
                        LIMIT 1000
                    ")->queryAll();

                    foreach ($results as $row) {
                        $content = $row[$column];
                        $elementId = $row['elementId'];

                        // Pattern 1: Transform URLs like /_800x600_crop/image.jpg
                        if (preg_match_all('/_(\d+)x(\d+)_([a-z]+)/', $content, $matches)) {
                            for ($i = 0; $i < count($matches[0]); $i++) {
                                $transforms[] = [
                                    'source' => 'database',
                                    'table' => $table,
                                    'column' => $column,
                                    'element_id' => $elementId,
                                    'width' => (int)$matches[1][$i],
                                    'height' => (int)$matches[2][$i],
                                    'mode' => $matches[3][$i],
                                    'type' => 'url_transform',
                                ];
                            }
                        }

                        // Pattern 2: Just dimensions /_800x600/
                        if (preg_match_all('/_(\d+)x(\d+)/', $content, $matches)) {
                            for ($i = 0; $i < count($matches[0]); $i++) {
                                $transforms[] = [
                                    'source' => 'database',
                                    'table' => $table,
                                    'column' => $column,
                                    'element_id' => $elementId,
                                    'width' => (int)$matches[1][$i],
                                    'height' => (int)$matches[2][$i],
                                    'mode' => 'crop',
                                    'type' => 'url_transform',
                                ];
                            }
                        }

                        // Pattern 3: ImageOptimize handles
                        if (preg_match_all('/optimizedImages(?:Field)?\.([a-zA-Z0-9_]+)/', $content, $matches)) {
                            for ($i = 0; $i < count($matches[0]); $i++) {
                                $transforms[] = [
                                    'source' => 'database',
                                    'table' => $table,
                                    'column' => $column,
                                    'element_id' => $elementId,
                                    'handle' => $matches[1][$i],
                                    'type' => 'imageoptimize',
                                ];
                            }
                        }
                    }
                }

                $this->stdout("✓\n", Console::FG_GREEN);
            } catch (\Exception $e) {
                $this->stdout("⊘ " . $e->getMessage() . "\n", Console::FG_YELLOW);
            }
        }

        return $transforms;
    }

    /**
     * Scan Twig templates for transforms
     */
    private function scanTemplates(): array
    {
        $templatesPath = Craft::getAlias('@templates');
        
        if (!is_dir($templatesPath)) {
            $this->stdout("Templates directory not found: {$templatesPath}\n", Console::FG_YELLOW);
            return [];
        }

        $files = $this->findTwigFiles($templatesPath);
        $this->stdout("Found " . count($files) . " Twig files\n\n");

        $transforms = [];

        foreach ($files as $file) {
            $relativePath = str_replace($templatesPath . '/', '', $file);
            $content = file_get_contents($file);

            // Pattern 1: .getUrl({ width: 800, height: 600 })
            if (preg_match_all('/\.getUrl\(\s*\{[^}]*width\s*:\s*(\d+)[^}]*height\s*:\s*(\d+)[^}]*\}\s*\)/', $content, $matches)) {
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $transforms[] = [
                        'source' => 'template',
                        'file' => $relativePath,
                        'width' => (int)$matches[1][$i],
                        'height' => (int)$matches[2][$i],
                        'mode' => 'crop',
                        'type' => 'getUrl',
                    ];
                }
            }

            // Pattern 2: background-image with transform URL
            if (preg_match_all('/background-image:\s*url\([^)]*_(\d+)x(\d+)/', $content, $matches)) {
                for ($i = 0; $i < count($matches[0]); $i++) {
                    $transforms[] = [
                        'source' => 'template',
                        'file' => $relativePath,
                        'width' => (int)$matches[1][$i],
                        'height' => (int)$matches[2][$i],
                        'mode' => 'crop',
                        'type' => 'background_image',
                    ];
                }
            }

            // Pattern 3: optimizedImageUrls[1700]
            if (preg_match_all('/optimizedImageUrls\[(\d+)\]/', $content, $matches)) {
                foreach ($matches[1] as $width) {
                    $transforms[] = [
                        'source' => 'template',
                        'file' => $relativePath,
                        'width' => (int)$width,
                        'height' => null,
                        'mode' => 'fit',
                        'type' => 'imageoptimize_width',
                    ];
                }
            }

            // Pattern 4: .srcset()
            if (preg_match('/\.srcset\(\)/', $content)) {
                // ImageOptimize srcset generates standard sizes
                $standardSizes = [400, 800, 1200, 1600, 2000];
                foreach ($standardSizes as $size) {
                    $transforms[] = [
                        'source' => 'template',
                        'file' => $relativePath,
                        'width' => $size,
                        'height' => null,
                        'mode' => 'fit',
                        'type' => 'srcset',
                    ];
                }
            }

            // Pattern 5: .placeholderSilhouette()
            if (preg_match('/\.placeholderSilhouette\(\)/', $content)) {
                $transforms[] = [
                    'source' => 'template',
                    'file' => $relativePath,
                    'width' => 20,
                    'height' => null,
                    'mode' => 'fit',
                    'type' => 'placeholder',
                ];
            }

            // Pattern 6: Custom transform handle
            if (preg_match_all('/\|\s*transform\([\'"]([a-zA-Z0-9_]+)[\'"]\)/', $content, $matches)) {
                foreach ($matches[1] as $handle) {
                    $transforms[] = [
                        'source' => 'template',
                        'file' => $relativePath,
                        'handle' => $handle,
                        'type' => 'named_transform',
                    ];
                }
            }
        }

        return $transforms;
    }

    /**
     * Analyze and combine results
     */
    private function analyzeResults(array $dbTransforms, array $templateTransforms): array
    {
        $all = array_merge($dbTransforms, $templateTransforms);
        
        // Deduplicate by dimensions
        $unique = [];
        $sizeFrequency = [];
        
        foreach ($all as $transform) {
            if (isset($transform['width']) && isset($transform['height'])) {
                $key = $transform['width'] . 'x' . ($transform['height'] ?? 'auto');
            } elseif (isset($transform['handle'])) {
                $key = 'handle:' . $transform['handle'];
            } else {
                continue;
            }

            if (!isset($unique[$key])) {
                $unique[$key] = $transform;
                $sizeFrequency[$key] = 0;
            }
            
            $sizeFrequency[$key]++;
        }

        // Sort by frequency
        arsort($sizeFrequency);

        return [
            'total_references' => count($all),
            'unique_transforms' => count($unique),
            'from_database' => count($dbTransforms),
            'from_templates' => count($templateTransforms),
            'size_frequency' => $sizeFrequency,
            'transforms' => array_values($unique),
        ];
    }

    /**
     * Display comprehensive report
     */
    private function displayReport(array $results): void
    {
        $analysis = $results['combined'];

        $this->stdout("═══════════════════════════════════════════════════════════════════════\n", Console::FG_CYAN);
        $this->stdout("DISCOVERY RESULTS\n", Console::FG_CYAN);
        $this->stdout("═══════════════════════════════════════════════════════════════════════\n\n", Console::FG_CYAN);

        $this->stdout("Transform Statistics:\n", Console::FG_YELLOW);
        $this->stdout("  Total references: {$analysis['total_references']}\n");
        $this->stdout("  Unique transforms: {$analysis['unique_transforms']}\n");
        $this->stdout("  Unique sizes: " . count($analysis['size_frequency']) . "\n");
        $this->stdout("  Assets affected: [needs scan]\n\n");

        $this->stdout("Transform Types:\n", Console::FG_YELLOW);
        
        // Count by type
        $typeCount = [];
        foreach ($results['database'] as $t) {
            $typeCount[$t['type']] = ($typeCount[$t['type']] ?? 0) + 1;
        }
        foreach ($results['templates'] as $t) {
            $typeCount[$t['type']] = ($typeCount[$t['type']] ?? 0) + 1;
        }
        
        foreach ($typeCount as $type => $count) {
            $this->stdout("  {$type}: {$count}\n");
        }
        
        $this->stdout("\nCommon sizes (top 10):\n", Console::FG_YELLOW);
        $count = 0;
        foreach ($analysis['size_frequency'] as $size => $freq) {
            if ($count++ >= 10) break;
            $this->stdout("  - {$size}: {$freq} occurrences\n");
        }

        $this->stdout("\n");
    }

    /**
     * Display template results
     */
    private function displayTemplateResults(array $transforms): void
    {
        $this->stdout("Found " . count($transforms) . " transform references in templates\n\n", Console::FG_YELLOW);

        $byFile = [];
        foreach ($transforms as $t) {
            $file = $t['file'] ?? 'unknown';
            $byFile[$file][] = $t;
        }

        foreach ($byFile as $file => $fileTransforms) {
            $this->stdout("  {$file} (" . count($fileTransforms) . " transforms)\n", Console::FG_GREY);
            
            foreach (array_slice($fileTransforms, 0, 3) as $t) {
                $desc = isset($t['width']) 
                    ? "{$t['width']}x" . ($t['height'] ?? 'auto')
                    : ($t['handle'] ?? 'unknown');
                $this->stdout("    - {$t['type']}: {$desc}\n", Console::FG_GREY);
            }
            
            if (count($fileTransforms) > 3) {
                $this->stdout("    ... and " . (count($fileTransforms) - 3) . " more\n", Console::FG_GREY);
            }
            
            $this->stdout("\n");
        }
    }

    /**
     * Display database results
     */
    private function displayDatabaseResults(array $transforms): void
    {
        $this->stdout("Found " . count($transforms) . " transform references in database\n\n", Console::FG_YELLOW);

        $byTable = [];
        foreach ($transforms as $t) {
            $table = $t['table'] ?? 'unknown';
            $byTable[$table][] = $t;
        }

        foreach ($byTable as $table => $tableTransforms) {
            $this->stdout("  {$table} (" . count($tableTransforms) . " transforms)\n", Console::FG_GREY);
        }
    }

    /**
     * Save report to JSON
     */
    private function saveReport(array $results): string
    {
        $reportPath = Craft::getAlias('@storage') . '/transform-discovery-' . date('Y-m-d-His') . '.json';
        
        $report = [
            'discovered_at' => date('Y-m-d H:i:s'),
            'database_transforms' => $results['database'],
            'template_transforms' => $results['templates'],
            'analysis' => $results['combined'],
        ];

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        
        return $reportPath;
    }

    /**
     * Find content tables
     */
    private function findContentTables($db): array
    {
        $tables = $db->getSchema()->getTableNames();
        return array_filter($tables, function($table) {
            return strpos($table, 'content') !== false || 
                   strpos($table, 'matrixcontent') !== false;
        });
    }

    /**
     * Find all Twig files recursively
     */
    private function findTwigFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Print header
     */
    private function printHeader(string $title): void
    {
        $this->stdout("\n" . str_repeat("═", 80) . "\n", Console::FG_CYAN);
        $this->stdout("{$title}\n", Console::FG_CYAN);
        $this->stdout(str_repeat("═", 80) . "\n\n", Console::FG_CYAN);
    }
}
