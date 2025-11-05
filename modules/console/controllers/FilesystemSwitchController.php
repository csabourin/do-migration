<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use craft\models\Volume;
use modules\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Filesystem Switch Controller (Craft 4 compatible)
 *
 * Safely switches volumes between AWS S3 and DigitalOcean Spaces
 *
 * Usage:
 *   ./craft ncc-module/filesystem-switch/preview
 *   ./craft ncc-module/filesystem-switch/to-do
 *   ./craft ncc-module/filesystem-switch/to-aws
 *   ./craft ncc-module/filesystem-switch/verify
 */
class FilesystemSwitchController extends Controller
{
    public $defaultAction = 'preview';

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * Filesystem mappings (AWS Handle => DO Handle) loaded from centralized config
     */
    private array $fsMappings;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();
        $this->fsMappings = $this->config->getFilesystemMappings();
    }

    /**
     * Preview what will be changed (dry run)
     */
    public function actionPreview(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("FILESYSTEM SWITCH PREVIEW\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $totalVolumes = 0;
        $totalAssets  = 0;

        $volumeService = Craft::$app->getVolumes();
        $allVolumes    = $volumeService->getAllVolumes(); // array<craft\models\Volume>

        foreach ($this->fsMappings as $awsHandle => $doHandle) {
            $this->stdout("Mapping: {$awsHandle} → {$doHandle}\n", Console::FG_YELLOW);

            // Resolve FS via service (Craft 4)
            $awsFs = Craft::$app->getFs()->getFilesystemByHandle($awsHandle);
            if (!$awsFs) {
                $this->stdout("  ⚠ AWS FS '{$awsHandle}' not found in Project Config\n\n", Console::FG_YELLOW);
                continue;
            }

            $doFs = Craft::$app->getFs()->getFilesystemByHandle($doHandle);
            if (!$doFs) {
                $this->stderr("  ✗ DO FS '{$doHandle}' NOT FOUND - cannot switch!\n\n", Console::FG_RED);
                continue;
            }

            $this->stdout("  AWS: " . $this->fsLabel($awsHandle) . " [" . $this->fsType($awsHandle) . "]\n", Console::FG_GREY);
            $this->stdout("  DO:  " . $this->fsLabel($doHandle) . " [" . $this->fsType($doHandle) . "]\n", Console::FG_GREY);

            // Find volumes currently pointing at the AWS handle
            $volsForHandle = array_filter($allVolumes, fn(Volume $v) => $v->fsHandle === $awsHandle);

            if (empty($volsForHandle)) {
                $this->stdout("  No volumes using this filesystem\n\n", Console::FG_GREY);
                continue;
            }

            foreach ($volsForHandle as $volume) {
                $assetCount = (new Query())
                    ->from('{{%assets}}')
                    ->where(['volumeId' => $volume->id])
                    ->count();

                $this->stdout("  ✓ Volume: {$volume->name} ({$volume->handle})\n", Console::FG_GREEN);
                $this->stdout("    Assets: {$assetCount}\n", Console::FG_GREY);

                $totalVolumes++;
                $totalAssets += (int)$assetCount;
            }

            $this->stdout("\n");
        }

        $this->stdout(str_repeat("-", 80) . "\n");
        $this->stdout("Total volumes to switch: {$totalVolumes}\n", Console::FG_CYAN);
        $this->stdout("Total assets affected: {$totalAssets}\n", Console::FG_CYAN);
        $this->stdout(str_repeat("-", 80) . "\n\n");

        $this->stdout("Commands:\n");
        $this->stdout("  Execute switch: ./craft ncc-module/filesystem-switch/to-do\n", Console::FG_GREEN);
        $this->stdout("  Rollback:       ./craft ncc-module/filesystem-switch/to-aws\n", Console::FG_YELLOW);
        $this->stdout("  Verify:         ./craft ncc-module/filesystem-switch/verify\n\n");

        return ExitCode::OK;
    }

    /**
     * Switch to DigitalOcean Spaces
     */
    public function actionToDo(bool $confirm = false): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("SWITCH TO DIGITALOCEAN SPACES\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        // Require confirmation
        if (!$confirm) {
            $this->stdout("⚠ This will switch ALL volumes from AWS to DigitalOcean Spaces\n\n", Console::FG_YELLOW);

            $response = $this->prompt(
                "Have you:\n" .
                "  1. Backed up the database? (./craft db/backup)\n" .
                "  2. Synced all files to DO Spaces?\n" .
                "  3. Verified DO connectivity? (./craft ncc-module/fs-diag/test images_do)\n" .
                "Type 'yes' to proceed:",
                ['required' => true, 'default' => 'no']
            );

            if ($response !== 'yes') {
                $this->stdout("Switch cancelled.\n");
                return ExitCode::OK;
            }
        }

        return $this->executeSwitch('to-do');
    }

    /**
     * Rollback to AWS S3
     */
    public function actionToAws(bool $confirm = false): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_YELLOW);
        $this->stdout("ROLLBACK TO AWS S3\n", Console::FG_YELLOW);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_YELLOW);

        if (!$confirm) {
            $response = $this->prompt(
                "This will revert ALL volumes back to AWS S3.\nType 'yes' to proceed:",
                ['required' => true, 'default' => 'no']
            );

            if ($response !== 'yes') {
                $this->stdout("Rollback cancelled.\n");
                return ExitCode::OK;
            }
        }

        return $this->executeSwitch('to-aws');
    }

    /**
     * Verify current filesystem setup
     */
    public function actionVerify(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("FILESYSTEM VERIFICATION\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $volumeService = Craft::$app->getVolumes();
        $allVolumes    = $volumeService->getAllVolumes();

        $awsCount = 0;
        $doCount = 0;
        $otherCount = 0;

        foreach ($allVolumes as $volume) {
            $assetCount = (new Query())
                ->from('{{%assets}}')
                ->where(['volumeId' => $volume->id])
                ->count();

            $fsType = $this->classifyHandle($volume->fsHandle); // AWS / DO / OTHER
            $color  = $fsType === 'DO' ? Console::FG_GREEN : ($fsType === 'AWS' ? Console::FG_YELLOW : Console::FG_GREY);

            if ($fsType === 'AWS') $awsCount++;
            elseif ($fsType === 'DO') $doCount++;
            else $otherCount++;

            $this->stdout(sprintf(
                "%-30s %-25s [%-5s] %6d assets\n",
                $volume->name,
                "({$volume->fsHandle})",
                $fsType,
                $assetCount
            ), $color);
        }

        $this->stdout("\n" . str_repeat("-", 80) . "\n");
        $this->stdout("AWS volumes:   {$awsCount}\n", Console::FG_YELLOW);
        $this->stdout("DO volumes:    {$doCount}\n", Console::FG_GREEN);
        $this->stdout("Other volumes: {$otherCount}\n", Console::FG_GREY);
        $this->stdout(str_repeat("-", 80) . "\n\n");

        if ($awsCount > 0 && $doCount === 0) {
            $this->stdout("Status: All volumes on AWS S3\n", Console::FG_YELLOW);
            $this->stdout("Ready to switch: ./craft ncc-module/filesystem-switch/to-do\n\n", Console::FG_CYAN);
        } elseif ($doCount > 0 && $awsCount === 0) {
            $this->stdout("Status: All volumes on DigitalOcean Spaces ✓\n", Console::FG_GREEN);
            $this->stdout("To rollback: ./craft ncc-module/filesystem-switch/to-aws\n\n", Console::FG_CYAN);
        } else {
            $this->stdout("Status: Mixed (some AWS, some DO)\n", Console::FG_RED);
            $this->stdout("⚠ Warning: Inconsistent state detected\n\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Execute the filesystem switch
     *
     * @param 'to-do'|'to-aws' $direction
     */
    private function executeSwitch(string $direction): int
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        $mappings = ($direction === 'to-do') ? $this->fsMappings : array_flip($this->fsMappings);

        $switched = 0;

        try {
            $this->stdout("Starting filesystem switch...\n\n", Console::FG_CYAN);

            $volumeService = Craft::$app->getVolumes();
            $allVolumes    = $volumeService->getAllVolumes();

            foreach ($mappings as $fromHandle => $toHandle) {
                $this->stdout("Processing: {$fromHandle} → {$toHandle}\n");

                // Ensure both FS handles exist in Project Config / service
                $fromFs = Craft::$app->getFs()->getFilesystemByHandle($fromHandle);
                if (!$fromFs) {
                    $this->stdout("  ⚠ Source FS '{$fromHandle}' not found, skipping\n\n", Console::FG_YELLOW);
                    continue;
                }

                $toFs = Craft::$app->getFs()->getFilesystemByHandle($toHandle);
                if (!$toFs) {
                    $this->stderr("  ✗ Target FS '{$toHandle}' not found - ABORTING\n", Console::FG_RED);
                    throw new \RuntimeException("Target filesystem '{$toHandle}' not found");
                }

                // Volumes to update (matching by current fsHandle)
                $volsForHandle = array_filter($allVolumes, fn(Volume $v) => $v->fsHandle === $fromHandle);

                if (empty($volsForHandle)) {
                    $this->stdout("  No volumes to switch\n\n", Console::FG_GREY);
                    continue;
                }

                foreach ($volsForHandle as $volume) {
                    $assetCount = (new Query())
                        ->from('{{%assets}}')
                        ->where(['volumeId' => $volume->id])
                        ->count();

                    $this->stdout("  Volume: {$volume->name} ({$assetCount} assets)\n", Console::FG_GREY);

                    // Update via model + service (lets Craft handle PC + DB)
                    $volume->fsHandle = $toHandle;

                    if (!$volumeService->saveVolume($volume)) {
                        // If save fails, throw to roll back everything
                        $errors = implode("; ", array_map(
                            fn($attr, $msgs) => $attr . ': ' . implode(', ', $msgs),
                            array_keys($volume->getErrors()),
                            $volume->getErrors()
                        ));
                        throw new \RuntimeException("Failed to save volume '{$volume->name}': {$errors}");
                    }

                    $this->stdout("    ✓ Switched to {$toHandle}\n", Console::FG_GREEN);
                    $switched++;
                }

                $this->stdout("\n");
            }

            $this->stdout("Committing changes...\n", Console::FG_CYAN);
            $transaction->commit();

            $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_GREEN);
            $this->stdout("✓ FILESYSTEM SWITCH COMPLETE\n", Console::FG_GREEN);
            $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_GREEN);

            $this->stdout("Switched {$switched} volume(s) successfully\n", Console::FG_GREEN);
            $this->stdout("\nNext steps:\n");
            $this->stdout("  1. Clear caches: ./craft clear-caches/all\n");
            $this->stdout("  2. Verify setup: ./craft ncc-module/filesystem-switch/verify\n");
            $this->stdout("  3. Test assets:  ./craft ncc-module/fs-diag/test images_do\n");

            if ($direction === 'to-do') {
                $this->stdout("  4. Rollback:     ./craft ncc-module/filesystem-switch/to-aws (if needed)\n\n");
            } else {
                $this->stdout("  4. Re-switch:    ./craft ncc-module/filesystem-switch/to-do (when ready)\n\n");
            }

            return ExitCode::OK;

        } catch (\Throwable $e) {
            $transaction->rollBack();

            $this->stderr("\n" . str_repeat("=", 80) . "\n", Console::FG_RED);
            $this->stderr("✗ FILESYSTEM SWITCH FAILED\n", Console::FG_RED);
            $this->stderr(str_repeat("=", 80) . "\n\n", Console::FG_RED);

            $this->stderr("Error: " . $e->getMessage() . "\n", Console::FG_RED);
            $this->stderr("Transaction rolled back - no changes made\n\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Test connectivity to all filesystems defined in Project Config
     */
    public function actionTestConnectivity(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("FILESYSTEM CONNECTIVITY TEST\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $pcFs = $this->getAllFsFromProjectConfig(); // [ ['handle'=>..., 'name'=>..., 'type'=>...], ... ]

        if (empty($pcFs)) {
            $this->stdout("No filesystems found in Project Config (config/project/fs/*).\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $passed = 0;
        $failed = 0;

        foreach ($pcFs as $row) {
            $label = ($row['name'] ?? $row['handle']) . " ({$row['handle']})";
            $this->stdout("Testing: {$label}\n");

            try {
                $fs = Craft::$app->getFs()->getFilesystemByHandle($row['handle']);
                if (!$fs) {
                    throw new \RuntimeException("Filesystem handle not resolvable by service");
                }

                // Try to list a few items at root (may be empty; that's fine)
                $iter  = $fs->getFileList('', false);
                $count = 0;
                foreach ($iter as $_) {
                    $count++;
                    if ($count >= 3) {
                        break;
                    }
                }

                $this->stdout("  ✓ Connected ({$count}+ items listed or root accessible)\n", Console::FG_GREEN);
                $passed++;

            } catch (\Throwable $e) {
                $this->stderr("  ✗ Failed: " . $e->getMessage() . "\n", Console::FG_RED);
                $failed++;
            }

            $this->stdout("\n");
        }

        $this->stdout(str_repeat("-", 80) . "\n");
        $this->stdout("Passed: {$passed}\n", Console::FG_GREEN);
        $this->stdout("Failed: {$failed}\n", $failed > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout(str_repeat("-", 80) . "\n\n");

        return $failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * List all filesystems defined in Project Config
     */
    public function actionListFilesystems(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("ALL FILESYSTEMS (Project Config)\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $pcFs = $this->getAllFsFromProjectConfig();

        if (empty($pcFs)) {
            $this->stdout("No filesystems found in Project Config.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        foreach ($pcFs as $fs) {
            $name   = $fs['name'] ?? $fs['handle'];
            $handle = $fs['handle'];
            $type   = $fs['type'] ?? '(unknown)';
            $this->stdout(sprintf(
                "%-30s %-25s type: %s\n",
                $name,
                "({$handle})",
                $type
            ));
        }

        $this->stdout("\nTotal: " . count($pcFs) . " filesystem(s)\n\n");

        return ExitCode::OK;
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────────────────────

    private function fsLabel(string $handle): string
    {
        $cfg = $this->getFsConfig($handle);
        return $cfg['name'] ?? $handle;
    }

    private function fsType(string $handle): string
    {
        // Try via service first (actual runtime class)
        try {
            $fs = Craft::$app->getFs()->getFilesystemByHandle($handle);
            if ($fs) {
                $class = get_class($fs);
                if (stripos($class, 'awss3') !== false || stripos($class, 's3') !== false) {
                    if (property_exists($fs, 'endpoint') && !empty($fs->endpoint ?? null)) {
                        return stripos((string)$fs->endpoint, 'digitaloceanspaces') !== false ? 'S3/DO' : 'S3/AWS';
                    }
                    return 'S3';
                }
                if (stripos($class, 'Local') !== false) {
                    return 'Local';
                }
                return (new \ReflectionClass($fs))->getShortName();
            }
        } catch (\Throwable $e) {
            // ignore and fall back to config
        }

        $cfg = $this->getFsConfig($handle);
        return $cfg['type'] ?? 'unknown';
    }

    private function classifyHandle(string $handle): string
    {
        if (preg_match('/_do$/', $handle)) {
            return 'DO';
        }
        if (in_array($handle, array_keys($this->fsMappings), true)) {
            return 'AWS';
        }
        if (in_array($handle, array_values($this->fsMappings), true)) {
            return 'DO';
        }
        return 'OTHER';
    }

    /**
     * Get the project-config entry for a filesystem handle (if any).
     *
     * @return array<string,mixed>|null
     */
    private function getFsConfig(string $handle): ?array
    {
        $fs = Craft::$app->getProjectConfig()->get('fs') ?? [];
        if (isset($fs[$handle]) && is_array($fs[$handle])) {
            return $fs[$handle];
        }
        return null;
    }

    /**
     * Get all FS entries from Project Config as a normalized list.
     *
     * @return array<int,array{handle:string,name?:string,type?:string}>
     */
    private function getAllFsFromProjectConfig(): array
    {
        $fs = Craft::$app->getProjectConfig()->get('fs') ?? [];
        $out = [];
        foreach ($fs as $handle => $cfg) {
            $out[] = [
                'handle' => $handle,
                'name'   => is_array($cfg) && isset($cfg['name']) ? $cfg['name'] : $handle,
                'type'   => is_array($cfg) && isset($cfg['type']) ? $cfg['type'] : null,
            ];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'] ?? $a['handle'], $b['name'] ?? $b['handle']));
        return $out;
    }
}
