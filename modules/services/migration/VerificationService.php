<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\ChangeLogManager;
use csabourin\spaghettiMigrator\services\migration\MigrationReporter;

/**
 * Verification Service
 *
 * Verifies migration completeness and correctness by:
 * - Checking that all asset records have corresponding physical files
 * - Queuing asset reindexing for proper Craft integration
 * - Updating optimisedImages subfolder after migration
 * - Exporting missing files to CSV for review
 * - Supporting both full and sample verification modes
 *
 * Features:
 * - Full verification of all assets in target volume
 * - Sample verification for quick checks
 * - Missing file tracking and CSV export
 * - Automatic asset reindexing queue
 * - optimisedImages subfolder configuration update
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class VerificationService
{
    /**
     * @var Controller Controller instance
     */
    private $controller;

    /**
     * @var MigrationConfig Configuration
     */
    private $config;

    /**
     * @var ChangeLogManager Change log manager
     */
    private $changeLogManager;

    /**
     * @var MigrationReporter Reporter
     */
    private $reporter;

    /**
     * @var string Migration ID
     */
    private $migrationId;

    /**
     * @var int|null Verification sample size (null = full verification)
     */
    private $verificationSampleSize;

    /**
     * @var array Missing files tracking
     */
    private $missingFiles = [];

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param MigrationConfig $config Configuration
     * @param ChangeLogManager $changeLogManager Change log manager
     * @param MigrationReporter $reporter Reporter
     * @param string $migrationId Migration ID
     * @param int|null $verificationSampleSize Verification sample size (null = full)
     */
    public function __construct(
        Controller $controller,
        MigrationConfig $config,
        ChangeLogManager $changeLogManager,
        MigrationReporter $reporter,
        string $migrationId,
        ?int $verificationSampleSize = null
    ) {
        $this->controller = $controller;
        $this->config = $config;
        $this->changeLogManager = $changeLogManager;
        $this->reporter = $reporter;
        $this->migrationId = $migrationId;
        $this->verificationSampleSize = $verificationSampleSize;
    }

    /**
     * Perform cleanup and verification
     *
     * Main verification orchestration that:
     * - Queues asset reindexing
     * - Performs verification (full or sample)
     * - Saves issues to file if found
     *
     * @param $targetVolume Target volume instance
     * @param $targetRootFolder Target folder instance
     * @return array Issues found during verification
     */
    public function performCleanupAndVerification($targetVolume, $targetRootFolder): array
    {
        // Note: In Craft 4, asset transforms are handled automatically by the ImageTransforms service
        // No need to manually clear transform indexes - they're regenerated on demand
        $this->controller->stdout("  Transform indexes will regenerate automatically (Craft 4)\n", Console::FG_GREY);

        // Note: In Craft 4, asset indexing is done via queue jobs
        // Trigger a resave of all assets in the target volume to ensure they're properly indexed
        $this->controller->stdout("\n  Triggering asset reindex via resave queue...\n");
        try {
            $assetsService = Craft::$app->getAssets();
            $query = \craft\elements\Asset::find()
                ->volumeId($targetVolume->id)
                ->limit(null);

            $count = $query->count();

            if ($count > 0) {
                // Queue resave elements job for all assets in target volume
                \Craft::$app->getQueue()->push(new \craft\queue\jobs\ResaveElements([
                    'elementType' => \craft\elements\Asset::class,
                    'criteria' => [
                        'volumeId' => $targetVolume->id,
                        'siteId' => '*',
                        'unique' => true,
                        'status' => null,
                    ]
                ]));

                $this->controller->stdout("  ✓ Queued {$count} assets for reindexing\n", Console::FG_GREEN);
                $this->controller->stdout("  Run ./craft queue/run to process the queue\n", Console::FG_CYAN);
            } else {
                $this->controller->stdout("  No assets to reindex\n", Console::FG_GREY);
            }
        } catch (\Exception $e) {
            $this->controller->stdout("  ⚠ Warning: " . $e->getMessage() . "\n", Console::FG_YELLOW);
        }

        $this->controller->stdout("\n  Verifying migration integrity...\n");

        // Determine verification scope
        $verificationLimit = $this->verificationSampleSize;

        if ($verificationLimit === null) {
            $this->controller->stdout("    ⏳ Performing FULL verification (all assets)...\n");
            $issues = $this->verifyMigrationFull($targetVolume, $targetRootFolder);
        } else {
            $this->controller->stdout("    ⏳ Performing SAMPLE verification ({$verificationLimit} assets)...\n");
            $issues = $this->verifyMigrationSample($targetVolume, $targetRootFolder, $verificationLimit);
        }

        if (empty($issues)) {
            $this->controller->stdout("    ✓ No issues found\n\n", Console::FG_GREEN);
        } else {
            $this->controller->stdout("    ⚠ Found " . count($issues) . " potential issues:\n", Console::FG_YELLOW);
            foreach (array_slice($issues, 0, 10) as $issue) {
                $this->controller->stdout("      - {$issue}\n", Console::FG_YELLOW);
            }
            if (count($issues) > 10) {
                $this->controller->stdout("      ... and " . (count($issues) - 10) . " more\n\n", Console::FG_YELLOW);
            }

            // Save issues to file
            $issuesFile = Craft::getAlias('@storage') . '/migration-issues-' . $this->migrationId . '.txt';
            file_put_contents($issuesFile, implode("\n", $issues));
            $this->controller->stdout("    Issues saved to: {$issuesFile}\n\n", Console::FG_CYAN);
        }

        return $issues;
    }

    /**
     * Full verification - checks ALL assets
     *
     * Iterates through all assets in the target volume and verifies
     * that the physical file exists on the filesystem.
     *
     * @param $targetVolume Target volume instance
     * @param $targetRootFolder Target folder instance
     * @return array Issues found
     */
    private function verifyMigrationFull($targetVolume, $targetRootFolder): array
    {
        $issues = [];
        $offset = 0;
        $batchSize = 100;
        $totalChecked = 0;
        $missingCount = 0;
        $errorCount = 0;

        try {
            $fs = $targetVolume->getFs();
        } catch (\Exception $e) {
            $error = "CRITICAL: Cannot access target volume filesystem: " . $e->getMessage();
            $this->controller->stderr("      ✗ {$error}\n", Console::FG_RED);
            Craft::error($error, __METHOD__);
            return [$error];
        }

        $this->controller->stdout("      Progress: ");

        while (true) {
            $assets = Asset::find()
                ->volumeId($targetVolume->id)
                ->folderId($targetRootFolder->id)
                ->limit($batchSize)
                ->offset($offset)
                ->all();

            if (empty($assets)) {
                break;
            }

            foreach ($assets as $asset) {
                try {
                    $assetPath = $asset->getPath();
                    if (!$fs->fileExists($assetPath)) {
                        $issue = sprintf(
                            "Missing file: %s (Asset ID: %d, Volume: %s, Path: %s)",
                            $asset->filename,
                            $asset->id,
                            $targetVolume->name,
                            $assetPath
                        );
                        $issues[] = $issue;
                        $missingCount++;

                        // Log to Craft for debugging
                        Craft::warning($issue, __METHOD__);
                    }
                    $totalChecked++;
                } catch (\Exception $e) {
                    $issue = sprintf(
                        "Cannot verify: %s (Asset ID: %d, Error: %s)",
                        $asset->filename,
                        $asset->id,
                        $e->getMessage()
                    );
                    $issues[] = $issue;
                    $errorCount++;

                    // Log exception for debugging
                    Craft::error($issue, __METHOD__);
                }

                if ($totalChecked % 50 === 0) {
                    $this->reporter->safeStdout(".", Console::FG_GREEN);
                }
            }

            $offset += $batchSize;
        }

        $this->controller->stdout(" [{$totalChecked} assets checked");
        if ($missingCount > 0 || $errorCount > 0) {
            $this->controller->stdout(", {$missingCount} missing, {$errorCount} errors", Console::FG_YELLOW);
        }
        $this->controller->stdout("]\n");

        return $issues;
    }

    /**
     * Sample verification
     *
     * Checks a random sample of assets for quick verification.
     *
     * @param $targetVolume Target volume instance
     * @param $targetRootFolder Target folder instance
     * @param int $limit Sample size
     * @return array Issues found
     */
    private function verifyMigrationSample($targetVolume, $targetRootFolder, int $limit): array
    {
        $issues = [];
        $missingCount = 0;
        $errorCount = 0;

        try {
            $fs = $targetVolume->getFs();
        } catch (\Exception $e) {
            $error = "CRITICAL: Cannot access target volume filesystem: " . $e->getMessage();
            $this->controller->stderr("      ✗ {$error}\n", Console::FG_RED);
            Craft::error($error, __METHOD__);
            return [$error];
        }

        $assets = Asset::find()
            ->volumeId($targetVolume->id)
            ->folderId($targetRootFolder->id)
            ->limit($limit)
            ->all();

        $totalChecked = count($assets);

        foreach ($assets as $asset) {
            try {
                $assetPath = $asset->getPath();
                if (!$fs->fileExists($assetPath)) {
                    $issue = sprintf(
                        "Missing file: %s (Asset ID: %d, Volume: %s, Path: %s)",
                        $asset->filename,
                        $asset->id,
                        $targetVolume->name,
                        $assetPath
                    );
                    $issues[] = $issue;
                    $missingCount++;

                    // Log to Craft for debugging
                    Craft::warning($issue, __METHOD__);
                }
            } catch (\Exception $e) {
                $issue = sprintf(
                    "Cannot verify: %s (Asset ID: %d, Error: %s)",
                    $asset->filename,
                    $asset->id,
                    $e->getMessage()
                );
                $issues[] = $issue;
                $errorCount++;

                // Log exception for debugging
                Craft::error($issue, __METHOD__);
            }
        }

        // Log summary
        if ($missingCount > 0 || $errorCount > 0) {
            $this->controller->stdout(
                "      Sample check: {$totalChecked} assets checked, {$missingCount} missing, {$errorCount} errors\n",
                Console::FG_YELLOW
            );
        }

        return $issues;
    }

    /**
     * Simple verification using configured sample size
     *
     * @param $targetVolume Target volume instance
     * @param $targetRootFolder Target folder instance
     * @return array Issues found
     */
    public function verifyMigration($targetVolume, $targetRootFolder): array
    {
        $sampleSize = $this->config->getVerificationSampleSize();
        return $this->verifyMigrationSample($targetVolume, $targetRootFolder, $sampleSize);
    }

    /**
     * Export missing files to CSV
     *
     * Creates a CSV file with details about missing files for review.
     */
    public function exportMissingFilesToCsv(): void
    {
        if (empty($this->missingFiles)) {
            return;
        }

        try {
            $csvDir = Craft::getAlias('@storage');
            $csvFile = $csvDir . '/migration-missing-files-' . $this->migrationId . '.csv';

            $fp = fopen($csvFile, 'w');
            if (!$fp) {
                throw new \Exception("Could not open CSV file for writing: {$csvFile}");
            }

            // Write CSV header
            fputcsv($fp, ['Asset ID', 'Filename', 'Expected Path', 'Volume ID', 'Linked Type', 'Reason']);

            // Write data rows
            foreach ($this->missingFiles as $missing) {
                fputcsv($fp, [
                    $missing['assetId'],
                    $missing['filename'],
                    $missing['expectedPath'],
                    $missing['volumeId'],
                    $missing['linkedType'],
                    $missing['reason']
                ]);
            }

            fclose($fp);

            $count = count($this->missingFiles);
            $this->controller->stdout("\n  ✓ Exported {$count} missing files to CSV: {$csvFile}\n", Console::FG_CYAN);

        } catch (\Exception $e) {
            Craft::warning("Could not export missing files to CSV: " . $e->getMessage(), __METHOD__);
            $this->controller->stdout("  ⚠ Could not export missing files to CSV\n", Console::FG_YELLOW);
        }
    }

    /**
     * Update filesystem subfolders for volumes migrated from bucket root
     *
     * This is crucial to prevent Craft from re-indexing assets twice.
     * Filesystems with 'targetSubfolder' config start with empty subfolder (root) during migration,
     * then switch to the environment-specific subfolder after all files are migrated.
     *
     * Works for ANY volume with targetSubfolder configuration, not just optimisedImages.
     *
     * @throws \Exception If critical filesystem update fails
     */
    public function updateMigratedFilesystemSubfolders(): void
    {
        $this->controller->stdout("\n  Updating filesystem subfolders for migrated volumes...\n", Console::FG_CYAN);

        $fsService = Craft::$app->getFs();
        $definitions = $this->config->getFilesystemDefinitions();

        $updated = 0;
        $skipped = 0;
        $errors = [];

        // First pass: validate all filesystems before making any changes
        $validatedFilesystems = [];
        foreach ($definitions as $def) {
            // Skip if no targetSubfolder specified
            if (empty($def['targetSubfolder'])) {
                continue;
            }

            $handle = $def['handle'];
            $targetSubfolder = $def['targetSubfolder'];

            $this->controller->stdout("\n  Validating filesystem: {$handle}\n");

            // Check if filesystem exists
            $fs = $fsService->getFilesystemByHandle($handle);
            if (!$fs) {
                $error = "Filesystem '{$handle}' not found in Craft installation";
                $this->controller->stdout("    ⚠ {$error}\n", Console::FG_YELLOW);
                $errors[] = [
                    'handle' => $handle,
                    'error' => $error,
                    'severity' => 'warning',
                    'can_skip' => true
                ];
                $skipped++;
                continue;
            }

            // Parse environment variable to get actual value
            $parsedSubfolder = \Craft::parseEnv($targetSubfolder);

            // Validate that the parsed subfolder is not empty
            if (empty($parsedSubfolder)) {
                $error = "Target subfolder resolves to empty value";
                $this->controller->stderr("    ✗ {$error}\n", Console::FG_RED);
                $this->controller->stderr("      Config value: {$targetSubfolder}\n");
                $this->controller->stderr("      Parsed value: (empty)\n");
                $this->controller->stderr("      Filesystem: {$handle}\n");

                // Check if this looks like an environment variable
                if (preg_match('/^\$[A-Z_]+$/', $targetSubfolder)) {
                    $this->controller->stderr("      ACTION REQUIRED: Set the environment variable in your .env file\n");
                    $this->controller->stderr("      Example: " . substr($targetSubfolder, 1) . "=your/subfolder/path\n");
                } else {
                    $this->controller->stderr("      This may indicate a configuration error\n");
                }

                $errors[] = [
                    'handle' => $handle,
                    'error' => $error,
                    'severity' => 'error',
                    'can_skip' => false,
                    'env_variable' => $targetSubfolder
                ];
                $skipped++;
                continue;
            }

            // Validation passed - add to list for update
            $validatedFilesystems[] = [
                'handle' => $handle,
                'fs' => $fs,
                'targetSubfolder' => $targetSubfolder,
                'parsedSubfolder' => $parsedSubfolder,
                'oldSubfolder' => $fs->subfolder
            ];

            $this->controller->stdout("    ✓ Validation passed\n", Console::FG_GREEN);
            $this->controller->stdout("      Current: " . ($fs->subfolder ?: '(root)') . "\n", Console::FG_GREY);
            $this->controller->stdout("      Target (ENV): {$targetSubfolder}\n", Console::FG_GREY);
            $this->controller->stdout("      Target (resolved): {$parsedSubfolder}\n", Console::FG_GREY);
        }

        // Check if we have any critical errors
        $criticalErrors = array_filter($errors, function($err) {
            return $err['severity'] === 'error' && !$err['can_skip'];
        });

        if (!empty($criticalErrors)) {
            $this->controller->stderr("\n  ✗ CRITICAL: Cannot proceed with filesystem updates\n", Console::FG_RED);
            $this->controller->stderr("  " . count($criticalErrors) . " filesystem(s) have critical configuration errors\n", Console::FG_RED);
            $this->controller->stderr("  Fix the errors above and re-run the migration\n\n", Console::FG_RED);

            throw new \Exception(
                "Filesystem subfolder update failed: " . count($criticalErrors) . " critical error(s). " .
                "First error: " . $criticalErrors[0]['error']
            );
        }

        // If no filesystems to update, just report and exit
        if (empty($validatedFilesystems)) {
            if ($skipped > 0) {
                $this->controller->stdout("\n  ⚠ No filesystems were updated ({$skipped} skipped)\n", Console::FG_YELLOW);
            } else {
                $this->controller->stdout("\n  ℹ No filesystems require subfolder updates\n", Console::FG_CYAN);
            }
            return;
        }

        // Second pass: perform the updates
        $this->controller->stdout("\n  Performing filesystem updates...\n", Console::FG_CYAN);

        foreach ($validatedFilesystems as $fsData) {
            $handle = $fsData['handle'];
            $fs = $fsData['fs'];
            $parsedSubfolder = $fsData['parsedSubfolder'];
            $oldSubfolder = $fsData['oldSubfolder'];
            $targetSubfolder = $fsData['targetSubfolder'];

            $this->controller->stdout("\n  Updating filesystem: {$handle}\n");

            // Update the subfolder with the parsed value
            $fs->subfolder = $parsedSubfolder;

            if (!$fsService->saveFilesystem($fs)) {
                $errorDetails = [];
                if ($fs->hasErrors()) {
                    foreach ($fs->getErrors() as $attribute => $attributeErrors) {
                        $errorDetails[] = "{$attribute}: " . implode(', ', $attributeErrors);
                    }
                }

                $errorMessage = "Failed to save filesystem '{$handle}'";
                if (!empty($errorDetails)) {
                    $errorMessage .= ": " . implode('; ', $errorDetails);
                }

                $this->controller->stderr("    ✗ {$errorMessage}\n", Console::FG_RED);
                $this->controller->stderr("    This is a critical error that must be resolved\n", Console::FG_RED);

                // Log detailed error information
                Craft::error("Filesystem save failed: {$errorMessage}", __METHOD__);
                Craft::error("Filesystem details: " . print_r([
                    'handle' => $handle,
                    'class' => get_class($fs),
                    'id' => $fs->id ?? 'new',
                    'subfolder' => $parsedSubfolder,
                    'errors' => $errorDetails
                ], true), __METHOD__);

                throw new \Exception($errorMessage);
            }

            $this->controller->stdout("    ✓ Successfully updated to subfolder: {$parsedSubfolder}\n", Console::FG_GREEN);
            $this->controller->stdout("      (from ENV variable: {$targetSubfolder})\n", Console::FG_GREY);
            $updated++;

            // Log to changelog for rollback capability
            if ($this->changeLogManager) {
                $this->changeLogManager->logChange([
                    'type' => 'filesystem_update',
                    'filesystem' => $handle,
                    'property' => 'subfolder',
                    'old_value' => $oldSubfolder ?: '',
                    'new_value' => $parsedSubfolder,
                    'env_variable' => $targetSubfolder,
                    'timestamp' => time()
                ]);
            }
        }

        // Final summary
        $this->controller->stdout("\n  " . str_repeat("=", 70) . "\n", Console::FG_CYAN);
        $this->controller->stdout("  FILESYSTEM UPDATE SUMMARY\n", Console::FG_CYAN);
        $this->controller->stdout("  " . str_repeat("=", 70) . "\n", Console::FG_CYAN);
        $this->controller->stdout("  ✓ Successfully updated: {$updated} filesystem(s)\n", Console::FG_GREEN);

        if ($skipped > 0) {
            $this->controller->stdout("  ⚠ Skipped: {$skipped} filesystem(s)\n", Console::FG_YELLOW);
        }

        if ($updated > 0) {
            $this->controller->stdout("\n  ✓ Filesystems are now configured with correct subfolders\n", Console::FG_GREEN);
            $this->controller->stdout("  This prevents Craft from re-indexing assets after migration\n", Console::FG_GREEN);
        }

        $this->controller->stdout("  " . str_repeat("=", 70) . "\n\n", Console::FG_CYAN);
    }

    /**
     * Legacy method - calls the generic version
     * @deprecated Use updateMigratedFilesystemSubfolders() instead
     */
    public function updateOptimisedImagesSubfolder(): void
    {
        $this->updateMigratedFilesystemSubfolders();
    }

    /**
     * Add missing file to tracking
     *
     * @param array $fileData File data
     */
    public function addMissingFile(array $fileData): void
    {
        $this->missingFiles[] = $fileData;
    }

    /**
     * Get missing files
     *
     * @return array Missing files
     */
    public function getMissingFiles(): array
    {
        return $this->missingFiles;
    }

    /**
     * Set missing files (for resume)
     *
     * @param array $files Missing files
     */
    public function setMissingFiles(array $files): void
    {
        $this->missingFiles = $files;
    }
}
