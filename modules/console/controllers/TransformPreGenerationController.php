<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\models\ImageTransform;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Pre-Generate Image Transforms Controller
 *
 * Pre-generates image transforms based on discovery reports to prevent broken images
 * during migration. Works with reports from TransformDiscoveryController.
 *
 * Workflow:
 *   1. Run transform-discovery/discover to scan database and templates
 *   2. Run transform-pre-generation/generate to pre-generate all transforms
 *   3. Run transform-pre-generation/verify to confirm all transforms exist
 *   4. Optional: Run transform-pre-generation/warmup to crawl pages
 *
 * Note: For discovery, use TransformDiscoveryController which provides
 * comprehensive scanning of both database content and Twig templates.
 */
class TransformPreGenerationController extends Controller
{
    public $defaultAction = 'generate';

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
    public $maxConcurrent;

    /**
     * @var bool Whether to force regeneration of existing transforms
     */
    public $force = false;

    /**
     * @var string|null Path to report file for generate/verify operations
     */
    public $reportFile = null;

    /**
     * @var bool Whether to skip confirmation prompts
     */
    public $yes = false;

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

        // Set default max concurrent from config if not already set
        if ($this->maxConcurrent === null) {
            $this->maxConcurrent = $this->config->getMaxConcurrentTransforms();
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
            $options[] = 'reportFile';
            $options[] = 'yes';
        }
        if ($actionID === 'verify') {
            $options[] = 'reportFile';
        }
        return $options;
    }

    /**
     * Generate transforms based on discovery report
     *
     * Uses the latest report from TransformDiscoveryController to pre-generate
     * all discovered image transforms.
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
                $this->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("Using latest report: " . basename($reportFile) . "\n\n", Console::FG_CYAN);
        }

        if (!file_exists($reportFile)) {
            $this->stderr("Report file not found: {$reportFile}\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $report = json_decode(file_get_contents($reportFile), true);

        // Support both old and new report formats
        $transforms = [];
        if (isset($report['transforms'])) {
            // Old format from TransformPreGenerationController::discover
            $transforms = $report['transforms'];
        } elseif (isset($report['database_transforms']) || isset($report['template_transforms'])) {
            // New format from TransformDiscoveryController
            $transforms = [
                'background_images' => array_merge(
                    $report['database_transforms'] ?? [],
                    $report['template_transforms'] ?? []
                ),
                'inline_transforms' => [],
                'imageoptimize' => [],
            ];
        } else {
            $transforms = $report['transforms'] ?? [];
        }

        if (empty($transforms['background_images']) && empty($transforms['inline_transforms'])) {
            $this->stdout("No transforms to generate.\n\n", Console::FG_YELLOW);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
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
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        if (!$this->yes && !$this->confirm("Proceed with transform generation?", true)) {
            $this->stdout("__CLI_EXIT_CODE_0__\n");
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

        // Return error if all transforms failed or if error rate is too high
        if ($stats['total'] > 0 && $stats['generated'] == 0 && $stats['errors'] > 0) {
            $this->stderr("\n✗ All transforms failed. Please check the logs for details.\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Warn if error rate is high (>50%)
        if ($stats['total'] > 0 && ($stats['errors'] / $stats['total']) > 0.5) {
            $this->stderr("\n⚠ High error rate (" . round(($stats['errors'] / $stats['total']) * 100) . "%). Some transforms may not have been generated.\n\n", Console::FG_YELLOW);
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
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
                $this->stderr("__CLI_EXIT_CODE_1__\n");
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

        if ($stats['missing'] > 0) {
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        } else {
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }
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
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        if (!$this->confirm("Proceed with warmup crawl?", true)) {
            $this->stdout("__CLI_EXIT_CODE_0__\n");
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

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function generateBackgroundImageTransforms($transforms, $batchSize): array
    {
        $stats = [
            'total' => count($transforms),
            'generated' => 0,
            'already_exists' => 0,
            'errors' => 0,
            'error_reasons' => [],
        ];

        foreach ($transforms as $i => $transform) {
            // Support both 'asset_id' (old format) and 'element_id' (new format)
            $assetId = $transform['asset_id'] ?? $transform['element_id'] ?? null;

            if (!$assetId) {
                // Can't generate without asset ID
                $this->stdout("?", Console::FG_GREY);
                $stats['errors']++;
                $stats['error_reasons']['missing_asset_id'] = ($stats['error_reasons']['missing_asset_id'] ?? 0) + 1;
                continue;
            }

            $asset = Asset::find()->id($assetId)->one();
            if (!$asset) {
                $this->stdout("?", Console::FG_GREY);
                $stats['errors']++;
                $stats['error_reasons']['asset_not_found'] = ($stats['error_reasons']['asset_not_found'] ?? 0) + 1;
                continue;
            }

            // Check if asset is an image
            if (!in_array(strtolower($asset->getExtension()), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
                $this->stdout("s", Console::FG_GREY);  // s = skipped (not an image)
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
                    'width' => $transform['width'] ?? null,
                    'height' => $transform['height'] ?? null,
                    'mode' => $transform['mode'] ?? 'crop',
                    'position' => $transform['position'] ?? 'center-center',
                ]);

                // Check if we have valid dimensions
                if (!$imageTransform->width && !$imageTransform->height) {
                    $stats['errors']++;
                    $stats['error_reasons']['missing_dimensions'] = ($stats['error_reasons']['missing_dimensions'] ?? 0) + 1;
                    $this->stdout("d", Console::FG_RED);  // d = no dimensions
                    continue;
                }

                $url = $asset->getUrl($imageTransform);

                if ($url) {
                    $stats['generated']++;
                    $this->stdout(".", Console::FG_GREEN);
                } else {
                    $stats['errors']++;
                    $stats['error_reasons']['url_generation_failed'] = ($stats['error_reasons']['url_generation_failed'] ?? 0) + 1;
                    $this->stdout("x", Console::FG_RED);
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $errorType = get_class($e);
                $stats['error_reasons'][$errorType] = ($stats['error_reasons'][$errorType] ?? 0) + 1;
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
        if (!$asset) {
            // Support both 'asset_id' (old format) and 'element_id' (new format)
            $assetId = $transform['asset_id'] ?? $transform['element_id'] ?? null;
            if ($assetId) {
                $asset = Asset::find()->id($assetId)->one();
            }
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

        // Display error breakdown if there were errors
        if ($stats['errors'] > 0 && !empty($stats['error_reasons'])) {
            $this->stdout("Error breakdown:\n", Console::FG_YELLOW);
            arsort($stats['error_reasons']);
            foreach ($stats['error_reasons'] as $reason => $count) {
                $this->stdout("  - {$reason}: {$count}\n", Console::FG_RED);
            }
            $this->stdout("\n");
        }
    }

    private function printTransformSample($transforms, $limit): void
    {
        $this->stdout("\nSample transforms (first {$limit}):\n", Console::FG_YELLOW);

        $sample = array_slice($transforms['background_images'], 0, $limit);
        foreach ($sample as $transform) {
            // Support both 'asset_id' (old format) and 'element_id' (new format)
            $assetId = $transform['asset_id'] ?? $transform['element_id'] ?? 'unknown';
            $this->stdout("  - Asset {$assetId}: {$transform['width']}x{$transform['height']} ({$transform['mode']})\n");
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
        $this->stdout("s=skipped ", Console::FG_GREY);
        $this->stdout("d=no dimensions ", Console::FG_RED);
        $this->stdout("!=error\n", Console::FG_YELLOW);
    }
}
