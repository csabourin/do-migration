<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\DuplicateResolver;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\ChangeLogManager;
use csabourin\spaghettiMigrator\services\migration\FilesystemNestingDetector;
use csabourin\spaghettiMigrator\services\migration\InventoryBuilder;
use csabourin\spaghettiMigrator\services\migration\MigrationReporter;

/**
 * Nested Filesystem Service
 *
 * Handles migration scenarios where source and target filesystems share the same
 * bucket but different subfolders, creating a parent-child relationship.
 *
 * PROBLEM:
 * When a volume exists at the bucket root while another volume is in a subfolder,
 * direct copying can cause:
 * - Source and destination being the same physical location
 * - Infinite loops during indexing
 * - Duplicate files and transforms
 *
 * SOLUTION: Two-step process using local temporary files as intermediary:
 * 1. Download from remote source to LOCAL temp folder
 * 2. Upload from LOCAL temp folder to remote destination
 *
 * EXAMPLE SCENARIOS:
 * - Volume at bucket root → subfolder consolidation (e.g., optimisedImages)
 * - Subfolder → deeper subfolder reorganization
 * - Any nested filesystem configuration
 *
 * @author Christian Sabourin
 * @version 2.0.0
 */
class NestedFilesystemService
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
     * @var InventoryBuilder Inventory builder
     */
    private $inventoryBuilder;

    /**
     * @var MigrationReporter Reporter
     */
    private $reporter;

    /**
     * @var bool Dry run mode
     */
    private $dryRun;

    /**
     * @var bool Auto-confirm mode
     */
    private $yes;

    /**
     * @var array Transform patterns for detection
     */
    private $transformPatterns = [
        '/_\d+x\d+_crop_center-center/',  // _800x600_crop_center-center
        '/_\d+x\d+_/',                      // _800x600_
        '/_[a-zA-Z]+\d*x\d*/',              // _thumb200x200
    ];

    /**
     * @var FilesystemNestingDetector Filesystem nesting detector
     */
    private $nestingDetector;

    /**
     * @var MigrationConfig
     */
    private $config;

    /**
     * Constructor
     *
     * @param Controller $controller Controller instance
     * @param ChangeLogManager $changeLogManager Change log manager
     * @param InventoryBuilder $inventoryBuilder Inventory builder
     * @param MigrationReporter $reporter Reporter
     * @param bool $dryRun Dry run mode
     * @param bool $yes Auto-confirm mode
     */
    public function __construct(
        Controller $controller,
        ChangeLogManager $changeLogManager,
        InventoryBuilder $inventoryBuilder,
        MigrationReporter $reporter,
        bool $dryRun = false,
        bool $yes = false
    ) {
        $this->controller = $controller;
        $this->changeLogManager = $changeLogManager;
        $this->inventoryBuilder = $inventoryBuilder;
        $this->reporter = $reporter;
        $this->dryRun = $dryRun;
        $this->yes = $yes;
        $this->nestingDetector = new FilesystemNestingDetector();
        $this->config = MigrationConfig::getInstance();
    }

    /**
     * Handle optimised images at root migration
     *
     * @param array $assetInventory Asset inventory
     * @param array $fileInventory File inventory
     * @param $targetVolume Target volume instance
     * @param $quarantineVolume Quarantine volume instance
     * @return array Migration statistics
     */
    public function handleOptimisedImagesAtRoot(
        array $assetInventory,
        array $fileInventory,
        $targetVolume,
        $quarantineVolume
    ): array {
        $optimisedHandle = $this->config->getOptimisedImagesVolumeHandle();
        $targetHandle = $targetVolume->handle;
        $quarantineHandle = $quarantineVolume ? $quarantineVolume->handle : $this->config->getQuarantineVolumeHandle();

        $this->reporter->printPhaseHeader("PHASE 0.5: OPTIMISED IMAGES → IMAGES MIGRATION");

        $volumesService = Craft::$app->getVolumes();
        $optimisedVolume = $volumesService->getVolumeByHandle($optimisedHandle);

        if (!$optimisedVolume) {
            $this->controller->stdout("  Skipping - {$optimisedHandle} volume not found\n\n");
            return ['total' => 0];
        }

        $this->controller->stdout("  Source: {$optimisedHandle} (Volume ID: {$optimisedVolume->id})\n");
        $this->controller->stdout("  Target: {$targetVolume->name} (Volume ID: {$targetVolume->id})\n\n");
        $this->controller->stdout("  STRATEGY: Process ALL assets with volumeId={$optimisedVolume->id}\n", Console::FG_CYAN);
        $this->controller->stdout("  - Manual read/write/verify (non-destructive: no source deletes in Phase 0.5)\n", Console::FG_CYAN);
        $this->controller->stdout("  - Searches in multiple locations ({$optimisedHandle}, {$targetHandle}, {$quarantineHandle})\n", Console::FG_CYAN);
        $this->controller->stdout("  - Handles missing files gracefully (updates volumeId anyway)\n", Console::FG_CYAN);
        $this->controller->stdout("  - Uses DuplicateResolver in safe-merge mode (re-links references, no source file deletes)\n\n", Console::FG_CYAN);

        // Filter assets by volumeId
        $optimisedAssets = array_filter($assetInventory, function ($asset) use ($optimisedVolume) {
            return $asset['volumeId'] == $optimisedVolume->id;
        });

        $totalAssets = count($optimisedAssets);
        $this->controller->stdout("  Found {$totalAssets} assets with volumeId={$optimisedVolume->id}\n\n");

        if ($totalAssets === 0) {
            $this->controller->stdout("  ✓ No assets to migrate\n\n", Console::FG_GREEN);
            return ['total' => 0];
        }

        // Confirm before proceeding
        if (!$this->dryRun && !$this->yes) {
            $this->controller->stdout("  This will migrate {$totalAssets} assets from {$optimisedHandle} to {$targetHandle}.\n", Console::FG_YELLOW);
            $this->controller->stdout("  All assets will have their volumeId updated, even if files are missing.\n\n", Console::FG_YELLOW);

            if (!$this->controller->confirm("Continue with migration?", true)) {
                $this->controller->stdout("  ⚠ Migration cancelled by user\n\n");
                return ['total' => 0, 'cancelled' => true];
            }
            $this->controller->stdout("\n");
        }

        // Build file index for quick lookup
        $fileIndex = $this->buildFileIndexForOptimisedMigration($optimisedVolume, $targetVolume, $quarantineVolume);

        // Safety check: if the file index is completely empty for all assets that need migrating,
        // this almost certainly means rclone has not yet copied the files to DO Spaces.
        // Proceeding would silently update volumeId for every asset without copying any file,
        // leaving them with broken references. Abort and require the operator to fix the sync first.
        if (!$this->dryRun) {
            $matchCount = 0;
            foreach ($optimisedAssets as $assetData) {
                if (isset($fileIndex[$assetData['filename']])) {
                    $matchCount++;
                }
            }

            if ($matchCount === 0) {
                $this->controller->stdout("\n", Console::FG_RED);
                $this->controller->stdout("  ✗ ABORTED: Phase 0.5 found 0 physical files for {$totalAssets} assets.\n", Console::FG_RED);
                $this->controller->stdout("  This almost certainly means the filesystem switch (Phase 2) ran before rclone\n", Console::FG_RED);
                $this->controller->stdout("  copied the '{$optimisedHandle}' files to DO Spaces.\n\n", Console::FG_RED);
                $this->controller->stdout("  TO FIX:\n", Console::FG_YELLOW);
                $this->controller->stdout("  1. Verify the rclone copy covered ALL files (check for flat files in bucket root)\n", Console::FG_YELLOW);
                $this->controller->stdout("  2. Re-run the rclone sync: check the 'Prerequisites' section of the dashboard\n", Console::FG_YELLOW);
                $this->controller->stdout("  3. Re-run this migration once the files are confirmed present in DO Spaces\n\n", Console::FG_YELLOW);
                throw new \RuntimeException(
                    "Phase 0.5 aborted: no physical files found for {$totalAssets} optimisedImages assets. " .
                    "Run rclone sync first (see dashboard Prerequisites), then retry."
                );
            }

            if ($matchCount < $totalAssets) {
                $missing = $totalAssets - $matchCount;
                $pct = round(($missing / $totalAssets) * 100);
                $this->controller->stdout("  ⚠ Warning: {$missing}/{$totalAssets} ({$pct}%) assets have no matching physical file.\n", Console::FG_YELLOW);
                $this->controller->stdout("  These will have their volumeId updated but no file will be copied.\n", Console::FG_YELLOW);
                $this->controller->stdout("  Consider running rclone check to verify DO Spaces is fully synced.\n\n", Console::FG_YELLOW);
            }
        }

        // Process all assets
        $this->controller->stdout("  Processing assets...\n");
        $this->reporter->printProgressLegend();
        $this->controller->stdout("  Legend: . = moved with file, w = volumeId updated (file missing), m = merged, d = duplicate, x = error\n");
        $this->controller->stdout("  Progress: ");

        $stats = [
            'moved_with_file' => 0,
            'volumeId_updated_missing_file' => 0,
            'merged' => 0,
            'duplicates_overwritten' => 0,
            'leftovers_quarantined' => 0,
            'leftover_root_candidates' => 0,
            'leftover_quarantine_errors' => 0,
            'errors' => 0
        ];

        // Collects one entry per merge_into_existing event for the post-loop report.
        $mergeLog = [];

        $targetRootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($targetVolume->id);

        foreach ($optimisedAssets as $assetData) {
            $assetId = $assetData['id'];
            $filename = $assetData['filename'];

            try {
                $asset = Asset::findOne($assetId);
                if (!$asset) {
                    $this->reporter->safeStdout("?", Console::FG_GREY);
                    continue;
                }

                // Check for duplicate filename collision.
                // skipFileCopy=true: Phase 0.5 moves the winner's file itself via
                // migrateOptimisedAsset, so mergeAssets must not write to the winner's
                // (source) filesystem — which may be disconnected or wrong-direction.
                $resolution = DuplicateResolver::resolveFilenameCollision(
                    $asset,
                    $targetVolume->id,
                    $targetRootFolder->id,
                    $this->dryRun,
                    false,
                    null,
                    true
                );

                if ($resolution['action'] === 'merge_into_existing') {
                    // DuplicateResolver already merged metadata/relations and removed
                    // the loser asset record in non-dry-run mode.
                    $this->reporter->safeStdout("m", Console::FG_CYAN);
                    $stats['merged']++;

                    $winnerId = $resolution['winner']->id ?? '?';
                    $reason   = $resolution['winner_reason'] ?? 'unknown';
                    $cu       = $resolution['candidate_usage'] ?? '?';
                    $eu       = $resolution['existing_usage'] ?? '?';

                    $mergeLog[] = [
                        'candidate_id' => $assetId,
                        'winner_id'    => $winnerId,
                        'filename'     => $filename,
                        'reason'       => $reason,
                        'candidate_active_relations' => $cu,
                        'existing_active_relations'  => $eu,
                    ];

                    Craft::warning(
                        "Phase 0.5 ID change: asset #{$assetId} ({$filename}) merged into #{$winnerId}. " .
                        "Reason: {$reason}. Active relations — candidate: {$cu}, existing: {$eu}. " .
                        "Any {asset:{$assetId}:url} reference tags in RTE content will no longer resolve.",
                        __METHOD__
                    );

                    continue;
                } elseif ($resolution['action'] === 'overwrite') {
                    // Candidate asset stays, existing duplicate was merged into it.
                    // Continue into migrateOptimisedAsset() so the winner is moved/relinked.
                    $this->reporter->safeStdout("d", Console::FG_YELLOW);
                    $stats['duplicates_overwritten']++;
                }

                if (!$this->dryRun) {
                    $result = $this->migrateOptimisedAsset(
                        $asset,
                        $optimisedVolume,
                        $targetVolume,
                        $targetRootFolder,
                        $fileIndex
                    );

                    if ($result['success']) {
                        if ($result['file_found']) {
                            $this->reporter->safeStdout(".", Console::FG_GREEN);
                            $stats['moved_with_file']++;
                        } else {
                            $this->reporter->safeStdout("w", Console::FG_YELLOW);
                            $stats['volumeId_updated_missing_file']++;
                        }
                    } else {
                        $this->reporter->safeStdout("x", Console::FG_RED);
                        $stats['errors']++;
                        Craft::warning("Failed to migrate asset {$assetId}: {$result['error']}", __METHOD__);
                    }
                } else {
                    $this->reporter->safeStdout(".", Console::FG_GREY);
                }

            } catch (\Exception $e) {
                $this->reporter->safeStdout("x", Console::FG_RED);
                $stats['errors']++;
                Craft::error("Error migrating asset {$assetId}: " . $e->getMessage(), __METHOD__);
            }

            // Progress reporting
            $processed = $stats['moved_with_file'] + $stats['volumeId_updated_missing_file'] + $stats['merged'] + $stats['duplicates_overwritten'] + $stats['errors'];
            if ($processed % 50 === 0 && $processed > 0) {
                $this->reporter->safeStdout(" [{$processed}/{$totalAssets}]\n  ");
            }
        }

        $this->reporter->safeStdout("\n\n");

        // Print merge log — each entry is an asset whose ID changed (candidate deleted,
        // existing target asset survived). These ID changes can break {asset:ID:url}
        // reference tags embedded in RTE content, because the deleted element ID
        // is no longer resolvable by Craft.
        if (!empty($mergeLog)) {
            $this->controller->stdout(
                "  ⚠ Asset ID changes (\"m\" merges) — verify no RTE reference tags are broken:\n",
                Console::FG_YELLOW
            );
            $this->controller->stdout(
                "    Columns: [candidate→winner] filename  reason  (candidate active-rel. / existing active-rel.)\n",
                Console::FG_GREY
            );
            foreach ($mergeLog as $entry) {
                $this->controller->stdout(
                    "    [#{$entry['candidate_id']}→#{$entry['winner_id']}] {$entry['filename']}  " .
                    "{$entry['reason']}  " .
                    "({$entry['candidate_active_relations']} / {$entry['existing_active_relations']})\n",
                    Console::FG_YELLOW
                );
            }
            $this->controller->stdout(
                "\n  ℹ Search your database for literal strings like \"{asset:{ID}:url}\" to find affected RTE content.\n\n",
                Console::FG_CYAN
            );
        }

        // Final cleanup step for Phase 0.5: move any remaining root-level source files
        // to quarantine so the optimisedImages root can be emptied safely.
        if (!$this->dryRun && $quarantineVolume) {
            $leftoverStats = $this->quarantineRemainingOptimisedRootFiles($optimisedVolume, $quarantineVolume);
            $stats['leftovers_quarantined'] = $leftoverStats['quarantined'];
            $stats['leftover_quarantine_errors'] = $leftoverStats['errors'];
            $stats['leftover_root_candidates'] = $leftoverStats['root_candidates'];
            $this->reportPhase05LeftoverRecoveryHints($targetVolume, $quarantineVolume);

            // Hard safety gate: if root files were detected but none were quarantined,
            // do not let Phase 0.5 silently pass. This signals an adapter/path issue
            // and prevents proceeding in a partially tangled state.
            if (
                ($stats['leftover_root_candidates'] ?? 0) > 0
                && ($stats['leftovers_quarantined'] ?? 0) === 0
            ) {
                $msg =
                    "Phase 0.5 safety gate triggered: detected " .
                    $stats['leftover_root_candidates'] .
                    " root-level leftover file candidates in '{$optimisedHandle}', " .
                    "but quarantined 0. Aborting to avoid silent partial migration. " .
                    "Check filesystem permissions/paths and rerun.";

                Craft::error($msg, __METHOD__);
                throw new \RuntimeException($msg);
            }
        }

        $this->controller->stdout("  Summary:\n");
        $this->controller->stdout("    Moved with file:          {$stats['moved_with_file']}\n", Console::FG_GREEN);
        $this->controller->stdout("    VolumeId updated (no file): {$stats['volumeId_updated_missing_file']}\n", Console::FG_YELLOW);
        $this->controller->stdout("    Merged into existing:     {$stats['merged']}\n", Console::FG_CYAN);
        $this->controller->stdout("    Duplicates overwritten:   {$stats['duplicates_overwritten']}\n", Console::FG_YELLOW);
        $this->controller->stdout("    Leftovers quarantined:    {$stats['leftovers_quarantined']}\n", Console::FG_CYAN);
        $this->controller->stdout("    Leftover root candidates: {$stats['leftover_root_candidates']}\n", Console::FG_GREY);

        if ($stats['leftover_quarantine_errors'] > 0) {
            $this->controller->stdout("    Leftover quarantine errors: {$stats['leftover_quarantine_errors']}\n", Console::FG_YELLOW);
        }

        if ($stats['errors'] > 0) {
            $this->controller->stdout("    Errors:                   {$stats['errors']}\n", Console::FG_RED);
        }

        $guaranteed = $stats['moved_with_file'] + $stats['volumeId_updated_missing_file'] + $stats['merged'];
        $this->controller->stdout("\n  ✓ GUARANTEED: {$guaranteed}/{$totalAssets} assets now point to volume {$targetVolume->id}\n", Console::FG_GREEN);

        if ($stats['volumeId_updated_missing_file'] > 0) {
            $this->controller->stdout("  ⓘ {$stats['volumeId_updated_missing_file']} assets have volumeId updated but files were not found\n", Console::FG_CYAN);
            $this->controller->stdout("    These can be recovered later if files are located\n\n", Console::FG_GREY);
        }

        $stats['total'] = $totalAssets;
        return $stats;
    }

    /**
     * Build file index for optimised migration
     *
     * Scans multiple volumes to locate files (optimisedImages, images, quarantine)
     *
     * @param $optimisedVolume Optimised volume instance
     * @param $targetVolume Target volume instance
     * @param $quarantineVolume Quarantine volume instance
     * @return array File index keyed by filename
     */
    private function buildFileIndexForOptimisedMigration($optimisedVolume, $targetVolume, $quarantineVolume): array
    {
        $optimisedHandle = $optimisedVolume->handle;
        $targetHandle = $targetVolume->handle;
        $quarantineHandle = $quarantineVolume ? $quarantineVolume->handle : $this->config->getQuarantineVolumeHandle();

        $this->controller->stdout("  Building file index (scanning {$optimisedHandle}, {$targetHandle}, {$quarantineHandle})...\n");

        $fileIndex = [];
        $volumesToScan = [
            $optimisedHandle => $optimisedVolume,
            $targetHandle => $targetVolume,
            $quarantineHandle => $quarantineVolume
        ];
        $scanErrors = [];

        foreach ($volumesToScan as $volumeName => $volume) {
            if (!$volume) {
                continue;
            }

            $this->controller->stdout("    Scanning {$volumeName}... ");

            try {
                $fs = $volume->getFs();
                $scan = $this->inventoryBuilder->scanFilesystem($fs, '', true, null);

                $fileCount = 0;
                foreach ($scan['all'] as $entry) {
                    if (($entry['type'] ?? null) !== 'file') {
                        continue;
                    }

                    $filename = basename($entry['path']);

                    // Skip transform files
                    if ($this->isTransformFile($filename, $entry['path'])) {
                        continue;
                    }

                    // When scanning the root optimisedImages filesystem, skip any file
                    // that lives under a subdirectory (path contains '/').
                    // The root FS sees the entire bucket, so subdirectory entries like
                    // 'images/photo.jpg' are physically identical to what the target
                    // volume's filesystem sees as 'photo.jpg'. Indexing them as
                    // optimisedImages source files causes Case 4 to read, re-write, then
                    // delete that exact file — destroying the target copy.
                    // Root-level files ('photo.jpg' with no '/') are the legitimate
                    // Phase 0.5 source files and are indexed normally.
                    // Subdirectory files will be indexed correctly when the target or
                    // quarantine volume is scanned below.
                    if ($volumeName === $optimisedHandle && strpos($entry['path'], '/') !== false) {
                        continue;
                    }

                    // Store first occurrence (priority: source optimised-images volume > target volume > quarantine)
                    if (!isset($fileIndex[$filename])) {
                        $fileIndex[$filename] = [
                            'volume' => $volumeName,
                            'volumeId' => $volume->id,
                            'path' => $entry['path'],
                            'fs' => $fs,
                            'size' => $entry['size'] ?? 0
                        ];
                        $fileCount++;
                    }
                }

                $this->controller->stdout("found {$fileCount} files\n", Console::FG_GREEN);

            } catch (\Exception $e) {
                $this->controller->stdout("error: " . $e->getMessage() . "\n", Console::FG_RED);
                Craft::warning("Failed to scan {$volumeName}: " . $e->getMessage(), __METHOD__);
                $scanErrors[$volumeName] = $e->getMessage();
            }
        }

        // If the primary source volume scan failed the file index is unusable for that
        // volume — abort to prevent silently updating all asset volumeIds without copying
        // any physical files.
        if (isset($scanErrors[$optimisedHandle])) {
            throw new \RuntimeException(
                "Phase 0.5 aborted: failed to scan the optimisedImages volume '{$optimisedHandle}': " .
                $scanErrors[$optimisedHandle]
            );
        }

        $totalFiles = count($fileIndex);
        $this->controller->stdout("  ✓ File index built: {$totalFiles} unique files\n\n");

        return $fileIndex;
    }

    /**
     * Migrate single asset from optimisedImages to target
     *
     * STRATEGY:
     * 1. Short-circuit when the asset is already in the correct location.
     * 2. If the physical file is already at the target (partial previous run),
     *    update only the DB record — no file I/O needed.
     * 3. If the file is missing everywhere, update the DB record and log a warning.
     * 4. Normal case: manual read → write → verify → DB update → source delete.
     *    We cannot use moveAsset() here because optimisedImages files sit at the
     *    bucket ROOT while asset records may have folderId pointing to a subfolder.
     *    moveAsset() trusts the registered path, so it either can't find the file or
     *    collides with the already-synced target copy.
     *
     * @param $asset Asset instance
     * @param $sourceVolume Source volume instance
     * @param $targetVolume Target volume instance
     * @param $targetRootFolder Target root folder instance
     * @param array $fileIndex File index
     * @return array Result with success, file_found, error keys
     */
    private function migrateOptimisedAsset($asset, $sourceVolume, $targetVolume, $targetRootFolder, array $fileIndex): array
    {
        $filename = $asset->filename;

        try {
            $fileInfo = $fileIndex[$filename] ?? null;
            $targetFs = $targetVolume->getFs();

            // Case 1: Asset already lives in the correct volume and folder — nothing to do.
            if ($asset->volumeId == $targetVolume->id && $asset->folderId == $targetRootFolder->id) {
                $this->changeLogManager->logChange([
                    'type' => 'volumeId_updated_file_already_in_target',
                    'assetId' => $asset->id,
                    'filename' => $filename,
                    'fromVolume' => $sourceVolume->id,
                    'toVolume' => $targetVolume->id,
                    'path' => $filename,
                ]);
                return ['success' => true, 'file_found' => true, 'note' => 'Already in target location'];
            }

            // Case 2: File already exists at the target (partial previous run).
            // No file I/O needed — only update the DB record.
            if ($fileInfo && $fileInfo['volume'] === $targetVolume->handle && $targetFs->fileExists($filename)) {
                $saved = $this->saveAssetLocation($asset, $targetVolume->id, $targetRootFolder->id, $filename);
                if (!$saved['success']) {
                    return array_merge($saved, ['file_found' => true]);
                }

                $this->changeLogManager->logChange([
                    'type' => 'volumeId_updated_file_already_in_target',
                    'assetId' => $asset->id,
                    'filename' => $filename,
                    'fromVolume' => $sourceVolume->id,
                    'toVolume' => $targetVolume->id,
                    'path' => $filename,
                ]);
                return ['success' => true, 'file_found' => true, 'note' => 'File already in target location'];
            }

            // Case 3: File not found in any scanned volume.
            // Update DB so the asset points to target; flag as missing file.
            if (!$fileInfo) {
                $saved = $this->saveAssetLocation($asset, $targetVolume->id, $targetRootFolder->id, $filename);
                if (!$saved['success']) {
                    return array_merge($saved, ['file_found' => false]);
                }

                Craft::info("VolumeId updated for asset {$asset->id} ({$filename}) but file not found", __METHOD__);
                $this->changeLogManager->logChange([
                    'type' => 'volumeId_updated_missing_file',
                    'assetId' => $asset->id,
                    'filename' => $filename,
                    'fromVolume' => $sourceVolume->id,
                    'toVolume' => $targetVolume->id,
                    'note' => 'File not found but volumeId updated',
                ]);
                return ['success' => true, 'file_found' => false, 'warning' => "File not found: {$filename}"];
            }

            // Case 4: Normal — file found in source (or quarantine). Manual copy.
            $sourcePath = $fileInfo['path'];
            $sourceFs = $fileInfo['fs'];

            // Stream copy to avoid loading the entire file into RAM.
            $stream = $sourceFs->getFileStream($sourcePath);
            try {
                $targetFs->writeFileFromStream($filename, $stream, []);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            // Step 3: Verify BEFORE touching the DB or deleting the source.
            if (!$targetFs->fileExists($filename)) {
                throw new \Exception("Write verification failed — target file missing after write: {$filename}");
            }

            // Step 4: Update DB record (file is confirmed in target).
            $saved = $this->saveAssetLocation($asset, $targetVolume->id, $targetRootFolder->id, $filename);
            if (!$saved['success']) {
                // Non-destructive behavior: keep the copied target file for manual recovery.
                return array_merge($saved, ['file_found' => true]);
            }

            // Step 5 intentionally omitted: Phase 0.5 is copy-only by design.
            // Source cleanup is deferred to later, explicit cleanup/quarantine phases.

            $this->changeLogManager->logChange([
                'type' => 'moved_from_optimised',
                'assetId' => $asset->id,
                'filename' => $filename,
                'fromVolume' => $sourceVolume->id,
                'toVolume' => $targetVolume->id,
                'toPath' => $filename,
            ]);

            return ['success' => true, 'file_found' => true, 'moved_from' => $sourceVolume->handle];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'file_found' => isset($fileInfo),
            ];
        }
    }

    /**
     * Save asset volumeId/folderId with fallback for stale field handles.
     *
     * saveElement() fails when custom field values reference fields that no longer
     * exist (e.g. an obsolete "thumbnail" field). This helper tries saveElement first;
     * if it fails, it falls back to a direct UPDATE on {{%assets}}, which is safe
     * because we are only changing storage location, not content.
     *
     * @param $asset Asset instance
     * @param int $volumeId
     * @param int $folderId
     * @param string $filename (for log messages)
     * @return array ['success' => bool, 'error' => string|null]
     */
    private function saveAssetLocation($asset, int $volumeId, int $folderId, string $filename): array
    {
        $asset->volumeId = $volumeId;
        $asset->folderId = $folderId;

        $saveExceptionMessage = null;
        try {
            if (Craft::$app->getElements()->saveElement($asset, false)) {
                return ['success' => true];
            }
        } catch (\Throwable $e) {
            // saveElement can throw (e.g. InvalidFieldException for obsolete field handles)
            // rather than just returning false. Fall through to the direct DB update.
            $saveExceptionMessage = $e->getMessage();
        }

        $errors = $asset->getErrorSummary(true);
        if ($saveExceptionMessage !== null) {
            $errors[] = $saveExceptionMessage;
        }

        // Fall back to direct DB update (handles stale/obsolete field handles).
        try {
            Craft::$app->getDb()->createCommand()->update(
                '{{%assets}}',
                ['volumeId' => $volumeId, 'folderId' => $folderId],
                ['id' => $asset->id]
            )->execute();

            Craft::warning(
                "Asset {$asset->id} ({$filename}): saveElement failed (" . implode(', ', $errors) .
                ") — used direct DB update for volumeId/folderId",
                __METHOD__
            );

            return ['success' => true];
        } catch (\Exception $dbEx) {
            return [
                'success' => false,
                'error' => 'saveElement: ' . implode(', ', $errors) . '; DB fallback: ' . $dbEx->getMessage(),
            ];
        }
    }

    /**
     * Check whether an asset's physical file is accessible on the target filesystem.
     *
     * Checks both the bare filename (root placement used in Phase 0.5) and the
     * asset's registered path, because the folderId-derived path may differ from
     * where the file was physically written.
     *
     * @param $asset Asset instance
     * @param $targetVolume Target volume
     * @return bool
     */
    private function verifyAssetFileAtTarget($asset, $targetVolume): bool
    {
        try {
            $fs = $targetVolume->getFs();
            $bare = $asset->filename;
            if ($fs->fileExists($bare)) {
                return true;
            }
            // Also check the folderId-derived path in case it differs
            $registered = $asset->getPath();
            if ($registered !== $bare && $fs->fileExists($registered)) {
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Craft::warning("verifyAssetFileAtTarget failed for asset {$asset->id}: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Copy a source file to the target volume to recover a winner asset whose
     * physical file is missing.
     *
     * Called when merge_into_existing finds the winner has no file at target after
     * Craft's deleteElement ran on the loser. Updates the asset DB record if the
     * volumeId or folderId still point to the old location.
     *
     * @param $winner Winning asset
     * @param array|null $fileInfo Entry from the file index (source to copy from)
     * @param $targetVolume Target volume
     * @param $targetRootFolder Target root folder
     * @param string $filename Bare filename
     * @return bool True if the file is confirmed at target after this call
     */
    private function recoverMissingWinnerFile($winner, ?array $fileInfo, $targetVolume, $targetRootFolder, string $filename): bool
    {
        if (!$fileInfo) {
            Craft::error(
                "recoverMissingWinnerFile: no source file info for asset {$winner->id} ({$filename})",
                __METHOD__
            );
            return false;
        }

        try {
            $sourceFs = $fileInfo['fs'];
            $sourcePath = $fileInfo['path'];

            if (!$sourceFs->fileExists($sourcePath)) {
                Craft::error(
                    "recoverMissingWinnerFile: source file missing at {$sourcePath} for asset {$winner->id} ({$filename})",
                    __METHOD__
                );
                return false;
            }

            $targetFs = $targetVolume->getFs();
            $stream = $sourceFs->getFileStream($sourcePath);
            try {
                $targetFs->writeFileFromStream($filename, $stream, []);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (!$targetFs->fileExists($filename)) {
                Craft::error(
                    "recoverMissingWinnerFile: write verification failed for asset {$winner->id} ({$filename})",
                    __METHOD__
                );
                return false;
            }

            // Ensure the DB record points to target
            if ($winner->volumeId !== $targetVolume->id || $winner->folderId !== $targetRootFolder->id) {
                $this->saveAssetLocation($winner, $targetVolume->id, $targetRootFolder->id, $filename);
            }

            Craft::info(
                "recoverMissingWinnerFile: recovered file for asset {$winner->id} ({$filename})",
                __METHOD__
            );
            return true;
        } catch (\Exception $e) {
            Craft::error(
                "recoverMissingWinnerFile: exception for asset {$winner->id} ({$filename}): " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }
    }

    /**
     * Cleanup physical file from optimisedImages after asset merge
     *
     * @param $optimisedVolume Optimised volume instance
     * @param string $filename Filename to cleanup
     * @param array $fileIndex File index
     */
    private function cleanupOptimisedFile($optimisedVolume, string $filename, array $fileIndex): void
    {
        $fileInfo = $fileIndex[$filename] ?? null;

        if (!$fileInfo || $fileInfo['volume'] !== $optimisedVolume->handle) {
            return;
        }

        try {
            $fs = $fileInfo['fs'] ?? null;
            $path = $fileInfo['path'] ?? null;

            if (!$fs || !$path) {
                Craft::warning("Invalid file info for cleanup: missing fs or path", __METHOD__);
                return;
            }

            if ($fs->fileExists($path)) {
                $fs->deleteFile($path);
                Craft::info("Cleaned up file from {$optimisedVolume->handle} after merge: {$path}", __METHOD__);
            }
        } catch (\Exception $e) {
            Craft::warning("Failed to cleanup file {$filename} from {$optimisedVolume->handle}: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Delete a file directly from the optimisedImages volume filesystem by filename.
     *
     * Used as a fallback after cleanupOptimisedFile to handle root copies that the
     * fileIndex missed (disconnected original filesystem, partial previous run, etc.)
     *
     * @param $optimisedVolume Optimised volume instance
     * @param string $filename Bare filename to delete
     */
    private function deleteFromOptimisedFs($optimisedVolume, string $filename): void
    {
        try {
            $fs = $optimisedVolume->getFs();
            if ($fs->fileExists($filename)) {
                $fs->deleteFile($filename);
                Craft::info("Deleted root copy from {$optimisedVolume->handle}: {$filename}", __METHOD__);
            }
        } catch (\Exception $e) {
            Craft::warning(
                "Could not delete root copy {$filename} from {$optimisedVolume->handle}: " . $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * Move remaining root-level files from optimisedImages to quarantine.
     *
     * This is intentionally a late Phase 0.5 sweep to empty the source root
     * without touching files in target subfolders.
     *
     * @param $optimisedVolume Source optimised volume
     * @param $quarantineVolume Quarantine volume
     * @return array ['quarantined' => int, 'errors' => int]
     */
    private function quarantineRemainingOptimisedRootFiles($optimisedVolume, $quarantineVolume): array
    {
        $quarantined = 0;
        $errors = 0;
        $rootCandidates = 0;

        try {
            $sourceFs = $optimisedVolume->getFs();
            $quarantineFs = $quarantineVolume->getFs();

            // Use the same scanner used elsewhere in migration to avoid adapter-
            // specific getFileList payload differences.
            $scan = $this->inventoryBuilder->scanFilesystem($sourceFs, '', true, null);

            $this->controller->stdout("  Quarantining remaining root files from {$optimisedVolume->handle}... ");

            foreach ($scan['all'] as $entry) {
                if (($entry['type'] ?? null) !== 'file') {
                    continue;
                }

                $sourcePath = (string) ($entry['path'] ?? '');
                if ($sourcePath === '') {
                    continue;
                }

                // Root-only sweep: do not touch nested paths visible through root FS.
                if (strpos($sourcePath, '/') !== false) {
                    continue;
                }

                $filename = basename($sourcePath);
                if ($this->isTransformFile($filename, $sourcePath)) {
                    continue;
                }

                $rootCandidates++;

                $targetPath = 'phase05-leftovers/' . $filename;

                try {
                    // Avoid collisions in quarantine by suffixing with timestamp.
                    if ($quarantineFs->fileExists($targetPath)) {
                        $pathInfo = pathinfo($filename);
                        $name = $pathInfo['filename'] ?? $filename;
                        $ext = isset($pathInfo['extension']) ? ('.' . $pathInfo['extension']) : '';
                        $targetPath = 'phase05-leftovers/' . $name . '_' . time() . '_' . $quarantined . $ext;
                    }

                    $stream = $sourceFs->getFileStream($sourcePath);
                    try {
                        $quarantineFs->writeFileFromStream($targetPath, $stream, []);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }

                    if (!$quarantineFs->fileExists($targetPath)) {
                        throw new \RuntimeException("Quarantine write verification failed for {$sourcePath}");
                    }

                    $sourceFs->deleteFile($sourcePath);

                    $this->changeLogManager->logChange([
                        'type' => 'phase05_quarantined_leftover_file',
                        'sourceVolumeId' => $optimisedVolume->id,
                        'quarantineVolumeId' => $quarantineVolume->id,
                        'sourcePath' => $sourcePath,
                        'targetPath' => $targetPath,
                    ]);

                    $quarantined++;
                } catch (\Exception $fileEx) {
                    $errors++;
                    Craft::warning(
                        "Failed quarantining Phase 0.5 leftover '{$sourcePath}': " . $fileEx->getMessage(),
                        __METHOD__
                    );
                }
            }

            $this->controller->stdout("{$quarantined} moved ({$rootCandidates} root candidates)\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $errors++;
            $this->controller->stdout("error\n", Console::FG_YELLOW);
            Craft::warning(
                "Phase 0.5 leftover quarantine sweep failed: " . $e->getMessage(),
                __METHOD__
            );
        }

        return ['quarantined' => $quarantined, 'errors' => $errors, 'root_candidates' => $rootCandidates];
    }

    /**
     * Report target assets that are missing files but have filename matches in
     * quarantine/phase05-leftovers so operators can recover them in one command.
     *
     * @param $targetVolume Target volume
     * @param $quarantineVolume Quarantine volume
     */
    private function reportPhase05LeftoverRecoveryHints($targetVolume, $quarantineVolume): void
    {
        try {
            $quarantineFs = $quarantineVolume->getFs();
            $leftoverIndex = [];

            $scan = $this->inventoryBuilder->scanFilesystem($quarantineFs, 'phase05-leftovers', true, null);
            foreach ($scan['all'] as $entry) {
                if (($entry['type'] ?? null) !== 'file') {
                    continue;
                }

                $path = (string) ($entry['path'] ?? '');
                if ($path === '') {
                    continue;
                }

                $leftoverIndex[basename($path)] = true;
            }

            if (empty($leftoverIndex)) {
                return;
            }

            $recoverable = [];
            $batchSize = 200;
            $offset = 0;

            while (true) {
                $assets = Asset::find()
                    ->volumeId($targetVolume->id)
                    ->limit($batchSize)
                    ->offset($offset)
                    ->all();

                if (empty($assets)) {
                    break;
                }

                foreach ($assets as $asset) {
                    if (!isset($leftoverIndex[$asset->filename])) {
                        continue;
                    }

                    if (!$targetVolume->getFs()->fileExists($asset->getPath())) {
                        $recoverable[] = $asset;
                    }
                }

                $offset += $batchSize;
                gc_collect_cycles();
            }

            if (!empty($recoverable)) {
                $count = count($recoverable);
                $this->controller->stdout("\n  ⓘ Phase 0.5 reconciliation: {$count} assets are recoverable from quarantine/phase05-leftovers\n", Console::FG_CYAN);
                $this->controller->stdout("    Run: ./craft spaghetti-migrator/missing-file-fix/recover-phase05-leftovers --dryRun=1\n", Console::FG_GREY);
            }
        } catch (\Exception $e) {
            Craft::warning(
                "Phase 0.5 reconciliation report failed: " . $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * Detect if file is a transform
     *
     * Craft generates transforms in subdirectories with patterns like:
     * - _485x275_crop_center-center_none
     * - _34x17_crop_center-center
     * - _thumbnail
     *
     * @param string $filename Filename
     * @param string $path Full path
     * @return bool True if transform file
     */
    public function isTransformFile(string $filename, string $path): bool
    {
        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if (strpos($segment, '_') === 0) {
                foreach ($this->transformPatterns as $pattern) {
                    if (preg_match($pattern, '/' . $segment . '/')) {
                        return true;
                    }
                }

                if (strpos($segment, '_transforms') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if two filesystems create a nested scenario
     *
     * Uses the FilesystemNestingDetector to automatically determine if
     * filesystems share the same bucket with one being a parent of the other.
     *
     * @param \craft\base\FsInterface $sourceFs Source filesystem
     * @param \craft\base\FsInterface $targetFs Target filesystem
     * @return bool True if nested (requires temp file approach)
     */
    public function requiresTempFileApproach($sourceFs, $targetFs): bool
    {
        return $this->nestingDetector->isNestedFilesystem($sourceFs, $targetFs);
    }

    /**
     * Get diagnostic information about filesystem nesting
     *
     * Useful for debugging and understanding why temp file approach is needed
     *
     * @param \craft\base\FsInterface $sourceFs Source filesystem
     * @param \craft\base\FsInterface $targetFs Target filesystem
     * @return array Diagnostic information
     */
    public function getNestingDiagnostics($sourceFs, $targetFs): array
    {
        return $this->nestingDetector->getDiagnosticInfo($sourceFs, $targetFs);
    }
}
