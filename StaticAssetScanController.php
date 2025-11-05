<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Static Asset Scan Controller
 *
 * Scans JS and CSS files for hardcoded AWS S3 URLs
 *
 * Usage:
 *   ./craft static-asset/scan
 *   ./craft static-asset/report
 */
class StaticAssetScanController extends Controller
{
    public $defaultAction = 'scan';

    /**
     * Scan JS and CSS files for S3 URLs
     */
    public function actionScan(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("STATIC ASSET SCANNER\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $webPath = Craft::getAlias('@webroot');

        // Directories to scan
        $scanDirs = [
            'assets/js',
            'assets/css',
            'dist',
            'build',
        ];

        $patterns = [
            's3\.amazonaws\.com',
            'ncc-website-2',
            'digitaloceanspaces\.com', // Also check DO Spaces for hardcoded URLs
        ];

        $matches = [];

        foreach ($scanDirs as $dir) {
            $fullPath = $webPath . '/' . $dir;

            if (!is_dir($fullPath)) {
                $this->stdout("⊘ {$dir}/ not found\n", Console::FG_GREY);
                continue;
            }

            $this->stdout("Scanning {$dir}/...\n", Console::FG_YELLOW);

            // Find all JS and CSS files
            $files = $this->findFiles($fullPath, ['js', 'css']);

            foreach ($files as $file) {
                $content = file_get_contents($file);
                $relativePath = str_replace($webPath . '/', '', $file);

                foreach ($patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $content, $match)) {
                        if (!isset($matches[$relativePath])) {
                            $matches[$relativePath] = [];
                        }

                        // Extract full URL context
                        if (preg_match('/https?:\/\/[^\s\'"]+' . $pattern . '[^\s\'"]*/i', $content, $urlMatch)) {
                            $matches[$relativePath][] = $urlMatch[0];
                        }
                    }
                }
            }
        }

        // Display results
        if (empty($matches)) {
            $this->stdout("\n✓ No hardcoded URLs found in static assets!\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stdout("\n" . str_repeat("-", 80) . "\n", Console::FG_CYAN);
        $this->stdout("FOUND HARDCODED URLs IN " . count($matches) . " FILES\n", Console::FG_CYAN);
        $this->stdout(str_repeat("-", 80) . "\n\n", Console::FG_CYAN);

        foreach ($matches as $file => $urls) {
            $this->stdout("File: {$file}\n", Console::FG_YELLOW);
            foreach (array_unique($urls) as $url) {
                $this->stdout("  • {$url}\n", Console::FG_GREY);
            }
            $this->stdout("\n");
        }

        $this->stdout("⚠ Manual update required for these files\n", Console::FG_YELLOW);
        $this->stdout("⚠ Consider using environment variables or relative paths\n\n", Console::FG_YELLOW);

        // Generate report
        $this->generateReport($matches);

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

        $report = [
            'scanned_at' => date('Y-m-d H:i:s'),
            'files_with_urls' => count($matches),
            'matches' => $matches,
        ];

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));

        $this->stdout("Report saved: {$reportPath}\n\n", Console::FG_GREEN);
    }
}