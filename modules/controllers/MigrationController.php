<?php

namespace csabourin\spaghettiMigrator\controllers;

use Craft;
use craft\web\Controller;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\CommandExecutionService;
use csabourin\spaghettiMigrator\services\MigrationAccessValidator;
use csabourin\spaghettiMigrator\services\MigrationProgressService;
use csabourin\spaghettiMigrator\services\MigrationStateManager;
use csabourin\spaghettiMigrator\services\ModuleDefinitionProvider;
use csabourin\spaghettiMigrator\services\ProcessManager;
use yii\base\Action;
use yii\web\Response;

/**
 * Migration Dashboard Controller
 *
 * Provides a Control Panel interface for orchestrating the AWS to DigitalOcean migration
 * with step-by-step guidance through all 14 migration modules.
 */
class MigrationController extends Controller
{
    /**
     * @var bool Allow anonymous access to dashboard (set to false in production)
     */
    protected array|bool|int $allowAnonymous = false;

    private ?MigrationAccessValidator $accessValidator = null;

    private ?MigrationProgressService $progressService = null;

    private ?CommandExecutionService $commandService = null;

    private ?ModuleDefinitionProvider $moduleProvider = null;

    private ?ProcessManager $processManager = null;

    private ?MigrationStateManager $stateManager = null;

    private ?MigrationConfig $config = null;

    /**
     * Ensure only administrators with mutable config can hit migration endpoints.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->getAccessValidator()->requireAdminUser();

        if ($this->requiresAdminChanges($action)) {
            $this->getAccessValidator()->requireAdminChangesEnabled();
        }

        return true;
    }

    /**
     * Render the main migration dashboard
     */
    public function actionIndex(): Response
    {
        $stateManager = $this->getStateManager();

        return $this->renderTemplate('spaghetti-migrator/dashboard', [
            'state' => $stateManager->getMigrationState(),
            'config' => $stateManager->getConfigurationStatus(),
            'modules' => $this->getModuleProvider()->getModuleDefinitions(),
        ]);
    }

    /**
     * API: Get migration status
     */
    public function actionGetStatus(): Response
    {
        $this->requireAcceptsJson();

        $stateManager = $this->getStateManager();

        return $this->asJson([
            'success' => true,
            'state' => $stateManager->getMigrationState(),
            'config' => $stateManager->getConfigurationStatus(),
        ]);
    }

    /**
     * API: Persist dashboard status updates
     */
    public function actionUpdateStatus(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $modulesParam = $request->getBodyParam('modules', []);

        if (is_string($modulesParam)) {
            $decoded = json_decode($modulesParam, true);
            if (is_array($decoded)) {
                $modulesParam = $decoded;
            }
        }

        if (!is_array($modulesParam)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid modules payload',
            ]);
        }

        $modules = [];
        foreach ($modulesParam as $moduleId) {
            if (is_string($moduleId) && $moduleId !== '') {
                $modules[$moduleId] = true;
            }
        }

        try {
            $this->getProgressService()->persistCompletedModules(array_keys($modules));

            return $this->asJson([
                'success' => true,
                'state' => $this->getStateManager()->getMigrationState(),
            ]);
        } catch (\Throwable $e) {
            Craft::error('Failed to persist migration dashboard status: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => 'Unable to persist migration status',
            ]);
        }
    }

    /**
     * API: Update module status (running, completed, failed)
     */
    public function actionUpdateModuleStatus(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $moduleId = $request->getBodyParam('moduleId');
        $status = $request->getBodyParam('status');
        $error = $request->getBodyParam('error', null);

        if (!$moduleId || !is_string($moduleId)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Module ID is required',
            ]);
        }

        if (!$status || !in_array($status, ['running', 'completed', 'failed'])) {
            return $this->asJson([
                'success' => false,
                'error' => 'Valid status is required (running, completed, failed)',
            ]);
        }

        try {
            $result = $this->getProgressService()->updateModuleStatus($moduleId, $status, $error);

            if (!$result) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Failed to update module status',
                ]);
            }

            return $this->asJson([
                'success' => true,
                'state' => $this->getStateManager()->getMigrationState(),
            ]);
        } catch (\Throwable $e) {
            Craft::error('Failed to update module status: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => 'Unable to update module status',
            ]);
        }
    }

    /**
     * API: Run a specific migration command
     */
    public function actionRunCommand(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $command = $request->getBodyParam('command');
        $argsParam = $request->getBodyParam('args', '[]');
        $args = is_string($argsParam) ? json_decode($argsParam, true) : $argsParam;
        $args = $args ?: []; // Ensure it's an array even if json_decode fails
        $dryRun = $request->getBodyParam('dryRun', false);
        $stream = $request->getBodyParam('stream', false);

        // Only require JSON acceptance for non-streaming requests
        if (!$stream) {
            $this->requireAcceptsJson();
        }

        if (!$command) {
            return $this->asJson([
                'success' => false,
                'error' => 'Command is required',
            ]);
        }

        // Validate command
        $commandService = $this->getCommandService();
        if (!$commandService->isCommandAllowed($command)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid command',
            ]);
        }

        try {
            // Build the full command
            $fullCommand = "spaghetti-migrator/{$command}";

            // Add dry run flag if requested
            if ($dryRun) {
                $args['dryRun'] = '1';
            }

            // Use streaming for long-running commands
            if ($stream) {
                return $commandService->streamConsoleCommand($fullCommand, $args);
            }

            // Execute the command
            $result = $commandService->executeConsoleCommand($fullCommand, $args);

            // Check exit code to determine success
            $success = ($result['exitCode'] === 0);

            return $this->asJson([
                'success' => $success,
                'output' => $result['output'],
                'exitCode' => $result['exitCode'],
                'error' => $success ? null : 'Command failed with exit code ' . $result['exitCode'],
            ]);

        } catch (\Exception $e) {
            Craft::error('Migration command failed: ' . $e->getMessage(), __METHOD__);
            Craft::error('Stack trace: ' . $e->getTraceAsString(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => CRAFT_ENVIRONMENT === 'dev' ? $e->getTraceAsString() : null,
            ]);
        }
    }

    /**
     * API: Run a command via the queue system (survives page refresh)
     */
    public function actionRunCommandQueue(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $command = $request->getBodyParam('command');
        $argsParam = $request->getBodyParam('args', '[]');
        $args = is_string($argsParam) ? json_decode($argsParam, true) : $argsParam;
        $args = $args ?: [];
        $dryRun = $request->getBodyParam('dryRun', false);

        if (!$command) {
            return $this->asJson([
                'success' => false,
                'error' => 'Command is required',
            ]);
        }

        // Validate command
        if (!$this->getCommandService()->isCommandAllowed($command)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid command',
            ]);
        }

        try {
            // Add dry run flag if requested
            if ($dryRun) {
                $args['dryRun'] = true;
            }

            // Only add yes flag for commands that support it
            $fullCommand = "spaghetti-migrator/{$command}";
            if (\csabourin\spaghettiMigrator\services\CommandExecutionService::commandSupportsYes($fullCommand)) {
                $args['yes'] = true;
            } else {
                unset($args['yes']);
            }

            // Generate migration ID
            $migrationId = 'queue-' . time() . '-' . uniqid();

            // Determine which job class to use
            $jobClass = null;
            $jobParams = [
                'command' => $command,
                'args' => $args,
                'migrationId' => $migrationId,
            ];

            // Use specialized job for image migration
            if ($command === 'image-migration/migrate') {
                $jobClass = \csabourin\spaghettiMigrator\jobs\MigrationJob::class;
                $jobParams = [
                    'migrationId' => $migrationId,
                    'dryRun' => $dryRun,
                    'skipBackup' => $args['skipBackup'] ?? false,
                    'skipInlineDetection' => $args['skipInlineDetection'] ?? false,
                    'resume' => $args['resume'] ?? false,
                    'checkpointId' => $args['checkpointId'] ?? null,
                ];
            } else {
                // Use generic command job for other commands
                $jobClass = \csabourin\spaghettiMigrator\jobs\ConsoleCommandJob::class;
            }

            // Push to queue
            $jobId = Craft::$app->getQueue()->push(new $jobClass($jobParams));

            Craft::info("Queued command {$command} with job ID {$jobId} and migration ID {$migrationId}", __METHOD__);

            return $this->asJson([
                'success' => true,
                'jobId' => $jobId,
                'migrationId' => $migrationId,
                'message' => 'Command queued successfully. It will continue running even if you refresh the page.',
            ]);

        } catch (\Exception $e) {
            Craft::error('Failed to queue command: ' . $e->getMessage(), __METHOD__);
            Craft::error('Stack trace: ' . $e->getTraceAsString(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => CRAFT_ENVIRONMENT === 'dev' ? $e->getTraceAsString() : null,
            ]);
        }
    }

    /**
     * API: Get queue job status
     */
    public function actionGetQueueStatus(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $jobId = $request->getQueryParam('jobId');

        if (!$jobId) {
            return $this->asJson([
                'success' => false,
                'error' => 'Job ID is required',
            ]);
        }

        try {
            $queue = Craft::$app->getQueue();
            $db = Craft::$app->getDb();

            // Query the queue table
            $job = $db->createCommand('
                SELECT id, description, progress, timePushed, ttr, attempt, fail, dateFailed, error
                FROM {{%queue}}
                WHERE id = :jobId
                LIMIT 1
            ', [':jobId' => $jobId])->queryOne();

            if (!$job) {
                // Job not found - either completed or never existed
                return $this->asJson([
                    'success' => true,
                    'status' => 'completed',
                    'message' => 'Job not found in queue (likely completed)',
                ]);
            }

            // Determine status
            $status = 'pending';
            $progressPercent = (float)($job['progress'] ?? 0);

            if ($job['fail'] == 1) {
                $status = 'failed';
            } elseif ($progressPercent > 0) {
                $status = 'running';
            }

            return $this->asJson([
                'success' => true,
                'status' => $status,
                'job' => [
                    'id' => $job['id'],
                    'description' => $job['description'],
                    'progress' => $progressPercent,
                    'progressLabel' => round($progressPercent * 100, 1) . '%',
                    'timePushed' => $job['timePushed'],
                    'attempt' => $job['attempt'],
                    'error' => $job['error'] ?? null,
                ],
            ]);

        } catch (\Exception $e) {
            Craft::error('Failed to get queue status: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: Get all migration-related queue jobs
     */
    public function actionGetQueueJobs(): Response
    {
        $this->requireAcceptsJson();

        try {
            $db = Craft::$app->getDb();

            // Get all jobs (limited to recent ones)
            $jobs = $db->createCommand('
                SELECT id, description, progress, timePushed, ttr, attempt, fail, dateFailed, error
                FROM {{%queue}}
                ORDER BY timePushed DESC
                LIMIT 50
            ')->queryAll();

            $result = [];
            foreach ($jobs as $job) {
                $status = 'pending';
                $progressPercent = (float)($job['progress'] ?? 0);

                if ($job['fail'] == 1) {
                    $status = 'failed';
                } elseif ($progressPercent > 0) {
                    $status = 'running';
                }

                $result[] = [
                    'id' => $job['id'],
                    'description' => $job['description'],
                    'status' => $status,
                    'progress' => $progressPercent,
                    'progressLabel' => round($progressPercent * 100, 1) . '%',
                    'timePushed' => $job['timePushed'],
                    'attempt' => $job['attempt'],
                    'error' => $job['error'] ?? null,
                ];
            }

            return $this->asJson([
                'success' => true,
                'jobs' => $result,
            ]);

        } catch (\Exception $e) {
            Craft::error('Failed to get queue jobs: ' . $e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: Cancel a running command
     */
    public function actionCancelCommand(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        try {
            $request = Craft::$app->getRequest();
            $command = $request->getBodyParam('command');

            if (!$command) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Command parameter is required',
                ]);
            }

            $processManager = $this->getProcessManager();
            $sessionId = $processManager->getSessionIdentifier();
            $result = $processManager->cancelCommand($sessionId, $command);

            return $this->asJson($result);

        } catch (\Exception $e) {
            Craft::error("Error cancelling command: {$e->getMessage()}", __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => CRAFT_ENVIRONMENT === 'dev' ? $e->getTraceAsString() : null,
            ]);
        }
    }

    /**
     * API: Get checkpoint information
     */
    public function actionGetCheckpoint(): Response
    {
        $this->requireAcceptsJson();

        $checkpoints = $this->getStateManager()->getCheckpoints();

        return $this->asJson([
            'success' => true,
            'checkpoints' => $checkpoints,
        ]);
    }

    /**
     * API: Get migration logs
     */
    public function actionGetLogs(): Response
    {
        $this->requireAcceptsJson();

        $config = $this->getConfig();
        $request = Craft::$app->getRequest();
        $lines = $request->getQueryParam('lines', $config->getDashboardLogLinesDefault());

        $logDir = Craft::getAlias('@storage/logs');
        $logFile = $logDir . '/' . $config->getDashboardLogFileName();

        $logs = [];
        if (file_exists($logFile)) {
            $logs = $this->getStateManager()->getLogTail($logFile, $lines);
        }

        return $this->asJson([
            'success' => true,
            'logs' => $logs,
        ]);
    }

    /**
     * API: Get migration changelogs
     */
    public function actionGetChangelog(): Response
    {
        $this->requireAcceptsJson();

        $changelogData = $this->getStateManager()->getChangelogs();

        return $this->asJson([
            'success' => true,
            'changelogs' => $changelogData['changelogs'],
            'directory' => $changelogData['directory'],
        ]);
    }

    /**
     * API: Get running migrations
     */
    public function actionGetRunningMigrations(): Response
    {
        $this->requireAcceptsJson();

        try {
            $stateService = new \csabourin\spaghettiMigrator\services\MigrationStateService();
            $stateService->ensureTableExists();

            $runningMigrations = $stateService->getRunningMigrations();

            return $this->asJson([
                'success' => true,
                'migrations' => $runningMigrations,
            ]);
        } catch (\Exception $e) {
            Craft::error('Failed to get running migrations: ' . $e->getMessage(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: Get migration progress
     */
    public function actionGetMigrationProgress(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $migrationId = $request->getQueryParam('migrationId');

        if (!$migrationId) {
            // Try to get the latest migration
            try {
                $stateService = new \csabourin\spaghettiMigrator\services\MigrationStateService();
                $stateService->ensureTableExists();

                $latestMigration = $stateService->getLatestMigration();

                if (!$latestMigration) {
                    return $this->asJson([
                        'success' => false,
                        'error' => 'No migrations found',
                    ]);
                }

                return $this->asJson([
                    'success' => true,
                    'migration' => $latestMigration,
                ]);
            } catch (\Exception $e) {
                Craft::error('Failed to get latest migration: ' . $e->getMessage(), __METHOD__);
                return $this->asJson([
                    'success' => false,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $stateService = new \csabourin\spaghettiMigrator\services\MigrationStateService();
            $stateService->ensureTableExists();

            $migration = $stateService->getMigrationState($migrationId);

            if (!$migration) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Migration not found',
                ]);
            }

            // Check if process is still running
            $pid = $migration['pid'] ?? null;
            $isRunning = false;

            if ($pid) {
                if (function_exists('posix_kill')) {
                    $isRunning = @posix_kill($pid, 0);
                } elseif (file_exists("/proc/$pid")) {
                    $isRunning = true;
                } else {
                    exec("ps -p $pid", $output, $returnCode);
                    $isRunning = $returnCode === 0;
                }
            }

            $migration['isProcessRunning'] = $isRunning;

            // If process is not running but status is 'running', update status
            if (!$isRunning && $migration['status'] === 'running') {
                $stateService->updateMigrationStatus($migrationId, 'paused', 'Process no longer running');
                $migration['status'] = 'paused';
            }

            return $this->asJson([
                'success' => true,
                'migration' => $migration,
            ]);
        } catch (\Exception $e) {
            Craft::error('Failed to get migration progress: ' . $e->getMessage(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: Test DO Spaces connection
     */
    public function actionTestConnection(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $result = $this->getStateManager()->testConnection();
        return $this->asJson($result);
    }

    /**
     * Get CommandExecutionService instance
     */
    private function getCommandService(): CommandExecutionService
    {
        if ($this->commandService === null) {
            $this->commandService = new CommandExecutionService($this->getProcessManager());
        }

        return $this->commandService;
    }

    /**
     * Get ModuleDefinitionProvider instance
     */
    private function getModuleProvider(): ModuleDefinitionProvider
    {
        if ($this->moduleProvider === null) {
            $this->moduleProvider = new ModuleDefinitionProvider();
        }

        return $this->moduleProvider;
    }

    /**
     * Get ProcessManager instance
     */
    private function getProcessManager(): ProcessManager
    {
        if ($this->processManager === null) {
            $this->processManager = new ProcessManager();
        }

        return $this->processManager;
    }

    /**
     * Get MigrationStateManager instance
     */
    private function getStateManager(): MigrationStateManager
    {
        if ($this->stateManager === null) {
            $this->stateManager = new MigrationStateManager($this->getProgressService());
        }

        return $this->stateManager;
    }

    private function getProgressService(): MigrationProgressService
    {
        if ($this->progressService === null) {
            $this->progressService = new MigrationProgressService();
        }

        return $this->progressService;
    }

    private function getAccessValidator(): MigrationAccessValidator
    {
        if ($this->accessValidator === null) {
            $this->accessValidator = new MigrationAccessValidator();
        }

        return $this->accessValidator;
    }

    private function getConfig(): MigrationConfig
    {
        if ($this->config === null) {
            $this->config = MigrationConfig::getInstance();
        }

        return $this->config;
    }

    private function requiresAdminChanges(Action $action): bool
    {
        return in_array($action->id, [
            'run-command',
            'run-command-queue',
            'update-module-status',
            'update-status',
            'cancel-command',
        ], true);
    }
}
