<?php
namespace csabourin\spaghettiMigrator\helpers;

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
     * Merge metadata from a losing asset into the winning asset.
     *
     * Safe merges are applied automatically:
     * - Empty winner values receive non-empty loser values
     * - Matching values are left unchanged
     * - Array/list values are unioned
     * - Asset-owned relation rows are moved to the winner, with duplicates removed
     *
     * Ambiguous scalar conflicts keep the winner value and are reported through
     * the optional logger.
     *
     * @param Asset $winner Asset record that will remain
     * @param Asset $loser Asset record that will be deleted
     * @param callable|null $logger Receives one array event per merge/conflict
     * @return array Merge summary
     */
    public static function mergeAssetMetadata(Asset $winner, Asset $loser, ?callable $logger = null): array
    {
        $summary = [
            'copied' => 0,
            'merged' => 0,
            'conflicts' => 0,
            'relations_moved' => 0,
            'relations_deduplicated' => 0,
            'source_urls_stripped' => 0,
        ];

        $relationStats = self::mergeAssetOwnedRelations($winner, $loser, $logger);
        $summary['relations_moved'] = $relationStats['moved'];
        $summary['relations_deduplicated'] = $relationStats['deduplicated'];

        foreach (self::getSiteIdsForMetadataMerge($winner, $loser) as $siteId) {
            $siteWinner = self::getAssetForSite($winner, $siteId);
            $siteLoser = self::getAssetForSite($loser, $siteId);

            if (!$siteWinner || !$siteLoser) {
                continue;
            }

            $changed = self::mergeTitleValue($siteWinner, $siteLoser, $siteId, $summary, $logger);

            foreach (self::getMergeableFieldHandles($siteWinner, $siteLoser) as $handle) {
                if (!method_exists($siteWinner, 'getFieldValue') || !method_exists($siteLoser, 'getFieldValue')) {
                    continue;
                }

                try {
                    $winnerValue = $siteWinner->getFieldValue($handle);
                    $loserValue = $siteLoser->getFieldValue($handle);
                } catch (\Throwable $e) {
                    $summary['conflicts']++;
                    self::logMetadataEvent($logger, [
                        'type' => 'metadata_unmergeable',
                        'field' => $handle,
                        'siteId' => $siteId,
                        'winnerAssetId' => $winner->id,
                        'loserAssetId' => $loser->id,
                        'resolution' => 'kept_winner',
                        'reason' => $e->getMessage(),
                    ]);
                    continue;
                }

                // Safety: never keep or reintroduce source-provider URLs (e.g. AWS)
                // during duplicate merges. Phase 0.5 may pick either duplicate as
                // the winner, so both sides must be sanitized before merge conflict
                // resolution chooses which value survives.
                $winnerSanitized = self::stripSourceUrlsFromMetadataWithCount($winnerValue);
                $winnerValue = $winnerSanitized['value'];
                $winnerStripped = (int) ($winnerSanitized['stripped'] ?? 0);
                if ($winnerStripped > 0) {
                    $summary['source_urls_stripped'] += $winnerStripped;
                    self::logMetadataEvent($logger, [
                        'type' => 'metadata_source_urls_stripped',
                        'field' => $handle,
                        'siteId' => $siteId,
                        'winnerAssetId' => $winner->id,
                        'loserAssetId' => $loser->id,
                        'assetRole' => 'winner',
                        'strippedCount' => $winnerStripped,
                    ]);
                }

                $loserSanitized = self::stripSourceUrlsFromMetadataWithCount($loserValue);
                $loserValue = $loserSanitized['value'];
                $loserStripped = (int) ($loserSanitized['stripped'] ?? 0);
                if ($loserStripped > 0) {
                    $summary['source_urls_stripped'] += $loserStripped;
                    self::logMetadataEvent($logger, [
                        'type' => 'metadata_source_urls_stripped',
                        'field' => $handle,
                        'siteId' => $siteId,
                        'winnerAssetId' => $winner->id,
                        'loserAssetId' => $loser->id,
                        'assetRole' => 'loser',
                        'strippedCount' => $loserStripped,
                    ]);
                }

                $result = self::mergeMetadataValue($winnerValue, $loserValue);

                if ($result['action'] === 'unchanged') {
                    if ($winnerStripped > 0 && method_exists($siteWinner, 'setFieldValue')) {
                        try {
                            $siteWinner->setFieldValue($handle, $winnerValue);
                            $changed = true;
                        } catch (\Throwable $e) {
                            $summary['conflicts']++;
                            self::logMetadataEvent($logger, [
                                'type' => 'metadata_unmergeable',
                                'field' => $handle,
                                'siteId' => $siteId,
                                'winnerAssetId' => $winner->id,
                                'loserAssetId' => $loser->id,
                                'resolution' => 'kept_winner',
                                'reason' => $e->getMessage(),
                            ]);
                        }
                    }
                    continue;
                }

                if ($result['action'] === 'conflict') {
                    if ($winnerStripped > 0 && method_exists($siteWinner, 'setFieldValue')) {
                        try {
                            $siteWinner->setFieldValue($handle, $winnerValue);
                            $changed = true;
                        } catch (\Throwable $e) {
                            $summary['conflicts']++;
                            self::logMetadataEvent($logger, [
                                'type' => 'metadata_unmergeable',
                                'field' => $handle,
                                'siteId' => $siteId,
                                'winnerAssetId' => $winner->id,
                                'loserAssetId' => $loser->id,
                                'resolution' => 'kept_winner',
                                'reason' => $e->getMessage(),
                            ]);
                        }
                    }

                    $summary['conflicts']++;
                    self::logMetadataEvent($logger, [
                        'type' => 'metadata_conflict',
                        'field' => $handle,
                        'siteId' => $siteId,
                        'winnerAssetId' => $winner->id,
                        'loserAssetId' => $loser->id,
                        'resolution' => 'kept_winner',
                        'winnerValue' => self::summarizeMetadataValue($winnerValue),
                        'loserValue' => self::summarizeMetadataValue($loserValue),
                    ]);
                    continue;
                }

                if (method_exists($siteWinner, 'setFieldValue')) {
                    try {
                        $siteWinner->setFieldValue($handle, $result['value']);
                    } catch (\Throwable $e) {
                        $summary['conflicts']++;
                        self::logMetadataEvent($logger, [
                            'type' => 'metadata_unmergeable',
                            'field' => $handle,
                            'siteId' => $siteId,
                            'winnerAssetId' => $winner->id,
                            'loserAssetId' => $loser->id,
                            'resolution' => 'kept_winner',
                            'reason' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    $changed = true;
                    $summary[$result['action'] === 'copy' ? 'copied' : 'merged']++;
                    self::logMetadataEvent($logger, [
                        'type' => 'metadata_' . $result['action'],
                        'field' => $handle,
                        'siteId' => $siteId,
                        'winnerAssetId' => $winner->id,
                        'loserAssetId' => $loser->id,
                    ]);
                }
            }

            if ($changed) {
                Craft::$app->getElements()->saveElement($siteWinner, false);
            }
        }

        return $summary;
    }

    /**
     * Resolve duplicate assets with the same filename in a target location
     *
     * @param Asset $candidateAsset The asset being moved/processed
     * @param int $targetVolumeId Target volume ID
     * @param int $targetFolderId Target folder ID
     * @param bool $dryRun Whether this is a dry run
     * @param bool $forcedWinner When true, candidate always wins regardless of quality/usage (e.g. originals folder)
     * @param callable|null $outputCallback Optional callback for output (receives message and color)
     * @return array ['action' => 'keep'|'overwrite'|'rename', 'filename' => string, 'winner' => Asset|null]
     */
    public static function resolveFilenameCollision(
        Asset $candidateAsset,
        int $targetVolumeId,
        int $targetFolderId,
        bool $dryRun = true,
        bool $forcedWinner = false,
        ?callable $outputCallback = null,
        bool $skipFileCopy = false
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
        // When $forcedWinner is true (e.g. asset is from 'originals' folder), candidate always wins
        if ($forcedWinner) {
            $winner          = $candidateAsset;
            $winnerReason    = 'forced winner (forcedWinner=true)';
            $candidateUsage  = null;
            $existingUsage   = null;
        } else {
            $details        = self::pickWinnerWithDetails($candidateAsset, $existingAsset);
            $winner         = $details['winner'];
            $winnerReason   = $details['reason'];
            $candidateUsage = $details['candidate_usage'];
            $existingUsage  = $details['existing_usage'];
        }

        if ($winner->id === $candidateAsset->id) {
            // Candidate wins - overwrite existing
            if ($outputCallback) {
                $outputCallback("Resolving duplicate: '{$candidateAsset->filename}' - candidate wins (better quality/usage)", Console::FG_CYAN);
            }

            if (!$dryRun) {
                self::mergeAssets($candidateAsset, $existingAsset, $skipFileCopy);
            }

            return [
                'action'          => 'overwrite',
                'filename'        => $candidateAsset->filename,
                'winner'          => $candidateAsset,
                'loser'           => $existingAsset,
                'winner_reason'   => $winnerReason,
                'candidate_usage' => $candidateUsage,
                'existing_usage'  => $existingUsage,
            ];
        } else {
            // Existing wins - candidate should be discarded/merged
            if ($outputCallback) {
                $outputCallback("Resolving duplicate: '{$candidateAsset->filename}' - existing wins (better quality/usage)", Console::FG_CYAN);
            }

            if (!$dryRun) {
                self::mergeAssets($existingAsset, $candidateAsset, $skipFileCopy);
            }

            return [
                'action'          => 'merge_into_existing',
                'filename'        => $candidateAsset->filename,
                'winner'          => $existingAsset,
                'loser'           => $candidateAsset,
                'winner_reason'   => $winnerReason,
                'candidate_usage' => $candidateUsage,
                'existing_usage'  => $existingUsage,
            ];
        }
    }

    /**
     * Pick the winner between two duplicate assets
     *
     * Priority:
     * 1. Used assets beat unused (active entries only — drafts/revisions excluded)
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
        return self::pickWinnerWithDetails($asset1, $asset2)['winner'];
    }

    /**
     * Pick the winner and return selection details for logging.
     *
     * Returns an array with keys:
     *   winner           — the winning Asset
     *   reason           — human-readable string explaining why this winner was chosen
     *   candidate_usage  — active-relation count for $candidate
     *   existing_usage   — active-relation count for $existing
     *
     * @param Asset $candidate The incoming/candidate asset
     * @param Asset $existing  The already-present/existing asset
     * @return array
     */
    private static function pickWinnerWithDetails(Asset $candidate, Asset $existing): array
    {
        $cu = self::getAssetUsageCount($candidate);
        $eu = self::getAssetUsageCount($existing);

        if ($cu !== $eu) {
            $winner = $cu > $eu ? $candidate : $existing;
            return [
                'winner'          => $winner,
                'reason'          => "active relations ({$cu} candidate vs {$eu} existing)",
                'candidate_usage' => $cu,
                'existing_usage'  => $eu,
            ];
        }

        $cs = $candidate->size ?? 0;
        $es = $existing->size ?? 0;
        if ($cs !== $es) {
            $winner = $cs > $es ? $candidate : $existing;
            return [
                'winner'          => $winner,
                'reason'          => sprintf('file size (%d vs %d bytes)', $cs, $es),
                'candidate_usage' => $cu,
                'existing_usage'  => $eu,
            ];
        }

        $cd = $candidate->dateModified ?? $candidate->dateCreated;
        $ed = $existing->dateModified ?? $existing->dateCreated;
        if ($cd && $ed) {
            $ct = $cd->getTimestamp();
            $et = $ed->getTimestamp();
            if ($ct !== $et) {
                $winner = $ct > $et ? $candidate : $existing;
                return [
                    'winner'          => $winner,
                    'reason'          => 'modification date',
                    'candidate_usage' => $cu,
                    'existing_usage'  => $eu,
                ];
            }
        }

        $winner = $candidate->id > $existing->id ? $candidate : $existing;
        return [
            'winner'          => $winner,
            'reason'          => "ID tiebreaker (#{$candidate->id} candidate vs #{$existing->id} existing)",
            'candidate_usage' => $cu,
            'existing_usage'  => $eu,
        ];
    }

    /**
     * Get the usage count for an asset from active entries only.
     *
     * Drafts, revisions, and soft-deleted elements are excluded so that
     * stale or unpublished references do not skew winner selection toward
     * assets that are only referenced in non-canonical content.
     *
     * @param Asset $asset
     * @return int
     */
    private static function getAssetUsageCount(Asset $asset): int
    {
        $count = Craft::$app->getDb()->createCommand(
            'SELECT COUNT(*) FROM {{%relations}} r
             INNER JOIN {{%elements}} e ON e.id = r.sourceId
             WHERE r.targetId = :targetId
             AND e.draftId IS NULL
             AND e.revisionId IS NULL
             AND e.dateDeleted IS NULL',
            [':targetId' => $asset->id]
        )->queryScalar();

        return (int) $count;
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
    private static function mergeAssets(Asset $winner, Asset $loser, bool $skipFileCopy = false): bool
    {
        try {
            self::mergeAssetMetadata($winner, $loser);

            // Transfer inbound relations from loser to winner, skipping any that
            // would create a duplicate relation row.
            //
            // IMPORTANT: `sourceSiteId` here is Craft's relation site-scope column
            // (which locale/site variant of the source element owns the relation).
            // It is NOT related to migration "source" vs "target" storage
            // providers (AWS/DO). Even in a single-site migration this column is
            // part of Craft's uniqueness model for relations.
            //
            // Unique key in Craft: (fieldId, sourceId, sourceSiteId, targetId).
            $db = Craft::$app->getDb();

            $winnerRelationKeys = array_flip(
                $db->createCommand(
                    'SELECT CONCAT(fieldId, "-", sourceId, "-", COALESCE(sourceSiteId, 0)) FROM {{%relations}} WHERE targetId = :id',
                    [':id' => $winner->id]
                )->queryColumn()
            );

            $loserRelations = $db->createCommand(
                'SELECT id, fieldId, sourceId, sourceSiteId FROM {{%relations}} WHERE targetId = :id',
                [':id' => $loser->id]
            )->queryAll();

            foreach ($loserRelations as $rel) {
                $key = self::buildInboundRelationKey($rel);
                if (isset($winnerRelationKeys[$key])) {
                    $db->createCommand()->delete('{{%relations}}', ['id' => $rel['id']])->execute();
                } else {
                    $db->createCommand()->update('{{%relations}}', ['targetId' => $winner->id], ['id' => $rel['id']])->execute();
                    $winnerRelationKeys[$key] = true;
                }
            }

            // Check if we should copy the loser's file to winner's location
            // This handles cases where the loser has a better/larger physical file.
            // Skip when the caller will immediately move the winner to a new location
            // (e.g. Phase 0.5 moves the file to target right after this call), to avoid
            // writing files to a disconnected or wrong filesystem.
            $loserSize = $loser->size ?? 0;
            $winnerSize = $winner->size ?? 0;

            if (!$skipFileCopy && $loserSize > $winnerSize) {
                try {
                    $loserFs = $loser->getVolume()->getFs();
                    $winnerFs = $winner->getVolume()->getFs();
                    $loserPath = $loser->getPath();
                    $winnerPath = $winner->getPath();

                    // Try to find the loser's file in multiple locations
                    $content = false;

                    // 1. Check loser's current volume
                    if ($loserFs->fileExists($loserPath)) {
                        $content = $loserFs->read($loserPath);
                    } else {
                        // 2. Check if file exists in winner's location already
                        $filename = $loser->filename;
                        if ($winnerFs->fileExists($filename)) {
                            $content = $winnerFs->read($filename);
                            Craft::info(
                                "File for loser asset {$loser->id} not found in its volume, but found in winner's volume",
                                __METHOD__
                            );
                        } else {
                            // 3. Check quarantine volume
                            $quarantineHandle = MigrationConfig::getInstance()->getQuarantineVolumeHandle();
                            $quarantineVolume = Craft::$app->getVolumes()->getVolumeByHandle($quarantineHandle);
                            if ($quarantineVolume) {
                                $quarantineFs = $quarantineVolume->getFs();
                                if ($quarantineFs->fileExists($filename)) {
                                    $content = $quarantineFs->read($filename);
                                    Craft::info(
                                        "File for loser asset {$loser->id} not found in its volume or winner's volume, but found in {$quarantineHandle}",
                                        __METHOD__
                                    );
                                }
                            }
                        }
                    }

                    // Replace winner's file with the loser's larger file via Craft's API.
                    // replaceAssetFile handles the physical swap, re-indexes metadata
                    // (size, dimensions), and invalidates any cached transforms.
                    if ($content !== false) {
                        $tempPath = tempnam(sys_get_temp_dir(), 'asset_upgrade_');
                        try {
                            file_put_contents($tempPath, $content);
                            Craft::$app->getAssets()->replaceAssetFile($winner, $tempPath, $winner->filename);
                        } finally {
                            if (file_exists($tempPath)) {
                                unlink($tempPath);
                            }
                        }

                        Craft::info(
                            "Replaced winner {$winner->id} file with larger file from loser {$loser->id}",
                            __METHOD__
                        );
                    } else {
                        Craft::warning(
                            "Could not find file for loser asset {$loser->id} in any location (own volume, winner's volume, or {$quarantineHandle})",
                            __METHOD__
                        );
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
     * Merge relation rows owned by the losing asset into the winning asset.
     *
     * These are asset metadata relations (`sourceId = assetId`), distinct from
     * inbound content references (`targetId = assetId`) that are handled by the
     * existing duplicate merge flow.
     *
     * @param Asset $winner
     * @param Asset $loser
     * @param callable|null $logger
     * @return array
     */
    private static function mergeAssetOwnedRelations(Asset $winner, Asset $loser, ?callable $logger = null): array
    {
        $stats = ['moved' => 0, 'deduplicated' => 0];
        $db = Craft::$app->getDb();
        // NOTE: `sourceSiteId` below is Craft relation site-scope metadata, not
        // migration source/target system context.
        $command = $db->createCommand(
            'SELECT id, fieldId, targetId, sourceSiteId FROM {{%relations}} WHERE sourceId = :sourceId',
            [':sourceId' => $loser->id]
        );

        if (!method_exists($command, 'queryAll')) {
            return $stats;
        }

        foreach ($command->queryAll() as $relation) {
            $existsCommand = $db->createCommand(
                'SELECT id FROM {{%relations}}
                WHERE sourceId = :sourceId
                AND fieldId = :fieldId
                AND targetId = :targetId
                AND COALESCE(sourceSiteId, 0) = COALESCE(:sourceSiteId, 0)',
                [
                    ':sourceId' => $winner->id,
                    ':fieldId' => $relation['fieldId'],
                    ':targetId' => $relation['targetId'],
                    ':sourceSiteId' => $relation['sourceSiteId'] ?? null,
                ]
            );

            $existingId = method_exists($existsCommand, 'queryScalar') ? $existsCommand->queryScalar() : null;

            if ($existingId) {
                $db->createCommand()->delete('{{%relations}}', ['id' => $relation['id']])->execute();
                $stats['deduplicated']++;
                continue;
            }

            $db->createCommand()
                ->update('{{%relations}}', ['sourceId' => $winner->id], ['id' => $relation['id']])
                ->execute();
            $stats['moved']++;
        }

        if ($stats['moved'] > 0 || $stats['deduplicated'] > 0) {
            self::logMetadataEvent($logger, [
                'type' => 'metadata_relations_merged',
                'winnerAssetId' => $winner->id,
                'loserAssetId' => $loser->id,
                'relationsMoved' => $stats['moved'],
                'relationsDeduplicated' => $stats['deduplicated'],
            ]);
        }

        return $stats;
    }

    /**
     * @param Asset $winner
     * @param Asset $loser
     * @return array
     */
    private static function getSiteIdsForMetadataMerge(Asset $winner, Asset $loser): array
    {
        $siteIds = [];

        foreach ([$winner, $loser] as $asset) {
            if (isset($asset->siteId)) {
                $siteIds[] = $asset->siteId;
            }

            if (method_exists($asset, 'getSupportedSites')) {
                foreach ($asset->getSupportedSites() as $site) {
                    if (is_array($site) && isset($site['siteId'])) {
                        $siteIds[] = $site['siteId'];
                    } elseif (is_object($site) && isset($site->siteId)) {
                        $siteIds[] = $site->siteId;
                    }
                }
            }
        }

        $siteIds = array_values(array_unique(array_filter($siteIds, static fn($siteId) => $siteId !== null)));
        return $siteIds ?: [null];
    }

    /**
     * @param Asset $asset
     * @param int|null $siteId
     * @return Asset|null
     */
    private static function getAssetForSite(Asset $asset, ?int $siteId): ?Asset
    {
        if ($siteId === null || (isset($asset->siteId) && (int) $asset->siteId === $siteId)) {
            return $asset;
        }

        $elements = Craft::$app->getElements();
        if (method_exists($elements, 'getElementById')) {
            $siteAsset = $elements->getElementById($asset->id, Asset::class, $siteId);
            if ($siteAsset instanceof Asset) {
                return $siteAsset;
            }
        }

        return $asset;
    }

    /**
     * @param Asset $winner
     * @param Asset $loser
     * @return array
     */
    private static function getMergeableFieldHandles(Asset $winner, Asset $loser): array
    {
        $handles = [];

        foreach ([$winner, $loser] as $asset) {
            if (isset($asset->customFieldHandles) && is_array($asset->customFieldHandles)) {
                $handles = array_merge($handles, $asset->customFieldHandles);
            }

            if (!method_exists($asset, 'getFieldLayout')) {
                continue;
            }

            $layout = $asset->getFieldLayout();
            if (!$layout || !method_exists($layout, 'getCustomFields')) {
                continue;
            }

            foreach ($layout->getCustomFields() as $field) {
                if (isset($field->handle)) {
                    $handles[] = $field->handle;
                }
            }
        }

        return array_values(array_unique(array_filter($handles)));
    }

    /**
     * @param Asset $winner
     * @param Asset $loser
     * @param int|null $siteId
     * @param array $summary
     * @param callable|null $logger
     * @return bool
     */
    private static function mergeTitleValue(
        Asset $winner,
        Asset $loser,
        ?int $siteId,
        array &$summary,
        ?callable $logger
    ): bool {
        $winnerTitle = $winner->title ?? null;
        $loserTitle = $loser->title ?? null;
        $result = self::mergeMetadataValue($winnerTitle, $loserTitle);

        if ($result['action'] === 'copy') {
            $winner->title = $result['value'];
            $summary['copied']++;
            self::logMetadataEvent($logger, [
                'type' => 'metadata_copy',
                'field' => 'title',
                'siteId' => $siteId,
                'winnerAssetId' => $winner->id,
                'loserAssetId' => $loser->id,
            ]);
            return true;
        }

        if ($result['action'] === 'conflict') {
            $summary['conflicts']++;
            self::logMetadataEvent($logger, [
                'type' => 'metadata_conflict',
                'field' => 'title',
                'siteId' => $siteId,
                'winnerAssetId' => $winner->id,
                'loserAssetId' => $loser->id,
                'resolution' => 'kept_winner',
                'winnerValue' => self::summarizeMetadataValue($winnerTitle),
                'loserValue' => self::summarizeMetadataValue($loserTitle),
            ]);
        }

        return false;
    }

    /**
     * @param mixed $winnerValue
     * @param mixed $loserValue
     * @return array
     */
    private static function mergeMetadataValue($winnerValue, $loserValue): array
    {
        $normalizedWinner = self::normalizeMetadataValue($winnerValue);
        $normalizedLoser = self::normalizeMetadataValue($loserValue);

        if (self::isMetadataValueEmpty($normalizedLoser)) {
            return ['action' => 'unchanged', 'value' => $winnerValue];
        }

        if (self::isMetadataValueEmpty($normalizedWinner)) {
            return ['action' => 'copy', 'value' => $normalizedLoser];
        }

        if ($normalizedWinner == $normalizedLoser) {
            return ['action' => 'unchanged', 'value' => $winnerValue];
        }

        if (
            is_array($normalizedWinner) &&
            is_array($normalizedLoser) &&
            self::isMergeableList($normalizedWinner) &&
            self::isMergeableList($normalizedLoser)
        ) {
            return [
                'action' => 'merge',
                'value' => array_values(array_unique(array_merge($normalizedWinner, $normalizedLoser), SORT_REGULAR)),
            ];
        }

        return ['action' => 'conflict', 'value' => $winnerValue];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalizeMetadataValue($value)
    {
        if (is_object($value) && method_exists($value, 'all')) {
            $value = $value->all();
        }

        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            $normalized = array_map(static function ($item) {
                return is_object($item) && isset($item->id) ? $item->id : $item;
            }, $value);

            return self::isListArray($value) ? array_values($normalized) : $normalized;
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function isMetadataValueEmpty($value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @param array $value
     * @return bool
     */
    private static function isListArray(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param array $value
     * @return bool
     */
    private static function isMergeableList(array $value): bool
    {
        if (!self::isListArray($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_array($item) || is_object($item) || is_resource($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param callable|null $logger
     * @param array $event
     */
    private static function logMetadataEvent(?callable $logger, array $event): void
    {
        if ($logger !== null) {
            $logger($event);
        }
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function summarizeMetadataValue($value): string
    {
        $normalized = self::normalizeMetadataValue($value);

        if (is_array($normalized)) {
            $encoded = json_encode($normalized);
            return $encoded === false ? '[array]' : substr($encoded, 0, 500);
        }

        if (is_object($normalized)) {
            return get_class($normalized);
        }

        if (is_bool($normalized)) {
            return $normalized ? 'true' : 'false';
        }

        return substr((string) $normalized, 0, 500);
    }

    /**
     * Recursively strip values that point to configured source URLs.
     *
     * This is used when merging loser metadata into winner metadata to avoid
     * restoring stale source-provider links after storage migration.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function stripSourceUrlsFromMetadata($value)
    {
        return self::stripSourceUrlsFromMetadataWithCount($value)['value'];
    }

    /**
     * Recursively strip source URLs and return sanitization telemetry.
     *
     * @param mixed $value
     * @return array{value:mixed,stripped:int}
     */
    private static function stripSourceUrlsFromMetadataWithCount($value): array
    {
        if (is_string($value)) {
            if (self::containsSourceUrl($value)) {
                return ['value' => '', 'stripped' => 1];
            }

            return ['value' => $value, 'stripped' => 0];
        }

        if (is_array($value)) {
            $sanitized = [];
            $stripped = 0;
            foreach ($value as $key => $item) {
                $clean = self::stripSourceUrlsFromMetadataWithCount($item);
                $cleanItem = $clean['value'];
                $stripped += (int) ($clean['stripped'] ?? 0);

                // For list-style arrays, drop emptied scalar entries.
                if (is_int($key) && ($cleanItem === '' || $cleanItem === null)) {
                    continue;
                }

                $sanitized[$key] = $cleanItem;
            }

            // Preserve list semantics when input is a list.
            if (self::isListArray($value)) {
                return ['value' => array_values($sanitized), 'stripped' => $stripped];
            }

            return ['value' => $sanitized, 'stripped' => $stripped];
        }

        return ['value' => $value, 'stripped' => 0];
    }

    /**
     * Detect whether a string contains any configured source-provider URL.
     *
     * @param string $value
     * @return bool
     */
    private static function containsSourceUrl(string $value): bool
    {
        foreach (self::getConfiguredSourceUrlNeedles() as $url) {
            if (stripos($value, $url) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build source URL needles from both AWS defaults and explicit legacy mappings.
     *
     * Explicit urlReplacement.mappings are important for older bucket URLs that are
     * no longer the configured AWS source but can still exist in stale metadata.
     *
     * @return array
     */
    private static function getConfiguredSourceUrlNeedles(): array
    {
        try {
            $config = MigrationConfig::getInstance();
            $sourceUrls = array_merge(
                array_keys($config->getExplicitUrlMappings()),
                $config->getAwsUrls()
            );

            $needles = [];
            foreach ($sourceUrls as $url) {
                if (!is_string($url) || $url === '') {
                    continue;
                }

                $needles[] = $url;
                $needles[] = str_replace('/', '\\/', $url);
                $needles[] = str_replace(['://', '/'], [':="" ', '="" '], $url);
            }

            return array_values(array_unique($needles));
        } catch (\Throwable $e) {
            // If config is unavailable (tests/bootstrap), do not block merges.
            return [];
        }
    }

    /**
     * Build a stable dedup key for inbound Craft relation rows.
     *
     * Key shape mirrors Craft uniqueness for relation-scope columns:
     * fieldId + sourceId + sourceSiteId (targetId is implicit per query scope).
     *
     * @param array $relation
     * @return string
     */
    private static function buildInboundRelationKey(array $relation): string
    {
        $fieldId = (string) ($relation['fieldId'] ?? '');
        $sourceId = (string) ($relation['sourceId'] ?? '');
        $sourceSiteId = self::normalizeRelationSiteScope($relation['sourceSiteId'] ?? null);

        return $fieldId . '-' . $sourceId . '-' . $sourceSiteId;
    }

    /**
     * Normalize nullable Craft relation site-scope values into a stable scalar.
     *
     * @param mixed $sourceSiteId
     * @return int
     */
    private static function normalizeRelationSiteScope($sourceSiteId): int
    {
        return $sourceSiteId === null ? 0 : (int) $sourceSiteId;
    }

}
