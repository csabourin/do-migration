# Extended Controllers - Code Examples

These controllers handle the gaps not covered by existing migration tools.

---

## 1. ExtendedUrlReplacementController

Handles additional database tables and JSON fields.

**File:** `modules/console/controllers/ExtendedUrlReplacementController.php`

```php
<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Extended URL Replacement Controller
 *
 * Covers additional tables beyond content tables:
 * - projectconfig (plugin settings)
 * - elements_sites (metadata)
 * - JSON fields (table fields, etc.)
 *
 * Usage:
 *   ./craft ncc-module/extended-url/scan-additional
 *   ./craft ncc-module/extended-url/replace-additional --dryRun=1
 *   ./craft ncc-module/extended-url/replace-json --dryRun=1
 */
class ExtendedUrlReplacementController extends Controller
{
    public $defaultAction = 'scan-additional';

    /**
     * Scan additional database tables for AWS S3 URLs
     */
    public function actionScanAdditional(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("EXTENDED URL SCAN - Additional Tables\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $db = Craft::$app->getDb();
        $oldUrls = $this->getOldUrls();

        // Tables to scan
        $tables = [
            ['table' => 'projectconfig', 'column' => 'config'],
            ['table' => 'elements_sites', 'column' => 'metadata'],
            ['table' => 'revisions', 'column' => 'data'],
            ['table' => 'changedattributes', 'column' => 'attribute'],
        ];

        $totalMatches = 0;

        foreach ($tables as $tableInfo) {
            $table = $tableInfo['table'];
            $column = $tableInfo['column'];

            $this->stdout("Scanning {$table}.{$column}... ", Console::FG_YELLOW);

            try {
                // Check if table exists
                if (!$db->getTableSchema($table)) {
                    $this->stdout("SKIPPED (table not found)\n", Console::FG_GREY);
                    continue;
                }

                // Build search condition
                $conditions = [];
                foreach ($oldUrls as $url) {
                    $conditions[] = "`{$column}` LIKE '%" . addslashes($url) . "%'";
                }
                $whereClause = implode(' OR ', $conditions);

                $count = (int) $db->createCommand("
                    SELECT COUNT(*)
                    FROM `{$table}`
                    WHERE {$whereClause}
                ")->queryScalar();

                if ($count > 0) {
                    $this->stdout("{$count} rows\n", Console::FG_GREEN);
                    $totalMatches += $count;

                    // Show sample
                    $sample = $db->createCommand("
                        SELECT `{$column}`
                        FROM `{$table}`
                        WHERE {$whereClause}
                        LIMIT 1
                    ")->queryScalar();

                    if ($sample) {
                        $preview = substr($sample, 0, 100);
                        $this->stdout("  Sample: {$preview}...\n", Console::FG_GREY);
                    }
                } else {
                    $this->stdout("0 rows\n", Console::FG_GREY);
                }

            } catch (\Throwable $e) {
                $this->stdout("ERROR: {$e->getMessage()}\n", Console::FG_RED);
            }
        }

        $this->stdout("\n" . str_repeat("-", 80) . "\n");
        $this->stdout("Total matches: {$totalMatches}\n", Console::FG_CYAN);
        $this->stdout(str_repeat("-", 80) . "\n\n");

        return ExitCode::OK;
    }

    /**
     * Replace AWS S3 URLs in additional tables
     */
    public function actionReplaceAdditional($dryRun = false): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("EXTENDED URL REPLACEMENT - Additional Tables\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($dryRun) {
            $this->stdout("MODE: DRY RUN\n\n", Console::FG_YELLOW);
        } else {
            $this->stdout("MODE: LIVE\n\n", Console::FG_RED);
            if (!$this->confirm("This will modify additional database tables. Continue?")) {
                return ExitCode::OK;
            }
        }

        $db = Craft::$app->getDb();
        $urlMappings = $this->getUrlMappings();

        // Simpler tables (non-JSON)
        $simpleTables = [
            ['table' => 'changedattributes', 'column' => 'attribute'],
        ];

        foreach ($simpleTables as $tableInfo) {
            $table = $tableInfo['table'];
            $column = $tableInfo['column'];

            if (!$db->getTableSchema($table)) {
                continue;
            }

            $this->stdout("Processing {$table}.{$column}... ");

            $totalAffected = 0;
            foreach ($urlMappings as $oldUrl => $newUrl) {
                if (!$dryRun) {
                    $affected = $db->createCommand("
                        UPDATE `{$table}`
                        SET `{$column}` = REPLACE(`{$column}`, :oldUrl, :newUrl)
                        WHERE `{$column}` LIKE :pattern
                    ", [
                        ':oldUrl' => $oldUrl,
                        ':newUrl' => $newUrl,
                        ':pattern' => "%{$oldUrl}%"
                    ])->execute();
                    $totalAffected += $affected;
                }
            }

            if ($dryRun) {
                $this->stdout("Would update rows\n", Console::FG_YELLOW);
            } else {
                $this->stdout("{$totalAffected} rows updated\n", Console::FG_GREEN);
            }
        }

        $this->stdout("\n✓ Complete\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Replace URLs in JSON fields
     */
    public function actionReplaceJson($dryRun = false): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("JSON FIELD URL REPLACEMENT\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($dryRun) {
            $this->stdout("MODE: DRY RUN\n\n", Console::FG_YELLOW);
        }

        $db = Craft::$app->getDb();
        $urlMappings = $this->getUrlMappings();

        // JSON fields to process
        $jsonTables = [
            ['table' => 'projectconfig', 'column' => 'config', 'idColumn' => 'path'],
            ['table' => 'elements_sites', 'column' => 'metadata', 'idColumn' => 'id'],
            ['table' => 'revisions', 'column' => 'data', 'idColumn' => 'id'],
        ];

        foreach ($jsonTables as $tableInfo) {
            $table = $tableInfo['table'];
            $column = $tableInfo['column'];
            $idColumn = $tableInfo['idColumn'];

            if (!$db->getTableSchema($table)) {
                $this->stdout("⊘ Table {$table} not found\n", Console::FG_GREY);
                continue;
            }

            $this->stdout("Processing {$table}.{$column}...\n", Console::FG_YELLOW);

            // Find rows with S3 URLs
            $conditions = [];
            foreach ($this->getOldUrls() as $url) {
                $conditions[] = "`{$column}` LIKE '%" . addslashes($url) . "%'";
            }
            $whereClause = implode(' OR ', $conditions);

            try {
                $rows = $db->createCommand("
                    SELECT `{$idColumn}`, `{$column}`
                    FROM `{$table}`
                    WHERE {$whereClause}
                ")->queryAll();

                if (empty($rows)) {
                    $this->stdout("  No matches\n", Console::FG_GREY);
                    continue;
                }

                $this->stdout("  Found " . count($rows) . " rows\n", Console::FG_GREEN);
                $updatedCount = 0;

                foreach ($rows as $row) {
                    $id = $row[$idColumn];
                    $original = $row[$column];

                    // Try to decode JSON
                    $data = @json_decode($original, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // Not JSON, treat as string
                        $updated = $original;
                        foreach ($urlMappings as $oldUrl => $newUrl) {
                            $updated = str_replace($oldUrl, $newUrl, $updated);
                        }
                    } else {
                        // JSON - recursive replace
                        $updated = $this->replaceUrlsInArray($data, $urlMappings);
                        $updated = json_encode($updated);
                    }

                    if ($updated !== $original) {
                        $this->stdout("  • ID {$id}: ", Console::FG_GREY);

                        if (!$dryRun) {
                            $db->createCommand()->update(
                                $table,
                                [$column => $updated],
                                [$idColumn => $id]
                            )->execute();
                            $this->stdout("UPDATED\n", Console::FG_GREEN);
                        } else {
                            $this->stdout("Would update\n", Console::FG_YELLOW);
                        }

                        $updatedCount++;
                    }
                }

                $this->stdout("  Total updated: {$updatedCount}\n\n", Console::FG_CYAN);

            } catch (\Throwable $e) {
                $this->stderr("  ERROR: {$e->getMessage()}\n\n", Console::FG_RED);
            }
        }

        $this->stdout("✓ Complete\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Recursively replace URLs in nested arrays
     */
    private function replaceUrlsInArray($data, $urlMappings)
    {
        if (is_string($data)) {
            foreach ($urlMappings as $oldUrl => $newUrl) {
                $data = str_replace($oldUrl, $newUrl, $data);
            }
            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->replaceUrlsInArray($value, $urlMappings);
            }
            return $data;
        }

        return $data;
    }

    /**
     * Get old AWS S3 URLs
     */
    private function getOldUrls(): array
    {
        return [
            'https://ncc-website-2.s3.amazonaws.com',
            'http://ncc-website-2.s3.amazonaws.com',
            'https://s3.ca-central-1.amazonaws.com/ncc-website-2',
            'http://s3.ca-central-1.amazonaws.com/ncc-website-2',
            'https://s3.amazonaws.com/ncc-website-2',
            'http://s3.amazonaws.com/ncc-website-2',
        ];
    }

    /**
     * Get URL mappings
     */
    private function getUrlMappings(): array
    {
        $newUrl = 'https://dev-medias-test.tor1.digitaloceanspaces.com';

        $mappings = [];
        foreach ($this->getOldUrls() as $oldUrl) {
            $mappings[$oldUrl] = $newUrl;
        }

        return $mappings;
    }
}
```

---

## 2. StaticAssetScanController

Scans JavaScript and CSS files for hardcoded S3 URLs.

**File:** `modules/console/controllers/StaticAssetScanController.php`

```php
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
 *   ./craft ncc-module/static-asset/scan
 *   ./craft ncc-module/static-asset/report
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
```

---

## 3. PluginConfigAuditController

Audits plugin configurations for S3 references.

**File:** `modules/console/controllers/PluginConfigAuditController.php`

```php
<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Plugin Configuration Audit Controller
 *
 * Audits plugin configurations for AWS S3 references
 *
 * Usage:
 *   ./craft ncc-module/plugin-audit/scan
 *   ./craft ncc-module/plugin-audit/list-plugins
 */
class PluginConfigAuditController extends Controller
{
    public $defaultAction = 'scan';

    /**
     * List all installed plugins
     */
    public function actionListPlugins(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("INSTALLED PLUGINS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $plugins = Craft::$app->getPlugins()->getAllPlugins();

        foreach ($plugins as $handle => $plugin) {
            $this->stdout("• {$plugin->name} ({$handle})\n", Console::FG_YELLOW);
            $this->stdout("  Version: {$plugin->version}\n", Console::FG_GREY);

            // Check for config file
            $configPath = Craft::getAlias("@config/{$handle}.php");
            if (file_exists($configPath)) {
                $this->stdout("  Config: config/{$handle}.php ✓\n", Console::FG_GREEN);
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Scan plugin configurations for S3 URLs
     */
    public function actionScan(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("PLUGIN CONFIGURATION AUDIT\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $configPath = Craft::getAlias('@config');
        $matches = [];

        // Known plugins that might have S3 config
        $pluginsToCheck = [
            'imager-x' => 'Imager-X (image transforms)',
            'blitz' => 'Blitz (static cache)',
            'redactor' => 'Redactor (rich text)',
            'ckeditor' => 'CKEditor (rich text)',
            'feed-me' => 'Feed Me (imports)',
            'image-optimize' => 'Image Optimize',
        ];

        $this->stdout("Checking common plugins...\n\n", Console::FG_YELLOW);

        foreach ($pluginsToCheck as $handle => $name) {
            $configFile = $configPath . '/' . $handle . '.php';

            if (!file_exists($configFile)) {
                $this->stdout("⊘ {$name}: No config file\n", Console::FG_GREY);
                continue;
            }

            $content = file_get_contents($configFile);

            if (preg_match('/s3\.amazonaws|ncc-website-2/i', $content)) {
                $matches[$handle] = $configFile;
                $this->stdout("⚠ {$name}: Contains S3 references\n", Console::FG_RED);

                // Show context
                preg_match_all('/.{0,60}(?:s3\.amazonaws|ncc-website-2).{0,60}/i', $content, $contexts);
                foreach (array_slice($contexts[0], 0, 2) as $context) {
                    $this->stdout("  → " . trim($context) . "\n", Console::FG_GREY);
                }
            } else {
                $this->stdout("✓ {$name}: Clean\n", Console::FG_GREEN);
            }
        }

        // Check database (projectconfig) - FIXED for Craft 4
        $this->stdout("\nChecking database plugin settings...\n\n", Console::FG_YELLOW);

        $db = Craft::$app->getDb();

        // Craft 4 uses 'value' column, not 'config' column
        try {
            $rows = $db->createCommand("
                SELECT path, value
                FROM projectconfig
                WHERE path LIKE 'plugins.%'
                AND (value LIKE '%s3.amazonaws%' OR value LIKE '%ncc-website-2%')
            ")->queryAll();

            if (!empty($rows)) {
                $this->stdout("⚠ Found S3 references in plugin settings:\n", Console::FG_RED);
                foreach ($rows as $row) {
                    $this->stdout("  • {$row['path']}\n", Console::FG_GREY);

                    // Try to show snippet of the value
                    $value = $row['value'];
                    if (strlen($value) > 100) {
                        $value = substr($value, 0, 100) . '...';
                    }
                    $this->stdout("    " . $value . "\n", Console::FG_GREY);
                }
            } else {
                $this->stdout("✓ No S3 references in plugin settings\n", Console::FG_GREEN);
            }
        } catch (\Exception $e) {
            $this->stderr("Error checking database: " . $e->getMessage() . "\n", Console::FG_RED);
            $this->stdout("Skipping database check\n\n", Console::FG_YELLOW);
        }

        // Summary
        $this->stdout("\n" . str_repeat("-", 80) . "\n");
        if (empty($matches) && empty($rows)) {
            $this->stdout("✓ All plugin configurations are clean!\n\n", Console::FG_GREEN);
        } else {
            $this->stdout("⚠ Found S3 references in " . count($matches) . " config files\n", Console::FG_YELLOW);
            $this->stdout("⚠ Manual review and update required\n\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }
}
```

---

## Installation

1. Copy controllers to your modules directory:
```bash
cp ExtendedUrlReplacementController.php modules/console/controllers/
cp StaticAssetScanController.php modules/console/controllers/
cp PluginConfigAuditController.php modules/console/controllers/
```

2. Verify accessibility:
```bash
./craft help ncc-module/extended-url
./craft help ncc-module/static-asset
./craft help ncc-module/plugin-audit
```

3. Run scans:
```bash
# Scan additional database tables
./craft ncc-module/extended-url/scan-additional

# Scan JSON fields
./craft ncc-module/extended-url/replace-json --dryRun=1

# Scan static assets
./craft ncc-module/static-asset/scan

# Audit plugin configs
./craft ncc-module/plugin-audit/scan
```

---

## Usage Examples

### Scenario 1: Found S3 URLs in projectconfig table
```bash
# 1. Scan
./craft ncc-module/extended-url/scan-additional

# 2. Replace (dry run)
./craft ncc-module/extended-url/replace-json --dryRun=1

# 3. Replace (live)
./craft ncc-module/extended-url/replace-json

# 4. Apply project config
./craft project-config/apply
```

### Scenario 2: Found hardcoded URLs in JS files
```bash
# 1. Scan
./craft ncc-module/static-asset/scan

# 2. Review report
cat storage/static-asset-scan-*.json

# 3. Manual update required (these are compiled assets)
# - Update source files
# - Rebuild assets
# - Re-scan to verify
```

### Scenario 3: Plugin has S3 config
```bash
# 1. Audit
./craft ncc-module/plugin-audit/scan

# 2. Manually edit config files
# Example: config/imager-x.php
# Change S3 URLs to DO_S3_BASE_URL environment variable

# 3. Clear cache
./craft clear-caches/all
```

---

## Notes

- These controllers are **supplements** to existing migration tools
- Always run with `--dryRun=1` first
- JSON field replacement is **recursive** (handles nested structures)
- Static asset scanning is **read-only** (manual fix required)
- Plugin configs typically require **manual updates**

---

**Version:** 1.0
**Compatibility:** Craft CMS 4.x
**Tested:** PHP 8.1+
