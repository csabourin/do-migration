<?php
namespace csabourin\craftS3SpacesMigration\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\models\ImageTransform;
use csabourin\craftS3SpacesMigration\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Pre-Generate Image Transforms Controller
 * 
 * Discovers and pre-generates image transforms to prevent broken images
 * during migration. Handles background-image URLs and other transform references.
 * 
 * Usage:
 *   1. Run discover to find all transforms being used
 *   2. Run generate to pre-generate those transforms
 *   3. Verify transforms exist before going live
 */
class TransformPreGenerationController extends Controller
{
    public $defaultAction = 'discover';

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * @var bool Whether to run in dry-run mode
     */
    public $dryRun = false;

    /**
     * @var int Batch size for processing (can be overridden via command line)
     */
    public $batchSize;

    /**
     * @var int Max concurrent transform generations
     */
    public $maxConcurrent = 5;

    /**
     * @var bool Whether to force regeneration of existing transforms
     */
    public $force = false;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();

        // Set default batch size from config if not already set
        if ($this->batchSize === null) {
            $this->batchSize = $this->config->getBatchSize();
        }
    }

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'generate') {
            $options[] = 'dryRun';
            $options[] = 'batchSize';
            $options[] = 'maxConcurrent';
            $options[] = 'force';
        }
        return $options;
    }

    /**
     * Discover all image transforms being used in the database
     * 
     * Scans for:
     * - Background-image URLs in content fields
     * - ImageOptimize transform references
     * - Inline image src attributes with transform parameters
     */
    public function actionDiscover(): int
    {
        $this->printHeader("TRANSFORM DISCOVERY");

        $db = Craft::$app->getDb();
        
        $this->stdout("\n1. Discovering content fields with images...\n", Console::FG_CYAN);
        
        // Find all content tables
        $contentTables = $this->findContentTables($db);
        $this->stdout("   Found " . count($contentTables) . " content tables\n\n");

        $transforms = [
            'background_images' => [],
            'inline_transforms' => [],
            'imageoptimize' => [],
        ];

        $this->stdout("2. Scanning for transform references...\n", Console::FG_CYAN);
        
        foreach ($contentTables as $table) {
            $this->stdout("   Scanning {$table}... ");
            
            try {
                $columns = $db->getTableSchema($table)->columnNames;
                
                // Look for likely content columns
                $contentColumns = array_filter($columns, function($col) {
                    return !in_array($col, ['id', 'elementId', 'siteId', 'dateCreated', 'dateUpdated', 'uid']);
                });

                $discovered = $this->scanTableForTransforms($db, $table, $contentColumns);
                
                $transforms['background_images'] = array_merge(
                    $transforms['background_images'], 
                    $discovered['background_images']
                );
                $transforms['inline_transforms'] = array_merge(
                    $transforms['inline_transforms'], 
                    $discovered['inline_transforms']
                );
                $transforms['imageoptimize'] = array_merge(
                    $transforms['imageoptimize'], 
                    $discovered['imageoptimize']
                );
                
                $this->stdout("done\n", Console::FG_GREEN);
            } catch (\Exception $e) {
                $this->stdout("skipped (error)\n", Console::FG_YELLOW);
            }
        }

        $this->stdout("\n3. Analyzing discovered transforms...\n", Console::FG_CYAN);
        
        $analysis = $this->analyzeDiscoveredTransforms($transforms);
        
        $this->printDiscoveryReport($analysis, $transforms);
        
        // Save to file
        $reportFile = Craft::getAlias('@storage') . '/transform-discovery-' . date('Y-m-d-His') . '.json';
        file_put_contents($reportFile, json_encode([
            'discovered_at' => date('Y-m-d H:i:s'),
            'analysis' => $analysis,
            'transforms' => $transforms
        ], JSON_PRETTY_PRINT));
        
        $this->stdout("\n✓ Discovery report saved to:\n", Console::FG_GREEN);
        $this->stdout("  {$reportFile}\n\n", Console::FG_CYAN);
        
        $this->stdout("Next steps:\n", Console::FG_YELLOW);
        $this->stdout("  1. Review the report\n");
        $this->stdout("  2. Run generation: ./craft transform-pregen/generate\n");
        $this->stdout("  3. Verify transforms exist before going live\n\n");

        return ExitCode::OK;
    }

    /**
     * Generate transforms based on discovery report
     * 
     * @param string|null $reportFile Path to discovery report JSON file
     */
    public function actionGenerate($reportFile = null): int
    {
        $this->printHeader("TRANSFORM PRE-GENERATION");

        // Load discovery report
        if (!$reportFile) {
            $reportFile = $this->findLatestDiscoveryReport();
            if (!$reportFile) {
                $this->stderr("No discovery report found. Run 'discover' first.\n\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("Using latest report: " . basename($reportFile) . "\n\n", Console::FG_CYAN);
        }

        if (!file_exists($reportFile)) {
            $this->stderr("Report file not found: {$reportFile}\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $report = json_decode(file_get_contents($reportFile), true);
        $transforms = $report['transforms'] ?? [];

        if (empty($transforms['background_images']) && empty($transforms['inline_transforms'])) {
            $this->stdout("No transforms to generate.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Transform generation plan:\n", Console::FG_YELLOW);
        $this->stdout("  Background images: " . count($transforms['background_images']) . "\n");
        $this->stdout("  Inline transforms: " . count($transforms['inline_transforms']) . "\n");
        $this->stdout("  Batch size: {$this->batchSize}\n");
        $this->stdout("  Max concurrent: {$this->maxConcurrent}\n\n");

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No transforms will be generated\n\n", Console::FG_YELLOW);
            $this->printTransformSample($transforms, 10);
            return ExitCode::OK;
        }

        if (!$this->confirm("Proceed with transform generation?", true)) {
            return ExitCode::OK;
        }

        // Generate transforms
        $stats = [
            'total' => 0,
            'generated' => 0,
            'already_exists' => 0,
            'errors' => 0,
            'start_time' => time(),
        ];

        $this->stdout("\nGenerating transforms...\n\n", Console::FG_CYAN);
        $this->printProgressLegend();

        // Process background images
        if (!empty($transforms['background_images'])) {
            $this->stdout("\n1. Background image transforms:\n", Console::FG_YELLOW);
            $this->stdout("   Progress: ");
            
            $result = $this->generateBackgroundImageTransforms(
                $transforms['background_images'],
                $this->batchSize
            );
            
            $stats['total'] += $result['total'];
            $stats['generated'] += $result['generated'];
            $stats['already_exists'] += $result['already_exists'];
            $stats['errors'] += $result['errors'];
            
            $this->stdout("\n   ✓ Generated: {$result['generated']}, Exists: {$result['already_exists']}, Errors: {$result['errors']}\n\n");
        }

        // Process inline transforms
        if (!empty($transforms['inline_transforms'])) {
            $this->stdout("2. Inline transforms:\n", Console::FG_YELLOW);
            $this->stdout("   Progress: ");
            
            $result = $this->generateInlineTransforms(
                $transforms['inline_transforms'],
                $this->batchSize
            );
            
            $stats['total'] += $result['total'];
            $stats['generated'] += $result['generated'];
            $stats['already_exists'] += $result['already_exists'];
            $stats['errors'] += $result['errors'];
            
            $this->stdout("\n   ✓ Generated: {$result['generated']}, Exists: {$result['already_exists']}, Errors: {$result['errors']}\n\n");
        }

        $duration = time() - $stats['start_time'];
        
        $this->printGenerationReport($stats, $duration);

        return ExitCode::OK;
    }

    /**
     * Verify that transforms exist for all discovered references
     */
    public function actionVerify($reportFile = null): int
    {
        $this->printHeader("TRANSFORM VERIFICATION");

        // Load discovery report
        if (!$reportFile) {
            $reportFile = $this->findLatestDiscoveryReport();
            if (!$reportFile) {
                $this->stderr("No discovery report found. Run 'discover' first.\n\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $report = json_decode(file_get_contents($reportFile), true);
        $transforms = $report['transforms'] ?? [];

        $this->stdout("Verifying transforms exist...\n\n", Console::FG_CYAN);

        $stats = [
            'total' => 0,
            'exists' => 0,
            'missing' => 0,
        ];

        $missing = [];

        // Verify background images
        foreach ($transforms['background_images'] as $transform) {
            $stats['total']++;
            
            if ($this->transformExists($transform)) {
                $stats['exists']++;
                $this->stdout(".", Console::FG_GREEN);
            } else {
                $stats['missing']++;
                $missing[] = $transform;
                $this->stdout("x", Console::FG_RED);
            }
        }

        $this->stdout("\n\n");
        
        $this->stdout("Results:\n", Console::FG_YELLOW);
        $this->stdout("  Total transforms: {$stats['total']}\n");
        $this->stdout("  Exist: {$stats['exists']}\n", Console::FG_GREEN);
        $this->stdout("  Missing: {$stats['missing']}\n", $stats['missing'] > 0 ? Console::FG_RED : Console::FG_GREEN);
        
        if (!empty($missing)) {
            $this->stdout("\nMissing transforms:\n", Console::FG_RED);
            foreach (array_slice($missing, 0, 20) as $transform) {
                $this->stdout("  - Asset {$transform['asset_id']}: {$transform['width']}x{$transform['height']}\n");
            }
            if (count($missing) > 20) {
                $this->stdout("  ... and " . (count($missing) - 20) . " more\n");
            }
        }

        $this->stdout("\n");

        return $stats['missing'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Warm up transforms by visiting pages (simulates real traffic)
     */
    public function actionWarmup(): int
    {
        $this->printHeader("TRANSFORM WARMUP");

        $this->stdout("This will crawl your site to trigger transform generation.\n\n", Console::FG_YELLOW);
        
        // Get all entries with images
        $entries = \craft\elements\Entry::find()
            ->section('*')
            ->limit(null)
            ->all();

        $urls = [];
        foreach ($entries as $entry) {
            if ($entry->url) {
                $urls[] = $entry->url;
            }
        }

        $this->stdout("Found " . count($urls) . " URLs to crawl\n\n");

        if ($this->dryRun) {
            $this->stdout("DRY RUN - Would crawl:\n", Console::FG_YELLOW);
            foreach (array_slice($urls, 0, 10) as $url) {
                $this->stdout("  - {$url}\n");
            }
            if (count($urls) > 10) {
                $this->stdout("  ... and " . (count($urls) - 10) . " more\n");
            }
            return ExitCode::OK;
        }

        if (!$this->confirm("Proceed with warmup crawl?", true)) {
            return ExitCode::OK;
        }

        $this->stdout("Crawling...\n", Console::FG_CYAN);
        $this->stdout("Progress: ");

        $success = 0;
        $errors = 0;

        foreach ($urls as $i => $url) {
            try {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $success++;
                    $this->stdout(".", Console::FG_GREEN);
                } else {
                    $errors++;
                    $this->stdout("x", Console::FG_RED);
                }
            } catch (\Exception $e) {
                $errors++;
                $this->stdout("!", Console::FG_YELLOW);
            }

            if (($i + 1) % 50 === 0) {
                $this->stdout(" [" . ($i + 1) . "/" . count($urls) . "]\n  ");
            }
        }

        $this->stdout("\n\n");
        $this->stdout("Results:\n", Console::FG_YELLOW);
        $this->stdout("  Success: {$success}\n", Console::FG_GREEN);
        $this->stdout("  Errors: {$errors}\n", $errors > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout("\n");

        return ExitCode::OK;
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function findContentTables($db): array
    {
        $tables = $db->getSchema()->getTableNames();
        
        return array_filter($tables, function($table) {
            return strpos($table, 'content') !== false || 
                   strpos($table, 'matrixcontent') !== false;
        });
    }

    private function scanTableForTransforms($db, $table, $columns): array
    {
        $discovered = [
            'background_images' => [],
            'inline_transforms' => [],
            'imageoptimize' => [],
        ];

        foreach ($columns as $column) {
            try {
                // Check if column contains URLs or image references
                $sample = $db->createCommand("
                    SELECT `{$column}`
                    FROM `{$table}`
                    WHERE `{$column}` IS NOT NULL 
                    AND (`{$column}` LIKE '%background-image%' 
                         OR `{$column}` LIKE '%_transform%'
                         OR `{$column}` LIKE '%.jpg%'
                         OR `{$column}` LIKE '%.png%'
                         OR `{$column}` LIKE '%.webp%')
                    LIMIT 100
                ")->queryColumn();

                if (empty($sample)) {
                    continue;
                }

                // Extract transform references
                foreach ($sample as $content) {
                    // Background images: style="background-image: url(...)"
                    if (preg_match_all('/background-image:\s*url\([\'"]?([^\'"]+)[\'"]?\)/i', $content, $matches)) {
                        foreach ($matches[1] as $url) {
                            $transform = $this->parseTransformFromUrl($url);
                            if ($transform) {
                                $discovered['background_images'][] = $transform;
                            }
                        }
                    }

                    // ImageOptimize placeholders
                    if (preg_match_all('/{asset:(\d+):transform:([^}]+)}/i', $content, $matches)) {
                        for ($i = 0; $i < count($matches[0]); $i++) {
                            $discovered['imageoptimize'][] = [
                                'asset_id' => $matches[1][$i],
                                'handle' => $matches[2][$i],
                            ];
                        }
                    }

                    // Inline img with transform
                    if (preg_match_all('/<img[^>]+src=[\'"]([^\'"]+_\d+x\d+[^\'"]*)[\'"][^>]*>/i', $content, $matches)) {
                        foreach ($matches[1] as $url) {
                            $transform = $this->parseTransformFromUrl($url);
                            if ($transform) {
                                $discovered['inline_transforms'][] = $transform;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Skip column
            }
        }

        return $discovered;
    }

    private function parseTransformFromUrl($url): ?array
    {
        // Extract transform parameters from URL
        // Examples:
        // - image_800x600.jpg
        // - image_800x600_crop_center.jpg
        // - /_transforms/image_800x600.jpg

        if (preg_match('/_(\d+)x(\d+)/', $url, $matches)) {
            $width = (int)$matches[1];
            $height = (int)$matches[2];

            // Extract asset ID or filename
            $assetId = null;
            if (preg_match('/assets\/(\d+)\//', $url, $idMatch)) {
                $assetId = (int)$idMatch[1];
            }

            $mode = 'crop'; // default
            if (strpos($url, '_fit') !== false) {
                $mode = 'fit';
            } elseif (strpos($url, '_stretch') !== false) {
                $mode = 'stretch';
            }

            $position = 'center-center'; // default
            if (preg_match('/_crop_([^_\.]+)/', $url, $posMatch)) {
                $position = $posMatch[1];
            }

            return [
                'url' => $url,
                'asset_id' => $assetId,
                'width' => $width,
                'height' => $height,
                'mode' => $mode,
                'position' => $position,
            ];
        }

        return null;
    }

    private function analyzeDiscoveredTransforms($transforms): array
    {
        $analysis = [
            'total_transforms' => 0,
            'unique_transforms' => 0,
            'unique_sizes' => [],
            'assets_affected' => [],
        ];

        $allTransforms = array_merge(
            $transforms['background_images'],
            $transforms['inline_transforms']
        );

        $analysis['total_transforms'] = count($allTransforms);

        // Deduplicate
        $unique = [];
        $uniqueSizes = [];
        $assetIds = [];

        foreach ($allTransforms as $transform) {
            $key = "{$transform['width']}x{$transform['height']}_{$transform['mode']}";
            $unique[$key] = $transform;
            $uniqueSizes["{$transform['width']}x{$transform['height']}"] = true;
            
            if ($transform['asset_id']) {
                $assetIds[$transform['asset_id']] = true;
            }
        }

        $analysis['unique_transforms'] = count($unique);
        $analysis['unique_sizes'] = array_keys($uniqueSizes);
        $analysis['assets_affected'] = array_keys($assetIds);

        return $analysis;
    }

    private function generateBackgroundImageTransforms($transforms, $batchSize): array
    {
        $stats = [
            'total' => count($transforms),
            'generated' => 0,
            'already_exists' => 0,
            'errors' => 0,
        ];

        foreach ($transforms as $i => $transform) {
            if (!$transform['asset_id']) {
                // Can't generate without asset ID
                $this->stdout("?", Console::FG_GREY);
                $stats['errors']++;
                continue;
            }

            $asset = Asset::find()->id($transform['asset_id'])->one();
            if (!$asset) {
                $this->stdout("?", Console::FG_GREY);
                $stats['errors']++;
                continue;
            }

            try {
                // Check if transform already exists
                if (!$this->force && $this->transformExists($transform, $asset)) {
                    $stats['already_exists']++;
                    $this->stdout("-", Console::FG_GREY);
                    continue;
                }

                // Generate transform
                $imageTransform = new ImageTransform([
                    'width' => $transform['width'],
                    'height' => $transform['height'],
                    'mode' => $transform['mode'],
                    'position' => $transform['position'],
                ]);

                $url = $asset->getUrl($imageTransform);
                
                if ($url) {
                    $stats['generated']++;
                    $this->stdout(".", Console::FG_GREEN);
                } else {
                    $stats['errors']++;
                    $this->stdout("x", Console::FG_RED);
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $this->stdout("!", Console::FG_YELLOW);
            }

            // Progress indicator
            if (($i + 1) % 50 === 0) {
                $this->stdout(" [" . ($i + 1) . "/" . $stats['total'] . "]\n   ");
            }
        }

        return $stats;
    }

    private function generateInlineTransforms($transforms, $batchSize): array
    {
        // Similar to generateBackgroundImageTransforms
        return $this->generateBackgroundImageTransforms($transforms, $batchSize);
    }

    private function transformExists($transform, $asset = null): bool
    {
        if (!$asset && $transform['asset_id']) {
            $asset = Asset::find()->id($transform['asset_id'])->one();
        }

        if (!$asset) {
            return false;
        }

        try {
            $imageTransform = new ImageTransform([
                'width' => $transform['width'],
                'height' => $transform['height'],
                'mode' => $transform['mode'] ?? 'crop',
                'position' => $transform['position'] ?? 'center-center',
            ]);

            // Check if transform file exists
            $url = $asset->getUrl($imageTransform);
            
            // For DO Spaces, check if file exists in filesystem
            $transformPath = Craft::$app->getAssetTransforms()->getTransformPath($asset, $imageTransform);
            if ($transformPath) {
                $fs = $asset->getVolume()->getTransformFs();
                return $fs && $fs->fileExists($transformPath);
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function findLatestDiscoveryReport(): ?string
    {
        $storageDir = Craft::getAlias('@storage');
        $files = glob($storageDir . '/transform-discovery-*.json');
        
        if (empty($files)) {
            return null;
        }

        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $files[0];
    }

    private function printDiscoveryReport($analysis, $transforms): void
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("DISCOVERY RESULTS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $this->stdout("Transform Statistics:\n", Console::FG_YELLOW);
        $this->stdout("  Total references: {$analysis['total_transforms']}\n");
        $this->stdout("  Unique transforms: {$analysis['unique_transforms']}\n");
        $this->stdout("  Unique sizes: " . count($analysis['unique_sizes']) . "\n");
        $this->stdout("  Assets affected: " . count($analysis['assets_affected']) . "\n\n");

        $this->stdout("Transform Types:\n", Console::FG_YELLOW);
        $this->stdout("  Background images: " . count($transforms['background_images']) . "\n");
        $this->stdout("  Inline transforms: " . count($transforms['inline_transforms']) . "\n");
        $this->stdout("  ImageOptimize: " . count($transforms['imageoptimize']) . "\n\n");

        if (!empty($analysis['unique_sizes'])) {
            $this->stdout("Common sizes (top 10):\n", Console::FG_YELLOW);
            $sizes = array_slice($analysis['unique_sizes'], 0, 10);
            foreach ($sizes as $size) {
                $this->stdout("  - {$size}\n");
            }
            $this->stdout("\n");
        }
    }

    private function printGenerationReport($stats, $duration): void
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("GENERATION COMPLETE\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $this->stdout("Results:\n", Console::FG_YELLOW);
        $this->stdout("  Total transforms: {$stats['total']}\n");
        $this->stdout("  Generated: {$stats['generated']}\n", Console::FG_GREEN);
        $this->stdout("  Already existed: {$stats['already_exists']}\n", Console::FG_GREY);
        $this->stdout("  Errors: {$stats['errors']}\n", $stats['errors'] > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout("  Duration: " . gmdate('H:i:s', $duration) . "\n\n");

        if ($stats['generated'] > 0) {
            $rate = $stats['generated'] / max($duration, 1);
            $this->stdout("  Rate: " . round($rate, 1) . " transforms/second\n\n");
        }
    }

    private function printTransformSample($transforms, $limit): void
    {
        $this->stdout("\nSample transforms (first {$limit}):\n", Console::FG_YELLOW);
        
        $sample = array_slice($transforms['background_images'], 0, $limit);
        foreach ($sample as $transform) {
            $this->stdout("  - Asset {$transform['asset_id']}: {$transform['width']}x{$transform['height']} ({$transform['mode']})\n");
        }
        $this->stdout("\n");
    }

    private function printHeader($title): void
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("{$title}\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n", Console::FG_CYAN);
    }

    private function printProgressLegend(): void
    {
        $this->stdout("\nLegend: ", Console::FG_GREY);
        $this->stdout(".=generated ", Console::FG_GREEN);
        $this->stdout("-=exists ", Console::FG_GREY);
        $this->stdout("x=failed ", Console::FG_RED);
        $this->stdout("?=not found ", Console::FG_GREY);
        $this->stdout("!=error\n", Console::FG_YELLOW);
    }
}
