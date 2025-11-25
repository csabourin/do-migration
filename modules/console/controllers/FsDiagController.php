<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use csabourin\spaghettiMigrator\console\BaseConsoleController;
use craft\helpers\Console;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Enhanced Filesystem Diagnostic Tool
 * 
 * Added commands to work with filesystems directly (without volumes)
 * Useful for checking files before volume setup or investigating unmapped filesystems
 * 
 * Usage:
 *   ./craft spaghetti-migrator/fs-diag/list-fs images_do
 *   ./craft spaghetti-migrator/fs-diag/list-fs images_do --path="images" --recursive=1
 *   ./craft spaghetti-migrator/fs-diag/search-fs images_do "myfile.jpg"
 *   ./craft spaghetti-migrator/fs-diag/verify-fs images_do "/path/to/file.jpg"
 */
class FsDiagController extends BaseConsoleController
{
    public string $defaultAction = 'list';

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * @var string Optional path within the filesystem
     */
    public $path = '';

    /**
     * @var bool Whether to list recursively
     */
    public $recursive = true;

    /**
     * @var int Maximum number of files to show
     */
    public $limit;

    /**
     * @var string Filename or pattern to search for
     */
    public $filename = '';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();

        // Set default limit from config if not already set
        if ($this->limit === null) {
            $this->limit = $this->config->getFileListLimit();
        }
    }

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'list-fs') {
            $options[] = 'path';
            $options[] = 'recursive';
            $options[] = 'limit';
        }

        if ($actionID === 'search-fs') {
            $options[] = 'path';
        }

        if ($actionID === 'compare-fs') {
            $options[] = 'path';
        }

        return $options;
    }

    /**
     * List files in a filesystem by handle (NO VOLUME REQUIRED)
     *
     * @param string $fsHandle The filesystem handle (e.g., 'images_do', 'documents_do')
     * @param string $path Optional path within the filesystem
     * @param bool $recursive Whether to list recursively
     * @param int $limit Maximum number of files to show (uses config default if not specified)
     */
    public function actionListFs($fsHandle, $path = '', $recursive = true, $limit = null)
    {
        // Use config default if limit not specified
        if ($limit === null) {
            $limit = $this->limit;
        }

        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("FILESYSTEM DIAGNOSTIC - LIST BY FS HANDLE\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // Get filesystem by handle (not volume)
        $fs = Craft::$app->getFs()->getFilesystemByHandle($fsHandle);
        if (!$fs) {
            $this->stderr("Filesystem '{$fsHandle}' not found in project config.\n\n", Console::FG_RED);
            $this->listAvailableFilesystems();
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Filesystem: {$fsHandle}\n", Console::FG_GREEN);
        $this->stdout("FS Class: " . get_class($fs) . "\n", Console::FG_GREY);

        // Show FS configuration
        if (property_exists($fs, 'subfolder')) {
            $resolved = Craft::parseEnv((string) $fs->subfolder);
            $this->stdout("Subfolder: " . ($resolved !== '' ? $resolved : '(none)') . "\n", Console::FG_GREY);
        }

        $this->stdout("\n");

        // Scan filesystem
        $cleanPath = trim($path, '/');
        $recursive = $this->normalizeBool($recursive);

        $this->stdout("Scanning path: '{$cleanPath}' (recursive: " . ($recursive ? 'yes' : 'no') . ")\n\n", Console::FG_CYAN);

        try {
            $files = $this->scanFilesystem($fs, $cleanPath, $recursive, $limit);

            if (empty($files['all'])) {
                $this->stdout("No files found!\n\n", Console::FG_YELLOW);
                $this->stdout("Troubleshooting tips:\n");
                $this->stdout("  1. Check if the path exists in your storage\n");
                $this->stdout("  2. Try without --path to scan the root\n");
                $this->stdout("  3. Verify filesystem configuration in project config\n\n");
            } else {
                $this->stdout("Found " . count($files['all']) . " total items, " . count($files['images']) . " images\n\n", Console::FG_GREEN);

                if (!empty($files['images'])) {
                    $this->stdout("Image Files (showing first {$limit}):\n", Console::FG_CYAN);
                    $this->stdout(str_repeat("-", 80) . "\n");

                    foreach (array_slice($files['images'], 0, $limit) as $i => $file) {
                        $size = $this->formatBytes($file['size'] ?? 0);
                        $date = $file['lastModified'] ? date('Y-m-d H:i:s', $file['lastModified']) : 'unknown';

                        $this->stdout(sprintf(
                            "%3d. %-50s %10s  %s\n",
                            $i + 1,
                            $file['path'],
                            $size,
                            $date
                        ));
                    }

                    if (count($files['images']) > $limit) {
                        $remaining = count($files['images']) - $limit;
                        $this->stdout("\n... and {$remaining} more images\n", Console::FG_GREY);
                    }
                }

                if (!empty($files['directories'])) {
                    $this->stdout("\nDirectories found: " . count($files['directories']) . "\n", Console::FG_CYAN);
                    foreach (array_slice($files['directories'], 0, 20) as $dir) {
                        $this->stdout("  - {$dir}\n", Console::FG_GREY);
                    }
                }
            }

        } catch (\Exception $e) {
            $this->stderr("\nError scanning filesystem:\n", Console::FG_RED);
            $this->stderr($e->getMessage() . "\n\n");
            $this->stderr("Stack trace:\n" . $e->getTraceAsString() . "\n\n", Console::FG_GREY);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n");
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Search for specific files in a filesystem by handle
     * 
     * @param string $fsHandle The filesystem handle
     * @param string $filename The filename or pattern to search for
     * @param string $path Optional starting path
     */
    public function actionSearchFs($fsHandle, $filename, $path = '')
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("FILESYSTEM SEARCH BY FS HANDLE\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // Get filesystem
        $fs = Craft::$app->getFs()->getFilesystemByHandle($fsHandle);
        if (!$fs) {
            $this->stderr("Filesystem '{$fsHandle}' not found.\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Searching for: '{$filename}'\n");
        $this->stdout("In filesystem: {$fsHandle}\n");
        if ($path) {
            $this->stdout("Starting from: '{$path}'\n");
        }
        $this->stdout("\n");

        try {
            // Scan recursively
            $files = $this->scanFilesystem($fs, $path, true, null);
            
            // Filter for matching files
            $matches = [];
            $pattern = '/' . preg_quote($filename, '/') . '/i';
            
            foreach ($files['all'] as $file) {
                if ($file['type'] === 'file' && preg_match($pattern, $file['path'])) {
                    $matches[] = $file;
                }
            }

            if (empty($matches)) {
                $this->stdout("No matches found.\n\n", Console::FG_YELLOW);
            } else {
                $this->stdout("Found " . count($matches) . " matching file(s):\n\n", Console::FG_GREEN);
                
                foreach ($matches as $i => $file) {
                    $size = $this->formatBytes($file['size'] ?? 0);
                    $date = $file['lastModified'] ? date('Y-m-d H:i:s', $file['lastModified']) : 'unknown';
                    
                    $this->stdout(sprintf(
                        "%3d. %s\n     Size: %s, Modified: %s\n",
                        $i + 1,
                        $file['path'],
                        $size,
                        $date
                    ));
                }
            }

        } catch (\Exception $e) {
            $this->stderr("Search failed: " . $e->getMessage() . "\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n");
        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Verify if specific file exists in filesystem
     * 
     * @param string $fsHandle The filesystem handle
     * @param string $filePath The file path to check
     */
    public function actionVerifyFs($fsHandle, $filePath)
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("FILESYSTEM FILE VERIFICATION\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // Get filesystem
        $fs = Craft::$app->getFs()->getFilesystemByHandle($fsHandle);
        if (!$fs) {
            $this->stderr("Filesystem '{$fsHandle}' not found.\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $cleanPath = trim($filePath, '/');
        
        $this->stdout("Checking file: {$cleanPath}\n");
        $this->stdout("In filesystem: {$fsHandle}\n\n");

        try {
            // Check if file exists
            if ($fs->fileExists($cleanPath)) {
                $this->stdout("✓ FILE EXISTS\n\n", Console::FG_GREEN);
                
                // Try to get file info
                try {
                    $size = $fs->fileSize($cleanPath);
                    $this->stdout("File size: " . $this->formatBytes($size) . "\n");
                    
                    $mimeType = $fs->mimeType($cleanPath);
                    $this->stdout("MIME type: {$mimeType}\n");
                    
                    $lastModified = $fs->lastModified($cleanPath);
                    $this->stdout("Modified: " . date('Y-m-d H:i:s', $lastModified) . "\n");
                    
                } catch (\Exception $e) {
                    $this->stdout("(File info unavailable: " . $e->getMessage() . ")\n", Console::FG_GREY);
                }
                
                $this->stdout("\n");
                $this->stdout("__CLI_EXIT_CODE_0__\n");
                return ExitCode::OK;

            } else {
                $this->stdout("✗ FILE NOT FOUND\n\n", Console::FG_RED);
                
                // Try to suggest similar files
                $dirname = dirname($cleanPath);
                $basename = basename($cleanPath);
                
                $this->stdout("Searching for similar files...\n");
                
                try {
                    $files = $this->scanFilesystem($fs, $dirname === '.' ? '' : $dirname, false, 100);
                    
                    $similar = [];
                    foreach ($files['all'] as $file) {
                        if ($file['type'] === 'file') {
                            $fileBasename = basename($file['path']);
                            $similarity = 0;
                            similar_text(strtolower($basename), strtolower($fileBasename), $similarity);
                            
                            if ($similarity > 70) {
                                $similar[] = [
                                    'path' => $file['path'],
                                    'similarity' => $similarity
                                ];
                            }
                        }
                    }
                    
                    if (!empty($similar)) {
                        usort($similar, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
                        
                        $this->stdout("\nSimilar files found:\n", Console::FG_YELLOW);
                        foreach (array_slice($similar, 0, 5) as $i => $item) {
                            $this->stdout(sprintf(
                                "  %d. %s (%.0f%% similar)\n",
                                $i + 1,
                                $item['path'],
                                $item['similarity']
                            ));
                        }
                    }
                    
                } catch (\Exception $e) {
                    // Couldn't scan for similar files
                }

                $this->stdout("\n");
                $this->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }

        } catch (\Exception $e) {
            $this->stderr("Verification failed: " . $e->getMessage() . "\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Compare two filesystems to find differences
     * 
     * @param string $fs1Handle First filesystem handle
     * @param string $fs2Handle Second filesystem handle
     * @param string $path Optional path to compare
     */
    public function actionCompareFs($fs1Handle, $fs2Handle, $path = '')
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("FILESYSTEM COMPARISON\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // Get both filesystems
        $fs1 = Craft::$app->getFs()->getFilesystemByHandle($fs1Handle);
        $fs2 = Craft::$app->getFs()->getFilesystemByHandle($fs2Handle);

        if (!$fs1) {
            $this->stderr("Filesystem '{$fs1Handle}' not found.\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$fs2) {
            $this->stderr("Filesystem '{$fs2Handle}' not found.\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Comparing:\n");
        $this->stdout("  FS1: {$fs1Handle}\n");
        $this->stdout("  FS2: {$fs2Handle}\n");
        if ($path) {
            $this->stdout("  Path: {$path}\n");
        }
        $this->stdout("\n");

        try {
            // Scan both filesystems
            $this->stdout("Scanning {$fs1Handle}... ");
            $files1 = $this->scanFilesystem($fs1, $path, true, null);
            $this->stdout("done (" . count($files1['all']) . " items)\n");
            
            $this->stdout("Scanning {$fs2Handle}... ");
            $files2 = $this->scanFilesystem($fs2, $path, true, null);
            $this->stdout("done (" . count($files2['all']) . " items)\n\n");
            
            // Build file maps (filename => file data)
            $map1 = [];
            foreach ($files1['all'] as $file) {
                if ($file['type'] === 'file') {
                    $map1[$file['path']] = $file;
                }
            }
            
            $map2 = [];
            foreach ($files2['all'] as $file) {
                if ($file['type'] === 'file') {
                    $map2[$file['path']] = $file;
                }
            }
            
            // Find differences
            $onlyIn1 = array_diff_key($map1, $map2);
            $onlyIn2 = array_diff_key($map2, $map1);
            $inBoth = array_intersect_key($map1, $map2);
            
            // Check for size differences in common files
            $sizeDiff = [];
            foreach ($inBoth as $path => $file1) {
                $file2 = $map2[$path];
                if (($file1['size'] ?? 0) !== ($file2['size'] ?? 0)) {
                    $sizeDiff[$path] = [
                        'fs1_size' => $file1['size'] ?? 0,
                        'fs2_size' => $file2['size'] ?? 0
                    ];
                }
            }
            
            // Display results
            $this->stdout("COMPARISON RESULTS:\n", Console::FG_CYAN);
            $this->stdout(str_repeat("=", 80) . "\n");
            
            $this->stdout("\nFiles only in {$fs1Handle}: " . count($onlyIn1) . "\n", Console::FG_YELLOW);
            if (count($onlyIn1) > 0 && count($onlyIn1) <= 20) {
                foreach ($onlyIn1 as $path => $file) {
                    $this->stdout("  - {$path} (" . $this->formatBytes($file['size'] ?? 0) . ")\n");
                }
            } elseif (count($onlyIn1) > 20) {
                foreach (array_slice($onlyIn1, 0, 10) as $path => $file) {
                    $this->stdout("  - {$path}\n");
                }
                $this->stdout("  ... and " . (count($onlyIn1) - 10) . " more\n", Console::FG_GREY);
            }
            
            $this->stdout("\nFiles only in {$fs2Handle}: " . count($onlyIn2) . "\n", Console::FG_YELLOW);
            if (count($onlyIn2) > 0 && count($onlyIn2) <= 20) {
                foreach ($onlyIn2 as $path => $file) {
                    $this->stdout("  - {$path} (" . $this->formatBytes($file['size'] ?? 0) . ")\n");
                }
            } elseif (count($onlyIn2) > 20) {
                foreach (array_slice($onlyIn2, 0, 10) as $path => $file) {
                    $this->stdout("  - {$path}\n");
                }
                $this->stdout("  ... and " . (count($onlyIn2) - 10) . " more\n", Console::FG_GREY);
            }
            
            $this->stdout("\nFiles in both: " . count($inBoth) . "\n", Console::FG_GREEN);
            
            if (!empty($sizeDiff)) {
                $this->stdout("\nFiles with different sizes: " . count($sizeDiff) . "\n", Console::FG_RED);
                foreach (array_slice($sizeDiff, 0, 10) as $path => $sizes) {
                    $this->stdout(sprintf(
                        "  - %s: %s vs %s\n",
                        $path,
                        $this->formatBytes($sizes['fs1_size']),
                        $this->formatBytes($sizes['fs2_size'])
                    ));
                }
                if (count($sizeDiff) > 10) {
                    $this->stdout("  ... and " . (count($sizeDiff) - 10) . " more\n", Console::FG_GREY);
                }
            }
            
            $this->stdout("\n");

        } catch (\Exception $e) {
            $this->stderr("Comparison failed: " . $e->getMessage() . "\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * List all available filesystems (not volumes)
     */
    private function listAvailableFilesystems()
    {
        $this->stdout("Available filesystems in project config:\n");
        
        $allFs = Craft::$app->getFs()->getAllFilesystems();
        
        if (empty($allFs)) {
            $this->stdout("  (none found)\n", Console::FG_GREY);
        } else {
            foreach ($allFs as $fs) {
                $handle = null;
                
                // Try to get the handle
                foreach (Craft::$app->getFs()->getAllFilesystems() as $h => $f) {
                    if ($f === $fs) {
                        $handle = $h;
                        break;
                    }
                }
                
                if ($handle) {
                    $this->stdout("  - {$handle} (" . get_class($fs) . ")\n", Console::FG_CYAN);
                }
            }
        }
        $this->stdout("\n");
    }

    // Include all the helper methods from original FsDiagController
    // (scanFilesystem, extractFsListingData, normalizeBool, formatBytes, etc.)
    // [These methods remain the same as in your original file]

    private function scanFilesystem($fs, $path, $recursive, $limit = null)
    {
        $cleanPath = trim((string) $path, '/');
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
            $extracted = $this->extractFsListingData($item);

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

            if ($limit && count($result['all']) >= $limit) {
                break;
            }
        }

        return $result;
    }

    private function extractFsListingData($item): array
    {
        $path = '';
        $isDir = false;
        $fileSize = null;
        $lastModified = null;

        // Try Craft's FsListing model
        if (is_object($item) && method_exists($item, 'getUri')) {
            try {
                $path = (string) $item->getUri();
                $isDir = (bool) $item->getIsDir();
                if (!$isDir) {
                    $fileSize = $item->getFileSize();
                    $dateModified = $item->getDateModified();
                    if ($dateModified instanceof \DateTime) {
                        $lastModified = $dateModified->getTimestamp();
                    }
                }
                return compact('path', 'isDir', 'fileSize', 'lastModified');
            } catch (\Throwable $e) {
                // Continue to fallback
            }
        }

        // Fallback for other formats
        if (is_array($item)) {
            $path = $item['path'] ?? $item['dirname'] ?? '';
            $isDir = ($item['type'] ?? '') === 'dir';
            $fileSize = $item['size'] ?? null;
            $lastModified = $item['timestamp'] ?? null;
        } elseif (is_string($item)) {
            $path = $item;
        }

        return compact('path', 'isDir', 'fileSize', 'lastModified');
    }

    private function normalizeBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(strtolower($value), ['false', '0', 'no', ''], true);
        }
        return (bool) $value;
    }

    private function formatBytes($bytes)
    {
        if ($bytes === null) return 'N/A';
        if ($bytes == 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}