<?php

namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\Plugin;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Provider Test Controller
 *
 * Demonstrates the new multi-provider architecture (v2.0).
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
class ProviderTestController extends Controller
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
        $this->stdout("\n");
        $this->stdout("=================================================\n", Console::FG_CYAN);
        $this->stdout("  SPAGHETTI MIGRATOR v2.0 - Provider Test\n", Console::FG_CYAN);
        $this->stdout("=================================================\n", Console::FG_CYAN);
        $this->stdout("\n");

        // Test source provider
        $this->stdout("Testing Source Provider...\n", Console::FG_YELLOW);
        $this->stdout("─────────────────────────────────────────────────\n");
        $sourceResult = $this->testProvider('source');

        $this->stdout("\n");

        // Test target provider
        $this->stdout("Testing Target Provider...\n", Console::FG_YELLOW);
        $this->stdout("─────────────────────────────────────────────────\n");
        $targetResult = $this->testProvider('target');

        $this->stdout("\n");

        // Summary
        $this->stdout("=================================================\n", Console::FG_CYAN);
        $this->stdout("  Summary\n", Console::FG_CYAN);
        $this->stdout("=================================================\n", Console::FG_CYAN);
        $this->stdout("Source: " . ($sourceResult ? "✓ OK" : "✗ FAILED") . "\n", $sourceResult ? Console::FG_GREEN : Console::FG_RED);
        $this->stdout("Target: " . ($targetResult ? "✓ OK" : "✗ FAILED") . "\n", $targetResult ? Console::FG_GREEN : Console::FG_RED);
        $this->stdout("\n");

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

        $this->stdout("\nListing files from {$this->provider} provider ({$providerConfig['type']})...\n", Console::FG_CYAN);
        $this->stdout("Limit: {$this->limit} files\n\n");

        try {
            // Create provider
            $provider = $registry->createProvider($providerConfig['type'], $providerConfig['config']);

            // List objects
            $iterator = $provider->listObjects('', ['maxKeys' => $this->limit]);

            $count = 0;
            foreach ($iterator as $object) {
                $count++;

                $this->stdout(sprintf(
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
                $this->stdout("No files found.\n", Console::FG_YELLOW);
            } else {
                $this->stdout("\nTotal files listed: {$count}\n", Console::FG_GREEN);
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
            $this->stdout("Example: php craft spaghetti-migrator/provider-test/copy-test --source-path=test.jpg --target-path=test-copy.jpg\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $config = MigrationConfig::getInstance();
        $registry = Plugin::getInstance()->providerRegistry;

        $this->stdout("\nCopy Test\n", Console::FG_CYAN);
        $this->stdout("─────────────────────────────────────────────────\n");
        $this->stdout("Source Path: {$this->sourcePath}\n");
        $this->stdout("Target Path: {$this->targetPath}\n\n");

        try {
            // Get source and target providers
            $sourceConfig = $config->getSourceProvider();
            $targetConfig = $config->getTargetProvider();

            $this->stdout("Creating source provider ({$sourceConfig['type']})...\n");
            $sourceProvider = $registry->createProvider($sourceConfig['type'], $sourceConfig['config']);

            $this->stdout("Creating target provider ({$targetConfig['type']})...\n");
            $targetProvider = $registry->createProvider($targetConfig['type'], $targetConfig['config']);

            // Check if source file exists
            $this->stdout("Checking if source file exists...\n");
            if (!$sourceProvider->objectExists($this->sourcePath)) {
                $this->stderr("Error: Source file does not exist: {$this->sourcePath}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            // Get source file metadata
            $metadata = $sourceProvider->getObjectMetadata($this->sourcePath);
            $this->stdout("Source file: {$metadata->getFormattedSize()}, {$metadata->contentType}\n", Console::FG_GREEN);

            // Copy file
            $this->stdout("Copying file...\n");
            $startTime = microtime(true);

            $success = $sourceProvider->copyObject($this->sourcePath, $targetProvider, $this->targetPath);

            $duration = microtime(true) - $startTime;

            if ($success) {
                $this->stdout("✓ Copy successful in " . number_format($duration, 2) . " seconds\n", Console::FG_GREEN);

                // Verify target file exists
                if ($targetProvider->objectExists($this->targetPath)) {
                    $targetMetadata = $targetProvider->getObjectMetadata($this->targetPath);
                    $this->stdout("✓ Target file verified: {$targetMetadata->getFormattedSize()}\n", Console::FG_GREEN);
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

            $this->stdout("Provider Type: {$providerConfig['type']}\n");

            // Create provider instance
            $this->stdout("Creating provider instance...\n");
            $provider = $registry->createProvider($providerConfig['type'], $providerConfig['config']);

            $this->stdout("✓ Provider created successfully\n", Console::FG_GREEN);

            // Get provider info
            $this->stdout("\nProvider Information:\n");
            $this->stdout("  Name: {$provider->getProviderName()}\n");
            $this->stdout("  Bucket: {$provider->getBucket()}\n");
            $this->stdout("  Region: " . ($provider->getRegion() ?? 'N/A') . "\n");

            // Get capabilities
            $capabilities = $provider->getCapabilities();
            $this->stdout("\nCapabilities:\n");
            $this->stdout("  Server-side copy: " . ($capabilities->supportsServerSideCopy ? '✓' : '✗') . "\n");
            $this->stdout("  Versioning: " . ($capabilities->supportsVersioning ? '✓' : '✗') . "\n");
            $this->stdout("  Streaming: " . ($capabilities->supportsStreaming ? '✓' : '✗') . "\n");
            $this->stdout("  Max file size: {$capabilities->toArray()['limits']['max_file_size']}\n");
            $this->stdout("  Optimal batch size: {$capabilities->optimalBatchSize}\n");

            // Test connection
            $this->stdout("\nTesting connection...\n");
            $result = $provider->testConnection();

            if ($result->success) {
                $this->stdout("✓ {$result->message}\n", Console::FG_GREEN);

                if (!empty($result->details)) {
                    foreach ($result->details as $key => $value) {
                        $this->stdout("  {$key}: {$value}\n");
                    }
                }

                if ($result->responseTime !== null) {
                    $this->stdout("  Response time: " . number_format($result->responseTime, 3) . "s\n");
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
