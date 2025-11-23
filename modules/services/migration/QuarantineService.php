<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\ChangeLogManager;
use csabourin\spaghettiMigrator\services\ErrorRecoveryManager;
use csabourin\spaghettiMigrator\services\migration\FileOperationsService;
use csabourin\spaghettiMigrator\services\migration\MigrationReporter;

/**
 * Quarantine Service
 *
 * Quarantines unused files and assets by:
 * - Moving orphaned files (no asset record) to quarantine volume
 * - Moving unused assets (no relations) to quarantine volume
 * - Preserving original filenames during quarantine
 * - Restoring filenames if Craft modifies them
 *
 * Features:
 * - Batch processing with error recovery
 * - Checkpoint support for resume
 * - Filename preservation verification
 * - Complete audit trail via change log
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class QuarantineService
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
     * @var ErrorRecoveryManager Error recovery manager
     */
    private $errorRecoveryManager;

    /**
     * @var FileOperationsService File operations service
     */
    private $fileOperationsService;

    /**
     * @var MigrationReporter Reporter
     */
    private $reporter;

    /**
     * @var int Batch size
     */
    private $batchSize;

    /**
     * @var int Checkpoint frequency
     */
    private $checkpointEveryBatches;

    /**
     * @var array Processed IDs (for resume capability)
     */
    private $processedIds = [];

    /**
     * @var array Overall statistics
     */
    private $stats = [
        'files_quarantined' => 0,
        'missing_files' => 0
    ];

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param MigrationConfig $config Configuration
     * @param ChangeLogManager $changeLogManager Change log manager
     * @param ErrorRecoveryManager $errorRecoveryManager Error recovery manager
     * @param FileOperationsService $fileOperationsService File operations service
     * @param MigrationReporter $reporter Reporter
     */
    public function __construct(
        Controller $controller,
        MigrationConfig $config,
        ChangeLogManager $changeLogManager,
        ErrorRecoveryManager $errorRecoveryManager,
        FileOperationsService $fileOperationsService,
        MigrationReporter $reporter
    ) {
        $this->controller = $controller;
        $this->config = $config;
        $this->changeLogManager = $changeLogManager;
        $this->errorRecoveryManager = $errorRecoveryManager;
        $this->fileOperationsService = $fileOperationsService;
        $this->reporter = $reporter;
        $this->batchSize = $config->getBatchSize();
        $this->checkpointEveryBatches = $config->getCheckpointEveryBatches();
    }

    /**
     * Quarantine unused files in batches
     *
     * Handles both orphaned files (no asset record) and unused assets (no relations).
     *
     * @param array $orphanedFiles Files without asset records
     * @param array $unusedAssets Assets with no relations
     * @param $quarantineVolume Quarantine volume instance
     * @param $quarantineFs Quarantine filesystem instance
     * @param callable $saveCheckpoint Checkpoint callback
     * @return array Statistics [files_quarantined, missing_files]
     */
    public function quarantineUnusedFilesBatched(
        array $orphanedFiles,
        array $unusedAssets,
        $quarantineVolume,
        $quarantineFs,
        callable $saveCheckpoint
    ): array {
        $totalQuarantined = 0;
        $totalMissing = 0;

        // Quarantine orphaned files
        if (!empty($orphanedFiles)) {
            $result = $this->quarantineOrphanedFiles(
                $orphanedFiles,
                $quarantineFs,
                $saveCheckpoint
            );
            $totalQuarantined += $result['quarantined'];
            $totalMissing += $result['missing'];
        }

        // Quarantine unused assets
        if (!empty($unusedAssets)) {
            $result = $this->quarantineUnusedAssets(
                $unusedAssets,
                $quarantineVolume,
                $saveCheckpoint
            );
            $totalQuarantined += $result['quarantined'];
        }

        return [
            'files_quarantined' => $totalQuarantined,
            'missing_files' => $totalMissing
        ];
    }

    /**
     * Quarantine orphaned files
     *
     * @param array $orphanedFiles Files without asset records
     * @param $quarantineFs Quarantine filesystem instance
     * @param callable $saveCheckpoint Checkpoint callback
     * @return array Statistics [quarantined, missing]
     */
    private function quarantineOrphanedFiles(
        array $orphanedFiles,
        $quarantineFs,
        callable $saveCheckpoint
    ): array {
        $this->controller->stdout("\n  Quarantining orphaned files...\n");
        $this->controller->stdout("  Progress: ");

        $total = count($orphanedFiles);
        $quarantined = 0;
        $missing = 0;

        foreach (array_chunk($orphanedFiles, $this->batchSize) as $batchNum => $batch) {
            foreach ($batch as $file) {
                $result = $this->errorRecoveryManager->retryOperation(
                    fn() => $this->quarantineSingleFile($file, $quarantineFs, 'orphaned'),
                    "quarantine_file_{$file['filename']}"
                );

                if ($result['success']) {
                    $this->reporter->safeStdout(".", Console::FG_YELLOW);
                    $quarantined++;
                    $this->stats['files_quarantined']++;
                } elseif (isset($result['error']) && $result['error'] === 'file_not_found') {
                    $this->reporter->safeStdout("!", Console::FG_RED);
                    $missing++;
                } else {
                    $this->reporter->safeStdout("!", Console::FG_RED);
                }

                if ($quarantined % 50 === 0) {
                    $this->reporter->safeStdout(" [{$quarantined}/{$total}]\n  ");
                }
            }

            if ($batchNum % $this->checkpointEveryBatches === 0) {
                $saveCheckpoint([
                    'quarantine_batch' => $batchNum,
                    'quarantined' => $quarantined
                ]);
            }
        }

        $this->controller->stdout("\n  ✓ Quarantined orphaned files: {$quarantined}\n", Console::FG_CYAN);
        if ($missing > 0) {
            $this->controller->stdout("  ⚠ Missing files: {$missing}\n", Console::FG_YELLOW);
        }
        $this->controller->stdout("\n");

        return ['quarantined' => $quarantined, 'missing' => $missing];
    }

    /**
     * Quarantine unused assets
     *
     * @param array $unusedAssets Assets with no relations
     * @param $quarantineVolume Quarantine volume instance
     * @param callable $saveCheckpoint Checkpoint callback
     * @return array Statistics [quarantined]
     */
    private function quarantineUnusedAssets(
        array $unusedAssets,
        $quarantineVolume,
        callable $saveCheckpoint
    ): array {
        $this->controller->stdout("  Quarantining unused assets...\n");
        $this->controller->stdout("  Progress: ");

        $total = count($unusedAssets);
        $quarantined = 0;
        $quarantineRoot = Craft::$app->getAssets()->getRootFolderByVolumeId($quarantineVolume->id);

        foreach (array_chunk($unusedAssets, $this->batchSize) as $batchNum => $batch) {
            foreach ($batch as $assetData) {
                $asset = Asset::findOne($assetData['id']);
                if (!$asset) {
                    $this->reporter->safeStdout("?", Console::FG_GREY);
                    continue;
                }

                // Skip if already processed
                if (in_array($asset->id, $this->processedIds)) {
                    $this->reporter->safeStdout("-", Console::FG_GREY);
                    continue;
                }

                $result = $this->errorRecoveryManager->retryOperation(
                    fn() => $this->quarantineSingleAsset($asset, $assetData, $quarantineVolume, $quarantineRoot),
                    "quarantine_asset_{$asset->id}"
                );

                if ($result['success']) {
                    $this->reporter->safeStdout(".", Console::FG_YELLOW);
                    $quarantined++;
                    $this->stats['files_quarantined']++;
                    $this->processedIds[] = $asset->id;
                } else {
                    $this->reporter->safeStdout("x", Console::FG_RED);
                }

                if ($quarantined % 50 === 0) {
                    $this->reporter->safeStdout(" [{$quarantined}/{$total}]\n  ");
                }
            }

            if ($batchNum % $this->checkpointEveryBatches === 0) {
                $saveCheckpoint([
                    'quarantine_batch' => $batchNum,
                    'quarantined' => $quarantined
                ]);
            }
        }

        $this->controller->stdout("\n  ✓ Quarantined unused assets: {$quarantined}\n\n", Console::FG_CYAN);

        return ['quarantined' => $quarantined];
    }

    /**
     * Quarantine single file
     *
     * Moves an orphaned file (no asset record) to the quarantine volume.
     *
     * @param array $file File data
     * @param $quarantineFs Quarantine filesystem instance
     * @param string $subfolder Subfolder within quarantine
     * @return array Result ['success' => bool, 'error' => string]
     */
    private function quarantineSingleFile(array $file, $quarantineFs, string $subfolder): array
    {
        try {
            $sourceFs = $file['fs'];
            $sourcePath = $file['path'];
            $targetPath = $subfolder . '/' . $file['filename'];

            // Check if source file exists before trying to read
            if (!$sourceFs->fileExists($sourcePath)) {
                $errorMsg = "Source file not found: {$sourcePath}";
                Craft::warning($errorMsg, __METHOD__);
                $this->fileOperationsService->trackError('quarantine_file_missing', $errorMsg);

                // Track in stats
                if (!isset($this->stats['missing_files'])) {
                    $this->stats['missing_files'] = 0;
                }
                $this->stats['missing_files']++;

                return ['success' => false, 'error' => 'file_not_found'];
            }

            // Get file content
            $content = $sourceFs->read($sourcePath);

            // Write to quarantine
            $quarantineFs->write($targetPath, $content, []);

            // Delete from source
            $sourceFs->deleteFile($sourcePath);

            $this->changeLogManager->logChange([
                'type' => 'quarantined_orphaned_file',
                'sourcePath' => $sourcePath,
                'targetPath' => $targetPath,
                'sourceVolume' => $file['volumeName'],
                'size' => $file['size']
            ]);

            return ['success' => true];

        } catch (\Exception $e) {
            $errorMsg = "Failed to quarantine file {$file['filename']}: " . $e->getMessage();
            $this->fileOperationsService->trackError('quarantine_file', $errorMsg);
            Craft::warning($errorMsg, __METHOD__);

            // Don't rethrow - return false to continue with next file
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Quarantine single asset
     *
     * Moves an unused asset to the quarantine volume, preserving the original filename.
     *
     * @param $asset Asset instance
     * @param array $assetData Asset data
     * @param $quarantineVolume Quarantine volume instance
     * @param $quarantineRoot Quarantine root folder
     * @return array Result ['success' => bool]
     */
    private function quarantineSingleAsset($asset, array $assetData, $quarantineVolume, $quarantineRoot): array
    {
        try {
            // Get original file info BEFORE move
            $originalFilename = $asset->filename;
            $originalPath = $asset->getPath();
            $originalVolumeId = $asset->volumeId;

            // Move to quarantine - Craft will preserve filename
            $success = $this->fileOperationsService->moveAssetCrossVolume($asset, $quarantineVolume, $quarantineRoot);

            if ($success) {
                // Verify filename wasn't changed
                $asset = Asset::findOne($asset->id); // Reload

                if ($asset->filename !== $originalFilename) {
                    Craft::warning(
                        "Filename changed during quarantine: '{$originalFilename}' → '{$asset->filename}' " .
                        "(Asset ID: {$asset->id})",
                        __METHOD__
                    );

                    // Try to restore original filename
                    $this->restoreOriginalFilename($asset, $originalFilename, $quarantineVolume);
                }

                $this->changeLogManager->logChange([
                    'type' => 'quarantined_unused_asset',
                    'assetId' => $asset->id,
                    'originalFilename' => $originalFilename,
                    'currentFilename' => $asset->filename,
                    'fromVolume' => $assetData['volumeId'],
                    'fromFolder' => $assetData['folderId'],
                    'originalPath' => $originalPath,
                    'quarantinePath' => $asset->getPath()
                ]);

                return ['success' => true];
            }

            return ['success' => false];

        } catch (\Exception $e) {
            $this->fileOperationsService->trackError('quarantine_asset', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Restore original filename if it was changed
     *
     * If Craft modified the filename during quarantine (due to conflicts),
     * this attempts to restore the original filename.
     *
     * @param $asset Asset instance
     * @param string $originalFilename Original filename
     * @param $volume Volume instance
     */
    private function restoreOriginalFilename($asset, string $originalFilename, $volume): void
    {
        try {
            $fs = $volume->getFs();
            $currentPath = $asset->getPath();
            $targetPath = dirname($currentPath) . '/' . $originalFilename;

            // Check if target path is available
            if (!$fs->fileExists($targetPath)) {
                // Rename file on filesystem
                $content = $fs->read($currentPath);
                $fs->write($targetPath, $content, []);
                $fs->deleteFile($currentPath);

                // Update asset record
                $asset->filename = $originalFilename;
                Craft::$app->getElements()->saveElement($asset);

                Craft::info(
                    "Restored original filename: '{$originalFilename}' for asset {$asset->id}",
                    __METHOD__
                );
            }
        } catch (\Exception $e) {
            Craft::error(
                "Could not restore original filename '{$originalFilename}': " . $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * Get processed IDs
     *
     * @return array Processed IDs
     */
    public function getProcessedIds(): array
    {
        return $this->processedIds;
    }

    /**
     * Set processed IDs (for resume)
     *
     * @param array $ids Processed IDs
     */
    public function setProcessedIds(array $ids): void
    {
        $this->processedIds = $ids;
    }

    /**
     * Get statistics
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
