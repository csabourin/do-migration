<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use vaersaagod\dospaces\Fs as DoSpacesFs;
use yii\console\ExitCode;

/**
 * Filesystem setup commands
 * 
 * UPDATED: Added imageTransforms_do for separate transform storage
 */
class FilesystemController extends Controller
{
    /**
     * @var bool Whether to force creation even if filesystems exist
     */
    public $force = false;

    /**
     * @var bool Skip confirmation prompts when deleting filesystems
     */
    public $yes = false;

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
        try {
            $this->config = MigrationConfig::getInstance();
        } catch (\Exception $e) {
            // Provide a helpful error message when config is missing
            $this->stderr("\n" . str_repeat("=", 80) . "\n", Console::FG_RED);
            $this->stderr("⚠️  CONFIGURATION ERROR\n", Console::FG_RED);
            $this->stderr(str_repeat("=", 80) . "\n\n", Console::FG_RED);
            $this->stderr("Migration configuration file not found!\n\n", Console::FG_YELLOW);
            $this->stderr("Please complete the following steps:\n\n");
            $this->stderr("1. Configure environment variables in your .env file:\n", Console::FG_CYAN);
            $this->stderr("   DO_S3_ACCESS_KEY=your_access_key\n");
            $this->stderr("   DO_S3_SECRET_KEY=your_secret_key\n");
            $this->stderr("   DO_S3_BUCKET=your-bucket-name\n");
            $this->stderr("   DO_S3_BASE_URL=https://your-bucket.tor1.digitaloceanspaces.com\n");
            $this->stderr("   DO_S3_BASE_ENDPOINT=tor1.digitaloceanspaces.com\n");
            $this->stderr("   DO_S3_REGION=tor1\n\n");
            $this->stderr("2. Copy the migration config file:\n", Console::FG_CYAN);
            $this->stderr("   cp vendor/ncc/migration-module/modules/config/migration-config.php config/migration-config.php\n\n");
            $this->stderr("Original error: " . $e->getMessage() . "\n\n", Console::FG_GREY);
            exit(ExitCode::CONFIG);
        }
    }

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'force';

        if ($actionID === 'delete') {
            $options[] = 'yes';
        }

        return $options;
    }

    /**
     * Create DigitalOcean Spaces filesystems
     * 
     * UPDATED: Now creates imageTransforms_do for transforms
     * UPDATED: optimisedImages_do will be empty after migration
     */
    public function actionCreate(): int
    {
        $this->stdout("Creating DigitalOcean Spaces filesystems...\n\n", Console::FG_YELLOW);

        $filesystems = $this->getFilesystemConfigs();
        $fsService = Craft::$app->getFs();
        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($filesystems as $config) {
            $handle = $config['handle'];

            // Check if filesystem already exists
            $existing = $fsService->getFilesystemByHandle($handle);

            if ($existing && !$this->force) {
                $this->stdout("  ⊘ Skipping '{$config['name']}' (already exists)\n", Console::FG_GREY);
                $skipped++;
                continue;
            }

            try {
                if ($existing && $this->force) {
                    $this->stdout("  ↻ Updating '{$config['name']}'...\n", Console::FG_BLUE);
                    $fs = $existing;
                } else {
                    $this->stdout("  + Creating '{$config['name']}'...\n", Console::FG_GREEN);
                    $fs = new DoSpacesFs();
                }

                // Configure the filesystem
                $fs->name = $config['name'];
                $fs->handle = $config['handle'];
                $fs->hasUrls = true;
                $fs->url = $config['baseUrl'];

                // DigitalOcean Spaces specific settings (use env var references from config)
                // Config returns full env var references with $ prefix (e.g., "$DO_S3_ACCESS_KEY")
                // These are stored in the database and Craft resolves them at runtime
                $fs->keyId = $this->config->getDoEnvVarAccessKey();
                $fs->secret = $this->config->getDoEnvVarSecretKey();
                $fs->bucket = $this->config->getDoEnvVarBucket();
                $fs->region = $config['region'];
                $fs->subfolder = $config['subfolder'];
                $fs->endpoint = $this->config->getDoEnvVarEndpoint();

                // Save the filesystem
                if (!$fsService->saveFilesystem($fs)) {
                    $this->stderr("  ✗ Failed to save '{$config['name']}'\n", Console::FG_RED);
                    if ($fs->hasErrors()) {
                        foreach ($fs->getErrors() as $attribute => $err) {
                            $this->stderr("    - {$attribute}: " . implode(', ', $err) . "\n", Console::FG_RED);
                        }
                    }
                    $errors++;
                } else {
                    $this->stdout("  ✓ Successfully saved '{$config['name']}'\n", Console::FG_GREEN);
                    $created++;
                }
            } catch (\Exception $e) {
                $this->stderr("  ✗ Error creating '{$config['name']}': {$e->getMessage()}\n", Console::FG_RED);
                $errors++;
            }
        }

        $this->stdout("\n");
        $this->stdout("Summary:\n", Console::FG_YELLOW);
        $this->stdout("  Created/Updated: {$created}\n", Console::FG_GREEN);
        $this->stdout("  Skipped: {$skipped}\n", Console::FG_GREY);
        $this->stdout("  Errors: {$errors}\n", $errors > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout("\n");

        // Machine-readable exit marker for reliable status detection
        if ($errors > 0) {
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * List all configured filesystems
     */
    public function actionList(): int
    {
        $filesystems = Craft::$app->getFs()->getAllFilesystems();

        $this->stdout("Configured Filesystems:\n\n", Console::FG_YELLOW);

        if (empty($filesystems)) {
            $this->stdout("  No filesystems found.\n", Console::FG_GREY);
        } else {
            foreach ($filesystems as $fs) {
                $type = (new \ReflectionClass($fs))->getShortName();
                $this->stdout("  • {$fs->name}\n", Console::FG_GREEN);
                $this->stdout("    Handle: {$fs->handle}\n", Console::FG_GREY);
                $this->stdout("    Type: {$type}\n", Console::FG_GREY);
                if ($fs->hasUrls) {
                    $this->stdout("    URL: {$fs->url}\n", Console::FG_GREY);
                }
                $this->stdout("\n");
            }
        }

        // Machine-readable exit marker for reliable status detection
        $this->stdout("__CLI_EXIT_CODE_0__\n");

        return ExitCode::OK;
    }

    /**
     * Delete all DigitalOcean Spaces filesystems
     */
    public function actionDelete(): int
    {
        if (!$this->yes && !$this->confirm('Are you sure you want to delete all DigitalOcean Spaces filesystems?')) {
            return ExitCode::OK;
        }

        if ($this->yes) {
            $this->stdout("⚠ Auto-confirmed (--yes flag)\n\n", Console::FG_YELLOW);
        }

        $this->stdout("Deleting DigitalOcean Spaces filesystems...\n\n", Console::FG_YELLOW);

        $filesystems = $this->getFilesystemConfigs();
        $fsService = Craft::$app->getFs();
        $deleted = 0;

        foreach ($filesystems as $config) {
            $fs = $fsService->getFilesystemByHandle($config['handle']);

            if ($fs) {
                try {
                    $fsService->deleteFilesystem($fs);
                    $this->stdout("  ✓ Deleted '{$config['name']}'\n", Console::FG_GREEN);
                    $deleted++;
                } catch (\Exception $e) {
                    $this->stderr("  ✗ Error deleting '{$config['name']}': {$e->getMessage()}\n", Console::FG_RED);
                }
            } else {
                $this->stdout("  ⊘ '{$config['name']}' not found\n", Console::FG_GREY);
            }
        }

        $this->stdout("\nDeleted {$deleted} filesystem(s)\n\n", Console::FG_YELLOW);

        // Machine-readable exit marker for reliable status detection
        $this->stdout("__CLI_EXIT_CODE_0__\n");

        return ExitCode::OK;
    }

    /**
     * Update optimisedImages_do filesystem to use subfolder after migration
     *
     * This should be called after all assets have been successfully migrated
     * from volume 4 (optimisedImages) to ensure no files are left at root.
     */
    public function actionUpdateOptimisedImagesSubfolder(): int
    {
        $this->stdout("Updating optimisedImages_do filesystem to use subfolder...\n\n", Console::FG_YELLOW);

        $fsService = Craft::$app->getFs();
        $fs = $fsService->getFilesystemByHandle('optimisedImages_do');

        if (!$fs) {
            $this->stderr("  ✗ optimisedImages_do filesystem not found\n", Console::FG_RED);
            $this->stderr("  Please create it first using: ./craft s3-spaces-migration/filesystem/create\n\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Get target subfolder from config
        $definitions = $this->config->getFilesystemDefinitions();
        $targetSubfolder = null;

        foreach ($definitions as $def) {
            if ($def['handle'] === 'optimisedImages_do' && isset($def['targetSubfolder'])) {
                $targetSubfolder = $def['targetSubfolder'];
                break;
            }
        }

        if (!$targetSubfolder) {
            $this->stderr("  ✗ No targetSubfolder defined for optimisedImages_do in migration-config.php\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        // Parse environment variable to get actual value
        $parsedSubfolder = \Craft::parseEnv($targetSubfolder);

        // Validate that the parsed subfolder is not empty
        if (empty($parsedSubfolder)) {
            $this->stderr("  ✗ Target subfolder resolves to empty value\n", Console::FG_RED);
            $this->stderr("  ENV variable: {$targetSubfolder}\n");
            $this->stderr("  Parsed value: (empty)\n");
            $this->stderr("  Please ensure the environment variable is set correctly in your .env file\n\n");
            return ExitCode::CONFIG;
        }

        $this->stdout("  Current subfolder: " . ($fs->subfolder ?: '(root)') . "\n", Console::FG_GREY);
        $this->stdout("  Target subfolder (ENV): {$targetSubfolder}\n", Console::FG_GREY);
        $this->stdout("  Target subfolder (resolved): {$parsedSubfolder}\n", Console::FG_GREY);

        // Check if there are any assets still linked to optimisedImages volume
        $volumesService = Craft::$app->getVolumes();
        $optimisedVolume = $volumesService->getVolumeByHandle('optimisedImages');

        if ($optimisedVolume) {
            $assetCount = (int) \craft\elements\Asset::find()->volumeId($optimisedVolume->id)->count();

            if ($assetCount > 0) {
                $this->stderr("\n  ⚠ WARNING: {$assetCount} assets still linked to optimisedImages volume (ID: {$optimisedVolume->id})\n", Console::FG_RED);
                $this->stderr("  Please complete the asset migration first before updating the filesystem.\n\n");

                if (!$this->confirm('Do you want to proceed anyway?', false)) {
                    return ExitCode::OK;
                }
            } else {
                $this->stdout("  ✓ No assets linked to optimisedImages volume - safe to proceed\n", Console::FG_GREEN);
            }
        }

        // Update the subfolder with the parsed value
        $fs->subfolder = $parsedSubfolder;

        if (!$fsService->saveFilesystem($fs)) {
            $this->stderr("\n  ✗ Failed to update filesystem\n", Console::FG_RED);
            if ($fs->hasErrors()) {
                foreach ($fs->getErrors() as $attribute => $err) {
                    $this->stderr("    - {$attribute}: " . implode(', ', $err) . "\n", Console::FG_RED);
                }
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n  ✓ Successfully updated optimisedImages_do to use subfolder: {$parsedSubfolder}\n", Console::FG_GREEN);
        $this->stdout("  (from ENV variable: {$targetSubfolder})\n", Console::FG_GREY);
        $this->stdout("  Volume 4 (optimisedImages) no longer points to bucket root\n\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Get filesystem configurations from centralized config
     *
     * UPDATED: Now uses centralized MigrationConfig instead of hardcoded values
     * Config methods return full env var references (e.g., "$DO_S3_BASE_URL")
     */
    private function getFilesystemConfigs(): array
    {
        $definitions = $this->config->getFilesystemDefinitions();
        $region = $this->config->getDoRegion();
        // Get env var reference from config (returns "$DO_S3_BASE_URL")
        $baseUrl = $this->config->getDoEnvVarBaseUrl();

        $configs = [];
        foreach ($definitions as $def) {
            $configs[] = [
                'name' => $def['name'],
                'handle' => $def['handle'],
                'baseUrl' => $baseUrl,
                'subfolder' => $def['subfolder'],
                'region' => $region,
            ];
        }

        return $configs;
    }

}