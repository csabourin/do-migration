<?php

namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\BaseConsoleController;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\Plugin;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Provider Test Controller
 *
 * Demonstrates the new multi-provider architecture (v5.0).
 * Use this controller to test provider connections and functionality.
 *
 * Examples:
 *   php craft spaghetti-migrator/provider-test/test-all
 *   php craft spaghetti-migrator/provider-test/test-source
 *   php craft spaghetti-migrator/provider-test/test-target
 *   php craft spaghetti-migrator/provider-test/list-files --provider=source --limit=10
 *   php craft spaghetti-migrator/provider-test/copy-test --source-path=test.jpg --target-path=test-copy.jpg
 *
 * @package csabourin\spaghettiMigrator\console\controllers
 * @since 2.0.0
 */
class ProviderTestController extends BaseConsoleController
{
    public $defaultAction = 'test-all';

    /**
     * @var int Number of files to list
     */
    public $limit = 10;

    /**
     * @var string Provider to test (source or target)
     */
    public $provider = 'source';

    /**
     * @var string Source file path for copy test
     */
    public $sourcePath = '';

    /**
     * @var string Target file path for copy test
     */
    public $targetPath = '';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'list-files') {
            $options[] = 'limit';
            $options[] = 'provider';
        }

        if ($actionID === 'copy-test') {
            $options[] = 'sourcePath';
            $options[] = 'targetPath';
        }

        return $options;
    }

    /**
     * Test all providers (source and target)
     *
     * @return int
     */
    public function actionTestAll(): int
    {
        $this->output("\n");
        $this->output("=================================================\n", Console::FG_CYAN);
        $this->output("  SPAGHETTI MIGRATOR v5.0 - Provider Test\n", Console::FG_CYAN);
        $this->output("=================================================\n", Console::FG_CYAN);
        $this->output("\n");

        // Test source provider
        $this->output("Testing Source Provider...\n", Console::FG_YELLOW);
        $this->output("─────────────────────────────────────────────────\n");
        $sourceResult = $this->testProvider('source');

        $this->output("\n");

        // Test target provider
        $this->output("Testing Target Provider...\n", Console::FG_YELLOW);
        $this->output("─────────────────────────────────────────────────\n");
        $targetResult = $this->testProvider('target');

        $this->output("\n");

        // Summary
        $this->output("=================================================\n", Console::FG_CYAN);
        $this->output("  Summary\n", Console::FG_CYAN);
        $this->output("=================================================\n", Console::FG_CYAN);
        $this->output("Source: " . ($sourceResult ? "✓ OK" : "✗ FAILED") . "\n", $sourceResult ? Console::FG_GREEN : Console::FG_RED);
        $this->output("Target: " . ($targetResult ? "✓ OK" : "✗ FAILED") . "\n", $targetResult ? Console::FG_GREEN : Console::FG_RED);
        $this->output("\n");

        return ($sourceResult && $targetResult) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Test source provider only
     *
     * @return int
     */
    public function actionTestSource(): int
    {
        $result = $this->testProvider('source');
        return $result ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Test target provider only
     *
     * @return int
     */
    public function actionTestTarget(): int
    {
        $result = $this->testProvider('target');
        return $result ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * List files from a provider
     *
     * @return int
     */
    public function actionListFiles(): int
    {
        $config = MigrationConfig::getInstance();
        $registry = Plugin::getInstance()->providerRegistry;

        // Get provider configuration
        $providerConfig = $this->provider === 'source'
            ? $config->getSourceProvider()
            : $config->getTargetProvider();

        $this->output("\nListing files from {$this->provider} provider ({$providerConfig['type']})...\n", Console::FG_CYAN);
        $this->output("Limit: {$this->limit} files\n\n");

        try {
            // Create provider
            $provider = $registry->createProvider($providerConfig['type'], $providerConfig['config']);

            // List objects
            $iterator = $provider->listObjects('', ['maxKeys' => $this->limit]);

            $count = 0;
            foreach ($iterator as $object) {
                $count++;

                $this->output(sprintf(
                    "%3d. %-50s %10s  %s\n",
                    $count,
                    $object->getFilename(),
                    $object->getFormattedSize(),
                    $object->lastModified->format('Y-m-d H:i:s')
                ));

                if ($count >= $this->limit) {
                    break;
                }
            }

            if ($count === 0) {
                $this->output("No files found.\n", Console::FG_YELLOW);
            } else {
                $this->output("\nTotal files listed: {$count}\n", Console::FG_GREEN);
            }

            return ExitCode::OK;

        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Test copying a file between providers
     *
     * @return int
     */
    public function actionCopyTest(): int
    {
        if (empty($this->sourcePath) || empty($this->targetPath)) {
            $this->stderr("Error: --source-path and --target-path are required\n", Console::FG_RED);
            $this->output("Example: php craft spaghetti-migrator/provider-test/copy-test --source-path=test.jpg --target-path=test-copy.jpg\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $config = MigrationConfig::getInstance();
        $registry = Plugin::getInstance()->providerRegistry;

        $this->output("\nCopy Test\n", Console::FG_CYAN);
        $this->output("─────────────────────────────────────────────────\n");
        $this->output("Source Path: {$this->sourcePath}\n");
        $this->output("Target Path: {$this->targetPath}\n\n");

        try {
            // Get source and target providers
            $sourceConfig = $config->getSourceProvider();
            $targetConfig = $config->getTargetProvider();

            $this->output("Creating source provider ({$sourceConfig['type']})...\n");
            $sourceProvider = $registry->createProvider($sourceConfig['type'], $sourceConfig['config']);

            $this->output("Creating target provider ({$targetConfig['type']})...\n");
            $targetProvider = $registry->createProvider($targetConfig['type'], $targetConfig['config']);

            // Check if source file exists
            $this->output("Checking if source file exists...\n");
            if (!$sourceProvider->objectExists($this->sourcePath)) {
                $this->stderr("Error: Source file does not exist: {$this->sourcePath}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            // Get source file metadata
            $metadata = $sourceProvider->getObjectMetadata($this->sourcePath);
            $this->output("Source file: {$metadata->getFormattedSize()}, {$metadata->contentType}\n", Console::FG_GREEN);

            // Copy file
            $this->output("Copying file...\n");
            $startTime = microtime(true);

            $success = $sourceProvider->copyObject($this->sourcePath, $targetProvider, $this->targetPath);

            $duration = microtime(true) - $startTime;

            if ($success) {
                $this->output("✓ Copy successful in " . number_format($duration, 2) . " seconds\n", Console::FG_GREEN);

                // Verify target file exists
                if ($targetProvider->objectExists($this->targetPath)) {
                    $targetMetadata = $targetProvider->getObjectMetadata($this->targetPath);
                    $this->output("✓ Target file verified: {$targetMetadata->getFormattedSize()}\n", Console::FG_GREEN);
                } else {
                    $this->stderr("⚠ Warning: Copy reported success but file not found at target\n", Console::FG_YELLOW);
                }

                return ExitCode::OK;
            } else {
                $this->stderr("✗ Copy failed\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

        } catch (\Exception $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Test a provider (internal method)
     *
     * @param string $providerType 'source' or 'target'
     * @return bool Success
     */
    private function testProvider(string $providerType): bool
    {
        $config = MigrationConfig::getInstance();
        $registry = Plugin::getInstance()->providerRegistry;

        try {
            // Get provider configuration
            $providerConfig = $providerType === 'source'
                ? $config->getSourceProvider()
                : $config->getTargetProvider();

            $this->output("Provider Type: {$providerConfig['type']}\n");

            // Create provider instance
            $this->output("Creating provider instance...\n");
            $provider = $registry->createProvider($providerConfig['type'], $providerConfig['config']);

            $this->output("✓ Provider created successfully\n", Console::FG_GREEN);

            // Get provider info
            $this->output("\nProvider Information:\n");
            $this->output("  Name: {$provider->getProviderName()}\n");
            $this->output("  Bucket: {$provider->getBucket()}\n");
            $this->output("  Region: " . ($provider->getRegion() ?? 'N/A') . "\n");

            // Get capabilities
            $capabilities = $provider->getCapabilities();
            $this->output("\nCapabilities:\n");
            $this->output("  Server-side copy: " . ($capabilities->supportsServerSideCopy ? '✓' : '✗') . "\n");
            $this->output("  Versioning: " . ($capabilities->supportsVersioning ? '✓' : '✗') . "\n");
            $this->output("  Streaming: " . ($capabilities->supportsStreaming ? '✓' : '✗') . "\n");
            $this->output("  Max file size: {$capabilities->toArray()['limits']['max_file_size']}\n");
            $this->output("  Optimal batch size: {$capabilities->optimalBatchSize}\n");

            // Test connection
            $this->output("\nTesting connection...\n");
            $result = $provider->testConnection();

            if ($result->success) {
                $this->output("✓ {$result->message}\n", Console::FG_GREEN);

                if (!empty($result->details)) {
                    foreach ($result->details as $key => $value) {
                        $this->output("  {$key}: {$value}\n");
                    }
                }

                if ($result->responseTime !== null) {
                    $this->output("  Response time: " . number_format($result->responseTime, 3) . "s\n");
                }

                return true;
            } else {
                $this->stderr("✗ {$result->message}\n", Console::FG_RED);

                if ($result->exception) {
                    $this->stderr("  Exception: {$result->exception->getMessage()}\n", Console::FG_RED);
                }

                return false;
            }

        } catch (\Exception $e) {
            $this->stderr("✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            $this->stderr("  Trace: {$e->getTraceAsString()}\n");
            return false;
        }
    }
}
