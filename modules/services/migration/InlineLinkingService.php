<?php

namespace csabourin\craftS3SpacesMigration\services\migration;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\StringHelper;
use csabourin\craftS3SpacesMigration\helpers\MigrationConfig;
use csabourin\craftS3SpacesMigration\services\ChangeLogManager;
use csabourin\craftS3SpacesMigration\services\ErrorRecoveryManager;
use csabourin\craftS3SpacesMigration\services\ProgressTracker;
use csabourin\craftS3SpacesMigration\services\migration\InventoryBuilder;
use csabourin\craftS3SpacesMigration\services\migration\MigrationReporter;

/**
 * Inline Linking Service
 *
 * Links inline images in RTE fields to asset records by:
 * - Detecting inline <img> tags in RTE content
 * - Matching URLs to assets
 * - Replacing src URLs with {asset:ID:url} references
 * - Creating proper asset relations
 *
 * Features:
 * - Batch processing for memory efficiency
 * - Optimized relation checking (batch queries vs N queries)
 * - Progress tracking with ETA
 * - Checkpoint support for resume
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class InlineLinkingService
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
     * @var InventoryBuilder Inventory builder
     */
    private $inventoryBuilder;

    /**
     * @var MigrationReporter Reporter
     */
    private $reporter;

    /**
     * @var int Batch size
     */
    private $batchSize;

    /**
     * @var int Progress reporting interval
     */
    private $progressReportingInterval;

    /**
     * @var int Checkpoint frequency
     */
    private $checkpointEveryBatches;

    /**
     * @var $migrationLock Migration lock
     */
    private $migrationLock;

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param MigrationConfig $config Configuration
     * @param ChangeLogManager $changeLogManager Change log manager
     * @param ErrorRecoveryManager $errorRecoveryManager Error recovery manager
     * @param InventoryBuilder $inventoryBuilder Inventory builder
     * @param MigrationReporter $reporter Reporter
     * @param $migrationLock Migration lock
     */
    public function __construct(
        Controller $controller,
        MigrationConfig $config,
        ChangeLogManager $changeLogManager,
        ErrorRecoveryManager $errorRecoveryManager,
        InventoryBuilder $inventoryBuilder,
        MigrationReporter $reporter,
        $migrationLock
    ) {
        $this->controller = $controller;
        $this->config = $config;
        $this->changeLogManager = $changeLogManager;
        $this->errorRecoveryManager = $errorRecoveryManager;
        $this->inventoryBuilder = $inventoryBuilder;
        $this->reporter = $reporter;
        $this->migrationLock = $migrationLock;
        $this->batchSize = $config->getBatchSize();
        $this->progressReportingInterval = 50;
        $this->checkpointEveryBatches = $config->getCheckpointEveryBatches();
    }

    /**
     * Link inline images in batches
     *
     * @param $db Database connection
     * @param array $assetInventory Asset inventory
     * @param $targetVolume Target volume instance
     * @param callable $saveCheckpoint Checkpoint callback
     * @return array Statistics
     */
    public function linkInlineImagesBatched($db, array $assetInventory, $targetVolume, callable $saveCheckpoint): array
    {
        // Build field map
        $rteFieldMap = $this->inventoryBuilder->buildRteFieldMap($db);

        if (empty($rteFieldMap)) {
            $this->controller->stdout("  ⚠ No RTE fields found - skipping inline linking\n\n", Console::FG_YELLOW);
            return ['rows_updated' => 0, 'relations_created' => 0];
        }

        $rteFieldCount = count($rteFieldMap);
        $this->controller->stdout("  Found {$rteFieldCount} RTE fields\n");

        $fieldColumnMap = $this->inventoryBuilder->mapFieldsToColumns($db, $rteFieldMap);
        $columnCount = count($fieldColumnMap);

        $this->controller->stdout("  Mapped to {$columnCount} content columns\n\n");

        if ($columnCount === 0) {
            return ['rows_updated' => 0, 'relations_created' => 0];
        }

        $assetLookup = $this->inventoryBuilder->buildAssetLookup($assetInventory);

        $stats = [
            'rows_scanned' => 0,
            'rows_with_images' => 0,
            'images_found' => 0,
            'images_linked' => 0,
            'images_already_linked' => 0,
            'images_no_match' => 0,
            'rows_updated' => 0,
            'relations_created' => 0
        ];

        $this->reporter->printProgressLegend();
        $this->controller->stdout("  Progress: ");

        $columnNum = 0;
        $lastLockRefresh = time();

        foreach ($fieldColumnMap as $mapping) {
            $table = $mapping['table'];
            $column = $mapping['column'];
            $fieldId = $mapping['field_id'];

            // Get total rows for progress tracking
            try {
                $totalRows = (int) $db->createCommand("
                    SELECT COUNT(*)
                    FROM `{$table}`
                    WHERE (`{$column}` LIKE '%<img%' OR `{$column}` LIKE '%&lt;img%')
                        AND elementId IS NOT NULL
                ")->queryScalar();
            } catch (\Exception $e) {
                $this->reporter->safeStdout("x", Console::FG_RED);
                continue;
            }

            if ($totalRows === 0) {
                continue;
            }

            // Create progress tracker
            $progress = new ProgressTracker("Inline Linking: {$table}.{$column}", $totalRows, $this->progressReportingInterval);

            $offset = 0;
            $batchNum = 0;

            while (true) {
                // Refresh lock periodically
                if (time() - $lastLockRefresh > 60) {
                    $this->migrationLock->refresh();
                    $lastLockRefresh = time();
                }

                try {
                    $rows = $db->createCommand("
                        SELECT id, elementId, `{$column}` as content
                        FROM `{$table}`
                        WHERE (`{$column}` LIKE '%<img%' OR `{$column}` LIKE '%&lt;img%')
                            AND elementId IS NOT NULL
                        LIMIT {$this->batchSize} OFFSET {$offset}
                    ")->queryAll();
                } catch (\Exception $e) {
                    $this->reporter->safeStdout("x", Console::FG_RED);
                    break;
                }

                if (empty($rows)) {
                    break;
                }

                $stats['rows_scanned'] += count($rows);
                $stats['rows_with_images'] += count($rows);

                // Process batch
                $batchResult = $this->errorRecoveryManager->retryOperation(
                    fn() => $this->processInlineImageBatch($rows, $table, $column, $fieldId, $assetLookup, $db),
                    "inline_image_batch_{$table}_{$column}_{$batchNum}"
                );

                if ($batchResult) {
                    $stats['rows_updated'] += $batchResult['rows_updated'];
                    $stats['relations_created'] += $batchResult['relations_created'];
                    $stats['images_linked'] += $batchResult['images_linked'];
                    $stats['images_already_linked'] += $batchResult['images_already_linked'];
                    $stats['images_no_match'] += $batchResult['images_no_match'];
                    $stats['images_found'] += $batchResult['images_found'];
                }

                $offset += $this->batchSize;
                $batchNum++;

                // Update progress
                if ($progress->increment(count($rows))) {
                    $this->reporter->safeStdout(" " . $progress->getProgressString() . "\n  ");
                } else {
                    $this->reporter->safeStdout(".", Console::FG_GREEN);
                }

                // Checkpoint periodically
                if ($batchNum % $this->checkpointEveryBatches === 0) {
                    $saveCheckpoint([
                        'inline_batch' => $batchNum,
                        'column' => "{$table}.{$column}",
                        'stats' => $stats
                    ]);
                }
            }

            $columnNum++;
        }

        $this->controller->stdout("\n\n");
        $this->reporter->printInlineLinkingResults($stats);

        return $stats;
    }

    /**
     * Process single batch of inline images
     *
     * @param array $rows Content rows
     * @param string $table Table name
     * @param string $column Column name
     * @param int $fieldId Field ID
     * @param array $assetLookup Asset lookup array
     * @param $db Database connection
     * @return array Batch statistics
     */
    private function processInlineImageBatch(
        array $rows,
        string $table,
        string $column,
        int $fieldId,
        array $assetLookup,
        $db
    ): array {
        $batchStats = [
            'rows_updated' => 0,
            'relations_created' => 0,
            'images_linked' => 0,
            'images_already_linked' => 0,
            'images_no_match' => 0,
            'images_found' => 0
        ];

        // OPTIMIZATION: Pre-load all existing relations for elements in this batch
        $elementIds = array_unique(array_filter(array_column($rows, 'elementId')));
        $existingRelationsMap = [];
        $maxSortOrders = [];

        if (!empty($elementIds) && $fieldId) {
            try {
                // Get all existing relations in one query
                $existingRelations = $db->createCommand("
                    SELECT sourceId, targetId, fieldId
                    FROM relations
                    WHERE sourceId IN (" . implode(',', $elementIds) . ")
                      AND fieldId = :fieldId
                ", [':fieldId' => $fieldId])->queryAll();

                // Build lookup map
                foreach ($existingRelations as $rel) {
                    $key = $rel['sourceId'] . '_' . $rel['targetId'] . '_' . $rel['fieldId'];
                    $existingRelationsMap[$key] = true;
                }

                // Get max sort orders in one query
                $maxSorts = $db->createCommand("
                    SELECT sourceId, MAX(sortOrder) as maxSort
                    FROM relations
                    WHERE sourceId IN (" . implode(',', $elementIds) . ")
                      AND fieldId = :fieldId
                    GROUP BY sourceId
                ", [':fieldId' => $fieldId])->queryAll();

                foreach ($maxSorts as $sortData) {
                    $maxSortOrders[$sortData['sourceId']] = (int)$sortData['maxSort'];
                }
            } catch (\Exception $e) {
                Craft::warning("Could not pre-load relations for batch: " . $e->getMessage(), __METHOD__);
            }
        }

        foreach ($rows as $row) {
            $content = $row['content'];
            $originalContent = $content;
            $rowId = $row['id'];
            $elementId = $row['elementId'];
            $modified = false;

            preg_match_all('/<img[^>]+>/i', $content, $imgTags);

            foreach ($imgTags[0] as $imgTag) {
                $batchStats['images_found']++;

                // Check if already linked
                if (
                    strpos($imgTag, '{asset:') !== false ||
                    strpos($imgTag, 'data-asset-id=') !== false
                ) {
                    $batchStats['images_already_linked']++;
                    continue;
                }

                if (!preg_match('/src=["\']([^"\']+)["\']/i', $imgTag, $srcMatch)) {
                    continue;
                }

                $src = $srcMatch[1];
                $asset = $this->inventoryBuilder->findAssetByUrl($src, $assetLookup);

                if (!$asset) {
                    $batchStats['images_no_match']++;
                    continue;
                }

                $assetId = $asset['id'];

                // Replace src with asset reference
                $newImgTag = preg_replace(
                    '/src=["\']([^"\']+)["\']/i',
                    'src="{asset:' . $assetId . ':url}"',
                    $imgTag
                );

                $content = str_replace($imgTag, $newImgTag, $content);
                $modified = true;
                $batchStats['images_linked']++;

                // Create relation
                if ($fieldId && $elementId) {
                    try {
                        // Check pre-loaded map
                        $relationKey = $elementId . '_' . $assetId . '_' . $fieldId;
                        $existingRelation = isset($existingRelationsMap[$relationKey]);

                        if (!$existingRelation) {
                            // Use pre-loaded max sort order
                            $maxSort = $maxSortOrders[$elementId] ?? 0;

                            $db->createCommand()->insert('relations', [
                                'fieldId' => $fieldId,
                                'sourceId' => $elementId,
                                'sourceSiteId' => null,
                                'targetId' => $assetId,
                                'sortOrder' => $maxSort + 1,
                                'dateCreated' => date('Y-m-d H:i:s'),
                                'dateUpdated' => date('Y-m-d H:i:s'),
                                'uid' => StringHelper::UUID()
                            ])->execute();

                            // Update maps for subsequent images in same element
                            $existingRelationsMap[$relationKey] = true;
                            $maxSortOrders[$elementId] = $maxSort + 1;

                            $batchStats['relations_created']++;
                        }
                    } catch (\Exception $e) {
                        // Continue without relation
                    }
                }
            }

            if ($modified) {
                try {
                    $db->createCommand()->update(
                        $table,
                        [$column => $content],
                        ['id' => $rowId]
                    )->execute();

                    $batchStats['rows_updated']++;

                    $this->changeLogManager->logChange([
                        'type' => 'inline_image_linked',
                        'table' => $table,
                        'column' => $column,
                        'rowId' => $rowId,
                        'elementId' => $elementId,
                        'originalContent' => $originalContent,
                        'newContent' => $content
                    ]);

                } catch (\Exception $e) {
                    throw $e;
                }
            }
        }

        return $batchStats;
    }

    /**
     * Estimate inline linking work
     *
     * @param $db Database connection
     * @return array Estimation results
     */
    public function estimateInlineLinking($db): array
    {
        $rteFieldMap = $this->inventoryBuilder->buildRteFieldMap($db);
        $rteFieldCount = count($rteFieldMap);

        if ($rteFieldCount === 0) {
            return [
                'columns_found' => 0,
                'images_estimate' => 0,
                'time_estimate' => '0s'
            ];
        }

        $fieldColumnMap = $this->inventoryBuilder->mapFieldsToColumns($db, $rteFieldMap);
        $columnCount = count($fieldColumnMap);

        $totalRows = 0;
        foreach ($fieldColumnMap as $mapping) {
            $table = $mapping['table'];
            $column = $mapping['column'];

            try {
                $rowCount = (int) $db->createCommand("
                    SELECT COUNT(*)
                    FROM `{$table}`
                    WHERE `{$column}` LIKE '%<img%'
                ")->queryScalar();

                $totalRows += $rowCount;
            } catch (\Exception $e) {
                continue;
            }
        }

        // Estimate ~2 images per row with images
        $imagesEstimate = $totalRows * 2;

        // Estimate ~1000 rows/second
        $timeSeconds = $totalRows / 1000;
        $timeEstimate = $this->reporter->formatDuration($timeSeconds);

        return [
            'columns_found' => $columnCount,
            'images_estimate' => $imagesEstimate,
            'time_estimate' => $timeEstimate
        ];
    }
}
