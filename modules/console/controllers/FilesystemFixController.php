<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\BaseConsoleController;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Fix DO Spaces Filesystem Configuration
 *
 * Fixes SSL issues caused by incorrect endpoint configuration
 *
 * @author Migration Specialist
 * @version 1.0
 */
class FilesystemFixController extends BaseConsoleController
{
    public $defaultAction = 'fix-endpoints';

    /**
     * Fix DO Spaces filesystem endpoint configurations
     *
     * This fixes the common issue where bucket names are duplicated in URLs
     * (e.g., dev-medias-test.dev-medias-test.tor1.digitaloceanspaces.com)
     *
     * The issue occurs when the endpoint includes the bucket name and the SDK
     * prepends the bucket name again, creating: bucket.endpoint
     *
     * CORRECT CONFIGURATION:
     * - endpoint: https://tor1.digitaloceanspaces.com (no bucket name)
     * - bucket: dev-medias-test
     *
     * INCORRECT CONFIGURATION:
     * - endpoint: https://dev-medias-test.tor1.digitaloceanspaces.com (includes bucket)
     * - bucket: dev-medias-test
     * Result: https://dev-medias-test.dev-medias-test.tor1.digitaloceanspaces.com
     */
    public function actionFixEndpoints()
    {
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("FIX DO SPACES FILESYSTEM ENDPOINTS\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $fsService = Craft::$app->getFs();
        $allFilesystems = $fsService->getAllFilesystems();

        $fixed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($allFilesystems as $fs) {
            // Only process DO Spaces filesystems (vaersaagod\dospaces\Fs)
            if (!($fs instanceof \vaersaagod\dospaces\Fs)) {
                continue;
            }

            $this->output("Checking filesystem: {$fs->name} (handle: {$fs->handle})\n", Console::FG_YELLOW);

            try {
                // Get current configuration
                $bucket = $fs->bucket;
                $endpoint = $fs->endpoint;
                $region = $fs->region;

                $this->output("  Current endpoint: {$endpoint}\n", Console::FG_GREY);
                $this->output("  Current bucket: {$bucket}\n", Console::FG_GREY);
                $this->output("  Current region: {$region}\n", Console::FG_GREY);

                // Check if endpoint contains bucket name
                if (strpos($endpoint, $bucket) !== false) {
                    $this->output("  ⚠ Endpoint contains bucket name - needs fixing\n", Console::FG_YELLOW);

                    // Extract region from endpoint if not set
                    if (empty($region)) {
                        // Try to extract region from endpoint like "tor1.digitaloceanspaces.com"
                        if (preg_match('/\/\/([^.]+)\.digitaloceanspaces\.com/', $endpoint, $matches)) {
                            $region = $matches[1];
                            $this->output("  ℹ Detected region: {$region}\n", Console::FG_CYAN);
                        }
                    }

                    // Construct correct endpoint (without bucket name)
                    $correctEndpoint = "https://{$region}.digitaloceanspaces.com";

                    $this->output("  ✓ Correct endpoint should be: {$correctEndpoint}\n", Console::FG_GREEN);

                    // Update the filesystem
                    $fs->endpoint = $correctEndpoint;

                    // Save the filesystem
                    if (Craft::$app->getFs()->saveFilesystem($fs)) {
                        $this->output("  ✓ Filesystem updated successfully\n", Console::FG_GREEN);
                        $fixed++;
                    } else {
                        $this->output("  ✗ Failed to save filesystem\n", Console::FG_RED);
                        $errors++;
                    }
                } else {
                    $this->output("  ✓ Endpoint is correct\n", Console::FG_GREEN);
                    $skipped++;
                }

                $this->output("\n");

            } catch (\Exception $e) {
                $this->output("  ✗ Error processing filesystem: " . $e->getMessage() . "\n", Console::FG_RED);
                $errors++;
            }
        }

        // Summary
        $this->output(str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("SUMMARY\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $this->output("Filesystems fixed: {$fixed}\n", Console::FG_GREEN);
        $this->output("Filesystems already correct: {$skipped}\n", Console::FG_GREY);
        if ($errors > 0) {
            $this->output("Errors: {$errors}\n", Console::FG_RED);
        }

        $this->output("\n");

        if ($fixed > 0) {
            $this->output("✓ Configuration updated. Run migration-check again to verify:\n", Console::FG_GREEN);
            $this->output("  ddev craft spaghetti-migrator/migration-check\n\n", Console::FG_GREY);
        }

        if ($errors > 0) {
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        } else {
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }
    }

    /**
     * Display current filesystem configurations
     */
    public function actionShow()
    {
        $this->output("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->output("DO SPACES FILESYSTEM CONFIGURATIONS\n", Console::FG_CYAN);
        $this->output(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $fsService = Craft::$app->getFs();
        $allFilesystems = $fsService->getAllFilesystems();

        $doSpacesCount = 0;

        foreach ($allFilesystems as $fs) {
            // Only process DO Spaces filesystems
            if (!($fs instanceof \vaersaagod\dospaces\Fs)) {
                continue;
            }

            $doSpacesCount++;

            $this->output("Filesystem: {$fs->name}\n", Console::FG_YELLOW);
            $this->output("  Handle: {$fs->handle}\n", Console::FG_GREY);
            $this->output("  Bucket: {$fs->bucket}\n", Console::FG_GREY);
            $this->output("  Endpoint: {$fs->endpoint}\n", Console::FG_GREY);
            $this->output("  Region: {$fs->region}\n", Console::FG_GREY);
            $this->output("  Subfolder: {$fs->subfolder}\n", Console::FG_GREY);

            // Check if there's a potential issue
            if (strpos($fs->endpoint, $fs->bucket) !== false) {
                $this->output("  ⚠ WARNING: Endpoint contains bucket name - may cause SSL errors\n", Console::FG_RED);
                $this->output("  Expected endpoint: https://{$fs->region}.digitaloceanspaces.com\n", Console::FG_YELLOW);
            } else {
                $this->output("  ✓ Configuration looks correct\n", Console::FG_GREEN);
            }

            $this->output("\n");
        }

        if ($doSpacesCount === 0) {
            $this->output("No DO Spaces filesystems found.\n", Console::FG_YELLOW);
        }

        $this->output("\n");

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }
}
