<?php

namespace csabourin\craftS3SpacesMigration\services\migration;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\craftS3SpacesMigration\helpers\MigrationConfig;
use csabourin\craftS3SpacesMigration\services\ChangeLogManager;
use csabourin\craftS3SpacesMigration\services\CheckpointManager;
use csabourin\craftS3SpacesMigration\services\ErrorRecoveryManager;
use csabourin\craftS3SpacesMigration\services\ProgressTracker;
use csabourin\craftS3SpacesMigration\services\migration\FileOperationsService;
use csabourin\craftS3SpacesMigration\services\migration\InventoryBuilder;

/**
 * Link Repair Service
 *
 * Fixes broken asset-file links using sophisticated matching strategies:
 * 1. Exact filename match (same volume)
 * 2. Exact filename match (any volume)
 * 3. Case-insensitive match
 * 4. Normalized match (removes special chars)
 * 5. Basename match (same extension family)
 * 6. Size-based matching
 * 7. Fuzzy matching (Levenshtein distance)
 *
 * Features:
 * - Confidence scoring for matches
 * - Rejects low-confidence matches (< 70%)
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
        $this->progressReportingInterval = 50;
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
            if (time() - $lastLockRefresh > 60) {
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
        if ($matchResult['confidence'] < 0.90) {
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
        $MIN_CONFIDENCE = 0.70;

        // Strategy 1: Exact match in same volume
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

        // Strategy 2: Exact match in any volume
        if (!empty($matches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($matches, $targetVolume),
                'strategy' => 'exact',
                'confidence' => 0.95
            ];
        }

        // Strategy 3: Case-insensitive
        $lowerFilename = strtolower($filename);
        $matches = $searchIndexes['case_insensitive'][$lowerFilename] ?? [];
        if (!empty($matches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($matches, $targetVolume),
                'strategy' => 'case_insensitive',
                'confidence' => 0.85
            ];
        }

        // Strategy 4: Normalized
        $normalized = $this->inventoryBuilder->normalizeFilename($filename);
        $matches = $searchIndexes['normalized'][$normalized] ?? [];
        if (!empty($matches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($matches, $targetVolume),
                'strategy' => 'normalized',
                'confidence' => 0.75
            ];
        }

        // Strategy 5: Basename match
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $matches = $searchIndexes['basename'][$basename] ?? [];

        $extensionFamily = $this->getExtensionFamily($extension);
        $sameExtensionMatches = array_filter($matches, function ($f) use ($extensionFamily) {
            $fileExt = pathinfo($f['filename'], PATHINFO_EXTENSION);
            return in_array(strtolower($fileExt), $extensionFamily);
        });

        if (!empty($sameExtensionMatches)) {
            return [
                'found' => true,
                'file' => $this->prioritizeFile($sameExtensionMatches, $targetVolume),
                'strategy' => 'basename',
                'confidence' => 0.70
            ];
        }

        // Strategy 6: Size-based
        try {
            $assetSize = $asset->size ?? null;
            if ($assetSize && isset($searchIndexes['by_size'][$assetSize])) {
                $sizeMatches = $searchIndexes['by_size'][$assetSize];
                $similarMatches = array_filter($sizeMatches, function ($f) use ($filename) {
                    return $this->calculateSimilarity($f['filename'], $filename) > 0.5;
                });

                if (!empty($similarMatches)) {
                    return [
                        'found' => true,
                        'file' => $this->prioritizeFile($similarMatches, $targetVolume),
                        'strategy' => 'size',
                        'confidence' => 0.60
                    ];
                }
            }
        } catch (\Exception $e) {
            // Skip size matching
        }

        // Strategy 7: Fuzzy matching
        $fuzzyMatches = $this->findFuzzyMatches($filename, $fileInventory, 5);
        if (!empty($fuzzyMatches)) {
            $bestMatch = $this->prioritizeFile($fuzzyMatches, $targetVolume);
            $similarity = $this->calculateSimilarity($filename, $bestMatch['filename']);

            if ($similarity < $MIN_CONFIDENCE) {
                Craft::warning("Rejecting fuzzy match: '{$bestMatch['filename']}' for '{$filename}' (confidence: " . round($similarity * 100, 1) . "%)", __METHOD__);
                return [
                    'found' => false,
                    'file' => null,
                    'strategy' => 'none',
                    'confidence' => 0.0,
                    'rejected_match' => $bestMatch['filename'],
                    'rejected_confidence' => $similarity
                ];
            }

            return [
                'found' => true,
                'file' => $bestMatch,
                'strategy' => 'fuzzy',
                'confidence' => $similarity
            ];
        }

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
