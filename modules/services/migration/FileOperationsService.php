<?php

namespace csabourin\craftS3SpacesMigration\services\migration;

use Craft;
use craft\db\Table;
use craft\helpers\Db;
use csabourin\craftS3SpacesMigration\services\ChangeLogManager;

/**
 * File Operations Service
 *
 * Handles low-level file operations including move, copy, and filesystem interactions.
 * Provides robust error handling and tracks all file operations for rollback.
 *
 * @author Christian Sabourin
 * @version 1.0.0
 */
class FileOperationsService
{
    /**
     * @var ChangeLogManager Change log manager for tracking operations
     */
    private $changeLogManager;

    /**
     * @var array Temporary files to clean up
     */
    private $tempFiles = [];

    /**
     * @var array Statistics tracking
     */
    private $stats = [];

    /**
     * @var array Error counts by operation
     */
    private $errorCounts = [];

    /**
     * @var string Migration ID
     */
    private $migrationId;

    /**
     * @var array Copied source files tracking (to avoid duplicate operations)
     */
    private $copiedSourceFiles = [];

    /**
     * @var int Expected missing file count
     */
    private $expectedMissingFileCount = 0;

    /**
     * @var int Error threshold for critical errors
     */
    private $criticalErrorThreshold = 20;

    /**
     * @var int Error threshold for general errors
     */
    private $errorThreshold = 50;

    /**
     * Constructor
     *
     * @param ChangeLogManager $changeLogManager Change log manager
     * @param string $migrationId Unique migration identifier
     */
    public function __construct(
        ChangeLogManager $changeLogManager,
        string $migrationId
    ) {
        $this->changeLogManager = $changeLogManager;
        $this->migrationId = $migrationId;
    }

    /**
     * Move asset within the same volume
     *
     * @param $asset Asset instance
     * @param $targetFolder Target folder instance
     * @return bool Success status
     * @throws \Exception If move fails with non-recoverable error
     */
    public function moveAssetSameVolume($asset, $targetFolder): bool
    {
        try {
            return Craft::$app->getAssets()->moveAsset($asset, $targetFolder);
        } catch (\Exception $e) {
            // If native move fails due to filesystem issues, try manual approach
            if (strpos($e->getMessage(), 'readStream') !== false ||
                strpos($e->getMessage(), 'file') !== false ||
                strpos($e->getMessage(), 'stream') !== false) {

                Craft::info(
                    "Native moveAsset failed for asset {$asset->id}, attempting manual move: " . $e->getMessage(),
                    __METHOD__
                );

                return $this->moveAssetManual($asset, $targetFolder);
            }

            // For other errors, rethrow
            throw $e;
        }
    }

    /**
     * Manual asset move (fallback method)
     *
     * Used when native Craft API fails due to filesystem issues.
     *
     * @param $asset Asset instance
     * @param $targetFolder Target folder instance
     * @return bool Success status
     */
    public function moveAssetManual($asset, $targetFolder): bool
    {
        $fs = $asset->getVolume()->getFs();
        $oldPath = $asset->getPath();

        // Get copy of file with error handling
        try {
            $tempPath = $asset->getCopyOfFile();
        } catch (\Exception $e) {
            // File doesn't exist on source - log and return false
            $errorMsg = "Cannot get copy of file for asset {$asset->id} ({$asset->filename}): " . $e->getMessage();
            Craft::warning($errorMsg, __METHOD__);
            $this->trackError('missing_source_file', $errorMsg);

            // Track in stats
            if (!isset($this->stats['missing_files'])) {
                $this->stats['missing_files'] = 0;
            }
            $this->stats['missing_files']++;

            return false;
        }

        $this->trackTempFile($tempPath);

        $asset->folderId = $targetFolder->id;
        $asset->newFolderId = $targetFolder->id;
        $asset->tempFilePath = $tempPath;

        try {
            $success = Craft::$app->getElements()->saveElement($asset);

            if ($success) {
                // Delete old file if path changed
                if ($oldPath !== $asset->getPath()) {
                    try {
                        $fs->deleteFile($oldPath);
                    } catch (\Exception $e) {
                        // File might not exist or already deleted - that's ok
                        Craft::info("Could not delete old file at {$oldPath}: " . $e->getMessage(), __METHOD__);
                    }
                }
            }

            return $success;
        } finally {
            // Always cleanup temp file
            if (file_exists($tempPath)) {
                try {
                    unlink($tempPath);
                } catch (\Exception $e) {
                    Craft::warning("Failed to unlink temp file in finally block: " . $e->getMessage(), __METHOD__);
                }
            }
            $this->tempFiles = array_diff($this->tempFiles, [$tempPath]);
        }
    }

    /**
     * Move asset across volumes
     *
     * @param $asset Asset instance
     * @param $targetVolume Target volume instance
     * @param $targetFolder Target folder instance
     * @return bool Success status
     */
    public function moveAssetCrossVolume($asset, $targetVolume, $targetFolder): bool
    {
        $elementsService = Craft::$app->getElements();

        try {
            $tempFile = $asset->getCopyOfFile();
        } catch (\Exception $e) {
            // File doesn't exist on source - log and return false
            $errorMsg = "Cannot get copy of file for asset {$asset->id} ({$asset->filename}): " . $e->getMessage();
            Craft::warning($errorMsg, __METHOD__);
            $this->trackError('missing_source_file', $errorMsg);

            // Track in stats
            if (!isset($this->stats['missing_files'])) {
                $this->stats['missing_files'] = 0;
            }
            $this->stats['missing_files']++;

            return false;
        }

        $this->trackTempFile($tempFile);

        $asset->volumeId = $targetVolume->id;
        $asset->folderId = $targetFolder->id;
        $asset->tempFilePath = $tempFile;

        try {
            $success = $elementsService->saveElement($asset);
        } finally {
            // Always cleanup temp file
            if (file_exists($tempFile)) {
                try {
                    unlink($tempFile);
                } catch (\Exception $e) {
                    Craft::warning("Failed to unlink temp file in finally block: " . $e->getMessage(), __METHOD__);
                }
            }
            $this->tempFiles = array_diff($this->tempFiles, [$tempFile]);
        }

        return $success;
    }

    /**
     * Copy file to asset (with duplicate handling and safe deletion)
     *
     * @param array $sourceFile Source file data ['fs' => FS, 'path' => string, 'volumeName' => string]
     * @param $asset Asset instance
     * @param $targetVolume Target volume instance
     * @param $targetFolder Target folder instance
     * @return bool|string Success status or 'already_copied'
     */
    public function copyFileToAsset(array $sourceFile, $asset, $targetVolume, $targetFolder)
    {
        $sourceFs = $sourceFile['fs'];
        $sourcePath = $sourceFile['path'];

        // Create a unique key for this source file
        $sourceKey = $sourceFile['volumeName'] . '::' . $sourcePath;

        // Check if this file was already successfully copied
        if (isset($this->copiedSourceFiles[$sourceKey])) {
            Craft::info(
                "Source file '{$sourcePath}' was already copied (referenced by multiple assets). Skipping duplicate copy.",
                __METHOD__
            );
            return 'already_copied';
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'asset_');
        $tempStream = fopen($tempPath, 'w');

        try {
            $content = $sourceFs->read($sourcePath);
            fwrite($tempStream, $content);
        } catch (\Exception $e) {
            fclose($tempStream);
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            // Check if file was already copied - not a critical error
            if (isset($this->copiedSourceFiles[$sourceKey])) {
                Craft::info(
                    "Source file '{$sourcePath}' not found, but was previously copied successfully.",
                    __METHOD__
                );
                return 'already_copied';
            }

            // Log the missing file error but don't throw
            $errorMsg = "Cannot read source file '{$sourcePath}': " . $e->getMessage();
            Craft::warning($errorMsg, __METHOD__);
            $this->trackError('missing_source_file', $errorMsg);

            // Track in stats
            if (!isset($this->stats['missing_files'])) {
                $this->stats['missing_files'] = 0;
            }
            $this->stats['missing_files']++;

            return false;
        }

        fclose($tempStream);

        $asset->volumeId = $targetVolume->id;
        $asset->folderId = $targetFolder->id;
        $asset->tempFilePath = $tempPath;

        $success = Craft::$app->getElements()->saveElement($asset);

        @unlink($tempPath);

        // Track successful copy and delete source file after successful migration
        if ($success) {
            $this->copiedSourceFiles[$sourceKey] = true;

            // Check if this file is part of a duplicate group
            $duplicateRecord = $this->getDuplicateRecord($sourceKey);

            // Use safe deletion check
            $canDelete = $this->canSafelyDeleteSource($sourceKey, $duplicateRecord, $asset);

            if ($canDelete) {
                // Delete the source file after successful copy
                try {
                    if ($sourceFs->fileExists($sourcePath)) {
                        $sourceFs->deleteFile($sourcePath);
                        Craft::info("Deleted source file after successful copy: {$sourcePath}", __METHOD__);

                        // Mark as completed if this was a duplicate group file
                        if ($duplicateRecord && $duplicateRecord['status'] === 'analyzed') {
                            $db = Craft::$app->getDb();
                            $db->createCommand()->update('{{%migration_file_duplicates}}', [
                                'status' => 'completed',
                                'processedAt' => Db::prepareDateForDb(new \DateTime()),
                                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                            ], [
                                'migrationId' => $this->migrationId,
                                'fileKey' => $sourceKey,
                            ])->execute();
                        }
                    }
                } catch (\Exception $e) {
                    // Log warning but don't fail - file was already copied successfully
                    Craft::warning(
                        "Could not delete source file '{$sourcePath}' after successful copy: " . $e->getMessage(),
                        __METHOD__
                    );
                }
            } else {
                Craft::info(
                    "Skipped deletion of {$sourcePath} - " .
                    ($duplicateRecord ? "part of duplicate group or same as destination" : "same as destination or referenced by other assets"),
                    __METHOD__
                );
            }
        }

        return $success;
    }

    /**
     * Extract data from Craft FS listing object
     *
     * Handles multiple formats (FsListing, string, array) for compatibility.
     *
     * @param mixed $item FsListing object, string, or array
     * @return array ['path' => string, 'isDir' => bool, 'fileSize' => int|null, 'lastModified' => int|null]
     */
    public function extractFsListingData($item): array
    {
        $data = [
            'path' => '',
            'isDir' => false,
            'fileSize' => null,
            'lastModified' => null,
        ];

        // Handle strings (legacy adapter format)
        if (is_string($item)) {
            $data['path'] = $item;
            $data['isDir'] = substr($item, -1) === '/';
            return $data;
        }

        // Handle arrays (some legacy adapters)
        if (is_array($item)) {
            $data['path'] = $item['path'] ?? $item['uri'] ?? $item['key'] ?? '';
            $data['isDir'] = ($item['type'] ?? 'file') === 'dir';
            $data['fileSize'] = $item['fileSize'] ?? $item['size'] ?? null;
            $data['lastModified'] = $item['lastModified'] ?? $item['timestamp'] ?? null;
            return $data;
        }

        // Handle craft\models\FsListing objects (Craft 4+)
        if (is_object($item)) {
            // Path from getUri() method
            if (method_exists($item, 'getUri')) {
                try {
                    $data['path'] = (string) $item->getUri();
                } catch (\Throwable $e) {
                    // Continue to fallback
                }
            }

            // Directory check from getIsDir() method
            if (method_exists($item, 'getIsDir')) {
                try {
                    $data['isDir'] = (bool) $item->getIsDir();
                } catch (\Throwable $e) {
                    // Fallback: check path suffix
                    if ($data['path']) {
                        $data['isDir'] = substr($data['path'], -1) === '/';
                    }
                }
            }

            // File size from getFileSize() method (files only)
            if (!$data['isDir'] && method_exists($item, 'getFileSize')) {
                try {
                    $data['fileSize'] = $item->getFileSize();
                } catch (\Throwable $e) {
                    // Size unavailable
                }
            }

            // Last modified timestamp from getDateModified() method
            if (method_exists($item, 'getDateModified')) {
                try {
                    $dateModified = $item->getDateModified();
                    if ($dateModified instanceof \DateTime) {
                        $data['lastModified'] = $dateModified->getTimestamp();
                    } elseif (is_numeric($dateModified)) {
                        $data['lastModified'] = (int) $dateModified;
                    }
                } catch (\Throwable $e) {
                    // Timestamp unavailable
                }
            }

            return $data;
        }

        // Unknown type - return empty data
        return $data;
    }

    /**
     * Check if path is in originals folder
     *
     * @param string $path File path
     * @return bool True if in originals folder
     */
    public function isInOriginalsFolder(string $path): bool
    {
        $path = strtolower(trim($path, '/'));

        return (
            strpos($path, '/originals/') !== false ||
            strpos($path, 'originals/') === 0 ||
            preg_match('#/originals/[^/]+$#i', $path)
        );
    }

    /**
     * Track temporary file for cleanup
     *
     * @param string $path Temporary file path
     * @return string The path (for chaining)
     */
    public function trackTempFile(string $path): string
    {
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * Emergency cleanup - release all temp files
     */
    public function emergencyCleanup(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                try {
                    unlink($tempFile);
                } catch (\Exception $e) {
                    Craft::warning("Failed to unlink temp file {$tempFile}: " . $e->getMessage(), __METHOD__);
                }
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Track error with type-specific thresholds
     *
     * @param string $operation Operation type
     * @param string $message Error message
     * @param array $context Additional context
     * @throws \Exception If error threshold exceeded
     */
    public function trackError(string $operation, string $message, array $context = []): void
    {
        if (!isset($this->errorCounts[$operation])) {
            $this->errorCounts[$operation] = [];
        }

        $errorEntry = [
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->errorCounts[$operation][] = $errorEntry;

        if (!isset($this->stats['errors'])) {
            $this->stats['errors'] = 0;
        }
        $this->stats['errors']++;

        // Log with full context
        Craft::error(
            "Operation '{$operation}' error: {$message}\nContext: " . json_encode($context),
            __METHOD__
        );

        // Write to migration-specific error log
        $errorLogFile = Craft::getAlias('@storage') . '/migration-errors-' . $this->migrationId . '.log';
        $errorLine = sprintf(
            "[%s] %s: %s | Context: %s\n",
            date('Y-m-d H:i:s'),
            $operation,
            $message,
            json_encode($context)
        );
        try {
            file_put_contents($errorLogFile, $errorLine, FILE_APPEND);
        } catch (\Exception $e) {
            Craft::error("Failed to write to error log file: " . $e->getMessage(), __METHOD__);
            Craft::error("Original error: " . $message, __METHOD__);
        }

        // CHECK THRESHOLD - Type-specific logic
        $this->checkErrorThresholds($errorLogFile);
    }

    /**
     * Check if error thresholds have been exceeded
     *
     * @param string $errorLogFile Path to error log file
     * @throws \Exception If threshold exceeded
     */
    private function checkErrorThresholds(string $errorLogFile): void
    {
        $missingFileErrors = count($this->errorCounts['missing_source_file'] ?? []);
        $criticalErrors = 0;

        // Count critical errors (all errors except expected missing files)
        foreach ($this->errorCounts as $errorType => $errors) {
            if ($errorType !== 'missing_source_file') {
                $criticalErrors += count($errors);
            }
        }

        $totalErrors = $missingFileErrors + $criticalErrors;
        $unexpectedMissingFileErrors = max(0, $missingFileErrors - $this->expectedMissingFileCount);
        $unexpectedErrors = $criticalErrors + $unexpectedMissingFileErrors;

        // Check critical errors first
        if ($criticalErrors >= $this->criticalErrorThreshold) {
            throw new \Exception(
                "Critical error threshold exceeded ({$criticalErrors} unexpected errors). " .
                "Migration halted for safety. Review errors and resume with --resume flag. " .
                "Error log: {$errorLogFile}"
            );
        }

        // Check if missing file errors exceed expected count
        $maxMissingFileErrors = max($this->expectedMissingFileCount + 10, $this->errorThreshold);
        if ($missingFileErrors > $maxMissingFileErrors) {
            throw new \Exception(
                "Missing file errors ({$missingFileErrors}) exceed expected count ({$this->expectedMissingFileCount}). " .
                "This may indicate a configuration issue. Review errors and resume with --resume flag. " .
                "Error log: {$errorLogFile}"
            );
        }

        // Halt if unexpected errors exceed threshold
        if ($unexpectedErrors >= $this->errorThreshold) {
            throw new \Exception(
                "Error threshold exceeded ({$unexpectedErrors} unexpected errors). " .
                "Migration halted for safety. Review errors and resume with --resume flag. " .
                "Error log: {$errorLogFile}"
            );
        }
    }

    /**
     * Get duplicate record from database
     *
     * @param string $sourceKey Source file key
     * @return array|null Duplicate record or null
     */
    private function getDuplicateRecord(string $sourceKey): ?array
    {
        try {
            $db = Craft::$app->getDb();
            return $db->createCommand('
                SELECT *
                FROM {{%migration_file_duplicates}}
                WHERE migrationId = :migrationId AND fileKey = :fileKey
                LIMIT 1
            ', [
                ':migrationId' => $this->migrationId,
                ':fileKey' => $sourceKey,
            ])->queryOne();
        } catch (\Exception $e) {
            Craft::warning("Could not query duplicate record: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Check if source file can be safely deleted
     *
     * @param string $sourceKey Source file key
     * @param array|null $duplicateRecord Duplicate record data
     * @param $asset Asset instance
     * @return bool True if safe to delete
     */
    private function canSafelyDeleteSource(string $sourceKey, ?$duplicateRecord, $asset): bool
    {
        // Don't delete if part of a duplicate group that hasn't been fully processed
        if ($duplicateRecord && $duplicateRecord['status'] !== 'analyzed') {
            return false;
        }

        // Don't delete if source and destination are the same (nested filesystem issue)
        $assetPath = $asset->getVolume()->getFs()->getRootPath() . '/' . $asset->getPath();
        $sourcePath = str_replace($sourceKey, '', $sourceKey);
        if ($assetPath === $sourcePath) {
            return false;
        }

        return true;
    }

    /**
     * Set expected missing file count
     *
     * @param int $count Expected count
     */
    public function setExpectedMissingFileCount(int $count): void
    {
        $this->expectedMissingFileCount = $count;
    }

    /**
     * Set error thresholds
     *
     * @param int $criticalThreshold Critical error threshold
     * @param int $errorThreshold General error threshold
     */
    public function setErrorThresholds(int $criticalThreshold, int $errorThreshold): void
    {
        $this->criticalErrorThreshold = $criticalThreshold;
        $this->errorThreshold = $errorThreshold;
    }

    /**
     * Get statistics
     *
     * @return array Statistics data
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get error counts
     *
     * @return array Error counts by operation
     */
    public function getErrorCounts(): array
    {
        return $this->errorCounts;
    }
}
