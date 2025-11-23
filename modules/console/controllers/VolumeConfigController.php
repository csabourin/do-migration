<?php
namespace csabourin\spaghettiMigrator\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Volume Configuration Controller
 *
 * Automates volume configuration tasks for the migration:
 * - Set transform filesystem for all volumes
 * - Add optimizedImagesField to volume field layouts
 *
 * @author Migration Specialist
 * @version 1.0
 */
class VolumeConfigController extends Controller
{
    public $defaultAction = 'status';

    /**
     * @var MigrationConfig Configuration helper
     */
    private $config;

    /**
     * @var bool Whether to run in dry-run mode
     */
    public $dryRun = false;

    /**
     * @var bool Skip all confirmation prompts (for automation)
     */
    public $yes = false;

    /**
     * @var string|null Volume handle for operations (optional)
     */
    public $volumeHandle = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();
    }

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'yes';

        if (in_array($actionID, ['set-transform-filesystem', 'add-optimised-field', 'create-quarantine-volume', 'configure-all'])) {
            $options[] = 'dryRun';
        }

        if ($actionID === 'add-optimised-field') {
            $options[] = 'volumeHandle';
        }

        return $options;
    }

    /**
     * Show current volume configuration status
     */
    public function actionStatus(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("VOLUME CONFIGURATION STATUS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        $volumesService = Craft::$app->getVolumes();
        $volumes = $volumesService->getAllVolumes();

        if (empty($volumes)) {
            $this->stdout("No volumes found.\n", Console::FG_YELLOW);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        $this->stdout("Found " . count($volumes) . " volume(s):\n\n", Console::FG_CYAN);

        foreach ($volumes as $volume) {
            $this->stdout("Volume: {$volume->name} (Handle: {$volume->handle})\n", Console::FG_GREEN);
            $this->stdout("  - Filesystem: {$volume->fs->name}\n");

            // Check transform filesystem
            $transformFs = $volume->getTransformFs();
            if ($transformFs) {
                $this->stdout("  - Transform Filesystem: {$transformFs->name}\n", Console::FG_GREEN);
                $transformSubpath = $volume->transformSubpath ?? '';
                if ($transformSubpath) {
                    $this->stdout("  - Transform Subpath: {$transformSubpath}\n", Console::FG_GREEN);
                } else {
                    $this->stdout("  - Transform Subpath: NOT SET\n", Console::FG_YELLOW);
                }
            } else {
                $this->stdout("  - Transform Filesystem: NOT SET\n", Console::FG_YELLOW);
                $this->stdout("  - Transform Subpath: NOT SET\n", Console::FG_YELLOW);
            }

            // Check field layout
            $fieldLayout = $volume->getFieldLayout();
            if ($fieldLayout) {
                $tabs = $fieldLayout->getTabs();
                $this->stdout("  - Field Layout Tabs: " . count($tabs) . "\n");

                foreach ($tabs as $tab) {
                    $elements = $tab->getElements();
                    $customFields = array_filter($elements, function($element) {
                        return $element instanceof \craft\fieldlayoutelements\CustomField;
                    });

                    $this->stdout("    • {$tab->name}: " . count($customFields) . " field(s)\n");

                    foreach ($customFields as $fieldElement) {
                        $field = $fieldElement->getField();
                        if ($field) {
                            $this->stdout("      - {$field->name} ({$field->handle})\n", Console::FG_GREY);
                        }
                    }
                }
            } else {
                $this->stdout("  - Field Layout: None\n", Console::FG_GREY);
            }

            $this->stdout("\n");
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Set transform filesystem for all volumes to "Image Transforms (DO)"
     *
     * This prevents polluting the main filesystems with transforms.
     */
    public function actionSetTransformFilesystem(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("SET TRANSFORM FILESYSTEM FOR ALL VOLUMES\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        // Find the Image Transforms (DO) filesystem
        $fsService = Craft::$app->getFs();
        $transformFsHandle = $this->config->getTransformFilesystemHandle();
        $transformFs = $fsService->getFilesystemByHandle($transformFsHandle);

        // If not found by handle, try finding by name
        if (!$transformFs) {
            $this->stdout("Transform filesystem not found by handle '{$transformFsHandle}', searching by name...\n", Console::FG_YELLOW);
            $allFilesystems = $fsService->getAllFilesystems();
            foreach ($allFilesystems as $fs) {
                if ($fs->name === 'Image Transforms (DO Spaces)') {
                    $transformFs = $fs;
                    $this->stdout("✓ Found transform filesystem by name: {$fs->name} (Handle: {$fs->handle})\n", Console::FG_GREEN);
                    break;
                }
            }
        }

        if (!$transformFs) {
            $this->stderr("✗ Image Transforms (DO) filesystem not found!\n", Console::FG_RED);
            $this->stderr("  Expected handle: '{$transformFsHandle}'\n", Console::FG_GREY);
            $this->stderr("  Expected name: 'Image Transforms (DO Spaces)'\n", Console::FG_GREY);
            $this->stderr("  Please create it first using: ./craft spaghetti-migrator/filesystem/create\n");
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("✓ Found transform filesystem: {$transformFs->name}\n\n", Console::FG_GREEN);

        $volumesService = Craft::$app->getVolumes();
        $volumes = $volumesService->getAllVolumes();

        if (empty($volumes)) {
            $this->stdout("No volumes found.\n", Console::FG_YELLOW);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($volumes as $volume) {
            $this->stdout("Processing: {$volume->name} (Handle: {$volume->handle})\n", Console::FG_CYAN);

            // Check if already set
            $currentTransformFs = $volume->getTransformFs();
            $currentFsName = $currentTransformFs ? $currentTransformFs->name : 'NOT SET';
            $currentFsId = $currentTransformFs ? $currentTransformFs->id : 'N/A';

            $this->stdout("  Current transform FS: {$currentFsName} (ID: {$currentFsId})\n", Console::FG_GREY);
            $this->stdout("  Target transform FS: {$transformFs->name} (ID: {$transformFs->id})\n", Console::FG_GREY);

            // Check current transform subpath
            $currentSubpath = $volume->transformSubpath ?? '';
            $targetSubpath = $volume->handle;
            $this->stdout("  Current transform subpath: " . ($currentSubpath ?: 'NOT SET') . "\n", Console::FG_GREY);
            $this->stdout("  Target transform subpath: {$targetSubpath}\n", Console::FG_GREY);

            // Check if both transform FS and subpath are already correct
            if ($currentTransformFs && $currentTransformFs->id === $transformFs->id && $currentSubpath === $targetSubpath) {
                $this->stdout("  ⊘ Already set to {$transformFs->name} with subpath '{$targetSubpath}'\n", Console::FG_GREY);
                $skipped++;
                continue;
            }

            if ($this->dryRun) {
                if (!$currentTransformFs || $currentTransformFs->id !== $transformFs->id) {
                    $this->stdout("  ➜ Would change transform FS from '{$currentFsName}' to '{$transformFs->name}'\n", Console::FG_YELLOW);
                }
                if ($currentSubpath !== $targetSubpath) {
                    $this->stdout("  ➜ Would set transform subpath to '{$targetSubpath}'\n", Console::FG_YELLOW);
                }
                $updated++;
            } else {
                // Set the transform filesystem using handle (string) instead of object
                $volume->transformFsHandle = $transformFs->handle;

                // Set the transform subpath to match the volume's handle
                $volume->transformSubpath = $targetSubpath;

                if ($volumesService->saveVolume($volume)) {
                    if (!$currentTransformFs || $currentTransformFs->id !== $transformFs->id) {
                        $this->stdout("  ✓ Changed transform FS from '{$currentFsName}' to '{$transformFs->name}'\n", Console::FG_GREEN);
                    }
                    if ($currentSubpath !== $targetSubpath) {
                        $this->stdout("  ✓ Set transform subpath to '{$targetSubpath}'\n", Console::FG_GREEN);
                    }
                    $updated++;
                } else {
                    $this->stderr("  ✗ Failed to update volume\n", Console::FG_RED);
                    if ($volume->hasErrors()) {
                        foreach ($volume->getErrors() as $attribute => $errors) {
                            $this->stderr("    - {$attribute}: " . implode(', ', $errors) . "\n", Console::FG_RED);
                        }
                    }
                }
            }
        }

        $this->stdout("\n" . str_repeat("-", 80) . "\n", Console::FG_CYAN);
        $this->stdout("Summary:\n", Console::FG_CYAN);
        $this->stdout("  - Volumes updated: {$updated}\n", $updated > 0 ? Console::FG_GREEN : Console::FG_GREY);
        $this->stdout("  - Volumes skipped: {$skipped}\n", Console::FG_GREY);

        if ($this->dryRun) {
            $this->stdout("\nTo apply these changes, run without --dry-run:\n", Console::FG_YELLOW);
            $this->stdout("  ./craft spaghetti-migrator/volume-config/set-transform-filesystem\n\n");
        } else {
            $this->stdout("\n✓ Transform filesystem configuration completed!\n\n", Console::FG_GREEN);
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Add optimizedImagesField to the Content tab of specified volume
     *
     * This must be done after migration but BEFORE generating transforms
     * so that transforms are correctly generated.
     *
     * @param string|null $volumeHandle The volume handle (default: images)
     * @param bool $dryRun If true, only show what would be changed without making changes
     */
    public function actionAddOptimisedField(): int
    {
        $volumeHandle = $this->volumeHandle ?? 'images';

        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("ADD OPTIMISED IMAGES FIELD TO VOLUME\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        // Get the volume
        $volumesService = Craft::$app->getVolumes();
        $volume = $volumesService->getVolumeByHandle($volumeHandle);

        if (!$volume) {
            $this->stderr("✗ Volume '{$volumeHandle}' not found!\n", Console::FG_RED);
            $this->stderr("  Available volumes:\n");
            foreach ($volumesService->getAllVolumes() as $v) {
                $this->stderr("    - {$v->handle}\n");
            }
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("✓ Found volume: {$volume->name}\n\n", Console::FG_GREEN);

        // Get the optimizedImagesField
        $fieldsService = Craft::$app->getFields();
        $fieldHandle = $this->config->getOptimizedImagesFieldHandle();
        $field = $fieldsService->getFieldByHandle($fieldHandle);

        if (!$field) {
            $this->stderr("✗ Field '{$fieldHandle}' not found!\n", Console::FG_RED);
            $this->stderr("  Please ensure the field exists in Craft before running this command.\n");
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("✓ Found field: {$field->name}\n\n", Console::FG_GREEN);

        // Get or create field layout
        $fieldLayout = $volume->getFieldLayout();

        if (!$fieldLayout) {
            $this->stdout("Creating new field layout...\n", Console::FG_YELLOW);
            $fieldLayout = new FieldLayout(['type' => \craft\elements\Asset::class]);
        }

        // Find or create Content tab
        $contentTab = null;
        foreach ($fieldLayout->getTabs() as $tab) {
            if ($tab->name === 'Content') {
                $contentTab = $tab;
                break;
            }
        }

        if (!$contentTab) {
            $this->stdout("Content tab not found, will create it...\n", Console::FG_YELLOW);
            if (!$this->dryRun) {
                $contentTab = new FieldLayoutTab([
                    'name' => 'Content',
                    'sortOrder' => 1,
                ]);
            }
        } else {
            $this->stdout("✓ Found Content tab\n", Console::FG_GREEN);

            // Check if field already exists in the tab
            $elements = $contentTab->getElements();
            foreach ($elements as $element) {
                if ($element instanceof \craft\fieldlayoutelements\CustomField) {
                    $existingField = $element->getField();
                    if ($existingField && $existingField->id === $field->id) {
                        $this->stdout("⊘ Field '{$fieldHandle}' already exists in Content tab\n", Console::FG_GREY);
                        $this->stdout("__CLI_EXIT_CODE_0__\n");
                        return ExitCode::OK;
                    }
                }
            }
        }

        if ($this->dryRun) {
            $this->stdout("\n➜ Would add '{$fieldHandle}' to Content tab of '{$volume->name}'\n", Console::FG_YELLOW);
            $this->stdout("\nTo apply these changes, run without --dry-run:\n", Console::FG_YELLOW);
            $this->stdout("  ./craft spaghetti-migrator/volume-config/add-optimised-field {$volumeHandle}\n\n");
        } else {
            // Get current field layout elements
            $fieldLayoutElements = [];

            foreach ($fieldLayout->getTabs() as $tab) {
                if ($tab->name === 'Content') {
                    // Add existing fields
                    $elements = $tab->getElements();
                    foreach ($elements as $element) {
                        if ($element instanceof \craft\fieldlayoutelements\CustomField) {
                            $existingField = $element->getField();
                            if ($existingField) {
                                $fieldLayoutElements[] = [
                                    'type' => \craft\fieldlayoutelements\CustomField::class,
                                    'fieldUid' => $existingField->uid,
                                    'required' => false,
                                ];
                            }
                        }
                    }

                    // Add the optimizedImagesField
                    $fieldLayoutElements[] = [
                        'type' => \craft\fieldlayoutelements\CustomField::class,
                        'fieldUid' => $field->uid,
                        'required' => false,
                    ];
                }
            }

            // If no Content tab existed, create it with the field
            if (!$contentTab) {
                $fieldLayoutElements = [
                    [
                        'type' => \craft\fieldlayoutelements\CustomField::class,
                        'fieldUid' => $field->uid,
                        'required' => false,
                    ]
                ];
            }

            // Update field layout
            $fieldLayout->setTabs([
                [
                    'name' => 'Content',
                    'elements' => $fieldLayoutElements,
                ]
            ]);

            // Save the field layout
            $fieldsService->saveLayout($fieldLayout);

            // Update the volume with the new field layout
            $volume->fieldLayoutId = $fieldLayout->id;

            if ($volumesService->saveVolume($volume)) {
                $this->stdout("✓ Successfully added '{$fieldHandle}' to Content tab!\n", Console::FG_GREEN);
                $this->stdout("\n✓ Configuration completed!\n\n", Console::FG_GREEN);
            } else {
                $this->stderr("✗ Failed to save volume\n", Console::FG_RED);
                $this->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }

    /**
     * Create quarantine volume if it doesn't exist
     *
     * @param bool $dryRun If true, only show what would be created without making changes
     */
    public function actionCreateQuarantineVolume(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("CREATE QUARANTINE VOLUME\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        $volumesService = Craft::$app->getVolumes();
        $fsService = Craft::$app->getFs();

        // Check if quarantine volume already exists
        $quarantineVolumeHandle = $this->config->getQuarantineVolumeHandle();
        $existingVolume = $volumesService->getVolumeByHandle($quarantineVolumeHandle);

        if ($existingVolume) {
            $this->stdout("✓ Quarantine volume already exists (ID: {$existingVolume->id})\n", Console::FG_GREEN);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        // Check if quarantine filesystem exists
        $quarantineFsHandle = $this->config->getQuarantineFilesystemHandle();
        $quarantineFs = $fsService->getFilesystemByHandle($quarantineFsHandle);

        if (!$quarantineFs) {
            $this->stderr("✗ Quarantine filesystem not found. Please run:\n", Console::FG_RED);
            $this->stderr("  ./craft spaghetti-migrator/filesystem/create\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Get transform filesystem
        $transformFsHandle = $this->config->getTransformFilesystemHandle();
        $transformFs = $fsService->getFilesystemByHandle($transformFsHandle);

        // If not found by handle, try finding by name
        if (!$transformFs) {
            $allFilesystems = $fsService->getAllFilesystems();
            foreach ($allFilesystems as $fs) {
                if ($fs->name === 'Image Transforms (DO Spaces)') {
                    $transformFs = $fs;
                    break;
                }
            }
        }

        if (!$transformFs) {
            $this->stderr("✗ Transform filesystem not found. Please run:\n", Console::FG_RED);
            $this->stderr("  ./craft spaghetti-migrator/filesystem/create\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout("Would create quarantine volume with:\n", Console::FG_YELLOW);
            $this->stdout("  - Handle: {$quarantineVolumeHandle}\n", Console::FG_GREY);
            $this->stdout("  - Name: Quarantined Assets\n", Console::FG_GREY);
            $this->stdout("  - Filesystem: {$quarantineFs->name}\n", Console::FG_GREY);
            $this->stdout("  - Transform Filesystem: {$transformFs->name}\n", Console::FG_GREY);
            $this->stdout("  - Transform Subpath: {$quarantineVolumeHandle}\n\n", Console::FG_GREY);
            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;
        }

        try {
            // Create new volume
            $volume = new \craft\models\Volume();
            $volume->name = 'Quarantined Assets';
            $volume->handle = $quarantineVolumeHandle;
            $volume->fsHandle = $quarantineFs->handle;  // Use handle, not ID
            $volume->sortOrder = 99; // Put it at the end

            // Set the transform filesystem using handle (string) instead of object
            $volume->transformFsHandle = $transformFs->handle;

            // Set the transform subpath to match the volume's handle
            $volume->transformSubpath = $quarantineVolumeHandle;

            // Save the volume
            if (!$volumesService->saveVolume($volume)) {
                $this->stderr("✗ Failed to save quarantine volume\n", Console::FG_RED);
                if ($volume->hasErrors()) {
                    foreach ($volume->getErrors() as $attribute => $errors) {
                        $this->stderr("  - {$attribute}: " . implode(', ', $errors) . "\n", Console::FG_RED);
                    }
                }
                $this->stderr("__CLI_EXIT_CODE_1__\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("✓ Successfully created quarantine volume (ID: {$volume->id})\n", Console::FG_GREEN);
            $this->stdout("  - Name: {$volume->name}\n", Console::FG_GREY);
            $this->stdout("  - Handle: {$volume->handle}\n", Console::FG_GREY);
            $this->stdout("  - Filesystem: {$quarantineFs->name}\n", Console::FG_GREY);
            $this->stdout("  - Transform Filesystem: {$transformFs->name}\n", Console::FG_GREY);
            $this->stdout("  - Transform Subpath: {$volume->transformSubpath}\n\n", Console::FG_GREY);

            $this->stdout("__CLI_EXIT_CODE_0__\n");
            return ExitCode::OK;

        } catch (\Exception $e) {
            $this->stderr("✗ Error creating quarantine volume: {$e->getMessage()}\n\n", Console::FG_RED);
            $this->stderr("__CLI_EXIT_CODE_1__\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Configure all volume settings required for migration
     *
     * This is a convenience command that runs:
     * 1. Create quarantine volume if it doesn't exist
     * 2. Set transform filesystem for all volumes
     * 3. Add optimizedImagesField to Images (DO) volume
     *
     * @param bool $dryRun If true, only show what would be changed without making changes
     */
    public function actionConfigureAll(): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("CONFIGURE ALL VOLUME SETTINGS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        // Step 1: Create quarantine volume if it doesn't exist
        $this->stdout("Step 1: Creating quarantine volume if needed...\n\n", Console::FG_YELLOW);
        $result0 = $this->actionCreateQuarantineVolume();

        if ($result0 !== ExitCode::OK) {
            $this->stderr("✗ Failed to create quarantine volume\n", Console::FG_RED);
            return $result0;
        }

        // Step 2: Set transform filesystem
        $this->stdout("\nStep 2: Setting transform filesystem for all volumes...\n\n", Console::FG_YELLOW);
        $result1 = $this->actionSetTransformFilesystem();

        if ($result1 !== ExitCode::OK) {
            $this->stderr("✗ Failed to set transform filesystem\n", Console::FG_RED);
            return $result1;
        }

        // Step 3: Add optimizedImagesField (only if not dry run, as this is post-migration)
        $this->stdout("\nStep 3: Adding optimizedImagesField to Images (DO) volume...\n\n", Console::FG_YELLOW);
        $this->stdout("Note: This should be done AFTER migration but BEFORE generating transforms\n", Console::FG_CYAN);

        if (!$this->dryRun) {
            $shouldAddField = $this->yes;

            if (!$this->yes) {
                $this->stdout("Do you want to add optimizedImagesField now? [y/n]: ");
                $input = trim(fgets(STDIN));
                $shouldAddField = (strtolower($input) === 'y' || strtolower($input) === 'yes');
            } else {
                $this->stdout("Auto-confirmed (--yes flag)\n", Console::FG_YELLOW);
            }

            if ($shouldAddField) {
                // Set volumeHandle before calling the action
                $this->volumeHandle = 'images';
                $result2 = $this->actionAddOptimisedField();

                if ($result2 !== ExitCode::OK) {
                    $this->stderr("✗ Failed to add optimizedImagesField\n", Console::FG_RED);
                    return $result2;
                }
            } else {
                $this->stdout("Skipped adding optimizedImagesField. Run manually when ready:\n", Console::FG_YELLOW);
                $this->stdout("  ./craft spaghetti-migrator/volume-config/add-optimised-field images\n\n");
            }
        } else {
            $this->stdout("Would prompt to add optimizedImagesField (if not dry run)\n\n", Console::FG_YELLOW);
        }

        $this->stdout("\n✓ All volume configuration tasks completed!\n\n", Console::FG_GREEN);

        $this->stdout("__CLI_EXIT_CODE_0__\n");
        return ExitCode::OK;
    }
}
