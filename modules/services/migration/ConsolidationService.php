<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\ChangeLogManager;
use csabourin\spaghettiMigrator\services\CheckpointManager;
use csabourin\spaghettiMigrator\services\ErrorRecoveryManager;
use csabourin\spaghettiMigrator\services\migration\FileOperationsService;
use csabourin\spaghettiMigrator\services\migration\MigrationReporter;
use csabourin\spaghettiMigrator\services\ProgressTracker;

/**
 * Consolidation Service
 *
 * Consolidates used files to correct locations by:
 * - Moving assets to target volume and folder
 * - Handling both same-volume and cross-volume moves
 * - Tracking processed assets for resume capability
 * - Skipping assets already in correct location
 *
 * Features:
 * - Batch processing with progress tracking
 * - Transaction safety for atomic operations
 * - Checkpoint support for resume
 * - Idempotency checks to avoid duplicate work
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class ConsolidationService
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
     * @var CheckpointManager Checkpoint manager
     */
    private $checkpointManager;

    /**
     * @var FileOperationsService File operations service
     */
    private $fileOperationsService;

    /**
     * @var MigrationReporter Reporter
     */
    private $reporter;

    /**
     * @var int Progress reporting interval
     */
    private $progressReportingInterval;

    /**
     * @var int Lock refresh interval in seconds
     */
    private $lockRefreshIntervalSeconds;

    /**
     * @var int Batch size
     */
    private $batchSize;

    /**
     * @var $migrationLock Migration lock
     */
    private $migrationLock;

    /**
     * @var array Processed asset IDs (for resume capability)
     */
    private $processedAssetIds = [];

    /**
     * @var array Overall statistics
     */
    private $stats = [
        'files_moved' => 0
    ];

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param MigrationConfig $config Configuration
     * @param ChangeLogManager $changeLogManager Change log manager
     * @param ErrorRecoveryManager $errorRecoveryManager Error recovery manager
     * @param CheckpointManager $checkpointManager Checkpoint manager
     * @param FileOperationsService $fileOperationsService File operations service
     * @param MigrationReporter $reporter Reporter
     * @param $migrationLock Migration lock
     */
    public function __construct(
        Controller $controller,
        MigrationConfig $config,
        ChangeLogManager $changeLogManager,
        ErrorRecoveryManager $errorRecoveryManager,
        CheckpointManager $checkpointManager,
        FileOperationsService $fileOperationsService,
        MigrationReporter $reporter,
        $migrationLock
    ) {
        $this->controller = $controller;
        $this->config = $config;
        $this->changeLogManager = $changeLogManager;
        $this->errorRecoveryManager = $errorRecoveryManager;
        $this->checkpointManager = $checkpointManager;
        $this->fileOperationsService = $fileOperationsService;
        $this->reporter = $reporter;
        $this->migrationLock = $migrationLock;
        $this->batchSize = $config->getBatchSize();
        $this->progressReportingInterval = $config->getProgressReportInterval();
        $this->lockRefreshIntervalSeconds = $config->getLockRefreshIntervalSeconds();
    }

    /**
     * Consolidate used files in batches
     *
     * Moves assets to the target volume and folder, handling both
     * same-volume and cross-volume moves with transaction safety.
     *
     * @param array $assets Assets to consolidate
     * @param $targetVolume Target volume instance
     * @param $targetRootFolder Target folder instance
     * @param callable $saveCheckpoint Checkpoint callback
     * @return array Statistics [moved, skipped]
     */
    public function consolidateUsedFilesBatched(
        array $assets,
        $targetVolume,
        $targetRootFolder,
        callable $saveCheckpoint
    ): array {
        if (empty($assets)) {
            $this->controller->stdout("  No files need consolidation\n\n");
            return ['moved' => 0, 'skipped' => 0];
        }

        // Filter out already processed
        $remainingAssets = array_filter($assets, function ($assetData) {
            return !$this->isAssetProcessed($assetData['id']);
        });

        if (empty($remainingAssets)) {
            $this->controller->stdout("  All assets already consolidated - skipping\n\n", Console::FG_GREEN);
            return ['moved' => 0, 'skipped' => count($assets)];
        }

        $total = count($remainingAssets);
        $skipped = count($assets) - $total;

        if ($skipped > 0) {
            $this->controller->stdout("  Resuming: {$skipped} already processed, {$total} remaining\n", Console::FG_CYAN);
        }

        $this->reporter->printProgressLegend();
        $this->controller->stdout("  Progress: ");

        $progress = new ProgressTracker("Consolidating Files", $total, $this->progressReportingInterval);

        $moved = 0;
        $skippedLocal = 0;
        $processedBatch = [];
        $lastLockRefresh = time();

        foreach ($remainingAssets as $assetData) {
            // Refresh lock periodically
            if (time() - $lastLockRefresh > $this->lockRefreshIntervalSeconds) {
                $this->migrationLock->refresh();
                $lastLockRefresh = time();
            }

            $asset = Asset::findOne($assetData['id']);
            if (!$asset) {
                $this->reporter->safeStdout("?", Console::FG_GREY);
                continue;
            }

            // Skip if already in correct location
            if ($asset->volumeId == $targetVolume->id && $asset->folderId == $targetRootFolder->id) {
                $this->reporter->safeStdout("-", Console::FG_GREY);
                $skippedLocal++;
                $processedBatch[] = $asset->id; // Mark as processed even if skipped

                if ($progress->increment()) {
                    $this->reporter->safeStdout(" " . $progress->getProgressString() . "\n  ");
                }
                continue;
            }

            $result = $this->errorRecoveryManager->retryOperation(
                fn() => $this->consolidateSingleAsset($asset, $assetData, $targetVolume, $targetRootFolder),
                "consolidate_asset_{$asset->id}"
            );

            if ($result['success']) {
                $this->reporter->safeStdout(".", Console::FG_GREEN);
                $moved++;
                $this->stats['files_moved']++;
                $processedBatch[] = $asset->id;
            } else {
                $this->reporter->safeStdout("x", Console::FG_RED);
            }

            // Update progress
            if ($progress->increment()) {
                $this->reporter->safeStdout(" " . $progress->getProgressString() . "\n  ");

                // Update quick state
                if (!empty($processedBatch)) {
                    $this->markAssetsProcessedBatch($processedBatch);
                    $processedBatch = [];
                }
            }

            // Full checkpoint every N items
            if (($moved + $skippedLocal) % ($this->batchSize * 5) === 0) {
                $saveCheckpoint([
                    'moved' => $moved,
                    'skipped' => $skippedLocal
                ]);
            }
        }

        // Final batch update
        if (!empty($processedBatch)) {
            $this->markAssetsProcessedBatch($processedBatch);
        }

        $this->controller->stdout("\n\n  ✓ Moved: {$moved}, Skipped: {$skippedLocal}\n\n", Console::FG_CYAN);

        return ['moved' => $moved, 'skipped' => $skippedLocal];
    }

    /**
     * Consolidate single asset
     *
     * Moves a single asset to the target volume/folder with transaction safety
     * and idempotency checks.
     *
     * @param $asset Asset instance
     * @param array $assetData Asset data array
     * @param $targetVolume Target volume instance
     * @param $targetRootFolder Target folder instance
     * @return array Result ['success' => bool, 'already_done' => bool, 'error' => string]
     */
    private function consolidateSingleAsset($asset, array $assetData, $targetVolume, $targetRootFolder): array
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Idempotency check - reload asset from DB in case it was moved in a previous partial run
            $reloadedAsset = Asset::findOne($asset->id);

            if (!$reloadedAsset) {
                $transaction->rollBack();
                return ['success' => false, 'error' => 'Asset not found'];
            }

            // Check if already in correct location
            if ($reloadedAsset->volumeId == $targetVolume->id && $reloadedAsset->folderId == $targetRootFolder->id) {
                $transaction->commit();
                return ['success' => true, 'already_done' => true];
            }

            $isCrossVolume = $reloadedAsset->volumeId != $targetVolume->id;

            if ($isCrossVolume) {
                $success = $this->fileOperationsService->moveAssetCrossVolume($reloadedAsset, $targetVolume, $targetRootFolder);
            } else {
                $success = $this->fileOperationsService->moveAssetSameVolume($reloadedAsset, $targetRootFolder);
            }

            if ($success) {
                $transaction->commit();

                $this->changeLogManager->logChange([
                    'type' => 'moved_asset',
                    'assetId' => $reloadedAsset->id,
                    'filename' => $reloadedAsset->filename,
                    'fromVolume' => $assetData['volumeId'],
                    'fromFolder' => $assetData['folderId'],
                    'toVolume' => $targetVolume->id,
                    'toFolder' => $targetRootFolder->id
                ]);

                return ['success' => true];
            } else {
                $transaction->rollBack();
                return ['success' => false];
            }

        } catch (\Exception $e) {
            $transaction->rollBack();
            $errorMsg = "Failed to consolidate asset {$asset->id}: " . $e->getMessage();
            $this->fileOperationsService->trackError('consolidate_asset', $errorMsg);
            Craft::warning($errorMsg, __METHOD__);
            // Don't rethrow - return false to continue with next asset
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if asset was already processed
     *
     * @param int $assetId Asset ID
     * @return bool True if already processed
     */
    private function isAssetProcessed(int $assetId): bool
    {
        return in_array($assetId, $this->processedAssetIds);
    }

    /**
     * Mark asset as processed
     *
     * @param int $assetId Asset ID
     */
    private function markAssetProcessed(int $assetId): void
    {
        if (!in_array($assetId, $this->processedAssetIds)) {
            $this->processedAssetIds[] = $assetId;
        }
    }

    /**
     * Mark multiple assets as processed and update quick state
     *
     * @param array $assetIds Array of asset IDs
     */
    private function markAssetsProcessedBatch(array $assetIds): void
    {
        $newIds = array_diff($assetIds, $this->processedAssetIds);
        if (!empty($newIds)) {
            $this->processedAssetIds = array_merge($this->processedAssetIds, $newIds);
            $this->checkpointManager->updateProcessedIds($newIds);
        }
    }

    /**
     * Get processed asset IDs
     *
     * @return array Processed asset IDs
     */
    public function getProcessedAssetIds(): array
    {
        return $this->processedAssetIds;
    }

    /**
     * Set processed asset IDs (for resume)
     *
     * @param array $ids Processed asset IDs
     */
    public function setProcessedAssetIds(array $ids): void
    {
        $this->processedAssetIds = $ids;
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
