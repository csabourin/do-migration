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

    /**
     * @var bool Enable CSRF validation for all requests
     */
    public $enableCsrfValidation = true;

    private ?MigrationAccessValidator $accessValidator = null;

    private ?MigrationProgressService $progressService = null;

    private ?CommandExecutionService $commandService = null;

    private ?ModuleDefinitionProvider $moduleProvider = null;

    private ?ProcessManager $processManager = null;

    private ?MigrationStateManager $stateManager = null;

    private ?MigrationConfig $config = null;

    /**
     * Ensure only administrators with mutable config can hit migration endpoints.
     * Validates CSRF tokens for POST requests.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // CSRF validation is handled automatically via $enableCsrfValidation property

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

        // Validate and decode JSON if string
        if (is_string($modulesParam)) {
            $decoded = json_decode($modulesParam, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Invalid JSON: ' . json_last_error_msg(),
                ]);
            }
            $modulesParam = $decoded;
        }

        // Validate array type
        if (!is_array($modulesParam)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid modules payload: must be an array',
            ]);
        }

        // Limit array size to prevent DoS
        if (count($modulesParam) > 100) {
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid modules array: too many items (max 100)',
            ]);
        }

        // Validate each module ID
        $modules = [];
        foreach ($modulesParam as $moduleId) {
            if (!is_string($moduleId) || $moduleId === '') {
                continue;
            }

            // Validate module ID format (alphanumeric, underscore, hyphen only)
            if (!preg_match('/^[a-z0-9_-]+$/i', $moduleId)) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Invalid module ID format: ' . htmlspecialchars($moduleId, ENT_QUOTES, 'UTF-8'),
                ]);
            }

            // Limit module ID length
            if (strlen($moduleId) > 100) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Module ID too long (max 100 characters)',
                ]);
            }

            $modules[$moduleId] = true;
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
        $dryRun = $request->getBodyParam('dryRun', false);
        $stream = $request->getBodyParam('stream', false);

        // Only require JSON acceptance for non-streaming requests
        if (!$stream) {
            $this->requireAcceptsJson();
        }

        // Validate command parameter
        if (!$command || !is_string($command)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Command is required and must be a string',
            ]);
        }

        // Validate command format
        if (!preg_match('/^[a-z0-9_-]+\/[a-z0-9_-]+$/i', $command)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid command format',
            ]);
        }

        // Limit command length
        if (strlen($command) > 200) {
            return $this->asJson([
                'success' => false,
                'error' => 'Command too long (max 200 characters)',
            ]);
        }

        // Validate and decode args
        if (is_string($argsParam)) {
            $args = json_decode($argsParam, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Invalid args JSON: ' . json_last_error_msg(),
                ]);
            }
        } else {
            $args = $argsParam;
        }

        // Ensure args is an array
        if (!is_array($args)) {
            $args = [];
        }

        // Limit args array size to prevent DoS
        if (count($args) > 50) {
            return $this->asJson([
                'success' => false,
                'error' => 'Too many arguments (max 50)',
            ]);
        }

        // Validate arg keys and values
        foreach ($args as $key => $value) {
            if (!is_string($key)) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Argument keys must be strings',
                ]);
            }

            if (!preg_match('/^[a-zA-Z][\w-]*$/', $key)) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Invalid argument name: ' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
                ]);
            }

            if (strlen($key) > 100) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Argument name too long (max 100 characters)',
                ]);
            }

            // Validate value types (only allow scalar values and arrays)
            if (!is_scalar($value) && !is_array($value) && $value !== null) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Invalid argument value type for: ' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
                ]);
            }

            // Limit string value length
            if (is_string($value) && strlen($value) > 1000) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Argument value too long for: ' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
                ]);
            }
        }

        // Validate command against whitelist
        $commandService = $this->getCommandService();
        if (!$commandService->isCommandAllowed($command)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Command not allowed',
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

        // Convert dryRun to boolean (comes from JS as '0' or '1')
        $dryRunParam = $request->getBodyParam('dryRun', false);
        $dryRun = filter_var($dryRunParam, FILTER_VALIDATE_BOOLEAN);

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
            // Queue all commands (including dry runs) for real-time feedback via polling
            // Dry runs will execute with --dryRun=1 flag in the queue job

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
                    // Convert string booleans from JS ('0'/'1') to proper PHP booleans
                    'skipBackup' => filter_var($args['skipBackup'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'skipInlineDetection' => filter_var($args['skipInlineDetection'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'resume' => filter_var($args['resume'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'checkpointId' => $args['checkpointId'] ?? null,
                ];
            } else {
                // Use generic command job for other commands
                $jobClass = \csabourin\spaghettiMigrator\jobs\ConsoleCommandJob::class;
            }

            // Create job instance and push to queue
            // Note: Craft CMS 4+ queue system doesn't support job priorities
            // Long-running jobs are handled via getTtr() method override in job classes
            $job = new $jobClass($jobParams);

            $jobId = Craft::$app->getQueue()->push($job);

            Craft::info("Queued command {$command} with job ID {$jobId}, migration ID {$migrationId}", __METHOD__);

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
        $migrationId = $request->getQueryParam('migrationId');

        if (!$jobId) {
            return $this->asJson([
                'success' => false,
                'error' => 'Job ID is required',
            ]);
        }

        try {
            $queue = Craft::$app->getQueue();
            $db = Craft::$app->getDb();
            $migrationStatus = null;
            $stateService = null;

            // Query the queue table
            $job = $db->createCommand('
                SELECT id, description, progress, timePushed, ttr, attempt, fail, dateFailed, error
                FROM {{%queue}}
                WHERE id = :jobId
                LIMIT 1
            ', [':jobId' => $jobId])->queryOne();

            if ($migrationId) {
                $stateService = new \csabourin\spaghettiMigrator\services\MigrationStateService();
                $stateService->ensureTableExists();
                $migration = $stateService->getMigrationState($migrationId);
                $migrationStatus = $migration['status'] ?? null;
            }

            if (!$job) {
                // Job not found - fall back to migration state if provided
                if ($migrationStatus) {
                    return $this->asJson([
                        'success' => true,
                        'status' => $migrationStatus,
                        'job' => null,
                        'message' => 'Job not found in queue, using migration state instead',
                    ]);
                }

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
            } elseif ($progressPercent > 0 || $migrationStatus === 'running') {
                $status = 'running';
            }

            return $this->asJson([
                'success' => true,
                'status' => $status,
                'migrationStatus' => $migrationStatus,
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

        $logs = [];
        $stateService = new \csabourin\spaghettiMigrator\services\MigrationStateService();
        $stateService->ensureTableExists();

        // Prefer persisted migration output if available so dashboard reflects command logs
        $latestMigration = $stateService->getLatestMigration();
        if ($latestMigration && !empty($latestMigration['output'])) {
            $logs = $this->getRecentOutputLines($latestMigration['output'], (int)$lines);
        }

        // Fallback to dashboard log file when no DB output exists
        if (empty($logs)) {
            $logDir = Craft::getAlias('@storage/logs');
            $logFile = $logDir . '/' . $config->getDashboardLogFileName();

            if (file_exists($logFile)) {
                $logs = $this->getStateManager()->getLogTail($logFile, $lines);
            }
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
     * API: Get comprehensive live monitoring data (migration state + recent logs)
     */
    public function actionGetLiveMonitor(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $migrationId = $request->getQueryParam('migrationId');
        $logLines = (int)($request->getQueryParam('logLines', 0));

        try {
            $stateService = new \csabourin\spaghettiMigrator\services\MigrationStateService();
            $stateService->ensureTableExists();

            // Get migration state
            if ($migrationId) {
                $migration = $stateService->getMigrationState($migrationId);
            } else {
                $migration = $stateService->getLatestMigration();
            }

            if (!$migration) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'No migration found',
                    'hasMigration' => false,
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

            // Get recent log entries
            $config = $this->getConfig();
            $recentLogs = $this->getRecentOutputLines($migration['output'] ?? null, $logLines);

            // Fallback to dashboard log file when no output has been persisted yet
            if (empty($recentLogs)) {
                $logDir = Craft::getAlias('@storage/logs');
                $logFile = $logDir . '/' . $config->getDashboardLogFileName();

                if (file_exists($logFile)) {
                    $recentLogs = $this->getStateManager()->getLogTail($logFile, $logLines > 0 ? $logLines : 1000);
                }
            }

            $logTasks = [];
            foreach ($stateService->getRecentMigrations(5, true) as $recent) {
                $logTasks[] = [
                    'migrationId' => $recent['migrationId'],
                    'command' => $recent['command'],
                    'status' => $recent['status'],
                    'startedAt' => $recent['startedAt'] ?? null,
                    'completedAt' => $recent['completedAt'] ?? null,
                    'lines' => $this->getRecentOutputLines($recent['output'] ?? '', $logLines),
                ];
            }

            // Calculate progress percentage
            $progressPercent = 0;
            if (isset($migration['totalCount']) && $migration['totalCount'] > 0) {
                $progressPercent = round(($migration['processedCount'] / $migration['totalCount']) * 100, 1);
            }

            // Get queue job if running via queue
            $queueJob = null;
            if (str_starts_with($migration['migrationId'] ?? '', 'queue-')) {
                $db = Craft::$app->getDb();
                $jobs = $db->createCommand('
                    SELECT id, description, progress, timePushed, attempt, fail, error
                    FROM {{%queue}}
                    WHERE description LIKE :description
                    ORDER BY timePushed DESC
                    LIMIT 1
                ', [':description' => '%' . $migration['migrationId'] . '%'])->queryOne();

                if ($jobs) {
                    $queueJob = [
                        'id' => $jobs['id'],
                        'description' => $jobs['description'],
                        'progress' => (float)$jobs['progress'],
                        'status' => $jobs['fail'] == 1 ? 'failed' : ($jobs['progress'] > 0 ? 'running' : 'pending'),
                    ];
                }
            }

            return $this->asJson([
                'success' => true,
                'hasMigration' => true,
                'migration' => [
                    'id' => $migration['migrationId'],
                    'phase' => $migration['phase'],
                    'status' => $migration['status'],
                    'pid' => $pid,
                    'isProcessRunning' => $isRunning,
                    'processedCount' => $migration['processedCount'] ?? 0,
                    'totalCount' => $migration['totalCount'] ?? 0,
                    'progressPercent' => $progressPercent,
                    'currentBatch' => $migration['currentBatch'] ?? 0,
                    'stats' => $migration['stats'] ?? [],
                    'errorMessage' => $migration['errorMessage'] ?? null,
                    'startedAt' => $migration['startedAt'] ?? null,
                    'lastUpdatedAt' => $migration['lastUpdatedAt'] ?? null,
                    'command' => $migration['command'] ?? null,
                ],
                'logs' => $recentLogs,
                'logTasks' => $logTasks,
                'queueJob' => $queueJob,
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            Craft::error('Failed to get live monitoring data: ' . $e->getMessage(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
                'hasMigration' => false,
            ]);
        }
    }

    /**
     * Extract the last N lines from persisted migration output.
     */
    private function getRecentOutputLines(?string $output, int $lines = 50): array
    {
        if (empty($output)) {
            return [];
        }

        $logLines = preg_split('/\r\n|\n|\r/', $output) ?: [];
        $logLines = array_values(array_filter($logLines, function ($line) {
            return trim($line) !== '';
        }));

        if ($lines > 0 && count($logLines) > $lines) {
            $logLines = array_slice($logLines, -$lines);
        }

        return $logLines;
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
     * API: Analyze missing files
     *
     * Scans all volumes for missing files and returns analysis results
     */
    public function actionAnalyzeMissingFiles(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        try {
            // Get all volumes
            $volumesService = Craft::$app->getVolumes();
            $imagesVolume = $volumesService->getVolumeByHandle('images');
            $documentsVolume = $volumesService->getVolumeByHandle('documents');
            $quarantineVolume = $volumesService->getVolumeByHandle('quarantine');

            if (!$imagesVolume || !$documentsVolume) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Required volumes not found. Need "images" and "documents" volumes.',
                ]);
            }

            // Count total assets
            $totalAssets = \craft\elements\Asset::find()->count();

            // Find missing files
            $missingFiles = [];
            $allAssets = \craft\elements\Asset::find()->all();

            foreach ($allAssets as $asset) {
                $volume = $asset->getVolume();
                $fs = $volume->getFs();
                $path = $asset->getPath();

                if (!$fs->fileExists($path)) {
                    $missingFiles[] = [
                        'id' => $asset->id,
                        'filename' => $asset->filename,
                        'volumeHandle' => $volume->handle,
                        'volumeName' => $volume->name,
                        'path' => $path,
                        'extension' => $asset->extension,
                    ];
                }
            }

            // Check quarantine if volume exists
            $foundInQuarantine = 0;
            $quarantineFiles = [];

            if ($quarantineVolume) {
                $quarantineAssets = \craft\elements\Asset::find()
                    ->volumeId($quarantineVolume->id)
                    ->all();

                foreach ($quarantineAssets as $qAsset) {
                    $quarantineFiles[$qAsset->filename] = [
                        'id' => $qAsset->id,
                        'path' => $qAsset->getPath(),
                    ];
                }

                // Check how many missing files are in quarantine
                foreach ($missingFiles as $item) {
                    if (isset($quarantineFiles[$item['filename']])) {
                        $foundInQuarantine++;
                    }
                }
            }

            return $this->asJson([
                'success' => true,
                'data' => [
                    'totalAssets' => $totalAssets,
                    'totalMissing' => count($missingFiles),
                    'foundInQuarantine' => $foundInQuarantine,
                    'quarantineAssetCount' => count($quarantineFiles),
                    'missingFiles' => array_slice($missingFiles, 0, 100), // Limit to first 100 for display
                    'hasMore' => count($missingFiles) > 100,
                ],
            ]);
        } catch (\Throwable $e) {
            Craft::error('Error analyzing missing files: ' . $e->getMessage(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => 'Error analyzing missing files: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * API: Fix missing file associations
     *
     * Moves files from quarantine to their correct locations and updates asset records
     */
    public function actionFixMissingFiles(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $dryRun = $request->getBodyParam('dryRun', true);

        try {
            // Get volumes
            $volumesService = Craft::$app->getVolumes();
            $imagesVolume = $volumesService->getVolumeByHandle('images');
            $documentsVolume = $volumesService->getVolumeByHandle('documents');
            $quarantineVolume = $volumesService->getVolumeByHandle('quarantine');

            if (!$imagesVolume || !$documentsVolume || !$quarantineVolume) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Required volumes not found.',
                ]);
            }

            // Get filesystems
            $imagesFs = $imagesVolume->getFs();
            $documentsFs = $documentsVolume->getFs();
            $quarantineFs = $quarantineVolume->getFs();

            // Find missing files
            $missingFiles = [];
            $allAssets = \craft\elements\Asset::find()->all();

            foreach ($allAssets as $asset) {
                $volume = $asset->getVolume();
                $fs = $volume->getFs();
                $path = $asset->getPath();

                if (!$fs->fileExists($path)) {
                    $missingFiles[] = $asset;
                }
            }

            // Build quarantine file map
            $quarantineFiles = [];
            $quarantineAssets = \craft\elements\Asset::find()
                ->volumeId($quarantineVolume->id)
                ->all();

            foreach ($quarantineAssets as $qAsset) {
                $quarantineFiles[$qAsset->filename] = [
                    'asset' => $qAsset,
                    'path' => $qAsset->getPath(),
                ];
            }

            // Fix files
            $fixed = 0;
            $errors = [];

            foreach ($missingFiles as $asset) {
                if (!isset($quarantineFiles[$asset->filename])) {
                    continue; // File not in quarantine
                }

                $quarantineFile = $quarantineFiles[$asset->filename];

                // Determine target filesystem based on extension
                $extension = strtolower($asset->extension);
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff'];

                if (in_array($extension, $imageExtensions)) {
                    $targetFs = $imagesFs;
                    $targetVolumeId = $imagesVolume->id;
                } else {
                    $targetFs = $documentsFs;
                    $targetVolumeId = $documentsVolume->id;
                }

                if (!$dryRun) {
                    try {
                        // Read file from quarantine
                        $content = $quarantineFs->read($quarantineFile['path']);

                        // Write to target location
                        $targetPath = $asset->getPath();
                        $targetFs->write($targetPath, $content, []);

                        // Update asset volume if needed
                        if ($asset->volumeId !== $targetVolumeId) {
                            $asset->volumeId = $targetVolumeId;
                            Craft::$app->getElements()->saveElement($asset);
                        }

                        // Delete from quarantine
                        $quarantineFs->deleteFile($quarantineFile['path']);

                        // Delete quarantine asset record
                        Craft::$app->getElements()->deleteElement($quarantineFile['asset']);

                        $fixed++;
                    } catch (\Throwable $e) {
                        $errors[] = [
                            'filename' => $asset->filename,
                            'error' => $e->getMessage(),
                        ];
                    }
                } else {
                    $fixed++; // Count what would be fixed in dry run
                }
            }

            return $this->asJson([
                'success' => true,
                'data' => [
                    'dryRun' => $dryRun,
                    'totalMissing' => count($missingFiles),
                    'foundInQuarantine' => $fixed,
                    'fixed' => $dryRun ? 0 : $fixed,
                    'errors' => $errors,
                    'message' => $dryRun
                        ? "Dry run complete. Found {$fixed} files that can be fixed."
                        : "Fixed {$fixed} files successfully.",
                ],
            ]);
        } catch (\Throwable $e) {
            Craft::error('Error fixing missing files: ' . $e->getMessage(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => 'Error fixing missing files: ' . $e->getMessage(),
            ]);
        }
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
            'fix-missing-files',
        ], true);
    }
}
