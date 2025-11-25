<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\console\Controller;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\ChangeLogManager;

/**
 * Duplicate Resolution Service
 *
 * Handles complex duplicate file scenarios where multiple assets point to the same
 * physical file. Uses a multi-phase approach for safety:
 *
 * Phase 1.7: Analyze and stage files to temp location
 * Phase 1.8: Determine primary assets and clean up duplicates
 * Phase 4.5: Final cleanup of temp files
 *
 * Safety Features:
 * - Files staged to quarantine before any deletion
 * - Safety verification before proceeding
 * - Primary asset selection based on priority rules
 * - Relation preservation
 * - Full audit trail
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class DuplicateResolutionService
{
    /**
     * @var Controller Controller instance
     */
    private $controller;

    /**
     * @var ChangeLogManager Change log manager
     */
    private $changeLogManager;

    /**
     * @var string Migration ID
     */
    private $migrationId;

    /**
     * @var array Priority folder patterns
     */
    private $priorityFolderPatterns = [];

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param ChangeLogManager $changeLogManager Change log manager
     * @param string $migrationId Migration ID
     * @param MigrationConfig|null $config Migration configuration (optional, defaults to getInstance)
     */
    public function __construct(
        Controller $controller,
        ChangeLogManager $changeLogManager,
        string $migrationId,
        ?MigrationConfig $config = null
    ) {
        $this->controller = $controller;
        $this->changeLogManager = $changeLogManager;
        $this->migrationId = $migrationId;

        // Get config if not provided
        if ($config === null) {
            $config = MigrationConfig::getInstance();
        }

        $this->priorityFolderPatterns = $config->getPriorityFolderPatterns();
    }

    /**
     * Analyze file duplicates
     *
     * Finds files that are shared by multiple assets.
     *
     * @param array $sourceVolumes Source volumes
     * @param $targetVolume Target volume
     * @return array Duplicate groups
     */
    public function analyzeFileDuplicates(array $sourceVolumes, $targetVolume): array
    {
        $db = Craft::$app->getDb();
        $duplicateGroups = [];

        $this->controller->stdout("  Building file path inventory...\n");

        // Query all assets
        $query = <<<SQL
            SELECT
                a.id as assetId,
                a.volumeId,
                a.folderId,
                a.filename,
                v.name as volumeName,
                v.handle as volumeHandle,
                f.path as folderPath
            FROM {{%assets}} a
            INNER JOIN {{%elements}} e ON e.id = a.id
            INNER JOIN {{%volumes}} v ON v.id = a.volumeId
            LEFT JOIN {{%volumefolders}} f ON f.id = a.folderId
            WHERE e.dateDeleted IS NULL
            ORDER BY v.name, f.path, a.filename
SQL;

        $assets = $db->createCommand($query)->queryAll();

        // Build volume ID to filesystem handle map
        $volumeFsHandles = [];
        $volumesService = Craft::$app->getVolumes();

        // Group by physical file location (fsHandle + folderPath + filename)
        $fileGroups = [];
        foreach ($assets as $asset) {
            // Get filesystem handle
            if (!isset($volumeFsHandles[$asset['volumeId']])) {
                try {
                    $volume = $volumesService->getVolumeById($asset['volumeId']);
                    if ($volume) {
                        $fs = $volume->getFs();
                        $volumeFsHandles[$asset['volumeId']] = $fs->handle ?? 'unknown';
                    } else {
                        $volumeFsHandles[$asset['volumeId']] = 'unknown';
                    }
                } catch (\Exception $e) {
                    Craft::warning("Could not get filesystem for volume {$asset['volumeId']}: " . $e->getMessage(), __METHOD__);
                    $volumeFsHandles[$asset['volumeId']] = 'unknown';
                }
            }

            $fsHandle = $volumeFsHandles[$asset['volumeId']];
            $folderPath = trim($asset['folderPath'] ?? '', '/');
            $relativePath = $folderPath ? $folderPath . '/' . $asset['filename'] : $asset['filename'];
            $fileKey = $fsHandle . '::' . $relativePath;

            if (!isset($fileGroups[$fileKey])) {
                $fileGroups[$fileKey] = [];
            }

            $asset['fsHandle'] = $fsHandle;
            $fileGroups[$fileKey][] = $asset;
        }

        // Filter to only duplicates
        $duplicateCount = 0;
        $totalAssets = 0;

        foreach ($fileGroups as $fileKey => $group) {
            if (count($group) > 1) {
                $duplicateCount++;
                $totalAssets += count($group);
                $duplicateGroups[$fileKey] = $group;
            }
        }

        $this->controller->stdout("  Found {$duplicateCount} files with multiple asset references ({$totalAssets} assets)\n",
            $duplicateCount > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        if ($duplicateCount === 0) {
            return [];
        }

        // Save to database for resumability
        $this->controller->stdout("  Persisting duplicate analysis to database...\n");

        $savedCount = 0;
        foreach ($duplicateGroups as $fileKey => $group) {
            [$fsHandle, $relativePath] = explode('::', $fileKey, 2);

            $assetIds = array_map(fn($a) => $a['assetId'], $group);
            $firstAsset = $group[0];

            try {
                $existing = $db->createCommand('
                    SELECT id FROM {{%migration_file_duplicates}}
                    WHERE migrationId = :migrationId AND fileKey = :fileKey
                ', [
                    ':migrationId' => $this->migrationId,
                    ':fileKey' => $fileKey,
                ])->queryScalar();

                if (!$existing) {
                    $db->createCommand()->insert('{{%migration_file_duplicates}}', [
                        'migrationId' => $this->migrationId,
                        'fileKey' => $fileKey,
                        'originalPath' => $relativePath,
                        'volumeName' => $firstAsset['volumeName'],
                        'volumeHandle' => $firstAsset['volumeHandle'],
                        'relativePathInVolume' => $relativePath,
                        'assetIds' => json_encode($assetIds),
                        'status' => 'pending',
                        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                        'uid' => StringHelper::UUID(),
                    ])->execute();

                    $savedCount++;
                }
            } catch (\Exception $e) {
                Craft::warning("Could not save duplicate record for {$fileKey}: " . $e->getMessage(), __METHOD__);
            }
        }

        $this->controller->stdout("  Saved {$savedCount} duplicate file records\n", Console::FG_GREEN);

        return $duplicateGroups;
    }

    /**
     * Stage files to temp location in quarantine filesystem
     *
     * @param array $duplicateGroups Duplicate groups
     * @param $quarantineVolume Quarantine volume
     * @param int $batchSize Batch size
     */
    public function stageFilesToTemp(array $duplicateGroups, $quarantineVolume, int $batchSize = 100): void
    {
        if (empty($duplicateGroups)) {
            return;
        }

        $quarantineFs = $quarantineVolume->getFs();
        $db = Craft::$app->getDb();

        $totalFiles = count($duplicateGroups);
        $processed = 0;
        $batches = array_chunk(array_keys($duplicateGroups), $batchSize, true);

        $this->controller->stdout("  Staging {$totalFiles} files to temp location (quarantine)\n");
        $this->controller->stdout("  Processing in " . count($batches) . " batches...\n\n");

        foreach ($batches as $batchIndex => $fileKeys) {
            $batchNum = $batchIndex + 1;
            $this->controller->stdout("  [Batch {$batchNum}/" . count($batches) . "] ", Console::FG_CYAN);
            $this->controller->stdout("Staging " . count($fileKeys) . " files... ");

            $batchSuccess = 0;
            $batchSkipped = 0;
            $statusCounts = [];

            foreach ($fileKeys as $fileKey) {
                $group = $duplicateGroups[$fileKey];
                [$fsHandle, $relativePath] = explode('::', $fileKey, 2);

                // Get duplicate record
                $record = $db->createCommand('
                    SELECT * FROM {{%migration_file_duplicates}}
                    WHERE migrationId = :migrationId AND fileKey = :fileKey
                ', [
                    ':migrationId' => $this->migrationId,
                    ':fileKey' => $fileKey,
                ])->queryOne();

                if (!$record) {
                    $batchSkipped++;
                    $statusCounts['no_record'] = ($statusCounts['no_record'] ?? 0) + 1;
                    continue;
                }

                // Skip if already staged
                if ($record['status'] !== 'pending') {
                    $batchSkipped++;
                    $statusCounts[$record['status']] = ($statusCounts[$record['status']] ?? 0) + 1;
                    continue;
                }

                // Get source filesystem
                $firstAsset = $group[0];
                $volumeHandle = $record['volumeHandle'] ?? $firstAsset['volumeHandle'] ?? null;
                if (!$volumeHandle) {
                    $batchSkipped++;
                    continue;
                }

                $sourceVolume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
                if (!$sourceVolume) {
                    $batchSkipped++;
                    continue;
                }

                $sourceFs = $sourceVolume->getFs();

                // Create temp path
                $tempFolder = 'temp/' . $this->migrationId . '/' . md5($fileKey);
                $filename = basename($relativePath);
                $tempPath = $tempFolder . '/' . $filename;

                try {
                    // Check if source exists
                    if (!$sourceFs->fileExists($relativePath)) {
                        $batchSkipped++;
                        continue;
                    }

                    // Copy to quarantine temp
                    $content = $sourceFs->read($relativePath);
                    $fileSize = strlen($content);
                    $fileHash = md5($content);

                    $quarantineFs->write($tempPath, $content, []);

                    // Update record
                    $db->createCommand()->update('{{%migration_file_duplicates}}', [
                        'tempPath' => $tempPath,
                        'physicalFileHash' => $fileHash,
                        'fileSize' => $fileSize,
                        'status' => 'staged',
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                    ], [
                        'migrationId' => $this->migrationId,
                        'fileKey' => $fileKey,
                    ])->execute();

                    $batchSuccess++;

                } catch (\Exception $e) {
                    Craft::warning("Failed to stage file {$relativePath}: " . $e->getMessage(), __METHOD__);
                    $batchSkipped++;
                }

                $processed++;
            }

            $this->controller->stdout("{$batchSuccess} staged, {$batchSkipped} skipped", Console::FG_GREEN);
            if (!empty($statusCounts)) {
                $this->controller->stdout(" (", Console::FG_GREY);
                $statusParts = [];
                foreach ($statusCounts as $status => $count) {
                    $statusParts[] = "{$status}: {$count}";
                }
                $this->controller->stdout(implode(', ', $statusParts), Console::FG_GREY);
                $this->controller->stdout(")", Console::FG_GREY);
            }
            $this->controller->stdout("\n");
            $this->controller->stdout("  Progress: {$processed}/{$totalFiles} files\n");
        }

        $this->controller->stdout("\n  ✓ Staging complete\n", Console::FG_GREEN);
    }

    /**
     * Verify file safety before proceeding with duplicate deletion
     *
     * @param array $duplicateGroups Duplicate groups
     * @param $quarantineVolume Quarantine volume
     * @return array Status report
     * @throws \Exception If files are not safely backed up
     */
    public function verifyFileSafety(array $duplicateGroups, $quarantineVolume): array
    {
        if (empty($duplicateGroups)) {
            return ['safe' => 0, 'unsafe' => 0, 'total' => 0];
        }

        $db = Craft::$app->getDb();
        $quarantineFs = $quarantineVolume->getFs();

        $safe = 0;
        $unsafe = 0;
        $details = [];

        $this->controller->stdout("  Verifying file safety for " . count($duplicateGroups) . " duplicate groups...\n");

        foreach (array_keys($duplicateGroups) as $fileKey) {
            $record = $db->createCommand('
                SELECT * FROM {{%migration_file_duplicates}}
                WHERE migrationId = :migrationId AND fileKey = :fileKey
            ', [
                ':migrationId' => $this->migrationId,
                ':fileKey' => $fileKey,
            ])->queryOne();

            if (!$record) {
                $unsafe++;
                $details[] = "Missing record: {$fileKey}";
                continue;
            }

            // Check if file is staged
            if ($record['status'] === 'staged' || $record['status'] === 'analyzed') {
                $tempPath = $record['tempPath'];
                if ($tempPath && $quarantineFs->fileExists($tempPath)) {
                    $safe++;
                } else {
                    $unsafe++;
                    $details[] = "Staged file missing: {$fileKey} -> {$tempPath}";
                }
            } else if ($record['status'] === 'pending') {
                $unsafe++;
                $details[] = "Not staged: {$fileKey} (status: {$record['status']})";
            } else {
                $safe++;
            }
        }

        $total = count($duplicateGroups);

        if ($unsafe > 0) {
            $this->controller->stdout("\n  ⚠⚠⚠ FILE SAFETY CHECK FAILED ⚠⚠⚠\n", Console::FG_RED);
            $this->controller->stdout("  Safe: {$safe}/{$total}\n", Console::FG_RED);
            $this->controller->stdout("  Unsafe: {$unsafe}/{$total}\n", Console::FG_RED);
            $this->controller->stdout("\n  Details:\n", Console::FG_YELLOW);
            foreach ($details as $detail) {
                $this->controller->stdout("    - {$detail}\n", Console::FG_YELLOW);
            }
            $this->controller->stdout("\n", Console::FG_RED);
        } else {
            $this->controller->stdout("  ✓ All files verified safe: {$safe}/{$total}\n", Console::FG_GREEN);
        }

        return [
            'safe' => $safe,
            'unsafe' => $unsafe,
            'total' => $total,
            'details' => $details,
        ];
    }

    /**
     * Determine active (primary) assets for each duplicate group
     *
     * @param array $duplicateGroups Duplicate groups
     * @param int $batchSize Batch size
     */
    public function determineActiveAssets(array $duplicateGroups, int $batchSize = 100): void
    {
        if (empty($duplicateGroups)) {
            return;
        }

        $db = Craft::$app->getDb();
        $totalGroups = count($duplicateGroups);
        $processed = 0;

        $this->controller->stdout("  Analyzing {$totalGroups} duplicate groups to determine primary assets...\n\n");

        $batches = array_chunk(array_keys($duplicateGroups), $batchSize, true);

        foreach ($batches as $batchIndex => $fileKeys) {
            $batchNum = $batchIndex + 1;
            $this->controller->stdout("  [Batch {$batchNum}/" . count($batches) . "] ", Console::FG_CYAN);
            $this->controller->stdout("Analyzing " . count($fileKeys) . " groups... ");

            foreach ($fileKeys as $fileKey) {
                $group = $duplicateGroups[$fileKey];

                // Get duplicate record
                $record = $db->createCommand('
                    SELECT * FROM {{%migration_file_duplicates}}
                    WHERE migrationId = :migrationId AND fileKey = :fileKey
                ', [
                    ':migrationId' => $this->migrationId,
                    ':fileKey' => $fileKey,
                ])->queryOne();

                if (!$record || $record['status'] === 'analyzed') {
                    continue;
                }

                // Determine primary asset
                $primaryAsset = $this->selectPrimaryAsset($group);

                // Update record
                $db->createCommand()->update('{{%migration_file_duplicates}}', [
                    'primaryAssetId' => $primaryAsset['assetId'],
                    'status' => 'analyzed',
                    'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                ], [
                    'migrationId' => $this->migrationId,
                    'fileKey' => $fileKey,
                ])->execute();

                $processed++;
            }

            $this->controller->stdout("complete\n", Console::FG_GREEN);
            $this->controller->stdout("  Progress: {$processed}/{$totalGroups} groups\n");
        }

        $this->controller->stdout("\n  ✓ Analysis complete\n", Console::FG_GREEN);
    }

    /**
     * Select primary asset from duplicate group
     *
     * Priority:
     * 1. Asset in priority folder (configurable patterns)
     * 2. Asset in volume with priority pattern in name
     * 3. Most used asset (most relations)
     * 4. First asset
     *
     * @param array $group Duplicate group
     * @return array Primary asset
     */
    public function selectPrimaryAsset(array $group): array
    {
        // Priority 1: Asset in priority folder
        foreach ($group as $asset) {
            $folderPath = $asset['folderPath'] ?? '';
            foreach ($this->priorityFolderPatterns as $pattern) {
                if (stripos($folderPath, $pattern) !== false) {
                    return $asset;
                }
            }
        }

        // Priority 2: Volume with priority pattern
        foreach ($group as $asset) {
            foreach ($this->priorityFolderPatterns as $pattern) {
                if (stripos($asset['volumeName'], $pattern) !== false) {
                    return $asset;
                }
            }
        }

        // Priority 3: Most used
        $db = Craft::$app->getDb();
        $maxReferences = 0;
        $mostUsedAsset = null;

        foreach ($group as $asset) {
            $refCount = $db->createCommand('
                SELECT COUNT(*) FROM {{%relations}}
                WHERE targetId = :assetId
            ', [':assetId' => $asset['assetId']])->queryScalar();

            if ($refCount > $maxReferences) {
                $maxReferences = $refCount;
                $mostUsedAsset = $asset;
            }
        }

        if ($mostUsedAsset) {
            return $mostUsedAsset;
        }

        // Fallback: first asset
        return $group[0];
    }

    /**
     * Delete unused duplicate assets
     *
     * Deletes asset records that are not primary and have no relations.
     *
     * @param array $duplicateGroups Duplicate groups
     * @param int $batchSize Batch size
     */
    public function deleteUnusedDuplicateAssets(array $duplicateGroups, int $batchSize = 100): void
    {
        if (empty($duplicateGroups)) {
            return;
        }

        $db = Craft::$app->getDb();
        $totalDeleted = 0;
        $totalKept = 0;

        $this->controller->stdout("  Cleaning up unused duplicate asset records...\n\n");

        $batches = array_chunk(array_keys($duplicateGroups), $batchSize, true);

        foreach ($batches as $batchIndex => $fileKeys) {
            $batchNum = $batchIndex + 1;
            $this->controller->stdout("  [Batch {$batchNum}/" . count($batches) . "] ", Console::FG_CYAN);
            $this->controller->stdout("Processing " . count($fileKeys) . " groups... ");

            $batchDeleted = 0;
            $batchKept = 0;

            foreach ($fileKeys as $fileKey) {
                $group = $duplicateGroups[$fileKey];

                // Get primary asset ID
                $record = $db->createCommand('
                    SELECT primaryAssetId FROM {{%migration_file_duplicates}}
                    WHERE migrationId = :migrationId AND fileKey = :fileKey
                ', [
                    ':migrationId' => $this->migrationId,
                    ':fileKey' => $fileKey,
                ])->queryOne();

                if (!$record || !$record['primaryAssetId']) {
                    continue;
                }

                $primaryAssetId = $record['primaryAssetId'];

                foreach ($group as $asset) {
                    if ($asset['assetId'] == $primaryAssetId) {
                        $batchKept++;
                        continue;
                    }

                    // Check if asset is referenced
                    $refCount = $db->createCommand('
                        SELECT COUNT(*) FROM {{%relations}}
                        WHERE targetId = :assetId
                    ', [':assetId' => $asset['assetId']])->queryScalar();

                    if ($refCount > 0) {
                        $batchKept++;
                        Craft::info("Keeping used asset {$asset['assetId']} (has {$refCount} references)", __METHOD__);
                    } else {
                        try {
                            $assetElement = Craft::$app->getElements()->getElementById($asset['assetId']);
                            if ($assetElement) {
                                Craft::$app->getElements()->deleteElement($assetElement);
                                $batchDeleted++;

                                $this->changeLogManager->logChange([
                                    'action' => 'delete_unused_duplicate_asset',
                                    'assetId' => $asset['assetId'],
                                    'filename' => $asset['filename'],
                                    'fileKey' => $fileKey,
                                    'reason' => 'Duplicate asset with no references'
                                ]);
                            }
                        } catch (\Exception $e) {
                            Craft::warning("Failed to delete unused asset {$asset['assetId']}: " . $e->getMessage(), __METHOD__);
                        }
                    }
                }
            }

            $totalDeleted += $batchDeleted;
            $totalKept += $batchKept;

            $this->controller->stdout("{$batchDeleted} deleted, {$batchKept} kept\n", Console::FG_GREEN);
        }

        $this->controller->stdout("\n  ✓ Cleanup complete: {$totalDeleted} unused assets deleted, {$totalKept} kept\n", Console::FG_GREEN);
    }

    /**
     * Resolve duplicate assets
     *
     * Merges duplicate asset records into a single "winner" asset by:
     * - Selecting the best asset using DuplicateResolver::pickWinner()
     * - Transferring all relations to the winner
     * - Upgrading winner's file if loser has a larger file
     * - Safely deleting duplicate asset records
     *
     * @param array $duplicates Duplicate sets (filename => [asset data])
     * @param $targetVolume Target volume
     */
    public function resolveDuplicateAssets(array $duplicates, $targetVolume): void
    {
        if (empty($duplicates)) {
            $this->controller->stdout("  No duplicates to resolve\n\n", Console::FG_GREEN);
            return;
        }

        $totalSets = count($duplicates);
        $totalDuplicates = 0;
        foreach ($duplicates as $dupSet) {
            $totalDuplicates += count($dupSet) - 1; // -1 because one will be kept
        }

        $this->controller->stdout("  Processing {$totalSets} duplicate sets ({$totalDuplicates} duplicates to merge)...\n");
        $this->controller->stdout("  NOTE: Files are protected by Phase 1.7 staging - safe to delete asset records\n\n", Console::FG_CYAN);

        $resolved = 0;
        $errors = 0;
        $setNum = 0;
        $stats = ['duplicates_resolved' => 0];

        foreach ($duplicates as $filename => $dupAssets) {
            $setNum++;

            if (count($dupAssets) < 2) {
                continue;
            }

            $this->controller->stdout("  [{$setNum}/{$totalSets}] Resolving '{$filename}' (" . count($dupAssets) . " copies)... ");

            try {
                // Get full Asset objects
                $assets = [];
                foreach ($dupAssets as $assetData) {
                    $asset = Asset::findOne($assetData['id']);
                    if ($asset) {
                        $assets[] = $asset;
                    }
                }

                if (count($assets) < 2) {
                    $this->controller->stdout("skipped (assets not found)\n", Console::FG_YELLOW);
                    continue;
                }

                // Pick the winner
                $winner = $assets[0];
                foreach (array_slice($assets, 1) as $asset) {
                    $currentWinner = \csabourin\spaghettiMigrator\helpers\DuplicateResolver::pickWinner($winner, $asset);
                    if ($currentWinner->id !== $winner->id) {
                        $winner = $currentWinner;
                    }
                }

                // Merge all losers into winner
                $merged = 0;
                foreach ($assets as $asset) {
                    if ($asset->id === $winner->id) {
                        continue;
                    }

                    // Transfer relations from loser to winner
                    Craft::$app->getDb()->createCommand()
                        ->update(
                            '{{%relations}}',
                            ['targetId' => $winner->id],
                            ['targetId' => $asset->id]
                        )
                        ->execute();

                    // Check if loser has a larger file
                    $loserSize = $asset->size ?? 0;
                    $winnerSize = $winner->size ?? 0;

                    if ($loserSize > $winnerSize) {
                        try {
                            $loserFs = $asset->getVolume()->getFs();
                            $winnerFs = $winner->getVolume()->getFs();
                            $loserPath = $asset->getPath();
                            $winnerPath = $winner->getPath();

                            // SAFETY CHECK: Verify source file exists before attempting copy
                            if (!$loserFs->fileExists($loserPath)) {
                                Craft::warning(
                                    "Source file missing for asset {$asset->id} at {$loserPath} - file may have been staged in Phase 1.7",
                                    __METHOD__
                                );
                            } else {
                                // Copy loser's file to winner's path
                                $content = $loserFs->read($loserPath);
                                if ($content !== false) {
                                    $winnerFs->write($winnerPath, $content, []);

                                    // Update winner's size
                                    $winner->size = $loserSize;
                                    Craft::$app->getElements()->saveElement($winner, false);

                                    $this->changeLogManager->logChange([
                                        'action' => 'upgrade_asset_file',
                                        'assetId' => $winner->id,
                                        'filename' => $filename,
                                        'oldSize' => $winnerSize,
                                        'newSize' => $loserSize,
                                        'reason' => 'Loser had larger file'
                                    ]);
                                }
                            }
                        } catch (\Exception $e) {
                            Craft::warning(
                                "Could not copy file from asset {$asset->id} to winner {$winner->id}: " . $e->getMessage(),
                                __METHOD__
                            );
                        }
                    }

                    // SAFETY CHECK: Count how many OTHER assets reference this physical file
                    $db = Craft::$app->getDb();
                    $assetPath = $asset->getPath();
                    $assetFolderId = $asset->folderId;
                    $assetVolumeId = $asset->volumeId;

                    $sharedFileCount = $db->createCommand('
                        SELECT COUNT(*) FROM {{%assets}} a
                        INNER JOIN {{%elements}} e ON e.id = a.id
                        LEFT JOIN {{%volumefolders}} f ON f.id = a.folderId
                        WHERE a.filename = :filename
                        AND a.volumeId = :volumeId
                        AND f.path = (SELECT path FROM {{%volumefolders}} WHERE id = :folderId)
                        AND a.id != :assetId
                        AND e.dateDeleted IS NULL
                    ', [
                        ':filename' => $asset->filename,
                        ':volumeId' => $assetVolumeId,
                        ':folderId' => $assetFolderId,
                        ':assetId' => $asset->id,
                    ])->queryScalar();

                    if ($sharedFileCount > 0) {
                        // File is shared by other assets - log this for safety
                        Craft::info(
                            "Deleting asset {$asset->id} but file {$assetPath} is shared by {$sharedFileCount} other asset(s) - Craft will preserve the file",
                            __METHOD__
                        );

                        $this->changeLogManager->logChange([
                            'action' => 'delete_duplicate_asset_shared_file',
                            'assetId' => $asset->id,
                            'filename' => $filename,
                            'sharedByCount' => $sharedFileCount,
                            'reason' => 'File preserved - shared by other assets'
                        ]);
                    }

                    // Delete the loser asset (Craft will preserve file if shared)
                    Craft::$app->getElements()->deleteElement($asset, true);
                    $merged++;
                }

                $this->controller->stdout("merged {$merged} into asset #{$winner->id}\n", Console::FG_GREEN);
                $resolved += $merged;
                $stats['duplicates_resolved'] += $merged;

            } catch (\Exception $e) {
                $this->controller->stdout("error: " . $e->getMessage() . "\n", Console::FG_RED);
                $errors++;
                Craft::error("Error resolving duplicates for '{$filename}': " . $e->getMessage(), __METHOD__);
            }
        }

        $this->controller->stdout("\n  Summary:\n");
        $this->controller->stdout("    Duplicates merged: {$resolved}\n", Console::FG_GREEN);
        if ($errors > 0) {
            $this->controller->stdout("    Errors: {$errors}\n", Console::FG_RED);
        }
        $this->controller->stdout("\n");
    }

    /**
     * Cleanup temp files from quarantine
     *
     * @param $quarantineVolume Quarantine volume
     */
    public function cleanupTempFiles($quarantineVolume): void
    {
        $db = Craft::$app->getDb();
        $quarantineFs = $quarantineVolume->getFs();

        $this->controller->stdout("  Cleaning up temp files...\n");

        // Get all records with temp files
        $records = $db->createCommand('
            SELECT * FROM {{%migration_file_duplicates}}
            WHERE migrationId = :migrationId AND tempPath IS NOT NULL
        ', [':migrationId' => $this->migrationId])->queryAll();

        $cleaned = 0;
        foreach ($records as $record) {
            try {
                if ($quarantineFs->fileExists($record['tempPath'])) {
                    $quarantineFs->deleteFile($record['tempPath']);
                    $cleaned++;
                }
            } catch (\Exception $e) {
                Craft::warning("Failed to delete temp file {$record['tempPath']}: " . $e->getMessage(), __METHOD__);
            }
        }

        $this->controller->stdout("  Cleaned {$cleaned} temp files\n", Console::FG_GREEN);

        // Try to remove temp directory
        try {
            $tempDir = 'temp/' . $this->migrationId;
            if ($quarantineFs->directoryExists($tempDir)) {
                $quarantineFs->deleteDirectory($tempDir);
                $this->controller->stdout("  Removed temp directory: {$tempDir}\n", Console::FG_GREEN);
            }
        } catch (\Exception $e) {
            // Not critical
            Craft::info("Could not remove temp directory: " . $e->getMessage(), __METHOD__);
        }
    }
}
