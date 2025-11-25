<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use craft\models\Volume;
use yii\console\ExitCode;

/**
 * Removes transform files stored inside directories beginning with an underscore
 * in the Optimised Images volume (volume ID 4) so migrations start with a clean state.
 */
class TransformCleanupController extends Controller
{
    /** @var bool Whether to preview actions instead of deleting files */
    public $dryRun = false;

    /** @var string|null Preferred volume handle */
    public $volumeHandle = 'optimisedImages';

    /** @var int|null Preferred volume ID */
    public $volumeId = 4;

    /** @var string Default action */
    public string $defaultAction = 'clean';

    /**
     * Configure CLI options
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'clean') {
            $options[] = 'dryRun';
            $options[] = 'volumeHandle';
            $options[] = 'volumeId';
        }

        return $options;
    }

    /**
     * Normalize boolean parameters before running the action
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->dryRun = $this->normalizeBool($this->dryRun, false);
        $this->volumeId = $this->volumeId !== null ? (int) $this->volumeId : null;

        return true;
    }

    /**
     * Remove transform files stored under underscore-prefixed directories
     */
    public function actionClean(): int
    {
        $this->printHeader();

        try {
            $volume = $this->resolveVolume();
        } catch (\Throwable $e) {
            $this->stdout("✗ {$e->getMessage()}\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$volume) {
            $this->stdout("✗ Unable to locate Optimised Images volume (handle optimisedImages / ID 4)\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Target Volume: {$volume->name} (handle: {$volume->handle}, ID: {$volume->id})\n", Console::FG_CYAN);
        $this->stdout($this->dryRun
            ? "MODE: DRY RUN – Listing candidates only\n\n"
            : "MODE: LIVE – Files and empty directories will be deleted\n\n",
            $this->dryRun ? Console::FG_YELLOW : Console::FG_RED
        );

        try {
            $assetIndex = $this->buildAssetIndex($volume->id);
        } catch (\Throwable $e) {
            $this->stdout("✗ Failed to build asset index: {$e->getMessage()}\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Indexed " . count($assetIndex) . " asset file paths\n", Console::FG_GREY);

        try {
            $scan = $this->scanFilesystemForTransforms($volume, $assetIndex);
        } catch (\Throwable $e) {
            $this->stdout("✗ Failed to scan filesystem: {$e->getMessage()}\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $eligibleCount = count($scan['candidates']);
        $linkedCount = count($scan['linked']);
        $totalTransformFiles = $eligibleCount + $linkedCount;

        $this->stdout("Files examined: {$scan['filesScanned']}\n");
        $this->stdout("Transform files in '_' folders: {$totalTransformFiles}\n");
        $this->stdout("Linked to assets (kept): {$linkedCount}\n", $linkedCount ? Console::FG_YELLOW : Console::FG_GREEN);
        $this->stdout("Eligible for deletion: {$eligibleCount}\n\n", $eligibleCount ? Console::FG_RED : Console::FG_GREEN);

        if ($eligibleCount === 0) {
            $this->stdout("✓ No orphaned transforms detected inside '_' directories.\n\n", Console::FG_GREEN);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        $reportPath = $this->writeReport($volume, $scan);
        if ($reportPath) {
            $this->stdout("Report saved to: {$reportPath}\n\n", Console::FG_CYAN);
        }

        // Always preview the first few items for clarity
        $this->stdout("Sample of files to remove:\n", Console::FG_YELLOW);
        $preview = array_slice($scan['candidates'], 0, 25);
        foreach ($preview as $item) {
            $this->stdout("  • {$item['path']} (" . $this->formatBytes($item['size']) . ")\n", Console::FG_GREY);
        }
        if ($eligibleCount > count($preview)) {
            $this->stdout("  ... and " . ($eligibleCount - count($preview)) . " more files\n", Console::FG_GREY);
        }
        $this->stdout("\n");

        if ($this->dryRun) {
            $this->stdout("Dry run complete – no files deleted. Run without --dryRun to execute.\n\n", Console::FG_YELLOW);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        $stats = $this->deleteTransforms($volume, $scan['candidates']);
        $dirStats = $this->deleteEmptyDirectories($volume, $scan['directoryStats']);

        $summary = [
            'deletedFiles' => $stats['deleted'],
            'missingFiles' => $stats['missing'],
            'errors' => $stats['errors'],
            'bytesFreed' => $stats['bytesFreed'],
            'directoriesRemoved' => $dirStats['removed'],
            'directoriesSkipped' => $dirStats['skipped'],
        ];

        $this->stdout("Cleanup summary:\n", Console::FG_CYAN);
        foreach ($summary as $label => $value) {
            $this->stdout(sprintf("  %-20s %s\n", $label . ':', is_numeric($value) ? number_format((float) $value) : $value));
        }
        $this->stdout("\n");

        if ($stats['errors'] > 0) {
            $this->stdout("✗ Completed with {$stats['errors']} errors – review logs.\n", Console::FG_RED);
            $this->stdout("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("✓ Transform cleanup completed successfully.\n", Console::FG_GREEN);
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Fetch the Optimised Images volume by handle or ID
     */
    private function resolveVolume(): ?Volume
    {
        $volumes = Craft::$app->getVolumes();
        $handles = [];

        if ($this->volumeHandle) {
            $handles[] = $this->volumeHandle;
        }
        $handles[] = 'optimisedImages';
        $handles[] = 'optimizedImages';

        foreach (array_unique($handles) as $handle) {
            $volume = $volumes->getVolumeByHandle($handle);
            if ($volume) {
                return $volume;
            }
        }

        if ($this->volumeId) {
            $volume = $volumes->getVolumeById($this->volumeId);
            if ($volume) {
                return $volume;
            }
        }

        return null;
    }

    /**
     * Build a lookup of asset paths for the target volume
     */
    private function buildAssetIndex(int $volumeId): array
    {
        $paths = [];
        $total = (int) Asset::find()->volumeId($volumeId)->status(null)->count();
        $batchSize = 500;
        $offset = 0;
        $startTime = microtime(true);
        $lastProgress = 0;

        $this->stdout("Building asset index...\n", Console::FG_CYAN);

        while ($offset < $total) {
            $assets = Asset::find()
                ->volumeId($volumeId)
                ->status(null)
                ->limit($batchSize)
                ->offset($offset)
                ->all();

            if (empty($assets)) {
                break;
            }

            foreach ($assets as $asset) {
                $path = $this->normalizePath($asset->getPath());
                if ($path !== '') {
                    $paths[$path] = $asset->id;
                }
            }

            $offset += count($assets);

            // Output progress
            $processed = min($offset, $total);
            $pct = $total > 0 ? round(($processed / $total) * 100, 1) : 100;

            // Calculate ETA
            $elapsed = microtime(true) - $startTime;
            $itemsPerSecond = $elapsed > 0 ? $processed / $elapsed : 0;
            $remaining = $total - $processed;
            $etaSeconds = $itemsPerSecond > 0 ? $remaining / $itemsPerSecond : 0;
            $etaFormatted = $this->formatTime($etaSeconds);

            // Report every 10% or at completion
            if ($pct - $lastProgress >= 10 || $processed >= $total) {
                $this->stdout("  [Progress] {$pct}% complete ({$processed}/{$total}) - {$etaFormatted} remaining\n", Console::FG_CYAN);

                // Machine-readable progress
                $progressData = json_encode([
                    'phase' => 'buildAssetIndex',
                    'percent' => $pct,
                    'current' => $processed,
                    'total' => $total,
                    'eta' => $etaFormatted,
                    'etaSeconds' => (int) $etaSeconds,
                    'itemsPerSecond' => round($itemsPerSecond, 2)
                ]);
                $this->stdout("__CLI_PROGRESS__{$progressData}__\n", Console::RESET);

                $lastProgress = $pct;
            }
        }

        return $paths;
    }

    /**
     * Scan filesystem for transform files under underscore-prefixed folders
     */
    private function scanFilesystemForTransforms(Volume $volume, array $assetIndex): array
    {
        $fs = $volume->getFs();
        $iter = $fs->getFileList('', true);

        $imageExtensions = ['jpg','jpeg','png','gif','webp','avif','heic','heif','bmp','tiff','svg'];

        $result = [
            'filesScanned' => 0,
            'candidates' => [],
            'linked' => [],
            'directoryStats' => [],
        ];

        $startTime = microtime(true);
        $lastReportCount = 0;
        $reportInterval = 1000; // Report every 1000 files

        $this->stdout("Scanning filesystem for transforms...\n", Console::FG_CYAN);

        foreach ($iter as $item) {
            $data = $this->extractFsListingData($item);
            $path = $this->normalizePath($data['path']);

            if ($path === '') {
                continue;
            }

            if ($data['isDir']) {
                continue;
            }

            $result['filesScanned']++;

            if (!$this->isTransformPath($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $imageExtensions, true)) {
                continue;
            }

            $entry = [
                'path' => $path,
                'size' => (int) ($data['fileSize'] ?? 0),
            ];

            $dir = $this->getDirectoryName($path);
            if (!isset($result['directoryStats'][$dir])) {
                $result['directoryStats'][$dir] = ['eligible' => 0, 'linked' => 0];
            }

            if (isset($assetIndex[$path])) {
                $entry['assetId'] = $assetIndex[$path];
                $result['linked'][] = $entry;
                $result['directoryStats'][$dir]['linked']++;
            } else {
                $result['candidates'][] = $entry;
                $result['directoryStats'][$dir]['eligible']++;
            }

            // Report progress periodically
            if ($result['filesScanned'] - $lastReportCount >= $reportInterval) {
                $elapsed = microtime(true) - $startTime;
                $filesPerSecond = $elapsed > 0 ? $result['filesScanned'] / $elapsed : 0;
                $transformsFound = count($result['candidates']) + count($result['linked']);

                $this->stdout(
                    "  [Progress] {$result['filesScanned']} files scanned, {$transformsFound} transforms found - " . round($filesPerSecond, 1) . " files/s\n",
                    Console::FG_CYAN
                );

                // Machine-readable progress
                $progressData = json_encode([
                    'phase' => 'scanFilesystem',
                    'filesScanned' => $result['filesScanned'],
                    'transformsFound' => $transformsFound,
                    'filesPerSecond' => round($filesPerSecond, 2)
                ]);
                $this->stdout("__CLI_PROGRESS__{$progressData}__\n", Console::RESET);

                $lastReportCount = $result['filesScanned'];
            }
        }

        // Final report
        $elapsed = microtime(true) - $startTime;
        $filesPerSecond = $elapsed > 0 ? $result['filesScanned'] / $elapsed : 0;
        $transformsFound = count($result['candidates']) + count($result['linked']);

        $this->stdout(
            "  [Complete] {$result['filesScanned']} files scanned, {$transformsFound} transforms found - " . round($filesPerSecond, 1) . " files/s\n",
            Console::FG_GREEN
        );

        return $result;
    }

    /**
     * Delete transform files from the filesystem
     */
    private function deleteTransforms(Volume $volume, array $files): array
    {
        $fs = $volume->getFs();
        $total = count($files);
        $deleted = 0;
        $missing = 0;
        $errors = 0;
        $bytesFreed = 0;
        $startTime = microtime(true);
        $lastProgress = 0;

        $this->stdout("\nDeleting transform files...\n", Console::FG_CYAN);

        foreach ($files as $index => $file) {
            $path = $file['path'];
            $current = $index + 1;

            try {
                if ($fs->fileExists($path)) {
                    $fs->deleteFile($path);
                    $deleted++;
                    $bytesFreed += (int) ($file['size'] ?? 0);
                } else {
                    $missing++;
                }
            } catch (\Throwable $e) {
                $errors++;
                Craft::warning("Failed to delete transform '{$path}': {$e->getMessage()}", __METHOD__);
            }

            // Output progress
            $pct = $total > 0 ? round(($current / $total) * 100, 1) : 100;

            // Calculate ETA
            $elapsed = microtime(true) - $startTime;
            $itemsPerSecond = $elapsed > 0 ? $current / $elapsed : 0;
            $remaining = $total - $current;
            $etaSeconds = $itemsPerSecond > 0 ? $remaining / $itemsPerSecond : 0;
            $etaFormatted = $this->formatTime($etaSeconds);

            // Report every 5% or at completion
            if ($pct - $lastProgress >= 5 || $current >= $total) {
                $this->stdout(
                    "  [Progress] {$pct}% complete ({$current}/{$total}) - {$deleted} deleted, {$missing} missing, {$errors} errors - ETA: {$etaFormatted}\n",
                    Console::FG_CYAN
                );

                // Machine-readable progress
                $progressData = json_encode([
                    'phase' => 'deleteTransforms',
                    'percent' => $pct,
                    'current' => $current,
                    'total' => $total,
                    'deleted' => $deleted,
                    'missing' => $missing,
                    'errors' => $errors,
                    'bytesFreed' => $bytesFreed,
                    'eta' => $etaFormatted,
                    'etaSeconds' => (int) $etaSeconds,
                    'itemsPerSecond' => round($itemsPerSecond, 2)
                ]);
                $this->stdout("__CLI_PROGRESS__{$progressData}__\n", Console::RESET);

                $lastProgress = $pct;
            }
        }

        return [
            'deleted' => $deleted,
            'missing' => $missing,
            'errors' => $errors,
            'bytesFreed' => $bytesFreed,
        ];
    }

    /**
     * Delete directories that became empty after transform cleanup
     */
    private function deleteEmptyDirectories(Volume $volume, array $directoryStats): array
    {
        $fs = $volume->getFs();
        $dirs = array_keys($directoryStats);
        usort($dirs, static function($a, $b) {
            return substr_count($b, '/') <=> substr_count($a, '/');
        });

        $removed = 0;
        $skipped = 0;
        $errors = 0;
        $total = count($dirs);
        $processed = 0;
        $startTime = microtime(true);
        $lastProgress = 0;

        $deleteMethod = null;
        if (method_exists($fs, 'deleteDirectory')) {
            $deleteMethod = 'deleteDirectory';
        } elseif (method_exists($fs, 'deleteDir')) {
            $deleteMethod = 'deleteDir';
        }

        if ($total > 0) {
            $this->stdout("\nCleaning up empty directories...\n", Console::FG_CYAN);
        }

        foreach ($dirs as $dir) {
            if ($dir === '' || $directoryStats[$dir]['eligible'] === 0) {
                continue;
            }

            $processed++;

            if ($directoryStats[$dir]['linked'] > 0) {
                $skipped++;
            } else {
                try {
                    $hasContents = $this->directoryHasContents($fs, $dir);
                    if ($hasContents) {
                        $skipped++;
                    } elseif ($deleteMethod) {
                        $fs->{$deleteMethod}($dir);
                        $removed++;
                    } else {
                        Craft::warning("Filesystem does not support directory deletion for '{$dir}'", __METHOD__);
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Craft::warning("Failed to delete directory '{$dir}': {$e->getMessage()}", __METHOD__);
                }
            }

            // Output progress
            $pct = $total > 0 ? round(($processed / $total) * 100, 1) : 100;

            // Calculate ETA
            $elapsed = microtime(true) - $startTime;
            $itemsPerSecond = $elapsed > 0 ? $processed / $elapsed : 0;
            $remaining = $total - $processed;
            $etaSeconds = $itemsPerSecond > 0 ? $remaining / $itemsPerSecond : 0;
            $etaFormatted = $this->formatTime($etaSeconds);

            // Report every 10% or at completion
            if ($total > 0 && ($pct - $lastProgress >= 10 || $processed >= $total)) {
                $this->stdout(
                    "  [Progress] {$pct}% complete ({$processed}/{$total}) - {$removed} removed, {$skipped} skipped, {$errors} errors - ETA: {$etaFormatted}\n",
                    Console::FG_CYAN
                );

                // Machine-readable progress
                $progressData = json_encode([
                    'phase' => 'deleteEmptyDirectories',
                    'percent' => $pct,
                    'current' => $processed,
                    'total' => $total,
                    'removed' => $removed,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'eta' => $etaFormatted,
                    'etaSeconds' => (int) $etaSeconds,
                    'itemsPerSecond' => round($itemsPerSecond, 2)
                ]);
                $this->stdout("__CLI_PROGRESS__{$progressData}__\n", Console::RESET);

                $lastProgress = $pct;
            }
        }

        return [
            'removed' => $removed,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Determine if a path is inside an underscore-prefixed directory
     */
    private function isTransformPath(string $path): bool
    {
        $segments = explode('/', $path);
        array_pop($segments);
        foreach ($segments as $segment) {
            if ($segment !== '' && strpos($segment, '_') === 0) {
                return true;
            }
        }
        return false;
    }

    private function getDirectoryName(string $path): string
    {
        $dir = trim(str_replace('\\', '/', dirname($path)), '.');
        return $dir === '' ? '' : trim($dir, '/');
    }

    private function normalizePath(?string $path): string
    {
        return trim((string) $path, '/');
    }

    private function normalizeBool($value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $filtered === null ? $default : $filtered;
    }

    /**
     * Extract data from FsListing
     */
    private function extractFsListingData($item): array
    {
        $data = [
            'path' => '',
            'isDir' => false,
            'fileSize' => null,
        ];

        if (is_string($item)) {
            $data['path'] = $item;
            $data['isDir'] = substr($item, -1) === '/';
            return $data;
        }

        if (is_array($item)) {
            $data['path'] = $item['path'] ?? $item['uri'] ?? $item['key'] ?? '';
            $data['isDir'] = ($item['type'] ?? 'file') === 'dir';
            $data['fileSize'] = $item['fileSize'] ?? $item['size'] ?? null;
            return $data;
        }

        if (is_object($item)) {
            if (method_exists($item, 'getUri')) {
                try {
                    $data['path'] = (string) $item->getUri();
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            if (method_exists($item, 'getIsDir')) {
                try {
                    $data['isDir'] = (bool) $item->getIsDir();
                } catch (\Throwable $e) {
                    $data['isDir'] = $data['path'] ? substr($data['path'], -1) === '/' : false;
                }
            }

            if (!$data['isDir'] && method_exists($item, 'getFileSize')) {
                try {
                    $data['fileSize'] = $item->getFileSize();
                } catch (\Throwable $e) {
                    $data['fileSize'] = null;
                }
            }
        }

        return $data;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / pow(1024, $power);
        return number_format($value, 2) . ' ' . $units[$power];
    }

    /**
     * Format seconds into human-readable time
     */
    private function formatTime(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds) . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = round(($seconds % 3600) / 60);
            return "{$hours}h {$minutes}m";
        }
    }

    private function directoryHasContents($fs, string $dir): bool
    {
        $cleanDir = trim($dir, '/');
        $iter = $fs->getFileList($cleanDir, false);
        foreach ($iter as $item) {
            $data = $this->extractFsListingData($item);
            if ($data['path'] !== '') {
                return true;
            }
        }
        return false;
    }

    private function writeReport(Volume $volume, array $scan): ?string
    {
        try {
            $basePath = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'transform-cleanup';
            FileHelper::createDirectory($basePath);
            $filename = 'report-' . date('Ymd-His') . '-' . uniqid('', true) . '.json';
            $path = $basePath . DIRECTORY_SEPARATOR . $filename;

            $payload = [
                'generatedAt' => date(DATE_ATOM),
                'mode' => $this->dryRun ? 'dry-run' : 'live',
                'volume' => [
                    'id' => $volume->id,
                    'handle' => $volume->handle,
                    'name' => $volume->name,
                ],
                'stats' => [
                    'filesScanned' => $scan['filesScanned'],
                    'linked' => count($scan['linked']),
                    'candidates' => count($scan['candidates']),
                ],
                'files' => $scan['candidates'],
            ];

            file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));
            return $path;
        } catch (\Throwable $e) {
            Craft::warning('Unable to write transform cleanup report: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    private function printHeader(): void
    {
        $this->stdout("\n" . str_repeat('=', 80) . "\n", Console::FG_CYAN);
        $this->stdout("OPTIMISED IMAGES TRANSFORM CLEANUP\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 80) . "\n\n", Console::FG_CYAN);
    }
}
