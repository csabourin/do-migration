<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\BaseConsoleController;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Plugin Configuration Audit Controller
 *
 * Audits plugin configurations for AWS S3 references
 *
 * Usage:
 *   ./craft plugin-audit/scan
 *   ./craft plugin-audit/list-plugins
 */
class PluginConfigAuditController extends BaseConsoleController
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
     * List all installed plugins
     */
    public function actionListPlugins(): int
    {
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("INSTALLED PLUGINS\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $plugins = Craft::$app->getPlugins()->getAllPlugins();

        foreach ($plugins as $handle => $plugin) {
            $this->output("• {$plugin->name} ({$handle})\n", Console::FG_YELLOW);
            $this->output("  Version: {$plugin->version}\n", Console::FG_GREY);

            // Check for config file
            $configPath = Craft::getAlias("@config/{$handle}.php");
            if (file_exists($configPath)) {
                $this->output("  Config: config/{$handle}.php ✓\n", Console::FG_GREEN);
            }

            $this->output("\n");
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Scan plugin configurations for S3 URLs
     */
    public function actionScan(): int
    {
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("PLUGIN CONFIGURATION AUDIT\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

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

        $this->output("Checking common plugins...\n\n", Console::FG_YELLOW);

        foreach ($pluginsToCheck as $handle => $name) {
            $configFile = $configPath . '/' . $handle . '.php';

            if (!file_exists($configFile)) {
                $this->output("⊘ {$name}: No config file\n", Console::FG_GREY);
                continue;
            }

            $content = file_get_contents($configFile);

            $awsBucket = preg_quote($this->config->getAwsBucket(), '/');
            $pattern = "/s3\.amazonaws|{$awsBucket}/i";

            if (preg_match($pattern, $content)) {
                $matches[$handle] = $configFile;
                $this->output("⚠ {$name}: Contains S3 references\n", Console::FG_RED);

                // Show context
                preg_match_all("/.{0,60}(?:s3\.amazonaws|{$awsBucket}).{0,60}/i", $content, $contexts);
                foreach (array_slice($contexts[0], 0, 2) as $context) {
                    $this->output("  → " . trim($context) . "\n", Console::FG_GREY);
                }
            } else {
                $this->output("✓ {$name}: Clean\n", Console::FG_GREEN);
            }
        }

        // Check database (projectconfig)
        $this->output("\nChecking database plugin settings...\n\n", Console::FG_YELLOW);

        $db = Craft::$app->getDb();
        $awsBucket = $this->config->getAwsBucket();
        $rows = $db->createCommand("
            SELECT path, value
            FROM projectconfig
            WHERE path LIKE 'plugins.%'
            AND (value LIKE '%s3.amazonaws%' OR value LIKE :awsBucket)
        ", [':awsBucket' => "%{$awsBucket}%"])->queryAll();

        if (!empty($rows)) {
            $this->output("⚠ Found S3 references in plugin settings:\n", Console::FG_RED);
            foreach ($rows as $row) {
                $this->output("  • {$row['path']}\n", Console::FG_GREY);
            }
        } else {
            $this->output("✓ No S3 references in plugin settings\n", Console::FG_GREEN);
        }

        // Summary
        $this->output("\n" . str_repeat("-", 80) . "\n");
        if (empty($matches) && empty($rows)) {
            $this->output("✓ All plugin configurations are clean!\n\n", Console::FG_GREEN);
        } else {
            $this->output("⚠ Found S3 references in " . count($matches) . " config files\n", Console::FG_YELLOW);
            $this->output("⚠ Manual review and update required\n\n", Console::FG_YELLOW);
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }
}
