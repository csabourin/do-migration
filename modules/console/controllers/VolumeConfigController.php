<?php
namespace modules\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use modules\helpers\MigrationConfig;
use yii\console\ExitCode;

/**
 * Volume Configuration Controller
 *
 * Automates volume configuration tasks for the migration:
 * - Set transform filesystem for all volumes
 * - Add optimisedImagesField to volume field layouts
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
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->config = MigrationConfig::getInstance();
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
            } else {
                $this->stdout("  - Transform Filesystem: NOT SET\n", Console::FG_YELLOW);
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

        return ExitCode::OK;
    }

    /**
     * Set transform filesystem for all volumes to "Image Transforms (DO)"
     *
     * This prevents polluting the main filesystems with transforms.
     *
     * @param bool $dryRun If true, only show what would be changed without making changes
     */
    public function actionSetTransformFilesystem(bool $dryRun = false): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("SET TRANSFORM FILESYSTEM FOR ALL VOLUMES\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        // Find the Image Transforms (DO) filesystem
        $fsService = Craft::$app->getFs();
        $transformFs = $fsService->getFilesystemByHandle('imageTransforms_do');

        if (!$transformFs) {
            $this->stderr("✗ Image Transforms (DO) filesystem not found!\n", Console::FG_RED);
            $this->stderr("  Please create it first using: ./craft ncc-module/filesystem/create\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("✓ Found transform filesystem: {$transformFs->name}\n\n", Console::FG_GREEN);

        $volumesService = Craft::$app->getVolumes();
        $volumes = $volumesService->getAllVolumes();

        if (empty($volumes)) {
            $this->stdout("No volumes found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($volumes as $volume) {
            $this->stdout("Processing: {$volume->name} (Handle: {$volume->handle})\n", Console::FG_CYAN);

            // Check if already set
            $currentTransformFs = $volume->getTransformFs();
            if ($currentTransformFs && $currentTransformFs->id === $transformFs->id) {
                $this->stdout("  ⊘ Already set to {$transformFs->name}\n", Console::FG_GREY);
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $currentFs = $currentTransformFs ? $currentTransformFs->name : 'NOT SET';
                $this->stdout("  ➜ Would change from '{$currentFs}' to '{$transformFs->name}'\n", Console::FG_YELLOW);
                $updated++;
            } else {
                $oldFs = $currentTransformFs ? $currentTransformFs->name : 'NOT SET';

                // Set the transform filesystem
                $volume->setTransformFs($transformFs);

                if ($volumesService->saveVolume($volume)) {
                    $this->stdout("  ✓ Changed from '{$oldFs}' to '{$transformFs->name}'\n", Console::FG_GREEN);
                    $updated++;
                } else {
                    $this->stderr("  ✗ Failed to update volume\n", Console::FG_RED);
                }
            }
        }

        $this->stdout("\n" . str_repeat("-", 80) . "\n", Console::FG_CYAN);
        $this->stdout("Summary:\n", Console::FG_CYAN);
        $this->stdout("  - Volumes updated: {$updated}\n", $updated > 0 ? Console::FG_GREEN : Console::FG_GREY);
        $this->stdout("  - Volumes skipped: {$skipped}\n", Console::FG_GREY);

        if ($dryRun) {
            $this->stdout("\nTo apply these changes, run without --dry-run:\n", Console::FG_YELLOW);
            $this->stdout("  ./craft ncc-module/volume-config/set-transform-filesystem\n\n");
        } else {
            $this->stdout("\n✓ Transform filesystem configuration completed!\n\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * Add optimisedImagesField to the Content tab of specified volume
     *
     * This must be done after migration but BEFORE generating transforms
     * so that transforms are correctly generated.
     *
     * @param string|null $volumeHandle The volume handle (default: images_do)
     * @param bool $dryRun If true, only show what would be changed without making changes
     */
    public function actionAddOptimisedField(?string $volumeHandle = null, bool $dryRun = false): int
    {
        $volumeHandle = $volumeHandle ?? 'images_do';

        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("ADD OPTIMISED IMAGES FIELD TO VOLUME\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($dryRun) {
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
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("✓ Found volume: {$volume->name}\n\n", Console::FG_GREEN);

        // Get the optimisedImagesField
        $fieldsService = Craft::$app->getFields();
        $field = $fieldsService->getFieldByHandle('optimisedImagesField');

        if (!$field) {
            $this->stderr("✗ Field 'optimisedImagesField' not found!\n", Console::FG_RED);
            $this->stderr("  Please ensure the field exists in Craft before running this command.\n");
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
            if (!$dryRun) {
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
                        $this->stdout("⊘ Field 'optimisedImagesField' already exists in Content tab\n", Console::FG_GREY);
                        return ExitCode::OK;
                    }
                }
            }
        }

        if ($dryRun) {
            $this->stdout("\n➜ Would add 'optimisedImagesField' to Content tab of '{$volume->name}'\n", Console::FG_YELLOW);
            $this->stdout("\nTo apply these changes, run without --dry-run:\n", Console::FG_YELLOW);
            $this->stdout("  ./craft ncc-module/volume-config/add-optimised-field {$volumeHandle}\n\n");
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

                    // Add the optimisedImagesField
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
                $this->stdout("✓ Successfully added 'optimisedImagesField' to Content tab!\n", Console::FG_GREEN);
                $this->stdout("\n✓ Configuration completed!\n\n", Console::FG_GREEN);
            } else {
                $this->stderr("✗ Failed to save volume\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        return ExitCode::OK;
    }

    /**
     * Create quarantine volume if it doesn't exist
     *
     * @param bool $dryRun If true, only show what would be created without making changes
     */
    public function actionCreateQuarantineVolume(bool $dryRun = false): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("CREATE QUARANTINE VOLUME\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        $volumesService = Craft::$app->getVolumes();
        $fsService = Craft::$app->getFs();

        // Check if quarantine volume already exists
        $existingVolume = $volumesService->getVolumeByHandle('quarantine');

        if ($existingVolume) {
            $this->stdout("✓ Quarantine volume already exists (ID: {$existingVolume->id})\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        // Check if quarantine filesystem exists
        $quarantineFs = $fsService->getFilesystemByHandle('quarantine');

        if (!$quarantineFs) {
            $this->stderr("✗ Quarantine filesystem not found. Please run:\n", Console::FG_RED);
            $this->stderr("  ./craft ncc-module/filesystem/create\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Get transform filesystem
        $transformFs = $fsService->getFilesystemByHandle('imageTransforms_do');

        if (!$transformFs) {
            $this->stderr("✗ Transform filesystem 'imageTransforms_do' not found. Please run:\n", Console::FG_RED);
            $this->stderr("  ./craft ncc-module/filesystem/create\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($dryRun) {
            $this->stdout("Would create quarantine volume with:\n", Console::FG_YELLOW);
            $this->stdout("  - Handle: quarantine\n", Console::FG_GREY);
            $this->stdout("  - Name: Quarantined Assets\n", Console::FG_GREY);
            $this->stdout("  - Filesystem: {$quarantineFs->name}\n", Console::FG_GREY);
            $this->stdout("  - Transform Filesystem: {$transformFs->name}\n\n", Console::FG_GREY);
            return ExitCode::OK;
        }

        try {
            // Create new volume
            $volume = new \craft\models\Volume();
            $volume->name = 'Quarantined Assets';
            $volume->handle = 'quarantine';
            $volume->fsId = $quarantineFs->id;
            $volume->transformFsId = $transformFs->id;
            $volume->sortOrder = 99; // Put it at the end

            // Save the volume
            if (!$volumesService->saveVolume($volume)) {
                $this->stderr("✗ Failed to save quarantine volume\n", Console::FG_RED);
                if ($volume->hasErrors()) {
                    foreach ($volume->getErrors() as $attribute => $errors) {
                        $this->stderr("  - {$attribute}: " . implode(', ', $errors) . "\n", Console::FG_RED);
                    }
                }
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("✓ Successfully created quarantine volume (ID: {$volume->id})\n", Console::FG_GREEN);
            $this->stdout("  - Name: {$volume->name}\n", Console::FG_GREY);
            $this->stdout("  - Handle: {$volume->handle}\n", Console::FG_GREY);
            $this->stdout("  - Filesystem: {$quarantineFs->name}\n", Console::FG_GREY);
            $this->stdout("  - Transform Filesystem: {$transformFs->name}\n\n", Console::FG_GREY);

            return ExitCode::OK;

        } catch (\Exception $e) {
            $this->stderr("✗ Error creating quarantine volume: {$e->getMessage()}\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Configure all volume settings required for migration
     *
     * This is a convenience command that runs:
     * 1. Create quarantine volume if it doesn't exist
     * 2. Set transform filesystem for all volumes
     * 3. Add optimisedImagesField to Images (DO) volume
     *
     * @param bool $dryRun If true, only show what would be changed without making changes
     */
    public function actionConfigureAll(bool $dryRun = false): int
    {
        $this->stdout("\n" . str_repeat("=", 80) . "\n", Console::FG_CYAN);
        $this->stdout("CONFIGURE ALL VOLUME SETTINGS\n", Console::FG_CYAN);
        $this->stdout(str_repeat("=", 80) . "\n\n", Console::FG_CYAN);

        if ($dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n\n", Console::FG_YELLOW);
        }

        // Step 1: Create quarantine volume if it doesn't exist
        $this->stdout("Step 1: Creating quarantine volume if needed...\n\n", Console::FG_YELLOW);
        $result0 = $this->actionCreateQuarantineVolume($dryRun);

        if ($result0 !== ExitCode::OK) {
            $this->stderr("✗ Failed to create quarantine volume\n", Console::FG_RED);
            return $result0;
        }

        // Step 2: Set transform filesystem
        $this->stdout("\nStep 2: Setting transform filesystem for all volumes...\n\n", Console::FG_YELLOW);
        $result1 = $this->actionSetTransformFilesystem($dryRun);

        if ($result1 !== ExitCode::OK) {
            $this->stderr("✗ Failed to set transform filesystem\n", Console::FG_RED);
            return $result1;
        }

        // Step 3: Add optimisedImagesField (only if not dry run, as this is post-migration)
        $this->stdout("\nStep 3: Adding optimisedImagesField to Images (DO) volume...\n\n", Console::FG_YELLOW);
        $this->stdout("Note: This should be done AFTER migration but BEFORE generating transforms\n", Console::FG_CYAN);

        if (!$dryRun) {
            $this->stdout("Do you want to add optimisedImagesField now? [y/n]: ");
            $input = trim(fgets(STDIN));

            if (strtolower($input) === 'y' || strtolower($input) === 'yes') {
                $result2 = $this->actionAddOptimisedField('images_do', $dryRun);

                if ($result2 !== ExitCode::OK) {
                    $this->stderr("✗ Failed to add optimisedImagesField\n", Console::FG_RED);
                    return $result2;
                }
            } else {
                $this->stdout("Skipped adding optimisedImagesField. Run manually when ready:\n", Console::FG_YELLOW);
                $this->stdout("  ./craft ncc-module/volume-config/add-optimised-field images_do\n\n");
            }
        } else {
            $this->stdout("Would prompt to add optimisedImagesField (if not dry run)\n\n", Console::FG_YELLOW);
        }

        $this->stdout("\n✓ All volume configuration tasks completed!\n\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
