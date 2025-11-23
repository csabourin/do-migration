<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\migration\ValidationService;
use csabourin\spaghettiMigrator\services\migration\FileOperationsService;
use csabourin\spaghettiMigrator\services\migration\MigrationReporter;

/**
 * Inventory Builder Service
 *
 * Builds comprehensive inventories of assets and files, analyzes relationships,
 * and creates lookup indexes for efficient matching.
 *
 * Responsibilities:
 * - Build asset inventory with batching
 * - Build file inventory from filesystems
 * - Analyze asset-file relationships
 * - Build RTE field maps
 * - Create search indexes for file matching
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class InventoryBuilder
{
    /**
     * @var Controller Controller instance for output
     */
    private $controller;

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * @var ValidationService Validation service
     */
    private $validationService;

    /**
     * @var FileOperationsService File operations service
     */
    private $fileOpsService;

    /**
     * @var MigrationReporter Reporter for output
     */
    private $reporter;

    /**
     * @var int Batch size for processing
     */
    private $batchSize;

    /**
     * @var array|null Cached RTE field map
     */
    private $rteFieldMap = null;

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param MigrationConfig $config Configuration helper
     * @param ValidationService $validationService Validation service
     * @param FileOperationsService $fileOpsService File operations service
     * @param MigrationReporter $reporter Reporter for output
     */
    public function __construct(
        Controller $controller,
        MigrationConfig $config,
        ValidationService $validationService,
        FileOperationsService $fileOpsService,
        MigrationReporter $reporter
    ) {
        $this->controller = $controller;
        $this->config = $config;
        $this->validationService = $validationService;
        $this->fileOpsService = $fileOpsService;
        $this->reporter = $reporter;
        $this->batchSize = $config->getBatchSize();
    }

    /**
     * Build asset inventory with batch processing
     *
     * @param array $sourceVolumes Source volume instances
     * @param $targetVolume Target volume instance
     * @return array Asset inventory indexed by asset ID
     */
    public function buildAssetInventoryBatched(array $sourceVolumes, $targetVolume): array
    {
        $db = Craft::$app->getDb();

        $allVolumeIds = array_merge(
            [$targetVolume->id],
            array_map(fn($v) => $v->id, $sourceVolumes)
        );

        $volumeIdList = implode(',', $allVolumeIds);

        // Get total count
        $totalAssets = (int) $db->createCommand("
            SELECT COUNT(DISTINCT a.id)
            FROM assets a
            JOIN elements e ON e.id = a.id
                AND e.dateDeleted IS NULL
                AND e.archived = 0
                AND e.draftId IS NULL
            WHERE a.volumeId IN ({$volumeIdList})
                AND a.kind = 'image'
        ")->queryScalar();

        $this->controller->stdout("    Found {$totalAssets} total assets\n");
        $this->controller->stdout("    Processing in batches of {$this->batchSize}...\n");

        $inventory = [];
        $offset = 0;
        $batchNum = 0;

        $this->controller->stdout("    Progress: ");

        while ($offset < $totalAssets) {
            $assets = $db->createCommand("
                SELECT
                    a.id,
                    a.volumeId,
                    a.folderId,
                    a.filename,
                    a.kind,
                    vf.path as folderPath,
                    COUNT(DISTINCT r.id) as relationCount,
                    e.dateCreated,
                    e.dateUpdated
                FROM assets a
                JOIN elements e ON e.id = a.id
                    AND e.dateDeleted IS NULL
                    AND e.archived = 0
                    AND e.draftId IS NULL
                LEFT JOIN volumefolders vf ON vf.id = a.folderId
                LEFT JOIN relations r ON r.targetId = a.id
                LEFT JOIN elements re ON re.id = r.sourceId
                    AND re.dateDeleted IS NULL
                    AND re.archived = 0
                WHERE a.volumeId IN ({$volumeIdList})
                    AND a.kind = 'image'
                GROUP BY a.id
                LIMIT {$this->batchSize} OFFSET {$offset}
            ")->queryAll();

            if (empty($assets)) {
                break;
            }

            foreach ($assets as $asset) {
                $assetId = $asset['id'];
                $relationCount = (int) $asset['relationCount'];

                $inventory[$assetId] = [
                    'id' => $assetId,
                    'volumeId' => $asset['volumeId'],
                    'folderId' => $asset['folderId'],
                    'filename' => $asset['filename'],
                    'folderPath' => $asset['folderPath'] ?? '',
                    'relationCount' => $relationCount,
                    'inlineCount' => 0,
                    'totalUsage' => $relationCount,
                    'isUsed' => $relationCount > 0,
                    'dateCreated' => $asset['dateCreated'],
                    'dateUpdated' => $asset['dateUpdated']
                ];
            }

            $offset += $this->batchSize;
            $batchNum++;

            $this->reporter->safeStdout(".", Console::FG_GREEN);
            if ($batchNum % 50 === 0) {
                $processed = min($offset, $totalAssets);
                $pct = round(($processed / $totalAssets) * 100, 1);
                $this->reporter->safeStdout(" [{$processed}/{$totalAssets} - {$pct}%]\n    ");
            }
        }

        $this->controller->stdout("\n");

        $used = count(array_filter($inventory, fn($a) => $a['isUsed']));
        $unused = count($inventory) - $used;

        $this->controller->stdout("    ✓ Asset inventory: {$used} used, {$unused} unused\n\n", Console::FG_GREEN);

        return $inventory;
    }

    /**
     * Build file inventory from all volumes
     *
     * @param array $sourceVolumes Source volume instances
     * @param $targetVolume Target volume instance
     * @param $quarantineVolume Quarantine volume instance
     * @return array File inventory
     */
    public function buildFileInventory(array $sourceVolumes, $targetVolume, $quarantineVolume): array
    {
        // Deduplicate volumes by ID
        $volumesById = [];
        foreach ($sourceVolumes as $volume) {
            $volumesById[$volume->id] = $volume;
        }
        $volumesById[$targetVolume->id] = $targetVolume;

        $allVolumes = array_values($volumesById);
        $fileInventory = [];
        $totalFiles = 0;

        foreach ($allVolumes as $volume) {
            $this->controller->stdout("    Scanning {$volume->name} filesystem...\n");

            try {
                $fs = $volume->getFs();

                // Show parsed FS subfolder/root for debugging
                $parsedPrefix = $this->validationService->getFsPrefix($fs);
                if ($parsedPrefix !== '') {
                    $this->controller->stdout("      FS prefix: '{$parsedPrefix}'\n", Console::FG_GREY);
                }

                $this->controller->stdout("      Listing files... ");

                // Scan from volume root
                $scan = $this->scanFilesystem($fs, '', true);

                $this->controller->stdout("done\n", Console::FG_GREEN);
                $this->controller->stdout("      Processing entries... ");

                // Process all files from scan
                foreach ($scan['all'] as $entry) {
                    if (($entry['type'] ?? null) !== 'file') {
                        continue;
                    }

                    $fileInventory[] = [
                        'volumeId' => $volume->id,
                        'volumeName' => $volume->name,
                        'path' => $entry['path'],
                        'filename' => basename($entry['path']),
                        'size' => $entry['size'] ?? 0,
                        'lastModified' => $entry['lastModified'] ?? null,
                        'fs' => $fs,
                    ];

                    $totalFiles++;
                }

                $volumeFileCount = count($scan['all']) - count($scan['directories']);
                $this->controller->stdout("done\n", Console::FG_GREEN);
                $this->controller->stdout("      ✓ Found {$volumeFileCount} files\n", Console::FG_GREEN);

            } catch (\Throwable $e) {
                // FAIL FAST - don't continue with broken volumes
                throw new \Exception(
                    "CRITICAL: Cannot scan volume '{$volume->name}': {$e->getMessage()}\n" .
                    "Stack trace: {$e->getTraceAsString()}"
                );
            }
        }

        $this->controller->stdout("    ✓ Total files across all volumes: {$totalFiles}\n\n", Console::FG_GREEN);

        return $fileInventory;
    }

    /**
     * Scan filesystem recursively using Craft FS API
     *
     * @param $fs Filesystem instance
     * @param string $path Starting path
     * @param bool $recursive Whether to scan recursively
     * @param int|null $limit Optional limit on results
     * @return array Structured scan results
     */
    public function scanFilesystem($fs, string $path, bool $recursive, ?int $limit = null): array
    {
        $cleanPath = trim((string) $path, '/');

        // Use Craft FS API - returns craft\models\FsListing objects
        $iter = $fs->getFileList($cleanPath, (bool) $recursive);

        $result = [
            'all' => [],
            'images' => [],
            'directories' => [],
            'other' => [],
        ];

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $seenPaths = [];

        foreach ($iter as $item) {
            // Extract data from craft\models\FsListing object
            $extracted = $this->fileOpsService->extractFsListingData($item);

            if (!$extracted['path'] || isset($seenPaths[$extracted['path']])) {
                continue;
            }

            $seenPaths[$extracted['path']] = true;

            $entry = [
                'path' => $extracted['path'],
                'type' => $extracted['isDir'] ? 'dir' : 'file',
                'size' => $extracted['fileSize'],
                'lastModified' => $extracted['lastModified'],
            ];

            $result['all'][] = $entry;

            if ($extracted['isDir']) {
                $result['directories'][] = $extracted['path'];
            } else {
                $ext = strtolower(pathinfo($extracted['path'], PATHINFO_EXTENSION));
                if (in_array($ext, $imageExtensions, true)) {
                    $result['images'][] = $entry;
                } else {
                    $result['other'][] = $entry;
                }
            }

            // Apply limit if specified
            if ($limit && count($result['all']) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Analyze asset-file relationships
     *
     * @param array $assetInventory Asset inventory
     * @param array $fileInventory File inventory
     * @param $targetVolume Target volume instance
     * @param $quarantineVolume Quarantine volume instance
     * @return array Analysis results
     */
    public function analyzeAssetFileLinks(array $assetInventory, array $fileInventory, $targetVolume, $quarantineVolume): array
    {
        $this->controller->stdout("    Matching assets to files...\n");
        $this->controller->stdout("    Building file lookup index... ");

        $fileLookup = [];
        foreach ($fileInventory as $file) {
            $key = $file['volumeId'] . '/' . $file['filename'];
            if (!isset($fileLookup[$key])) {
                $fileLookup[$key] = [];
            }
            $fileLookup[$key][] = $file;
        }

        $this->controller->stdout("done (" . count($fileInventory) . " files)\n", Console::FG_GREEN);
        $this->controller->stdout("    Verifying asset file existence (may take a few minutes)...\n");

        $analysis = [
            'assets_with_files' => [],
            'broken_links' => [],
            'orphaned_files' => [],
            'used_assets_correct_location' => [],
            'used_assets_wrong_location' => [],
            'unused_assets' => [],
            'duplicates' => []
        ];

        $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);

        $total = count($assetInventory);
        $processed = 0;
        $lastProgress = 0;
        $startTime = microtime(true);

        foreach ($assetInventory as $asset) {
            $assetObj = Asset::findOne($asset['id']);
            if (!$assetObj) {
                $processed++;
                continue;
            }

            $expectedPath = $assetObj->getPath();
            $fileExists = false;

            try {
                $fs = $assetObj->getVolume()->getFs();
                $fileExists = $fs->fileExists($expectedPath);
            } catch (\Exception $e) {
                // Cannot verify
            }

            if ($fileExists) {
                $asset['fileExists'] = true;
                $asset['filePath'] = $expectedPath;
                $analysis['assets_with_files'][] = $asset;

                $isInTarget = $asset['volumeId'] == $targetVolume->id;
                $isInRoot = $asset['folderId'] == $targetRootFolder->id;

                if ($asset['isUsed']) {
                    if ($isInTarget && $isInRoot) {
                        $analysis['used_assets_correct_location'][] = $asset;
                    } else {
                        $analysis['used_assets_wrong_location'][] = $asset;
                    }
                } else {
                    // Only mark assets in TARGET volume as unused for quarantine
                    if ($isInTarget) {
                        $analysis['unused_assets'][] = $asset;
                    }
                }
            } else {
                $analysis['broken_links'][] = $asset;

                $key = $asset['volumeId'] . '/' . $asset['filename'];
                if (isset($fileLookup[$key])) {
                    $asset['possibleFiles'] = $fileLookup[$key];
                }
            }

            $processed++;

            // Calculate ETA
            $elapsed = microtime(true) - $startTime;
            $itemsPerSecond = $processed / max($elapsed, 0.1);
            $remaining = $total - $processed;
            $etaSeconds = $remaining / max($itemsPerSecond, 0.01);
            $etaFormatted = $this->reporter->formatDuration($etaSeconds);
            $pct = round(($processed / $total) * 100, 1);

            // Show progress every 5% or at completion
            if ($pct - $lastProgress >= 5 || $processed === $total) {
                $this->controller->stdout(
                    "    [Progress] {$pct}% complete ({$processed}/{$total}) - ETA: {$etaFormatted}\n",
                    Console::FG_CYAN
                );

                // Machine-readable progress marker for web interface
                $progressData = json_encode([
                    'percent' => $pct,
                    'current' => $processed,
                    'total' => $total,
                    'eta' => $etaFormatted,
                    'etaSeconds' => (int) $etaSeconds
                ]);
                $this->controller->stdout("__CLI_PROGRESS__{$progressData}__\n", Console::RESET);

                $lastProgress = $pct;
            }
        }

        $this->controller->stdout("    Identifying orphaned files... ");

        $assetFilenames = array_column($assetInventory, 'filename', 'filename');
        foreach ($fileInventory as $file) {
            if (!isset($assetFilenames[$file['filename']])) {
                // Only quarantine orphaned files from TARGET volume
                if ($file['volumeId'] == $targetVolume->id) {
                    $analysis['orphaned_files'][] = $file;
                }
            }
        }

        $this->controller->stdout("done\n", Console::FG_GREEN);
        $this->controller->stdout("    Checking for duplicates... ");

        // Check for duplicate filenames
        $filenameCounts = array_count_values(array_column($assetInventory, 'filename'));
        foreach ($filenameCounts as $filename => $count) {
            if ($count > 1) {
                $dupes = array_filter($assetInventory, fn($a) => $a['filename'] === $filename);
                $analysis['duplicates'][$filename] = array_values($dupes);
            }
        }

        // Check for multiple assets pointing to the same physical file path
        $pathMap = [];
        foreach ($assetInventory as $asset) {
            if (isset($asset['filePath'])) {
                $key = $asset['volumeId'] . '::' . $asset['filePath'];
                if (!isset($pathMap[$key])) {
                    $pathMap[$key] = [];
                }
                $pathMap[$key][] = $asset;
            }
        }

        // Add assets pointing to same file to duplicates
        foreach ($pathMap as $key => $assets) {
            if (count($assets) > 1) {
                $filename = $assets[0]['filename'];
                $dupKey = $filename . '_path_' . md5($key);
                $analysis['duplicates'][$dupKey] = array_values($assets);
            }
        }

        $this->controller->stdout("done\n\n", Console::FG_GREEN);

        return $analysis;
    }

    /**
     * Build RTE field map
     *
     * @param $db Database connection
     * @return array Field map indexed by field ID
     */
    public function buildRteFieldMap($db): array
    {
        if ($this->rteFieldMap !== null) {
            return $this->rteFieldMap;
        }

        $rteFields = $db->createCommand("
            SELECT id, handle, name, type, uid
            FROM fields
            WHERE type LIKE '%redactor%'
               OR type LIKE '%ckeditor%'
               OR type LIKE '%vizy%'
            ORDER BY handle
        ")->queryAll();

        $map = [];
        foreach ($rteFields as $field) {
            $map[$field['id']] = [
                'id' => $field['id'],
                'handle' => $field['handle'],
                'name' => $field['name'],
                'type' => $field['type'],
                'uid' => $field['uid']
            ];
        }

        $this->rteFieldMap = $map;
        return $map;
    }

    /**
     * Map RTE fields to content table columns
     *
     * @param $db Database connection
     * @param array $rteFieldMap RTE field map
     * @return array Column mappings
     */
    public function mapFieldsToColumns($db, array $rteFieldMap): array
    {
        $schema = $db->createCommand('SELECT DATABASE()')->queryScalar();

        $tables = $db->createCommand("
            SELECT DISTINCT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :schema
                AND (TABLE_NAME = 'content' OR TABLE_NAME LIKE 'matrixcontent%' OR TABLE_NAME LIKE '%content%')
                AND TABLE_TYPE = 'BASE TABLE'
        ", [':schema' => $schema])->queryAll();

        $mappings = [];

        foreach ($tables as $tableData) {
            $table = $tableData['TABLE_NAME'];

            $columns = $db->createCommand("
                SELECT COLUMN_NAME
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :schema
                    AND TABLE_NAME = :table
                    AND DATA_TYPE IN ('text', 'longtext', 'mediumtext')
                    AND COLUMN_NAME LIKE 'field_%'
            ", [
                ':schema' => $schema,
                ':table' => $table
            ])->queryAll();

            foreach ($columns as $colData) {
                $column = $colData['COLUMN_NAME'];

                foreach ($rteFieldMap as $fieldId => $field) {
                    $uid = $field['uid'];
                    $handle = $field['handle'];

                    $patterns = [
                        'field_' . strtolower($handle) . '_' . strtolower($uid),
                        'field_' . strtolower($uid),
                        'field_' . strtolower($handle),
                    ];

                    $columnLower = strtolower($column);

                    foreach ($patterns as $pattern) {
                        if (
                            $columnLower === $pattern ||
                            strpos($columnLower, $pattern) !== false
                        ) {
                            $mappings[] = [
                                'table' => $table,
                                'column' => $column,
                                'field_id' => $fieldId,
                                'field_handle' => $handle
                            ];

                            break 2;
                        }
                    }
                }
            }
        }

        return $mappings;
    }

    /**
     * Build asset lookup for efficient URL-to-asset matching
     *
     * @param array $assetInventory Asset inventory
     * @return array Lookup array
     */
    public function buildAssetLookup(array $assetInventory): array
    {
        $lookup = [];
        foreach ($assetInventory as $asset) {
            $filename = $asset['filename'];
            $lookup[$filename] = $asset;

            if (!empty($asset['folderPath'])) {
                $fullPath = trim($asset['folderPath'], '/') . '/' . $filename;
                $lookup[$fullPath] = $asset;
            }
        }
        return $lookup;
    }

    /**
     * Build file search indexes for matching strategies
     *
     * @param array $fileInventory File inventory
     * @return array Search indexes
     */
    public function buildFileSearchIndexes(array $fileInventory): array
    {
        $indexes = [
            'exact' => [],
            'case_insensitive' => [],
            'normalized' => [],
            'basename' => [],
            'by_size' => [],
        ];

        foreach ($fileInventory as $file) {
            $filename = $file['filename'];
            $size = $file['size'] ?? 0;

            // Exact match index
            if (!isset($indexes['exact'][$filename])) {
                $indexes['exact'][$filename] = [];
            }
            $indexes['exact'][$filename][] = $file;

            // Case-insensitive index
            $lowerFilename = strtolower($filename);
            if (!isset($indexes['case_insensitive'][$lowerFilename])) {
                $indexes['case_insensitive'][$lowerFilename] = [];
            }
            $indexes['case_insensitive'][$lowerFilename][] = $file;

            // Normalized index
            $normalized = $this->normalizeFilename($filename);
            if (!isset($indexes['normalized'][$normalized])) {
                $indexes['normalized'][$normalized] = [];
            }
            $indexes['normalized'][$normalized][] = $file;

            // Basename index
            $basename = pathinfo($filename, PATHINFO_FILENAME);
            if (!isset($indexes['basename'][$basename])) {
                $indexes['basename'][$basename] = [];
            }
            $indexes['basename'][$basename][] = $file;

            // Size index
            if ($size > 0) {
                if (!isset($indexes['by_size'][$size])) {
                    $indexes['by_size'][$size] = [];
                }
                $indexes['by_size'][$size][] = $file;
            }
        }

        return $indexes;
    }

    /**
     * Normalize filename for fuzzy matching
     *
     * @param string $filename Filename to normalize
     * @return string Normalized filename
     */
    public function normalizeFilename(string $filename): string
    {
        // Remove special characters, convert to lowercase
        $normalized = preg_replace('/[^a-z0-9.]/', '', strtolower($filename));
        return $normalized;
    }

    /**
     * Find asset by URL from inline images
     *
     * @param string $url Image URL
     * @param array $assetLookup Asset lookup array
     * @return array|null Asset data or null
     */
    public function findAssetByUrl(string $url, array $assetLookup): ?array
    {
        $url = trim($url);
        $url = preg_replace('/[?#].*$/', '', $url);
        $url = preg_replace('/^https?:\/\/[^\/]+/i', '', $url);
        $url = ltrim($url, '/');

        // Direct lookup
        if (isset($assetLookup[$url])) {
            return $assetLookup[$url];
        }

        $filename = basename($url);
        if (isset($assetLookup[$filename])) {
            return $assetLookup[$filename];
        }

        // Try common prefixes
        $prefixes = ['uploads/', 'uploads/images/', 'assets/', 'images/', '_optimisedImages/'];

        foreach ($prefixes as $prefix) {
            if (strpos($url, $prefix) === 0) {
                $cleanUrl = substr($url, strlen($prefix));
                if (isset($assetLookup[$cleanUrl])) {
                    return $assetLookup[$cleanUrl];
                }
            }
        }

        // Fallback: search by filename
        foreach ($assetLookup as $key => $asset) {
            if (basename($key) === $filename) {
                return $asset;
            }
        }

        return null;
    }
}
