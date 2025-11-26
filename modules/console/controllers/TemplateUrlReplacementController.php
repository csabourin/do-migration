<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\BaseConsoleController;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Template URL Replacement Controller
 * 
 * Replaces hardcoded AWS S3 URLs in Twig templates with environment variables
 * 
 * Usage:
 *   1. ./craft spaghetti-migrator/template-url/scan - Find all hardcoded URLs
 *   2. ./craft spaghetti-migrator/template-url/replace --dryRun=1 - Preview changes
 *   3. ./craft spaghetti-migrator/template-url/replace - Apply changes
 *   4. ./craft spaghetti-migrator/template-url/verify - Verify no AWS URLs remain
 */
class TemplateUrlReplacementController extends BaseConsoleController
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
     * @var bool Whether to run in dry-run mode
     */
    public $dryRun = false;

    /**
     * @var string Override the environment variable to use
     */
    public $envVar = 'DO_S3_BASE_URL';

    /**
     * @var bool Create backups before modifying files
     */
    public $backup = true;

    /**
     * @var bool Skip all confirmation prompts (for automation)
     */
    public $yes = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if (in_array($actionID, ['replace', 'scan'])) {
            $options[] = 'dryRun';
            $options[] = 'envVar';
            $options[] = 'backup';
            $options[] = 'yes';
        }
        if ($actionID === 'restore-backups') {
            $options[] = 'yes';
        }
        return $options;
    }

    /**
     * Scan templates for hardcoded AWS S3 URLs
     */
    public function actionScan(): int
    {
        $this->printHeader("TEMPLATE URL SCANNER");

        $templatesPath = Craft::getAlias('@templates');
        
        if (!is_dir($templatesPath)) {
            $this->stderr("Templates directory not found: {$templatesPath}\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->output("Scanning templates in: {$templatesPath}\n\n", Console::FG_CYAN);

        // Find all Twig files
        $files = $this->findTwigFiles($templatesPath);
        $this->output("Found " . count($files) . " Twig files\n\n", Console::FG_YELLOW);

        // Scan for AWS URLs
        $matches = $this->scanFilesForAwsUrls($files, $templatesPath);

        if (empty($matches)) {
            $this->output("✓ No hardcoded AWS S3 URLs found in templates!\n\n", Console::FG_GREEN);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        // Display results
        $this->displayScanResults($matches);

        // Generate report
        $this->generateScanReport($matches);

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Replace hardcoded URLs with environment variables
     */
    public function actionReplace(): int
    {
        $this->printHeader("TEMPLATE URL REPLACEMENT");

        if ($this->dryRun) {
            $this->output("MODE: DRY RUN - No files will be modified\n\n", Console::FG_YELLOW);
        } else {
            $this->output("MODE: LIVE - Files will be modified\n\n", Console::FG_RED);
                $this->stdout("__CLI_EXIT_CODE_0__\n");

            if (!$this->yes && !$this->confirm("This will modify your template files. Continue?")) {
                return ExitCode::OK;
            } elseif ($this->yes) {
                $this->output("⚠ Auto-confirmed (--yes flag)\n\n", Console::FG_YELLOW);
            }
        }

        $templatesPath = Craft::getAlias('@templates');
        $files = $this->findTwigFiles($templatesPath);

        $this->output("Environment variable: {$this->envVar}\n", Console::FG_CYAN);
        $this->output("Backup enabled: " . ($this->backup ? "Yes" : "No") . "\n\n", Console::FG_CYAN);

        // Scan first
        $matches = $this->scanFilesForAwsUrls($files, $templatesPath);

            $this->stdout("__CLI_EXIT_CODE_0__\n");
        if (empty($matches)) {
            $this->output("✓ No URLs to replace!\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->output("Found URLs in " . count($matches) . " files\n\n", Console::FG_YELLOW);

        // Perform replacements
        $stats = [
            'files_processed' => 0,
            'files_modified' => 0,
            'urls_replaced' => 0,
            'errors' => 0,
        ];

        foreach ($matches as $relativePath => $fileMatches) {
            $fullPath = $templatesPath . '/' . $relativePath;
            $stats['files_processed']++;

            $this->output("Processing: {$relativePath}\n", Console::FG_CYAN);

            try {
                $content = file_get_contents($fullPath);
                $originalContent = $content;
                $replacements = 0;

                // Replace each URL pattern
                foreach ($fileMatches['patterns'] as $pattern) {
                    $oldPattern = $pattern['full_match'];
                    $newPattern = $this->generateReplacementPattern($pattern);
                    
                    $newContent = str_replace($oldPattern, $newPattern, $content);
                    
                    if ($newContent !== $content) {
                        $replacements++;
                        $content = $newContent;
                        $this->output("  ✓ Replaced: {$pattern['type']}\n", Console::FG_GREEN);
                    }
                }

                if ($replacements > 0) {
                    if (!$this->dryRun) {
                        // Create backup
                        if ($this->backup) {
                            $backupPath = $fullPath . '.backup-' . date('YmdHis');
                            file_put_contents($backupPath, $originalContent);
                            $this->output("  💾 Backup: {$backupPath}\n", Console::FG_GREY);
                        }

                        // Write new content
                        file_put_contents($fullPath, $content);
                        $this->output("  ✓ Modified {$replacements} URL(s)\n\n", Console::FG_GREEN);
                    } else {
                        $this->output("  [DRY RUN] Would replace {$replacements} URL(s)\n\n", Console::FG_YELLOW);
                    }

                    $stats['files_modified']++;
                    $stats['urls_replaced'] += $replacements;
                } else {
                    $this->output("  ⊘ No changes needed\n\n", Console::FG_GREY);
                }

            } catch (\Exception $e) {
                $this->stderr("  ✗ Error: {$e->getMessage()}\n\n", Console::FG_RED);
                $stats['errors']++;
            }
        }

        // Display summary
        $this->printReplacementSummary($stats);

        return $stats['errors'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Verify no AWS URLs remain in templates
     */
    public function actionVerify(): int
    {
        $this->printHeader("TEMPLATE URL VERIFICATION");

        $templatesPath = Craft::getAlias('@templates');
        $files = $this->findTwigFiles($templatesPath);

        $this->output("Scanning " . count($files) . " templates for remaining AWS URLs...\n\n", Console::FG_CYAN);

        $matches = $this->scanFilesForAwsUrls($files, $templatesPath);

        if (empty($matches)) {
            $this->output("✓ No AWS S3 URLs found in templates!\n", Console::FG_GREEN);
            $this->output("✓ All URLs have been replaced with environment variables.\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->output("⚠ Still found AWS URLs in " . count($matches) . " files:\n\n", Console::FG_YELLOW);
        $this->displayScanResults($matches);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Restore templates from backups
     */
    public function actionRestoreBackups(): int
    {
        $this->printHeader("RESTORE TEMPLATE BACKUPS");

        $templatesPath = Craft::getAlias('@templates');
        
        // Find all backup files
        $backups = $this->findBackupFiles($templatesPath);

        if (empty($backups)) {
            $this->output("No backup files found.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->output("Found " . count($backups) . " backup files\n\n", Console::FG_YELLOW);

        foreach ($backups as $backup) {
            $this->output("  {$backup['relative']}\n", Console::FG_GREY);
        }

        $this->output("\n");

        if (!$this->yes && !$this->confirm("Restore all backups?")) {
            return ExitCode::OK;
        } elseif ($this->yes) {
            $this->output("⚠ Auto-confirmed (--yes flag)\n\n", Console::FG_YELLOW);
        }

        $restored = 0;
        foreach ($backups as $backup) {
            try {
                $originalFile = str_replace($backup['suffix'], '', $backup['full']);
                
                // Restore backup
                copy($backup['full'], $originalFile);
                
                // Delete backup
                unlink($backup['full']);
                
                $this->output("  ✓ Restored: {$backup['relative']}\n", Console::FG_GREEN);
                $restored++;
            } catch (\Exception $e) {
                $this->stderr("  ✗ Error restoring {$backup['relative']}: {$e->getMessage()}\n", Console::FG_RED);
            }
        }

        $this->output("\n✓ Restored {$restored} files\n\n", Console::FG_GREEN);

        return ExitCode::OK;
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
     * Find all backup files
     */
    private function findBackupFiles(string $dir): array
    {
        $backups = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.backup-\d{14}$/', $file->getFilename())) {
                $backups[] = [
                    'full' => $file->getPathname(),
                    'relative' => str_replace($dir . '/', '', $file->getPathname()),
                    'suffix' => '.backup-' . substr($file->getFilename(), -14),
                ];
            }
        }

        return $backups;
    }

    /**
     * Scan files for AWS S3 URLs
     */
    private function scanFilesForAwsUrls(array $files, string $basePath): array
    {
        $matches = [];

        // AWS S3 URL patterns
        $patterns = [
            // Direct S3 URLs
            [
                'pattern' => '/https?:\/\/[^\/]*\.s3[^\/]*\.amazonaws\.com\/[^"\'\s\)]+/i',
                'type' => 'direct_s3_url',
            ],
            // Hardcoded DO Spaces URLs (also need to be variables)
            [
                'pattern' => '/https?:\/\/[^\/]*\.digitaloceanspaces\.com\/[^"\'\s\)]+/i',
                'type' => 'do_spaces_url',
            ],
            // Asset URLs with bucket name - loaded from centralized config
            [
                'pattern' => '/https?:\/\/s3[^\/]*\.amazonaws\.com\/' . preg_quote($this->config->getAwsBucket(), '/') . '\/[^"\'\s\)]+/i',
                'type' => 's3_bucket_url',
            ],
        ];

        foreach ($files as $file) {
            $relativePath = str_replace($basePath . '/', '', $file);
            $content = file_get_contents($file);

            $fileMatches = [
                'file' => $relativePath,
                'patterns' => [],
            ];

            foreach ($patterns as $patternInfo) {
                if (preg_match_all($patternInfo['pattern'], $content, $urlMatches)) {
                    foreach ($urlMatches[0] as $url) {
                        $fileMatches['patterns'][] = [
                            'type' => $patternInfo['type'],
                            'url' => $url,
                            'full_match' => $url,
                        ];
                    }
                }
            }

            if (!empty($fileMatches['patterns'])) {
                $matches[$relativePath] = $fileMatches;
            }
        }

        return $matches;
    }

    /**
     * Generate replacement pattern for a URL
     */
    private function generateReplacementPattern(array $pattern): string
    {
        $url = $pattern['url'];

        // Extract the path after the domain
        if (preg_match('/https?:\/\/[^\/]+\/(.+)$/', $url, $matches)) {
            $path = $matches[1];

            // Remove bucket name if present - loaded from centralized config
            $awsBucket = preg_quote($this->config->getAwsBucket(), '/');
            $path = preg_replace("/^{$awsBucket}\//", '', $path);

            return "{{ getenv('{$this->envVar}') }}/{$path}";
        }

        return $url;
    }

    /**
     * Display scan results
     */
    private function displayScanResults(array $matches): void
    {
        $this->output("═══════════════════════════════════════════════════════════════════════\n", Console::FG_CYAN);
        $this->output("SCAN RESULTS\n", Console::FG_CYAN);
        $this->output("═══════════════════════════════════════════════════════════════════════\n\n", Console::FG_CYAN);

        $totalUrls = 0;
        foreach ($matches as $match) {
            $totalUrls += count($match['patterns']);
        }

        $this->output("Found hardcoded URLs:\n", Console::FG_YELLOW);
        $this->output("  Files affected: " . count($matches) . "\n");
        $this->output("  Total URLs: {$totalUrls}\n\n");

        $this->output("Files with hardcoded URLs:\n", Console::FG_YELLOW);
        foreach ($matches as $relativePath => $match) {
            $count = count($match['patterns']);
            $this->output("  • {$relativePath} ({$count} URLs)\n", Console::FG_GREY);
            
            // Show first 3 URLs
            foreach (array_slice($match['patterns'], 0, 3) as $pattern) {
                $this->output("    - {$pattern['type']}: {$pattern['url']}\n", Console::FG_GREY);
            }
            
            if (count($match['patterns']) > 3) {
                $remaining = count($match['patterns']) - 3;
                $this->output("    ... and {$remaining} more\n", Console::FG_GREY);
            }
            
            $this->output("\n");
        }
    }

    /**
     * Generate scan report JSON
     */
    private function generateScanReport(array $matches): void
    {
        $reportPath = Craft::getAlias('@storage') . '/template-url-scan-' . date('Y-m-d-His') . '.json';
        
        $report = [
            'scanned_at' => date('Y-m-d H:i:s'),
            'files_with_urls' => count($matches),
            'matches' => $matches,
        ];

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        
        $this->output("✓ Report saved to: {$reportPath}\n\n", Console::FG_GREEN);
    }

    /**
     * Print replacement summary
     */
    private function printReplacementSummary(array $stats): void
    {
        $this->output("\n═══════════════════════════════════════════════════════════════════════\n", Console::FG_CYAN);
        $this->output("REPLACEMENT SUMMARY\n", Console::FG_CYAN);
        $this->output("═══════════════════════════════════════════════════════════════════════\n\n", Console::FG_CYAN);

        $this->output("Results:\n", Console::FG_YELLOW);
        $this->output("  Files processed: {$stats['files_processed']}\n");
        $this->output("  Files modified: {$stats['files_modified']}\n", Console::FG_GREEN);
        $this->output("  URLs replaced: {$stats['urls_replaced']}\n", Console::FG_GREEN);
        $this->output("  Errors: {$stats['errors']}\n", $stats['errors'] > 0 ? Console::FG_RED : Console::FG_GREEN);
        
        $this->output("\n");

        if ($this->dryRun) {
            $this->output("This was a DRY RUN. Run with --dryRun=0 to apply changes.\n", Console::FG_YELLOW);
        } else {
            $this->output("✓ Template URLs have been updated!\n", Console::FG_GREEN);
            $this->output("✓ Environment variable used: {$this->envVar}\n", Console::FG_GREEN);
            
            if ($this->backup) {
                $this->output("\n💾 Backup files created with .backup-YYYYMMDDHHMMSS extension\n", Console::FG_GREY);
                $this->output("   Use './craft spaghetti-migrator/template-url/restore-backups' to restore if needed\n", Console::FG_GREY);
            }
        }

        $this->output("\n");
    }

    /**
     * Print header
     */
    private function printHeader(string $title): void
    {
        $this->output("\n" . str_repeat("═", 80) . "\n", Console::FG_CYAN);
        $this->output("{$title}\n", Console::FG_CYAN);
        $this->output(str_repeat("═", 80) . "\n\n", Console::FG_CYAN);
    }
}
