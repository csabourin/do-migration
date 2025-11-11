<?php
namespace csabourin\craftS3SpacesMigration\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use csabourin\craftS3SpacesMigration\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Static Asset Scan Controller
 *
 * Thoroughly scans static asset files for hardcoded AWS S3 and storage URLs
 *
 * Features:
 * - Scans source files: SCSS, SASS, LESS, JS, TS, JSX, TSX, Vue, Svelte
 * - Scans compiled files: CSS, JS
 * - Searches multiple directories: resources/, src/, assets/, dist/, build/, etc.
 * - Reports exact line numbers for each occurrence
 * - Generates detailed JSON reports
 *
 * Usage:
 *   ./craft static-asset/scan
 */
class StaticAssetScanController extends Controller
{
    public $defaultAction = 'scan';

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();
    }

    /**
     * Scan JS and CSS files for S3 URLs
     */
    public function actionScan(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("STATIC ASSET SCANNER\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $webPath = Craft::getAlias('@webroot');
        $basePath = dirname($webPath); // Get parent to access resources, src, etc.

        // Directories to scan - comprehensive list including source files
        $scanDirs = [
            // Compiled assets
            'web/assets/js',
            'web/assets/css',
            'web/dist',
            'web/build',
            'assets/js',
            'assets/css',
            
            // Source files
            'resources',
            'resources/js',
            'resources/css',
            'resources/scss',
            'resources/sass',
            'resources/styles',
            'resources/assets',
            'src',
            'src/js',
            'src/css',
            'src/scss',
            'src/sass',
            'src/styles',
            'scss',
            'sass',
            'styles',
            
            // Framework-specific
            'themes',
            'templates/assets',
        ];

        // Patterns to match - loaded from centralized config
        $awsBucket = preg_quote($this->config->getAwsBucket(), '/');
        $patterns = [
            's3\.amazonaws\.com',
            $awsBucket,
            'digitaloceanspaces\.com',
            // Also catch bucket names in URLs
            "\\/{2}{$awsBucket}\\.",
            "https?:\\/\\/[^\\/]*{$awsBucket}",
            // Catch hardcoded asset paths that might reference old storage
            '\\/\\/[^\\/]*s3[^\\/]*amazonaws',
        ];

        $matches = [];
        $scannedFiles = 0;

        // Try both webroot and base paths
        $searchPaths = [
            $webPath,
            $basePath,
        ];

        foreach ($searchPaths as $searchPath) {
            foreach ($scanDirs as $dir) {
                $fullPath = rtrim($searchPath, '/') . '/' . ltrim($dir, '/');

                if (!is_dir($fullPath)) {
                    continue; // Skip silently to avoid clutter
                }

                $this->stdout("Scanning {$dir}/...\n", Console::FG_YELLOW);

                // Find all static asset files including source files
                $files = $this->findFiles($fullPath, [
                    'js', 'mjs', 'cjs', 'jsx',
                    'ts', 'tsx',
                    'css', 'scss', 'sass', 'less',
                    'vue', 'svelte',
                    'json', // Sometimes URLs in config files
                ]);

                $scannedFiles += count($files);

                foreach ($files as $file) {
                    $content = file_get_contents($file);
                    $lines = explode("\n", $content);
                    $relativePath = str_replace($searchPath . '/', '', $file);

                    foreach ($patterns as $pattern) {
                        foreach ($lines as $lineNum => $line) {
                            if (preg_match('/' . $pattern . '/i', $line, $match)) {
                                if (!isset($matches[$relativePath])) {
                                    $matches[$relativePath] = [];
                                }

                                // Extract full URL or context
                                $context = $line;

                                // Try to extract just the URL if possible
                                if (preg_match('/https?:\/\/[^\s\'")\]]+/i', $line, $urlMatch)) {
                                    $context = $urlMatch[0];
                                } elseif (preg_match('/["\']([^"\']*' . $pattern . '[^"\']*)["\']/i', $line, $quotedMatch)) {
                                    $context = $quotedMatch[1];
                                }

                                $matches[$relativePath][] = [
                                    'line' => $lineNum + 1,
                                    'context' => trim($context),
                                    'full_line' => trim($line),
                                ];
                            }
                        }
                    }
                }
            }
        }

        $this->stdout("\nTotal files scanned: {$scannedFiles}\n", Console::FG_CYAN);

        // Display results
        if (empty($matches)) {
            $this->stdout("\n✓ No hardcoded URLs found in static assets!\n\n", Console::FG_GREEN);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        $this->stdout("\n" . str_repeat("-", 80) . "\n", Console::FG_CYAN);
        $totalMatches = array_sum(array_map('count', $matches));
        $this->stdout("FOUND {$totalMatches} HARDCODED URL(S) IN " . count($matches) . " FILE(S)\n", Console::FG_CYAN);
        $this->stdout(str_repeat("-", 80) . "\n\n", Console::FG_CYAN);

        foreach ($matches as $file => $occurrences) {
            $this->stdout("File: {$file}\n", Console::FG_YELLOW);

            // Remove duplicates based on line and context
            $uniqueOccurrences = [];
            foreach ($occurrences as $occurrence) {
                $key = $occurrence['line'] . ':' . $occurrence['context'];
                $uniqueOccurrences[$key] = $occurrence;
            }

            foreach ($uniqueOccurrences as $occurrence) {
                $lineNum = str_pad($occurrence['line'], 5, ' ', STR_PAD_LEFT);
                $this->stdout("  Line {$lineNum}: ", Console::FG_GREY);
                $this->stdout($occurrence['context'] . "\n", Console::FG_RED);

                // Show full line context if different and helpful
                if ($occurrence['context'] !== $occurrence['full_line'] &&
                    strlen($occurrence['full_line']) < 120) {
                    $this->stdout("              Full: " . $occurrence['full_line'] . "\n", Console::FG_GREY);
                }
            }
            $this->stdout("\n");
        }

        $this->stdout("⚠ Manual update required for these files\n", Console::FG_YELLOW);
        $this->stdout("⚠ Consider using environment variables or relative paths\n\n", Console::FG_YELLOW);

        // Generate report
        $this->generateReport($matches);

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Find files by extension
     */
    private function findFiles(string $dir, array $extensions): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Generate JSON report
     */
    private function generateReport(array $matches): void
    {
        $reportPath = Craft::getAlias('@storage') . '/static-asset-scan-' . date('Y-m-d-His') . '.json';

        $totalMatches = array_sum(array_map('count', $matches));

        $report = [
            'scanned_at' => date('Y-m-d H:i:s'),
            'files_with_urls' => count($matches),
            'total_occurrences' => $totalMatches,
            'matches' => $matches,
            'summary' => [
                'action_required' => 'Manual update required for hardcoded URLs',
                'recommendation' => 'Use environment variables or relative paths',
            ],
        ];

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));

        $this->stdout("Report saved: {$reportPath}\n\n", Console::FG_GREEN);
    }
}