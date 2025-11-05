<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use modules\helpers\MigrationConfig;
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
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'force';
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

                // DigitalOcean Spaces specific settings
                $fs->keyId = '$DO_S3_ACCESS_KEY';
                $fs->secret = '$DO_S3_SECRET_KEY';
                $fs->bucket = '$DO_S3_BUCKET';
                $fs->region = $config['region'];
                $fs->subfolder = $config['subfolder'];
                $fs->endpoint = '$DO_S3_BASE_URL';

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

        return $errors > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
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

        return ExitCode::OK;
    }

    /**
     * Delete all DigitalOcean Spaces filesystems
     */
    public function actionDelete(): int
    {
        if (!$this->confirm('Are you sure you want to delete all DigitalOcean Spaces filesystems?')) {
            return ExitCode::OK;
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

        return ExitCode::OK;
    }

    /**
     * Get filesystem configurations from centralized config
     *
     * UPDATED: Now uses centralized MigrationConfig instead of hardcoded values
     */
    private function getFilesystemConfigs(): array
    {
        $definitions = $this->config->getFilesystemDefinitions();
        $region = $this->config->getDoRegion();
        $baseUrl = '$DO_S3_BASE_URL';

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