<?php

namespace csabourin\craftS3SpacesMigration\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use csabourin\craftS3SpacesMigration\Plugin;
use yii\web\Response;

/**
 * Settings Controller
 *
 * Handles import/export of plugin settings
 */
class SettingsController extends Controller
{
    /**
     * Export all settings as JSON file
     *
     * @return Response
     */
    public function actionExport(): Response
    {
        $this->requirePermission('accessCp');

        $request = Craft::$app->getRequest();
        $isJsonRequest = $request->getAcceptsJson() || $request->getIsAjax();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        // Export settings to array
        $settingsArray = $settings->exportToArray();

        // Add metadata
        $export = [
            'exportDate' => date('Y-m-d H:i:s'),
            'pluginVersion' => $plugin->getVersion(),
            'settings' => $settingsArray,
        ];

        // Generate filename
        $filename = 'migration-settings-' . date('Y-m-d-His') . '.json';

        // Return JSON download
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_JSON;
        $response->data = $export;
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    /**
     * Import settings from JSON file
     *
     * @return Response
     */
    public function actionImport(): Response
    {
        $this->requirePermission('accessCp');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $isJsonRequest = $request->getAcceptsJson() || $request->getIsAjax();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        // Get uploaded file
        $file = UploadedFile::getInstanceByName('settingsFile');

        if (!$file) {
            if ($isJsonRequest) {
                return $this->asErrorJson('No file uploaded.');
            }

            Craft::$app->getSession()->setError('No file uploaded');
            return $this->redirect('settings/plugins/s3-spaces-migration');
        }

        // Validate file type
        if ($file->extension !== 'json') {
            if ($isJsonRequest) {
                return $this->asErrorJson('Invalid file type. Please upload a JSON file.');
            }

            Craft::$app->getSession()->setError('Invalid file type. Please upload a JSON file.');
            return $this->redirect('settings/plugins/s3-spaces-migration');
        }

        try {
            // Read and decode JSON
            $jsonContent = file_get_contents($file->tempName);
            $importData = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON file: ' . json_last_error_msg());
            }

            // Validate import data structure
            if (!isset($importData['settings']) || !is_array($importData['settings'])) {
                throw new \Exception('Invalid settings file format');
            }

            $importedSettings = $importData['settings'];

            // Import settings
            $settings->setAttributes($importedSettings, false);

            // Validate settings
            if (!$settings->validate()) {
                $errors = [];
                foreach ($settings->getErrors() as $attribute => $attributeErrors) {
                    $errors = array_merge($errors, $attributeErrors);
                }
                throw new \Exception('Settings validation failed: ' . implode(', ', $errors));
            }

            // Save settings using Craft's native method with exportToArray for consistency
            if (Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->exportToArray())) {
                if ($isJsonRequest) {
                    return $this->asJson([
                        'success' => true,
                        'message' => 'Settings imported successfully.',
                    ]);
                }

                Craft::$app->getSession()->setNotice('Settings imported successfully');
            } else {
                throw new \Exception('Failed to save imported settings');
            }
        } catch (\Exception $e) {
            if ($isJsonRequest) {
                return $this->asErrorJson('Import failed: ' . $e->getMessage());
            }

            Craft::$app->getSession()->setError('Import failed: ' . $e->getMessage());
            return $this->redirect('settings/plugins/s3-spaces-migration');
        }

        return $this->redirect('settings/plugins/s3-spaces-migration');
    }
}
