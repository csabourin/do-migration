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
use csabourin\spaghettiMigrator\services\ProgressTracker;
use csabourin\spaghettiMigrator\services\migration\FileOperationsService;
use csabourin\spaghettiMigrator\services\migration\InventoryBuilder;

/**
 * Link Repair Service
 *
 * Fixes broken asset-file links using exact matching strategies only:
 * 1. Exact filename match (same volume) - 100% certainty
 * 2. Exact filename match (any volume) - 100% certainty
 *
 * Probabilistic strategies (case-insensitive, normalized, basename,
 * size-based, fuzzy) are disabled to prevent incorrect file associations
 * that cause files to go missing after migration.
 *
 * Features:
 * - Only accepts 100% certainty matches
 * - Originals folder priority
 * - Full audit trail
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class LinkRepairService
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
     * @var CheckpointManager Checkpoint manager
     */
    private $checkpointManager;

    /**
     * @var ErrorRecoveryManager Error recovery manager
     */
    private $errorRecoveryManager;

    /**
     * @var FileOperationsService File operations service
     */
    private $fileOpsService;

    /**
     * @var InventoryBuilder Inventory builder
     */
    private $inventoryBuilder;

    /**
     * @var int Progress reporting interval
     */
    private $progressReportingInterval;

    /**
     * @var int Lock refresh interval in seconds
     */
    private $lockRefreshIntervalSeconds;

    /**
     * @var float Minimum confidence for fuzzy matching
     */
    private $fuzzyMatchMinConfidence;

    /**
     * @var float Warning confidence threshold for fuzzy matching
     */
    private $fuzzyMatchWarnConfidence;

    /**
     * @var int Batch size
     */
    private $batchSize;

    /**
     * @var $migrationLock Migration lock
     */
    private $migrationLock;

    /**
     * @var array Processed asset IDs
     */
    private $processedAssetIds = [];

    /**
     * @var array Statistics
     */
    private $stats = [];

    /**
     * @var array Missing files for CSV export
     */
    private $missingFiles = [];

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param MigrationConfig $config Configuration
     * @param ChangeLogManager $changeLogManager Change log manager
     * @param CheckpointManager $checkpointManager Checkpoint manager
     * @param ErrorRecoveryManager $errorRecoveryManager Error recovery manager
     * @param FileOperationsService $fileOpsService File operations service
     * @param InventoryBuilder $inventoryBuilder Inventory builder
     * @param $migrationLock Migration lock
     */
    public function __construct(
        Controller $controller,
        MigrationConfig $config,
        ChangeLogManager $changeLogManager,
        CheckpointManager $checkpointManager,
        ErrorRecoveryManager $errorRecoveryManager,
        FileOperationsService $fileOpsService,
        InventoryBuilder $inventoryBuilder,
        $migrationLock
    ) {
        $this->controller = $controller;
        $this->config = $config;
        $this->changeLogManager = $changeLogManager;
        $this->checkpointManager = $checkpointManager;
        $this->errorRecoveryManager = $errorRecoveryManager;
        $this->fileOpsService = $fileOpsService;
        $this->inventoryBuilder = $inventoryBuilder;
        $this->migrationLock = $migrationLock;
        $this->progressReportingInterval = $config->getProgressReportInterval();
        $this->lockRefreshIntervalSeconds = $config->getLockRefreshIntervalSeconds();
        $this->fuzzyMatchMinConfidence = $config->getFuzzyMatchMinConfidence();
        $this->fuzzyMatchWarnConfidence = $config->getFuzzyMatchWarnConfidence();
        $this->batchSize = $config->getBatchSize();
    }

    /**
     * Fix broken links in batches
     *
     * @param array $brokenLinks Broken asset links
     * @param array $fileInventory File inventory
     * @param array $sourceVolumes Source volumes
     * @param $targetVolume Target volume
     * @param $targetRootFolder Target root folder
     * @param callable $saveCheckpoint Checkpoint callback
     * @return array Statistics
     */
    public function fixBrokenLinksBatched(
        array $brokenLinks,
        array $fileInventory,
        array $sourceVolumes,
        $targetVolume,
        $targetRootFolder,
        callable $saveCheckpoint
    ): array {
        if (empty($brokenLinks)) {
            return ['fixed' => 0, 'not_found' => 0];
        }

        // Filter processed assets
        $remainingLinks = array_filter($brokenLinks, function ($assetData) {
            return !in_array($assetData['id'], $this->processedAssetIds);
        });

        if (empty($remainingLinks)) {
            $this->controller->stdout("  All broken links already processed - skipping\n\n", Console::FG_GREEN);
            return ['fixed' => 0, 'not_found' => 0];
        }

        $total = count($remainingLinks);
        $skipped = count($brokenLinks) - $total;

        if ($skipped > 0) {
            $this->controller->stdout("  Resuming: {$skipped} already processed, {$total} remaining\n", Console::FG_CYAN);
        }

        $this->controller->stdout("\n");

        $searchIndexes = $this->inventoryBuilder->buildFileSearchIndexes($fileInventory);
        $progress = new ProgressTracker("Fixing Broken Links", $total, $this->progressReportingInterval);

        $fixed = 0;
        $notFound = 0;
        $processedBatch = [];
        $lastLockRefresh = time();
        $counter = 0;

        foreach ($remainingLinks as $assetData) {
            $counter++;

            // Refresh lock periodically
            if (time() - $lastLockRefresh > $this->lockRefreshIntervalSeconds) {
                $this->migrationLock->refresh();
                $lastLockRefresh = time();
            }

            $asset = Asset::findOne($assetData['id']);
            if (!$asset) {
                $this->controller->stdout("  [{$counter}/{$total}] Asset not found in database (ID: {$assetData['id']})\n", Console::FG_GREY);
                continue;
            }

            $result = $this->errorRecoveryManager->retryOperation(
                fn() => $this->fixSingleBrokenLink($asset, $fileInventory, $searchIndexes, $sourceVolumes, $targetVolume, $targetRootFolder, $assetData),
                "fix_broken_link_{$asset->id}"
            );

            if ($result['fixed']) {
                $statusMsg = $result['action'] ?? 'Fixed';
                $this->controller->stdout("  [{$counter}/{$total}] ✓ {$statusMsg}: {$asset->filename}", Console::FG_GREEN);
                if (isset($result['details'])) {
                    $this->controller->stdout(" - {$result['details']}", Console::FG_GREY);
                }
                $this->controller->stdout("\n");
                $fixed++;
                $this->stats['assets_updated'] = ($this->stats['assets_updated'] ?? 0) + 1;
                $processedBatch[] = $asset->id;
            } else {
                $this->controller->stdout("  [{$counter}/{$total}] ✗ File not found: {$asset->filename}", Console::FG_YELLOW);
                if (isset($result['reason'])) {
                    $this->controller->stdout(" - {$result['reason']}", Console::FG_GREY);
                }
                $this->controller->stdout("\n");
                $notFound++;
            }

            // Update progress
            if ($progress->increment()) {
                if (!empty($processedBatch)) {
                    $this->checkpointManager->updateProcessedIds($processedBatch);
                    $this->processedAssetIds = array_merge($this->processedAssetIds, $processedBatch);
                    $processedBatch = [];
                }
            }

            // Full checkpoint
            if (($fixed + $notFound) % ($this->batchSize * 5) === 0) {
                $saveCheckpoint([
                    'fixed' => $fixed,
                    'not_found' => $notFound
                ]);
            }
        }

        // Final batch update
        if (!empty($processedBatch)) {
            $this->checkpointManager->updateProcessedIds($processedBatch);
        }

        $this->controller->stdout("\n\n  ✓ Fixed: {$fixed}, Not found: {$notFound}\n\n", Console::FG_CYAN);

        return ['fixed' => $fixed, 'not_found' => $notFound];
    }

    /**
     * Fix single broken link
     *
     * @param $asset Asset instance
     * @param array $fileInventory File inventory
     * @param array $searchIndexes Search indexes
     * @param array $sourceVolumes Source volumes
     * @param $targetVolume Target volume
     * @param $targetRootFolder Target root folder
     * @param array $assetData Asset data
     * @return array Result
     */
    private function fixSingleBrokenLink(
        $asset,
        array $fileInventory,
        array $searchIndexes,
        array $sourceVolumes,
        $targetVolume,
        $targetRootFolder,
        array $assetData
    ): array {
        // Check if file exists on any source volume
        foreach ($sourceVolumes as $sourceVolume) {
            try {
                $sourceFs = $sourceVolume->getFs();
                $expectedPath = trim($assetData['folderPath'], '/') . '/' . $asset->filename;

                if ($sourceFs->fileExists($expectedPath)) {
                    return $this->updateAssetPath($asset, $expectedPath, $sourceVolume, $assetData);
                }
            } catch (\Exception $e) {
                Craft::warning("Could not verify source file existence: " . $e->getMessage(), __METHOD__);
            }
        }

        // Attempt to find alternative matches
        $matchResult = $this->findFileForAsset($asset, $fileInventory, $searchIndexes, $targetVolume, $assetData);

        if (!$matchResult['found']) {
            // Track missing file
            $this->missingFiles[] = [
                'assetId' => $asset->id,
                'filename' => $asset->filename,
                'expectedPath' => $assetData['folderPath'] . '/' . $asset->filename,
                'volumeId' => $assetData['volumeId'],
                'linkedType' => 'asset',
                'reason' => 'File not found with any matching strategy'
            ];

            $this->changeLogManager->logChange([
                'type' => 'broken_link_not_fixed',
                'assetId' => $asset->id,
                'filename' => $asset->filename,
                'reason' => 'File not found with any matching strategy',
                'rejected_match' => $matchResult['rejected_match'] ?? null,
                'rejected_confidence' => $matchResult['rejected_confidence'] ?? null
            ]);

            return ['fixed' => false, 'reason' => 'No matching file found'];
        }

        // Warn if low confidence
        if ($matchResult['confidence'] < $this->fuzzyMatchWarnConfidence) {
            $this->controller->stdout("⚠", Console::FG_YELLOW);
            Craft::warning("Using low-confidence match ({$matchResult['confidence']}): '{$matchResult['file']['filename']}' for '{$asset->filename}'", __METHOD__);
        }

        $sourceFile = $matchResult['file'];
        $isFromOriginals = $this->fileOpsService->isInOriginalsFolder($sourceFile['path']);

        try {
            $success = $this->fileOpsService->copyFileToAsset($sourceFile, $asset, $targetVolume, $targetRootFolder);

            if ($success === 'already_copied') {
                return [
                    'fixed' => true,
                    'action' => 'Already migrated',
                    'details' => "source file {$sourceFile['volumeName']}/{$sourceFile['path']} was copied by another asset"
                ];
            }

            if ($success) {
                if ($isFromOriginals) {
                    $this->stats['originals_moved'] = ($this->stats['originals_moved'] ?? 0) + 1;
                }

                $this->changeLogManager->logChange([
                    'type' => 'fixed_broken_link',
                    'assetId' => $asset->id,
                    'filename' => $asset->filename,
                    'matchedFile' => $sourceFile['filename'],
                    'sourceVolume' => $sourceFile['volumeName'],
                    'sourcePath' => $sourceFile['path'],
                    'matchStrategy' => $matchResult['strategy'],
                    'confidence' => $matchResult['confidence'],
                    'fromOriginals' => $isFromOriginals,
                ]);

                $action = $isFromOriginals ? 'Moved from originals' : 'Copied file';
                return [
                    'fixed' => true,
                    'action' => $action,
                    'details' => "from {$sourceFile['volumeName']}/{$sourceFile['path']} (confidence: " . round($matchResult['confidence'] * 100) . "%)"
                ];
            }

        } catch (\Exception $e) {
            $this->fileOpsService->trackError('fix_broken_link', $e->getMessage(), ['assetId' => $asset->id]);
            return ['fixed' => false, 'reason' => $e->getMessage()];
        }

        return ['fixed' => false, 'reason' => 'Unknown error'];
    }

    /**
     * Update asset path (when file exists but record is incorrect)
     *
     * @param $asset Asset instance
     * @param string $path Correct path
     * @param $volume Correct volume
     * @param array $assetData Asset data
     * @return array Result
     */
    private function updateAssetPath($asset, string $path, $volume, array $assetData): array
    {
        $asset->volumeId = $volume->id;
        $success = Craft::$app->getElements()->saveElement($asset);

        if ($success) {
            $this->changeLogManager->logChange([
                'type' => 'updated_asset_path',
                'assetId' => $asset->id,
                'filename' => $asset->filename,
                'newVolume' => $volume->name,
                'newPath' => $path
            ]);

            return [
                'fixed' => true,
                'action' => 'Updated path',
                'details' => "file exists at {$path}"
            ];
        }

        return ['fixed' => false, 'reason' => 'Could not save asset'];
    }

    /**
     * Find file for asset using multiple strategies
     *
     * @param $asset Asset instance
     * @param array $fileInventory File inventory
     * @param array $searchIndexes Search indexes
     * @param $targetVolume Target volume
     * @param array $assetData Asset data
     * @return array Match result
     */
    public function findFileForAsset($asset, array $fileInventory, array $searchIndexes, $targetVolume, array $assetData): array
    {
        $filename = $asset->filename;

        // Strategy 1: Exact match in same volume (100% certainty)
        $matches = $searchIndexes['exact'][$filename] ?? [];
        $sameVolumeMatches = array_filter($matches, fn($f) => $f['volumeId'] == $assetData['volumeId']);
        if (!empty($sameVolumeMatches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($sameVolumeMatches, $targetVolume),
                'strategy' => 'exact',
                'confidence' => 1.0
            ];
        }

        // Strategy 2: Exact match in any volume (100% certainty - same filename)
        if (!empty($matches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($matches, $targetVolume),
                'strategy' => 'exact',
                'confidence' => 1.0
            ];
        }

        // Strategies 3-7 (case-insensitive, normalized, basename, size-based, fuzzy)
        // are disabled to ensure 100% certainty on all file replacements.
        // Probabilistic matching was causing files to go missing after migration
        // by incorrectly associating assets with wrong files.

        return ['found' => false, 'file' => null, 'strategy' => 'none', 'confidence' => 0.0];
    }

    /**
     * Fuzzy matching with Levenshtein distance
     *
     * @param string $filename Filename to match
     * @param array $fileInventory File inventory
     * @param int $maxDistance Max Levenshtein distance
     * @return array Matching files
     */
    private function findFuzzyMatches(string $filename, array $fileInventory, int $maxDistance = 5): array
    {
        $matches = [];
        $filenameLength = strlen($filename);

        // Pre-filter by length
        $minLength = (int) ($filenameLength * 0.7);
        $maxLength = (int) ($filenameLength * 1.3);

        // Pre-filter by first 3 characters
        $prefix = strlen($filename) >= 3 ? strtolower(substr($filename, 0, 3)) : strtolower($filename);

        $candidates = [];
        foreach ($fileInventory as $file) {
            $candidateFilename = $file['filename'];
            $candidateLength = strlen($candidateFilename);

            if ($candidateLength < $minLength || $candidateLength > $maxLength) {
                continue;
            }

            if (strlen($candidateFilename) >= 3) {
                $candidatePrefix = strtolower(substr($candidateFilename, 0, 3));
                if (levenshtein($prefix, $candidatePrefix) > 2) {
                    continue;
                }
            }

            $candidates[] = $file;
        }

        $filenameLower = strtolower($filename);
        foreach ($candidates as $file) {
            $candidateFilename = $file['filename'];
            $distance = levenshtein($filenameLower, strtolower($candidateFilename));

            if ($distance <= $maxDistance) {
                $matches[] = [
                    'file' => $file,
                    'distance' => $distance,
                    'similarity' => 1 - ($distance / max($filenameLength, strlen($candidateFilename)))
                ];
            }
        }

        usort($matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_map(fn($m) => $m['file'], array_slice($matches, 0, 5));
    }

    /**
     * Prioritize file from matches
     *
     * Priority:
     * 1. Files in originals folders
     * 2. Files in target volume
     * 3. Newer files
     *
     * @param array $files File matches
     * @param $targetVolume Target volume
     * @return array Best file
     */
    private function prioritizeFile(array $files, $targetVolume): array
    {
        $files = array_values($files);

        usort($files, function ($a, $b) use ($targetVolume) {
            // Priority 1: Originals
            $aIsOriginal = $this->fileOpsService->isInOriginalsFolder($a['path']);
            $bIsOriginal = $this->fileOpsService->isInOriginalsFolder($b['path']);

            if ($aIsOriginal !== $bIsOriginal) {
                return $bIsOriginal - $aIsOriginal;
            }

            // Priority 2: Target volume
            $aIsTarget = ($a['volumeId'] === $targetVolume->id) ? 1 : 0;
            $bIsTarget = ($b['volumeId'] === $targetVolume->id) ? 1 : 0;

            if ($aIsTarget !== $bIsTarget) {
                return $bIsTarget - $aIsTarget;
            }

            // Priority 3: Newer files
            $aTime = $a['lastModified'] ?? 0;
            $bTime = $b['lastModified'] ?? 0;

            return $bTime - $aTime;
        });

        return $files[0];
    }

    /**
     * Get extension family
     *
     * @param string $extension File extension
     * @return array Extension family
     */
    private function getExtensionFamily(string $extension): array
    {
        $extension = strtolower($extension);

        $families = [
            'jpg' => ['jpg', 'jpeg'],
            'jpeg' => ['jpg', 'jpeg'],
            'png' => ['png'],
            'gif' => ['gif'],
            'webp' => ['webp'],
            'svg' => ['svg'],
        ];

        return $families[$extension] ?? [$extension];
    }

    /**
     * Calculate similarity between two filenames
     *
     * @param string $filename1 First filename
     * @param string $filename2 Second filename
     * @return float Similarity score (0.0 to 1.0)
     */
    private function calculateSimilarity(string $filename1, string $filename2): float
    {
        $normalized1 = $this->inventoryBuilder->normalizeFilename($filename1);
        $normalized2 = $this->inventoryBuilder->normalizeFilename($filename2);

        similar_text($normalized1, $normalized2, $percent);

        return $percent / 100.0;
    }

    /**
     * Get missing files for CSV export
     *
     * @return array Missing files
     */
    public function getMissingFiles(): array
    {
        return $this->missingFiles;
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
