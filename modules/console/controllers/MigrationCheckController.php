<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use modules\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Pre-Migration Diagnostic
 * 
 * Verifies configuration and detects potential issues before migration
 * 
 * @author Migration Specialist
 * @version 1.0
 */
class MigrationCheckController extends Controller
{
    public $defaultAction = 'check';

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();
    }

    /**
     * Run comprehensive pre-migration checks
     */
    public function actionCheck()
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("PRE-MIGRATION DIAGNOSTIC\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $issues = [];
        $warnings = [];
        $passed = 0;

        // Check 1: Volume Configuration
        $this->stdout("1. Checking volume configuration...\n", Console::FG_YELLOW);
        $volumeCheck = $this->checkVolumes();
        if ($volumeCheck['status'] === 'pass') {
            $this->stdout("   ✓ PASS\n", Console::FG_GREEN);
            $passed++;
        } else if ($volumeCheck['status'] === 'warning') {
            $this->stdout("   ⚠ WARNING\n", Console::FG_YELLOW);
            $warnings = array_merge($warnings, $volumeCheck['messages']);
        } else {
            $this->stdout("   ✗ FAIL\n", Console::FG_RED);
            $issues = array_merge($issues, $volumeCheck['messages']);
        }

        // Check 2: Filesystem Access
        $this->stdout("2. Checking filesystem access...\n", Console::FG_YELLOW);
        $fsCheck = $this->checkFilesystems();
        if ($fsCheck['status'] === 'pass') {
            $this->stdout("   ✓ PASS\n", Console::FG_GREEN);
            $passed++;
        } else {
            $this->stdout("   ✗ FAIL\n", Console::FG_RED);
            $issues = array_merge($issues, $fsCheck['messages']);
        }

        // Check 3: Database Schema
        $this->stdout("3. Checking database schema...\n", Console::FG_YELLOW);
        $dbCheck = $this->checkDatabase();
        if ($dbCheck['status'] === 'pass') {
            $this->stdout("   ✓ PASS\n", Console::FG_GREEN);
            $passed++;
        } else {
            $this->stdout("   ✗ FAIL\n", Console::FG_RED);
            $issues = array_merge($issues, $dbCheck['messages']);
        }

        // Check 4: PHP Configuration
        $this->stdout("4. Checking PHP configuration...\n", Console::FG_YELLOW);
        $phpCheck = $this->checkPhp();
        if ($phpCheck['status'] === 'pass') {
            $this->stdout("   ✓ PASS\n", Console::FG_GREEN);
            $passed++;
        } else if ($phpCheck['status'] === 'warning') {
            $this->stdout("   ⚠ WARNING\n", Console::FG_YELLOW);
            $warnings = array_merge($warnings, $phpCheck['messages']);
        } else {
            $this->stdout("   ✗ FAIL\n", Console::FG_RED);
            $issues = array_merge($issues, $phpCheck['messages']);
        }

        // Check 5: Sample File Operations
        $this->stdout("5. Testing file operations...\n", Console::FG_YELLOW);
        $fileCheck = $this->checkFileOperations();
        if ($fileCheck['status'] === 'pass') {
            $this->stdout("   ✓ PASS\n", Console::FG_GREEN);
            $passed++;
        } else if ($fileCheck['status'] === 'warning') {
            $this->stdout("   ⚠ WARNING\n", Console::FG_YELLOW);
            $warnings = array_merge($warnings, $fileCheck['messages']);
        } else {
            $this->stdout("   ✗ FAIL\n", Console::FG_RED);
            $issues = array_merge($issues, $fileCheck['messages']);
        }

        // Check 6: Asset Counts
        $this->stdout("6. Analyzing asset distribution...\n", Console::FG_YELLOW);
        $assetCheck = $this->checkAssetDistribution();
        $this->stdout("   ℹ INFO\n", Console::FG_CYAN);

        // Summary
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("DIAGNOSTIC SUMMARY\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $this->stdout("Checks passed: {$passed}/6\n", Console::FG_GREEN);

        if (!empty($warnings)) {
            $this->stdout("\nWARNINGS (" . count($warnings) . "):\n", Console::FG_YELLOW);
            foreach ($warnings as $warning) {
                $this->stdout("  ⚠ {$warning}\n", Console::FG_YELLOW);
            }
        }

        if (!empty($issues)) {
            $this->stdout("\nISSUES (" . count($issues) . "):\n", Console::FG_RED);
            foreach ($issues as $issue) {
                $this->stdout("  ✗ {$issue}\n", Console::FG_RED);
            }
            $this->stdout("\n⛔ MIGRATION SHOULD NOT PROCEED UNTIL ISSUES ARE RESOLVED\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        } else if (!empty($warnings)) {
            $this->stdout("\n✓ Ready to migrate (with warnings)\n", Console::FG_YELLOW);
            $this->stdout("Review warnings above and proceed with caution.\n\n");
            return ExitCode::OK;
        } else {
            $this->stdout("\n✓ ALL CHECKS PASSED - Ready for migration!\n", Console::FG_GREEN);
            $this->stdout("\nNext step: php craft ncc-module/image-migration/migrate --dryRun=1\n\n");
            return ExitCode::OK;
        }
    }

    /**
     * Check volume configuration
     */
    private function checkVolumes()
    {
        $volumesService = Craft::$app->getVolumes();
        $messages = [];
        $status = 'pass';

        // Check for required volumes - loaded from centralized config
        $requiredHandles = array_merge(
            $this->config->getSourceVolumeHandles(),
            [$this->config->getQuarantineVolumeHandle()]
        );
        $foundVolumes = [];

        foreach ($requiredHandles as $handle) {
            $volume = $volumesService->getVolumeByHandle($handle);
            if ($volume) {
                $foundVolumes[$handle] = $volume;
                $this->stdout("     ✓ Volume '{$handle}' found (ID: {$volume->id})\n", Console::FG_GREEN);
            } else {
                if ($handle === 'quarantine') {
                    $messages[] = "Quarantine volume not found - you must create it before migration";
                    $status = 'fail';
                } else if ($handle === 'optimisedImages') {
                    $messages[] = "OptimisedImages volume not found - will be skipped";
                    $status = 'warning';
                } else {
                    $messages[] = "Required volume '{$handle}' not found";
                    $status = 'fail';
                }
            }
        }

        // Check quarantine uses different filesystem
        if (isset($foundVolumes['quarantine']) && isset($foundVolumes['images'])) {
            $quarantineFs = $foundVolumes['quarantine']->fsHandle;
            $imagesFs = $foundVolumes['images']->fsHandle;

            if ($quarantineFs === $imagesFs) {
                $messages[] = "Quarantine volume must use DIFFERENT filesystem than Images volume";
                $status = 'fail';
            } else {
                $this->stdout("     ✓ Quarantine uses separate filesystem\n", Console::FG_GREEN);
            }
        }

        return ['status' => $status, 'messages' => $messages];
    }

    /**
     * Check filesystem access
     */
    private function checkFilesystems()
    {
        $messages = [];
        $status = 'pass';

        $volumesService = Craft::$app->getVolumes();

        foreach (['images', 'quarantine'] as $handle) {
            $volume = $volumesService->getVolumeByHandle($handle);
            if (!$volume) continue;

            try {
                $fs = $volume->getFs();
                
                // Test read
                try {
                    $files = $fs->listContents('', false);
                    $this->stdout("     ✓ Read access: {$handle}\n", Console::FG_GREEN);
                } catch (\Exception $e) {
                    $messages[] = "Cannot read from '{$handle}' filesystem: " . $e->getMessage();
                    $status = 'fail';
                }

                // Test write
                $testFile = '_migration_test_' . time() . '.txt';
                try {
                    $fs->write($testFile, 'test');
                    $this->stdout("     ✓ Write access: {$handle}\n", Console::FG_GREEN);
                    
                    // Cleanup
                    try {
                        $fs->deleteFile($testFile);
                        $this->stdout("     ✓ Delete access: {$handle}\n", Console::FG_GREEN);
                    } catch (\Exception $e) {
                        $messages[] = "Cannot delete from '{$handle}' filesystem: " . $e->getMessage();
                        $status = 'fail';
                    }
                } catch (\Exception $e) {
                    $messages[] = "Cannot write to '{$handle}' filesystem: " . $e->getMessage();
                    $status = 'fail';
                }

            } catch (\Exception $e) {
                $messages[] = "Cannot access '{$handle}' filesystem: " . $e->getMessage();
                $status = 'fail';
            }
        }

        return ['status' => $status, 'messages' => $messages];
    }

    /**
     * Check database schema
     */
    private function checkDatabase()
    {
        $messages = [];
        $status = 'pass';
        $db = Craft::$app->getDb();

        $requiredTables = ['assets', 'volumefolders', 'relations', 'elements'];

        foreach ($requiredTables as $table) {
            try {
                $exists = $db->getTableSchema($table);
                if ($exists) {
                    $this->stdout("     ✓ Table '{$table}' exists\n", Console::FG_GREEN);
                } else {
                    $messages[] = "Required table '{$table}' not found";
                    $status = 'fail';
                }
            } catch (\Exception $e) {
                $messages[] = "Cannot check table '{$table}': " . $e->getMessage();
                $status = 'fail';
            }
        }

        return ['status' => $status, 'messages' => $messages];
    }

    /**
     * Check PHP configuration
     */
    private function checkPhp()
    {
        $messages = [];
        $status = 'pass';

        // Memory limit
        $memoryLimit = ini_get('memory_limit');
        $memoryBytes = $this->convertToBytes($memoryLimit);
        $requiredBytes = 512 * 1024 * 1024; // 512MB

        if ($memoryBytes === -1) {
            $this->stdout("     ✓ Memory limit: unlimited\n", Console::FG_GREEN);
        } else if ($memoryBytes < $requiredBytes) {
            $messages[] = "Memory limit ({$memoryLimit}) may be too low. Recommended: 512M or higher";
            $status = 'warning';
        } else {
            $this->stdout("     ✓ Memory limit: {$memoryLimit}\n", Console::FG_GREEN);
        }

        // Max execution time
        $maxTime = ini_get('max_execution_time');
        if ($maxTime == 0) {
            $this->stdout("     ✓ Max execution time: unlimited\n", Console::FG_GREEN);
        } else if ($maxTime < 300) {
            $messages[] = "Max execution time ({$maxTime}s) may be too low. Recommended: 300s or unlimited";
            $status = 'warning';
        } else {
            $this->stdout("     ✓ Max execution time: {$maxTime}s\n", Console::FG_GREEN);
        }

        // Required extensions
        $requiredExts = ['pdo', 'pdo_mysql', 'gd', 'json', 'zip'];
        foreach ($requiredExts as $ext) {
            if (extension_loaded($ext)) {
                $this->stdout("     ✓ Extension '{$ext}' loaded\n", Console::FG_GREEN);
            } else {
                $messages[] = "Required PHP extension '{$ext}' not loaded";
                $status = 'fail';
            }
        }

        return ['status' => $status, 'messages' => $messages];
    }

    /**
     * Test file operations
     */
    private function checkFileOperations()
    {
        $messages = [];
        $status = 'pass';

        $volumesService = Craft::$app->getVolumes();
        $imagesVolume = $volumesService->getVolumeByHandle('images');

        if (!$imagesVolume) {
            return ['status' => 'fail', 'messages' => ['Images volume not found']];
        }

        // Get a sample asset
        $asset = Asset::find()
            ->volumeId($imagesVolume->id)
            ->kind('image')
            ->limit(1)
            ->one();

        if (!$asset) {
            $messages[] = "No assets found in Images volume - cannot test file operations";
            return ['status' => 'warning', 'messages' => $messages];
        }

        $this->stdout("     Testing with asset: {$asset->filename}\n", Console::FG_GREY);

        try {
            // Test 1: Check file exists
            $fs = $asset->getVolume()->getFs();
            $path = $asset->getPath();
            
            if ($fs->fileExists($path)) {
                $this->stdout("     ✓ File existence check works\n", Console::FG_GREEN);
            } else {
                $messages[] = "Sample asset file not found on filesystem";
                $status = 'warning';
            }

            // Test 2: Try to read file
            try {
                $content = $fs->read($path);
                $this->stdout("     ✓ File read operation works (read method)\n", Console::FG_GREEN);
            } catch (\Exception $e) {
                $messages[] = "Cannot read files using read() method: " . $e->getMessage();
                $status = 'fail';
            }

            // Test 3: Try readStream (expected to fail on DO Spaces)
            try {
                $stream = $fs->readStream($path);
                if (is_resource($stream)) {
                    fclose($stream);
                    $this->stdout("     ✓ File stream operation works\n", Console::FG_GREEN);
                }
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'readStream') !== false) {
                    $this->stdout("     ⚠ readStream not supported (expected for DO Spaces)\n", Console::FG_YELLOW);
                    $this->stdout("       Migration will use fallback method\n", Console::FG_GREY);
                } else {
                    $messages[] = "File stream error: " . $e->getMessage();
                    $status = 'warning';
                }
            }

            // Test 4: Get temp copy
            try {
                $tempPath = $asset->getCopyOfFile();
                if (file_exists($tempPath)) {
                    $this->stdout("     ✓ Temp file creation works\n", Console::FG_GREEN);
                    @unlink($tempPath);
                } else {
                    $messages[] = "Cannot create temporary file copy";
                    $status = 'fail';
                }
            } catch (\Exception $e) {
                $messages[] = "Cannot create temp copy: " . $e->getMessage();
                $status = 'fail';
            }

        } catch (\Exception $e) {
            $messages[] = "File operation test failed: " . $e->getMessage();
            $status = 'fail';
        }

        return ['status' => $status, 'messages' => $messages];
    }

    /**
     * Check asset distribution
     */
    private function checkAssetDistribution()
    {
        $db = Craft::$app->getDb();

        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        $this->stdout("\n     Asset Distribution:\n", Console::FG_CYAN);

        $total = 0;
        foreach ($volumes as $volume) {
            $count = Asset::find()->volumeId($volume->id)->count();
            $total += $count;
            
            if ($count > 0) {
                $this->stdout("       {$volume->name}: {$count} assets\n", Console::FG_GREY);
            }
        }

        $this->stdout("       TOTAL: {$total} assets\n\n", Console::FG_CYAN);

        // Check for assets in originals folders
        $originalsCount = $db->createCommand("
            SELECT COUNT(*)
            FROM assets a
            JOIN volumefolders vf ON vf.id = a.folderId
            WHERE (vf.name = 'originals' OR vf.path LIKE '%/originals/%' OR vf.path = 'originals/')
        ")->queryScalar();

        if ($originalsCount > 0) {
            $this->stdout("     ⚠ Found {$originalsCount} assets in '/originals' folders\n", Console::FG_YELLOW);
        }

        return ['status' => 'info', 'messages' => []];
    }

    /**
     * Convert PHP memory notation to bytes
     */
    private function convertToBytes($value)
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value)-1]);
        $value = (int)$value;
        
        switch($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Show detailed asset analysis
     */
    public function actionAnalyze()
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("DETAILED ASSET ANALYSIS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $db = Craft::$app->getDb();

        // Volume distribution
        $this->stdout("1. Volume Distribution:\n", Console::FG_YELLOW);
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        foreach ($volumes as $volume) {
            $count = Asset::find()->volumeId($volume->id)->count();
            $this->stdout("   {$volume->name}: {$count}\n", Console::FG_GREY);
        }

        // Folder distribution
        $this->stdout("\n2. Folder Distribution (Images volume):\n", Console::FG_YELLOW);
        $imagesVolume = Craft::$app->getVolumes()->getVolumeByHandle('images');
        if ($imagesVolume) {
            $folders = $db->createCommand("
                SELECT vf.name, vf.path, COUNT(a.id) as count
                FROM volumefolders vf
                LEFT JOIN assets a ON a.folderId = vf.id
                WHERE vf.volumeId = :volId
                GROUP BY vf.id
                ORDER BY count DESC
                LIMIT 20
            ", [':volId' => $imagesVolume->id])->queryAll();

            foreach ($folders as $folder) {
                $path = $folder['path'] ?: '(root)';
                $this->stdout("   {$path}: {$folder['count']}\n", Console::FG_GREY);
            }
        }

        // Usage analysis
        $this->stdout("\n3. Asset Usage Analysis:\n", Console::FG_YELLOW);
        
        $withRelations = (int)$db->createCommand("
            SELECT COUNT(DISTINCT a.id)
            FROM assets a
            JOIN relations r ON r.targetId = a.id
            JOIN elements e ON e.id = r.sourceId
                AND e.dateDeleted IS NULL
                AND e.archived = 0
        ")->queryScalar();

        $this->stdout("   Assets with relations: {$withRelations}\n", Console::FG_GREEN);

        // File types
        $this->stdout("\n4. File Types:\n", Console::FG_YELLOW);
        $types = $db->createCommand("
            SELECT 
                LOWER(SUBSTRING_INDEX(filename, '.', -1)) as ext,
                COUNT(*) as count
            FROM assets
            GROUP BY ext
            ORDER BY count DESC
            LIMIT 10
        ")->queryAll();

        foreach ($types as $type) {
            $this->stdout("   .{$type['ext']}: {$type['count']}\n", Console::FG_GREY);
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }
}