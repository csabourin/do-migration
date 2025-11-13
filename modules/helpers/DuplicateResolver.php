<?php
namespace csabourin\craftS3SpacesMigration\helpers;

use Craft;
use craft\elements\Asset;
use craft\helpers\Console;

/**
 * Duplicate Resolver Helper
 *
 * Provides consistent duplicate resolution logic across all migration commands.
 * Handles file collision by picking the best candidate based on:
 * 1. Asset usage (prefer used assets over unused)
 * 2. File size (prefer larger/original files)
 * 3. Modification time (prefer newer files)
 * 4. Asset relations (prefer assets with more references)
 */
class DuplicateResolver
{
    /**
     * Resolve duplicate assets with the same filename in a target location
     *
     * @param Asset $candidateAsset The asset being moved/processed
     * @param int $targetVolumeId Target volume ID
     * @param int $targetFolderId Target folder ID
     * @param bool $dryRun Whether this is a dry run
     * @param callable|null $outputCallback Optional callback for output (receives message and color)
     * @return array ['action' => 'keep'|'overwrite'|'rename', 'filename' => string, 'winner' => Asset|null]
     */
    public static function resolveFilenameCollision(
        Asset $candidateAsset,
        int $targetVolumeId,
        int $targetFolderId,
        bool $dryRun = true,
        ?callable $outputCallback = null
    ): array {
        // Find existing asset with same filename in target location
        $existingAsset = Asset::find()
            ->volumeId($targetVolumeId)
            ->folderId($targetFolderId)
            ->filename($candidateAsset->filename)
            ->one();

        // No collision - can proceed
        if (!$existingAsset || $existingAsset->id === $candidateAsset->id) {
            return [
                'action' => 'keep',
                'filename' => $candidateAsset->filename,
                'winner' => $candidateAsset
            ];
        }

        // We have a collision - determine winner
        $winner = self::pickWinner($candidateAsset, $existingAsset);

        if ($winner->id === $candidateAsset->id) {
            // Candidate wins - overwrite existing
            if ($outputCallback) {
                $outputCallback("Resolving duplicate: '{$candidateAsset->filename}' - candidate wins (better quality/usage)", Console::FG_CYAN);
            }

            if (!$dryRun) {
                self::mergeAssets($candidateAsset, $existingAsset);
            }

            return [
                'action' => 'overwrite',
                'filename' => $candidateAsset->filename,
                'winner' => $candidateAsset,
                'loser' => $existingAsset
            ];
        } else {
            // Existing wins - candidate should be discarded/merged
            if ($outputCallback) {
                $outputCallback("Resolving duplicate: '{$candidateAsset->filename}' - existing wins (better quality/usage)", Console::FG_CYAN);
            }

            if (!$dryRun) {
                self::mergeAssets($existingAsset, $candidateAsset);
            }

            return [
                'action' => 'merge_into_existing',
                'filename' => $candidateAsset->filename,
                'winner' => $existingAsset,
                'loser' => $candidateAsset
            ];
        }
    }

    /**
     * Pick the winner between two duplicate assets
     *
     * Priority:
     * 1. Used assets beat unused
     * 2. More relations beat fewer
     * 3. Larger file size beats smaller
     * 4. Newer modification time beats older
     * 5. Higher asset ID (newer) beats lower (older)
     *
     * @param Asset $asset1
     * @param Asset $asset2
     * @return Asset The winning asset
     */
    public static function pickWinner(Asset $asset1, Asset $asset2): Asset
    {
        // Check usage - prefer used assets
        $usage1 = self::getAssetUsageCount($asset1);
        $usage2 = self::getAssetUsageCount($asset2);

        if ($usage1 > $usage2) {
            return $asset1;
        } elseif ($usage2 > $usage1) {
            return $asset2;
        }

        // Same usage level - check file size (prefer larger/original)
        $size1 = $asset1->size ?? 0;
        $size2 = $asset2->size ?? 0;

        if ($size1 > $size2) {
            return $asset1;
        } elseif ($size2 > $size1) {
            return $asset2;
        }

        // Same size - check modification time (prefer newer)
        $date1 = $asset1->dateModified ?? $asset1->dateCreated;
        $date2 = $asset2->dateModified ?? $asset2->dateCreated;

        if ($date1 && $date2) {
            $time1 = $date1->getTimestamp();
            $time2 = $date2->getTimestamp();

            if ($time1 > $time2) {
                return $asset1;
            } elseif ($time2 > $time1) {
                return $asset2;
            }
        }

        // Still tied - prefer higher ID (newer asset record)
        return $asset1->id > $asset2->id ? $asset1 : $asset2;
    }

    /**
     * Get the usage count for an asset (how many relations/references it has)
     *
     * @param Asset $asset
     * @return int
     */
    private static function getAssetUsageCount(Asset $asset): int
    {
        // Count relations via asset fields
        $relationsCount = (new \craft\db\Query())
            ->from('{{%relations}}')
            ->where(['targetId' => $asset->id])
            ->count();

        return (int) $relationsCount;
    }

    /**
     * Merge loser asset into winner
     * - Transfer any relations from loser to winner
     * - Copy physical file from loser to winner if winner's file is smaller or missing
     * - Delete loser asset
     *
     * @param Asset $winner The asset to keep
     * @param Asset $loser The asset to remove
     * @return bool Success
     */
    private static function mergeAssets(Asset $winner, Asset $loser): bool
    {
        try {
            // Transfer relations from loser to winner
            Craft::$app->getDb()->createCommand()
                ->update(
                    '{{%relations}}',
                    ['targetId' => $winner->id],
                    ['targetId' => $loser->id]
                )
                ->execute();

            // Check if we should copy the loser's file to winner's location
            // This handles cases where the loser has a better/larger physical file
            $loserSize = $loser->size ?? 0;
            $winnerSize = $winner->size ?? 0;

            if ($loserSize > $winnerSize) {
                try {
                    $loserFs = $loser->getVolume()->getFs();
                    $winnerFs = $winner->getVolume()->getFs();
                    $loserPath = $loser->getPath();
                    $winnerPath = $winner->getPath();

                    // Copy loser's file content to winner's path
                    // Use read()/write() instead of readStream()/writeStream() for DO Spaces compatibility
                    $content = $loserFs->read($loserPath);
                    if ($content !== false) {
                        $winnerFs->write($winnerPath, $content, []);

                        // Update winner's file size
                        $winner->size = $loserSize;
                        Craft::$app->getElements()->saveElement($winner, false);
                    }
                } catch (\Exception $e) {
                    Craft::warning(
                        "Could not copy file from loser to winner: " . $e->getMessage(),
                        __METHOD__
                    );
                }
            }

            // Delete the loser asset
            Craft::$app->getElements()->deleteElement($loser, true);

            return true;
        } catch (\Exception $e) {
            Craft::error(
                "Error merging assets {$loser->id} into {$winner->id}: " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }
    }

    /**
     * Resolve all duplicates in a given volume/folder
     *
     * @param int $volumeId
     * @param int|null $folderId Optional folder ID (null for all folders)
     * @param bool $dryRun
     * @param callable|null $outputCallback
     * @return array ['resolved' => int, 'errors' => int, 'details' => array]
     */
    public static function resolveAllDuplicates(
        int $volumeId,
        ?int $folderId = null,
        bool $dryRun = true,
        ?callable $outputCallback = null
    ): array {
        $resolved = 0;
        $errors = 0;
        $details = [];

        // Find all duplicate filenames in the volume/folder
        $query = (new \craft\db\Query())
            ->select(['filename', 'COUNT(*) as count'])
            ->from('{{%assets}}')
            ->where(['volumeId' => $volumeId])
            ->groupBy(['filename'])
            ->having(['>', 'count', 1]);

        if ($folderId !== null) {
            $query->andWhere(['folderId' => $folderId]);
        }

        $duplicateFilenames = $query->all();

        foreach ($duplicateFilenames as $row) {
            $filename = $row['filename'];

            // Get all assets with this filename
            $assetsQuery = Asset::find()
                ->volumeId($volumeId)
                ->filename($filename);

            if ($folderId !== null) {
                $assetsQuery->folderId($folderId);
            }

            $assets = $assetsQuery->all();

            if (count($assets) < 2) {
                continue;
            }

            // Pick winner
            $winner = $assets[0];
            foreach (array_slice($assets, 1) as $asset) {
                $currentWinner = self::pickWinner($winner, $asset);
                if ($currentWinner->id !== $winner->id) {
                    $winner = $currentWinner;
                }
            }

            // Merge all losers into winner
            foreach ($assets as $asset) {
                if ($asset->id === $winner->id) {
                    continue;
                }

                if ($outputCallback) {
                    $outputCallback(
                        "Merging duplicate '{$filename}': asset #{$asset->id} → #{$winner->id}",
                        Console::FG_CYAN
                    );
                }

                if (!$dryRun) {
                    if (self::mergeAssets($winner, $asset)) {
                        $resolved++;
                        $details[] = [
                            'filename' => $filename,
                            'winner' => $winner->id,
                            'loser' => $asset->id
                        ];
                    } else {
                        $errors++;
                    }
                } else {
                    $resolved++;
                }
            }
        }

        return [
            'resolved' => $resolved,
            'errors' => $errors,
            'details' => $details
        ];
    }

    /**
     * Check if two assets point to the same physical file
     *
     * @param Asset $asset1
     * @param Asset $asset2
     * @return bool
     */
    public static function pointToSameFile(Asset $asset1, Asset $asset2): bool
    {
        // Same volume and same path = same file
        if ($asset1->volumeId === $asset2->volumeId) {
            $path1 = $asset1->getPath();
            $path2 = $asset2->getPath();
            return $path1 === $path2;
        }

        return false;
    }

    /**
     * Find assets pointing to the same physical file
     *
     * @param int|null $volumeId Optional volume ID to limit search
     * @return array Array of arrays, each containing assets pointing to same file
     */
    public static function findAssetsPointingToSameFile(?int $volumeId = null): array
    {
        $query = Asset::find();

        if ($volumeId !== null) {
            $query->volumeId($volumeId);
        }

        $allAssets = $query->all();
        $fileMap = [];

        // Build map of file paths to assets
        foreach ($allAssets as $asset) {
            $key = $asset->volumeId . '::' . $asset->getPath();
            if (!isset($fileMap[$key])) {
                $fileMap[$key] = [];
            }
            $fileMap[$key][] = $asset;
        }

        // Filter to only duplicates
        $duplicates = [];
        foreach ($fileMap as $key => $assets) {
            if (count($assets) > 1) {
                $duplicates[$key] = $assets;
            }
        }

        return $duplicates;
    }
}
