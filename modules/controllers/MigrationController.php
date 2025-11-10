<?php

namespace csabourin\craftS3SpacesMigration\controllers;

use Craft;
use craft\web\Controller;
use csabourin\craftS3SpacesMigration\helpers\MigrationConfig;
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
     * Render the main migration dashboard
     */
    public function actionIndex(): Response
    {
        // Get current migration state
        $state = $this->getMigrationState();

        // Get configuration status
        $config = $this->getConfigurationStatus();

        return $this->renderTemplate('s3-spaces-migration/dashboard', [
            'state' => $state,
            'config' => $config,
            'modules' => $this->getModuleDefinitions(),
        ]);
    }

    /**
     * API: Get migration status
     */
    public function actionGetStatus(): Response
    {
        $this->requireAcceptsJson();

        return $this->asJson([
            'success' => true,
            'state' => $this->getMigrationState(),
            'config' => $this->getConfigurationStatus(),
        ]);
    }

    /**
     * API: Run a specific migration command
     */
    public function actionRunCommand(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $command = $request->getBodyParam('command');
        $argsParam = $request->getBodyParam('args', '[]');
        $args = is_string($argsParam) ? json_decode($argsParam, true) : $argsParam;
        $args = $args ?: []; // Ensure it's an array even if json_decode fails
        $dryRun = $request->getBodyParam('dryRun', false);
        $stream = $request->getBodyParam('stream', false);

        if (!$command) {
            return $this->asJson([
                'success' => false,
                'error' => 'Command is required',
            ]);
        }

        // Validate command
        $allowedCommands = $this->getAllowedCommands();
        if (!in_array($command, $allowedCommands)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid command',
            ]);
        }

        try {
            // Build the full command
            $fullCommand = "s3-spaces-migration/{$command}";

            // Add dry run flag if requested
            if ($dryRun) {
                $args['dryRun'] = '1';
            }

            // Use streaming for long-running commands
            if ($stream) {
                return $this->streamConsoleCommand($fullCommand, $args);
            }

            // Execute the command
            $result = $this->executeConsoleCommand($fullCommand, $args);

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

            // Get running processes from session
            $session = Craft::$app->getSession();
            $runningProcesses = $session->get('runningProcesses', []);

            // Check if command is running
            if (!isset($runningProcesses[$command])) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Command is not currently running',
                ]);
            }

            $processInfo = $runningProcesses[$command];
            $pid = $processInfo['pid'];

            Craft::info("Cancellation requested for command: {$command} (PID: {$pid})", __METHOD__);

            // Mark as cancelled in session (the streaming loop will detect this)
            $runningProcesses[$command]['cancelled'] = true;
            $session->set('runningProcesses', $runningProcesses);

            return $this->asJson([
                'success' => true,
                'message' => 'Cancellation signal sent. Process will terminate shortly.',
                'pid' => $pid,
            ]);

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

        $checkpointDir = Craft::getAlias('@storage/migration-checkpoints');
        $checkpoints = [];

        if (is_dir($checkpointDir)) {
            $files = glob($checkpointDir . '/checkpoint-*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $checkpoints[] = [
                        'filename' => basename($file),
                        'timestamp' => $data['checkpoint']['timestamp'] ?? null,
                        'progress' => $data['checkpoint']['progress'] ?? [],
                        'phase' => $data['checkpoint']['phase'] ?? null,
                    ];
                }
            }

            // Sort by timestamp descending
            usort($checkpoints, function($a, $b) {
                return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
            });
        }

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

        $request = Craft::$app->getRequest();
        $lines = $request->getQueryParam('lines', 100);

        $logDir = Craft::getAlias('@storage/logs');
        $logFile = $logDir . '/web.log';

        $logs = [];
        if (file_exists($logFile)) {
            $logs = $this->getTailOfFile($logFile, $lines);
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

        $changelogDir = Craft::getAlias('@storage/migration-logs');
        $changelogs = [];

        if (is_dir($changelogDir)) {
            $files = glob($changelogDir . '/changelog-*.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $changelogs[] = [
                        'filename' => basename($file),
                        'filepath' => $file,
                        'timestamp' => $data['timestamp'] ?? filemtime($file),
                        'operation' => $data['operation'] ?? 'unknown',
                        'summary' => $data['summary'] ?? [],
                        'changes' => $data['changes'] ?? [],
                    ];
                }
            }

            // Sort by timestamp descending
            usort($changelogs, function($a, $b) {
                return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
            });
        }

        return $this->asJson([
            'success' => true,
            'changelogs' => $changelogs,
            'directory' => $changelogDir,
        ]);
    }

    /**
     * API: Test DO Spaces connection
     */
    public function actionTestConnection(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        try {
            $config = MigrationConfig::getInstance();
            $doConfig = $config->get('digitalocean');

            // Simple validation
            $errors = [];
            if (empty($doConfig['accessKey'])) {
                $errors[] = 'DO_S3_ACCESS_KEY is not configured';
            }
            if (empty($doConfig['secretKey'])) {
                $errors[] = 'DO_S3_SECRET_KEY is not configured';
            }
            if (empty($doConfig['bucket'])) {
                $errors[] = 'DO_S3_BUCKET is not configured';
            }

            if (!empty($errors)) {
                return $this->asJson([
                    'success' => false,
                    'errors' => $errors,
                ]);
            }

            return $this->asJson([
                'success' => true,
                'message' => 'Configuration looks valid. Run pre-flight checks to verify connection.',
            ]);

        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Execute a console command and return output
     */
    private function executeConsoleCommand(string $command, array $args = []): array
    {
        // Commands that support --yes flag for automation
        $commandsSupportingYes = [
            // extended-url-replacement
            's3-spaces-migration/extended-url-replacement/replace-additional',
            's3-spaces-migration/extended-url-replacement/replace-json',

            // filesystem
            's3-spaces-migration/filesystem/create',
            's3-spaces-migration/filesystem/delete',

            // filesystem-fix
            's3-spaces-migration/filesystem-fix/fix-endpoints',

            // filesystem-switch
            's3-spaces-migration/filesystem-switch/to-aws',
            's3-spaces-migration/filesystem-switch/to-do',

            // image-migration
            's3-spaces-migration/image-migration/cleanup',
            's3-spaces-migration/image-migration/force-cleanup',
            's3-spaces-migration/image-migration/migrate',
            's3-spaces-migration/image-migration/rollback',

            // migration-diag
            's3-spaces-migration/migration-diag/move-originals',

            // template-url-replacement
            's3-spaces-migration/template-url-replacement/replace',
            's3-spaces-migration/template-url-replacement/restore-backups',

            // transform-pre-generation
            's3-spaces-migration/transform-pre-generation/generate',
            's3-spaces-migration/transform-pre-generation/warmup',

            // url-replacement
            's3-spaces-migration/url-replacement/replace-s3-urls',

            // volume-config
            's3-spaces-migration/volume-config/add-optimised-field',
            's3-spaces-migration/volume-config/configure-all',
            's3-spaces-migration/volume-config/create-quarantine-volume',
            's3-spaces-migration/volume-config/set-transform-filesystem',
        ];

        // Automatically add --yes flag for web automation if command supports it
        if (in_array($command, $commandsSupportingYes) && !isset($args['yes'])) {
            $args['yes'] = true;
        }

        // Build argument string - only include truthy values
        $argString = '';
        foreach ($args as $key => $value) {
            // Skip false, empty string, '0', and 0 values
            if ($value === false || $value === '' || $value === '0' || $value === 0) {
                continue;
            }

            // For boolean true, just add the flag without a value
            if ($value === true || $value === '1' || $value === 1) {
                $argString .= " --{$key}";
            } else {
                // For other values, add key=value
                $argString .= " --{$key}=" . escapeshellarg($value);
            }
        }

        // Execute command
        $craftPath = Craft::getAlias('@root/craft');
        $fullCommand = "{$craftPath} {$command}{$argString} 2>&1";

        // Log the command being executed
        Craft::info("Executing console command: {$fullCommand}", __METHOD__);

        exec($fullCommand, $output, $exitCode);

        // Log the result
        Craft::info("Command exit code: {$exitCode}", __METHOD__);
        if ($exitCode !== 0) {
            Craft::warning("Command failed with output: " . implode("\n", $output), __METHOD__);
        }

        return [
            'output' => implode("\n", $output),
            'exitCode' => $exitCode,
        ];
    }

    /**
     * Stream console command output in realtime using Server-Sent Events (SSE)
     */
    private function streamConsoleCommand(string $command, array $args = []): Response
    {
        // Commands that support --yes flag for automation
        $commandsSupportingYes = [
            's3-spaces-migration/extended-url-replacement/replace-additional',
            's3-spaces-migration/extended-url-replacement/replace-json',
            's3-spaces-migration/filesystem/create',
            's3-spaces-migration/filesystem/delete',
            's3-spaces-migration/filesystem-fix/fix-endpoints',
            's3-spaces-migration/filesystem-switch/to-aws',
            's3-spaces-migration/filesystem-switch/to-do',
            's3-spaces-migration/image-migration/cleanup',
            's3-spaces-migration/image-migration/force-cleanup',
            's3-spaces-migration/image-migration/migrate',
            's3-spaces-migration/image-migration/rollback',
            's3-spaces-migration/migration-diag/move-originals',
            's3-spaces-migration/template-url-replacement/replace',
            's3-spaces-migration/template-url-replacement/restore-backups',
            's3-spaces-migration/transform-pre-generation/generate',
            's3-spaces-migration/transform-pre-generation/warmup',
            's3-spaces-migration/url-replacement/replace-s3-urls',
            's3-spaces-migration/volume-config/add-optimised-field',
            's3-spaces-migration/volume-config/configure-all',
            's3-spaces-migration/volume-config/create-quarantine-volume',
            's3-spaces-migration/volume-config/set-transform-filesystem',
        ];

        // Automatically add --yes flag for web automation if command supports it
        if (in_array($command, $commandsSupportingYes) && !isset($args['yes'])) {
            $args['yes'] = true;
        }

        // Build argument string
        $argString = '';
        foreach ($args as $key => $value) {
            if ($value === false || $value === '' || $value === '0' || $value === 0) {
                continue;
            }

            if ($value === true || $value === '1' || $value === 1) {
                $argString .= " --{$key}";
            } else {
                $argString .= " --{$key}=" . escapeshellarg($value);
            }
        }

        // Build full command
        $craftPath = Craft::getAlias('@root/craft');
        $fullCommand = "{$craftPath} {$command}{$argString} 2>&1";

        // Log the command
        Craft::info("Streaming console command: {$fullCommand}", __METHOD__);

        // Set up SSE headers
        $response = Craft::$app->getResponse();
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable nginx buffering

        // Start output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Open process with pipes for streaming
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($fullCommand, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            $response->data = "event: error\ndata: " . json_encode(['error' => 'Failed to start command']) . "\n\n";
            return $response;
        }

        // Get process status to retrieve PID
        $status = proc_get_status($process);
        $pid = $status['pid'];

        // Store PID in session for cancellation support
        $session = Craft::$app->getSession();
        $runningProcesses = $session->get('runningProcesses', []);
        $runningProcesses[$command] = [
            'pid' => $pid,
            'startTime' => time(),
            'command' => $command,
        ];
        $session->set('runningProcesses', $runningProcesses);

        Craft::info("Started process with PID {$pid} for command: {$command}", __METHOD__);

        // Close stdin (we don't need it)
        fclose($pipes[0]);

        // Set streams to non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Send initial event with PID
        echo "event: start\ndata: " . json_encode(['message' => 'Command started', 'pid' => $pid]) . "\n\n";
        flush();

        // Stream output line by line
        $buffer = '';
        $errorBuffer = '';
        $outputLines = [];

        while (true) {
            $status = proc_get_status($process);

            // Check if cancellation was requested
            $runningProcesses = $session->get('runningProcesses', []);
            if (!isset($runningProcesses[$command]) || ($runningProcesses[$command]['cancelled'] ?? false)) {
                Craft::info("Cancellation detected for command: {$command}", __METHOD__);

                // Attempt graceful shutdown with SIGTERM
                proc_terminate($process, 15); // SIGTERM

                // Send cancellation event
                echo "event: cancelled\ndata: " . json_encode(['message' => 'Command cancellation requested']) . "\n\n";
                flush();

                // Wait up to 3 seconds for graceful shutdown
                $waitStart = microtime(true);
                while (microtime(true) - $waitStart < 3) {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(100000); // 100ms
                }

                // Force kill if still running
                $status = proc_get_status($process);
                if ($status['running']) {
                    Craft::warning("Process {$pid} did not terminate gracefully, sending SIGKILL", __METHOD__);
                    proc_terminate($process, 9); // SIGKILL
                    sleep(1);
                }

                break;
            }

            // Read from stdout
            if (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $buffer .= $chunk;

                    // Process complete lines
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);
                        $outputLines[] = $line;

                        // Send line as SSE event
                        echo "event: output\ndata: " . json_encode(['line' => $line]) . "\n\n";
                        flush();
                    }
                }
            }

            // Read from stderr
            if (!feof($pipes[2])) {
                $chunk = fread($pipes[2], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $errorBuffer .= $chunk;

                    // Process complete lines
                    while (($pos = strpos($errorBuffer, "\n")) !== false) {
                        $line = substr($errorBuffer, 0, $pos);
                        $errorBuffer = substr($errorBuffer, $pos + 1);
                        $outputLines[] = $line;

                        // Send error line as SSE event
                        echo "event: output\ndata: " . json_encode(['line' => $line, 'type' => 'error']) . "\n\n";
                        flush();
                    }
                }
            }

            // Check if process has exited
            if (!$status['running']) {
                break;
            }

            // Small sleep to prevent busy waiting
            usleep(50000); // 50ms
        }

        // Read any remaining output
        $buffer .= stream_get_contents($pipes[1]);
        $errorBuffer .= stream_get_contents($pipes[2]);

        // Process remaining buffered output
        foreach (explode("\n", $buffer) as $line) {
            if ($line !== '') {
                $outputLines[] = $line;
                echo "event: output\ndata: " . json_encode(['line' => $line]) . "\n\n";
                flush();
            }
        }

        foreach (explode("\n", $errorBuffer) as $line) {
            if ($line !== '') {
                $outputLines[] = $line;
                echo "event: output\ndata: " . json_encode(['line' => $line, 'type' => 'error']) . "\n\n";
                flush();
            }
        }

        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);

        // Get exit code
        $exitCode = proc_close($process);
        $success = ($exitCode === 0);

        // Check if this was a cancellation
        $runningProcesses = $session->get('runningProcesses', []);
        $wasCancelled = $runningProcesses[$command]['cancelled'] ?? false;

        // Clean up PID from session
        $runningProcesses = $session->get('runningProcesses', []);
        unset($runningProcesses[$command]);
        $session->set('runningProcesses', $runningProcesses);

        Craft::info("Process {$pid} for command {$command} completed. Exit code: {$exitCode}", __METHOD__);

        // Send completion event
        echo "event: complete\ndata: " . json_encode([
            'success' => $success,
            'exitCode' => $exitCode,
            'cancelled' => $wasCancelled,
            'output' => implode("\n", $outputLines)
        ]) . "\n\n";
        flush();

        // Log result
        Craft::info("Command exit code: {$exitCode}", __METHOD__);
        if ($exitCode !== 0 && !$wasCancelled) {
            Craft::warning("Command failed with output: " . implode("\n", $outputLines), __METHOD__);
        }

        $response->data = '';
        return $response;
    }

    /**
     * Get the current migration state
     */
    private function getMigrationState(): array
    {
        // Check for checkpoints
        $checkpointDir = Craft::getAlias('@storage/migration-checkpoints');
        $hasCheckpoint = is_dir($checkpointDir) && count(glob($checkpointDir . '/checkpoint-*.json')) > 0;

        // Check for changelogs
        $logDir = Craft::getAlias('@storage/migration-logs');
        $hasChangelog = is_dir($logDir) && count(glob($logDir . '/changelog-*.json')) > 0;

        // Determine current phase based on completed actions
        $currentPhase = 0;
        $completedModules = [];

        // Check filesystem status
        $filesystems = Craft::$app->getFs()->getAllFilesystems();
        $hasDoFilesystems = false;
        foreach ($filesystems as $fs) {
            if (strpos($fs->handle, '_do') !== false) {
                $hasDoFilesystems = true;
                $completedModules[] = 'filesystem';
                $currentPhase = max($currentPhase, 1);
                break;
            }
        }

        return [
            'hasCheckpoint' => $hasCheckpoint,
            'hasChangelog' => $hasChangelog,
            'hasDoFilesystems' => $hasDoFilesystems,
            'currentPhase' => $currentPhase,
            'completedModules' => $completedModules,
            'canResume' => $hasCheckpoint,
        ];
    }

    /**
     * Get configuration status
     */
    private function getConfigurationStatus(): array
    {
        try {
            $config = MigrationConfig::getInstance();

            $doConfig = $config->get('digitalocean');
            $awsConfig = $config->get('aws');

            return [
                'isConfigured' => true,
                'hasDoCredentials' => !empty($doConfig['accessKey']) && !empty($doConfig['secretKey']),
                'hasDoUrl' => !empty($doConfig['baseUrl']),
                'hasDoBucket' => !empty($doConfig['bucket']),
                'hasAwsConfig' => !empty($awsConfig['bucket']),
                'hasAwsCredentials' => !empty($awsConfig['accessKey']) && !empty($awsConfig['secretKey']),
                'doRegion' => $doConfig['region'] ?? 'tor1',
                'doBucket' => $doConfig['bucket'] ?? '',
                'awsBucket' => $awsConfig['bucket'] ?? '',
                'awsRegion' => $awsConfig['region'] ?? '',
            ];
        } catch (\Exception $e) {
            // Return all expected keys with default values when config fails
            return [
                'isConfigured' => false,
                'hasDoCredentials' => false,
                'hasDoUrl' => false,
                'hasDoBucket' => false,
                'hasAwsConfig' => false,
                'hasAwsCredentials' => false,
                'doRegion' => '',
                'doBucket' => '',
                'awsBucket' => '',
                'awsRegion' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get module definitions for the dashboard
     */
    private function getModuleDefinitions(): array
    {
        $config = null;
        $awsBucket = '';
        $awsRegion = '';
        $awsAccessKey = '';
        $awsSecretKey = '';
        $doAccessKey = '';
        $doSecretKey = '';
        $doRegion = '';
        $doEndpoint = '';

        $envVarNames = [
            'awsBucket' => 'AWS_SOURCE_BUCKET',
            'awsRegion' => 'AWS_SOURCE_REGION',
            'awsAccessKey' => 'AWS_SOURCE_ACCESS_KEY',
            'awsSecretKey' => 'AWS_SOURCE_SECRET_KEY',
            'doAccessKey' => 'DO_S3_ACCESS_KEY',
            'doSecretKey' => 'DO_S3_SECRET_KEY',
            'doRegion' => 'DO_S3_REGION',
            'doEndpoint' => 'DO_S3_BASE_ENDPOINT',
        ];

        try {
            $config = MigrationConfig::getInstance();
            $awsBucket = $config->getAwsBucket();
            $awsRegion = $config->getAwsRegion();
            $awsAccessKey = $config->getAwsAccessKey();
            $awsSecretKey = $config->getAwsSecretKey();
            $doAccessKey = $config->getDoAccessKey();
            $doSecretKey = $config->getDoSecretKey();
            $doRegion = $config->getDoRegion();
            $doEndpoint = $config->getDoEndpoint();

            $envVarNames['awsBucket'] = $config->getAwsEnvVarBucket();
            $envVarNames['awsRegion'] = $config->getAwsEnvVarRegion();
            $envVarNames['awsAccessKey'] = $config->getAwsEnvVarAccessKey();
            $envVarNames['awsSecretKey'] = $config->getAwsEnvVarSecretKey();
            $envVarNames['doAccessKey'] = $config->get('envVars.doAccessKey', $envVarNames['doAccessKey']);
            $envVarNames['doSecretKey'] = $config->get('envVars.doSecretKey', $envVarNames['doSecretKey']);
            $envVarNames['doRegion'] = $config->get('envVars.doRegion', $envVarNames['doRegion']);
            $envVarNames['doEndpoint'] = $config->get('envVars.doEndpoint', $envVarNames['doEndpoint']);
        } catch (\Throwable $e) {
            // Use defaults below when configuration is unavailable
        }

        $placeholder = static function (?string $value, ?string $envVarName, string $defaultPlaceholder): string {
            $value = $value !== null ? trim((string) $value) : '';
            if ($value !== '') {
                return $value;
            }

            $envVarName = $envVarName !== null ? trim((string) $envVarName) : '';
            if ($envVarName !== '') {
                return '${' . $envVarName . '}';
            }

            return $defaultPlaceholder;
        };

        $awsBucketPlaceholder = $placeholder($awsBucket, $envVarNames['awsBucket'] ?? 'AWS_SOURCE_BUCKET', '${AWS_SOURCE_BUCKET}');
        $awsRegionPlaceholder = $placeholder($awsRegion, $envVarNames['awsRegion'] ?? 'AWS_SOURCE_REGION', '${AWS_SOURCE_REGION}');
        $awsAccessKeyPlaceholder = $placeholder($awsAccessKey, $envVarNames['awsAccessKey'] ?? 'AWS_SOURCE_ACCESS_KEY', '${AWS_SOURCE_ACCESS_KEY}');
        $awsSecretKeyPlaceholder = $placeholder($awsSecretKey, $envVarNames['awsSecretKey'] ?? 'AWS_SOURCE_SECRET_KEY', '${AWS_SOURCE_SECRET_KEY}');
        $doAccessKeyPlaceholder = $placeholder($doAccessKey, $envVarNames['doAccessKey'] ?? 'DO_S3_ACCESS_KEY', '${DO_S3_ACCESS_KEY}');
        $doSecretKeyPlaceholder = $placeholder($doSecretKey, $envVarNames['doSecretKey'] ?? 'DO_S3_SECRET_KEY', '${DO_S3_SECRET_KEY}');
        $doRegionPlaceholder = $placeholder($doRegion, $envVarNames['doRegion'] ?? 'DO_S3_REGION', 'tor1');

        $normalizeEndpoint = static function (?string $endpoint): string {
            if ($endpoint === null) {
                return '';
            }

            $endpoint = trim($endpoint);
            if ($endpoint === '') {
                return '';
            }

            if (stripos($endpoint, 'http://') === 0 || stripos($endpoint, 'https://') === 0) {
                $endpoint = preg_replace('#^https?://#i', '', $endpoint);
            }

            return rtrim($endpoint, '/');
        };

        $doEndpointHost = $normalizeEndpoint($doEndpoint);
        $doEndpointPlaceholder = $doEndpointHost !== '' ? $doEndpointHost : '';

        if ($doEndpointPlaceholder === '') {
            $endpointEnvVar = $envVarNames['doEndpoint'] ?? '';
            if ($endpointEnvVar !== '') {
                $doEndpointPlaceholder = '${' . $endpointEnvVar . '}';
            }
        }

        if ($doEndpointPlaceholder === '' && $doRegionPlaceholder !== '') {
            $candidate = $doRegionPlaceholder;
            if (stripos($candidate, 'digitaloceanspaces.com') === false) {
                $candidate = rtrim($candidate, '.') . '.digitaloceanspaces.com';
            }
            $doEndpointPlaceholder = $candidate;
        }

        if ($doEndpointPlaceholder === '') {
            $doEndpointPlaceholder = 'tor1.digitaloceanspaces.com';
        }

        $rcloneAwsConfigCommand = sprintf(
            'rclone config create aws-s3 s3 provider AWS access_key_id %s secret_access_key %s region %s acl public-read',
            $awsAccessKeyPlaceholder,
            $awsSecretKeyPlaceholder,
            $awsRegionPlaceholder
        );

        $rcloneDoConfigCommand = sprintf(
            'rclone config create prod-medias s3 provider DigitalOcean access_key_id %s secret_access_key %s endpoint %s acl public-read',
            $doAccessKeyPlaceholder,
            $doSecretKeyPlaceholder,
            $doEndpointPlaceholder
        );

        $rcloneCopyCommand = sprintf(
            'rclone copy aws-s3:%s prod-medias:medias --exclude "_*/**" --fast-list --transfers=32 --checkers=16 --use-mmap --s3-acl=public-read -P',
            $awsBucketPlaceholder
        );

        $rcloneCheckCommand = sprintf(
            'rclone check aws-s3:%s prod-medias:medias --one-way',
            $awsBucketPlaceholder
        );

        $definitions = [
            [
                'id' => 'prerequisites',
                'title' => '⚠️ Prerequisites (Complete BEFORE Migration)',
                'phase' => -1,
                'icon' => 'warning',
                'modules' => [
                    [
                        'id' => 'install-plugin',
                        'title' => '1. Install DO Spaces Plugin (REQUIRED)',
                        'description' => 'CRITICAL: Install the DigitalOcean Spaces plugin FIRST.<br><br>Run these commands in your terminal:<br><code>composer require vaersaagod/dospaces<br>./craft plugin/install dospaces</code><br><br>Verify installation: Check that the plugin appears in Settings → Plugins',
                        'command' => null,
                        'duration' => '5-10 min',
                        'critical' => true,
                        'requiresArgs' => true,
                    ],
                    [
                        'id' => 'install-rclone',
                        'title' => '2. Install & Configure rclone (REQUIRED)',
                        'description' => 'CRITICAL: Install rclone for efficient file synchronization.<br><br>Install: Visit https://rclone.org/install/<br>Verify: <code>which rclone</code><br><br>Configure AWS remote:<br><code>' . $rcloneAwsConfigCommand . '</code><br><br>Configure DO remote:<br><code>' . $rcloneDoConfigCommand . '</code>',
                        'command' => null,
                        'duration' => '10-15 min',
                        'critical' => true,
                        'requiresArgs' => true,
                    ],
                    [
                        'id' => 'sync-files',
                        'title' => '3. Sync AWS → DO Files (REQUIRED)',
                        'description' => 'CRITICAL: Perform a FRESH synchronization of all files from AWS to DigitalOcean BEFORE starting migration.<br><br>Run this command in your terminal:<br><code>' . $rcloneCopyCommand . '</code><br><br>Verify sync completed:<br><code>' . $rcloneCheckCommand . '</code><br><br>This step ensures all files are available on DO before database migration.',
                        'command' => null,
                        'duration' => '1-4 hours',
                        'critical' => true,
                        'requiresArgs' => true,
                    ],
                    [
                        'id' => 'env-config',
                        'title' => '4. Configure Environment Variables',
                        'description' => 'Add these to your .env file:<br><code>MIGRATION_ENV=prod<br>AWS_SOURCE_BUCKET=your-aws-bucket<br>AWS_SOURCE_REGION=your-aws-region<br>DO_S3_ACCESS_KEY=your_do_access_key<br>DO_S3_SECRET_KEY=your_do_secret_key<br>DO_S3_BUCKET=your-bucket-name<br>DO_S3_BASE_URL=https://your-bucket.tor1.digitaloceanspaces.com<br>DO_S3_REGION=tor1</code><br><br>Copy migration config:<br><code>cp vendor/ncc/migration-module/modules/config/migration-config.php config/migration-config.php</code>',
                        'command' => null,
                        'duration' => '5 min',
                        'critical' => true,
                        'requiresArgs' => true,
                    ],
                    [
                        'id' => 'backup',
                        'title' => '5. Create Database Backup (REQUIRED)',
                        'description' => 'CRITICAL: Create a complete database backup before proceeding.<br><br>Run one of these commands:<br><code>./craft db/backup</code><br>Or with DDEV:<br><code>ddev export-db --file=backup-before-migration.sql.gz</code><br><br>Also backup config files:<br><code>tar -czf backup-files.tar.gz templates/ config/ modules/</code>',
                        'command' => null,
                        'duration' => '5-10 min',
                        'critical' => true,
                        'requiresArgs' => true,
                    ],
                ]
            ],
            [
                'id' => 'setup',
                'title' => 'Setup & Configuration',
                'phase' => 0,
                'icon' => 'settings',
                'modules' => [
                    [
                        'id' => 'filesystem',
                        'title' => 'Create DO Filesystems',
                        'description' => 'Create new DigitalOcean Spaces filesystem configurations in Craft CMS.',
                        'command' => 'filesystem/create',
                        'duration' => '15-30 min',
                        'critical' => true,
                    ],
                    [
                        'id' => 'filesystem-list',
                        'title' => 'List Filesystems',
                        'description' => 'View all configured filesystems in the system.',
                        'command' => 'filesystem/list',
                        'duration' => '1-2 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'filesystem-fix',
                        'title' => 'Fix DO Spaces Endpoints',
                        'description' => 'Fix endpoint configurations for DigitalOcean Spaces filesystems.',
                        'command' => 'filesystem-fix/fix-endpoints',
                        'duration' => '2-5 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'filesystem-show',
                        'title' => 'Show Filesystem Config',
                        'description' => 'Display current filesystem configurations.',
                        'command' => 'filesystem-fix/show',
                        'duration' => '1-2 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'volume-config-status',
                        'title' => 'Volume Configuration Status',
                        'description' => 'Show current volume configuration status.',
                        'command' => 'volume-config/status',
                        'duration' => '1-2 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'volume-config',
                        'title' => 'Configure All Volumes',
                        'description' => 'CRITICAL: Configure transform filesystem for ALL volumes. This prevents transform pollution and ensures proper file organization.<br><br>This will set the transform filesystem for all volumes to use the dedicated transform volume.',
                        'command' => 'volume-config/configure-all',
                        'duration' => '5-10 min',
                        'critical' => true,
                    ],
                    [
                        'id' => 'volume-config-quarantine',
                        'title' => 'Create Quarantine Volume',
                        'description' => 'Create quarantine volume for problematic assets.',
                        'command' => 'volume-config/create-quarantine-volume',
                        'duration' => '2-5 min',
                        'critical' => false,
                    ],
                ]
            ],
            [
                'id' => 'preflight',
                'title' => 'Pre-Flight Checks',
                'phase' => 1,
                'icon' => 'check',
                'modules' => [
                    [
                        'id' => 'migration-check',
                        'title' => 'Run Pre-Flight Checks',
                        'description' => 'Validate configuration and environment with 10 automated checks:<br>• DO Spaces plugin installed<br>• rclone available<br>• Fresh AWS → DO sync completed<br>• Transform filesystem configured<br>• Volume field layouts<br>• DO credentials valid<br>• AWS connectivity<br>• Database schema<br>• PHP environment<br>• File permissions',
                        'command' => 'migration-check/check',
                        'duration' => '5-10 min',
                        'critical' => true,
                    ],
                    [
                        'id' => 'migration-check-analyze',
                        'title' => 'Detailed Asset Analysis',
                        'description' => 'Show detailed analysis of assets before migration.',
                        'command' => 'migration-check/analyze',
                        'duration' => '5-10 min',
                        'critical' => false,
                    ],
                ]
            ],
            [
                'id' => 'url-replacement',
                'title' => 'URL Replacement',
                'phase' => 2,
                'icon' => 'refresh',
                'modules' => [
                    [
                        'id' => 'url-replacement-config',
                        'title' => 'Show URL Replacement Config',
                        'description' => 'Display current URL replacement configuration.',
                        'command' => 'url-replacement/show-config',
                        'duration' => '1-2 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'url-replacement',
                        'title' => 'Replace Database URLs',
                        'description' => 'Replace AWS URLs in content tables with DO URLs',
                        'command' => 'url-replacement/replace-s3-urls',
                        'duration' => '10-60 min',
                        'critical' => true,
                        'supportsDryRun' => true,
                    ],
                    [
                        'id' => 'url-replacement-verify',
                        'title' => 'Verify URL Replacement',
                        'description' => 'Verify that no AWS S3 URLs remain in the database.',
                        'command' => 'url-replacement/verify',
                        'duration' => '5-10 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'extended-url-scan',
                        'title' => 'Scan Additional Tables',
                        'description' => 'Scan additional database tables for AWS S3 URLs.',
                        'command' => 'extended-url-replacement/scan-additional',
                        'duration' => '5-10 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'extended-url',
                        'title' => 'Replace URLs in Additional Tables',
                        'description' => 'Replace URLs in additional tables.',
                        'command' => 'extended-url-replacement/replace-additional',
                        'duration' => '10-30 min',
                        'critical' => false,
                        'supportsDryRun' => true,
                    ],
                    [
                        'id' => 'extended-url-json',
                        'title' => 'Replace URLs in JSON Fields',
                        'description' => 'Replace URLs in JSON fields.',
                        'command' => 'extended-url-replacement/replace-json',
                        'duration' => '10-30 min',
                        'critical' => false,
                        'supportsDryRun' => true,
                    ],
                ]
            ],
            [
                'id' => 'templates',
                'title' => 'Template Updates',
                'phase' => 3,
                'icon' => 'code',
                'modules' => [
                    [
                        'id' => 'template-scan',
                        'title' => 'Scan Templates',
                        'description' => 'Scan Twig templates for hardcoded AWS URLs',
                        'command' => 'template-url-replacement/scan',
                        'duration' => '5-10 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'template-replace',
                        'title' => 'Replace Template URLs',
                        'description' => 'Replace hardcoded URLs with environment variables',
                        'command' => 'template-url-replacement/replace',
                        'duration' => '5-15 min',
                        'critical' => false,
                        'supportsDryRun' => true,
                    ],
                    [
                        'id' => 'template-verify',
                        'title' => 'Verify Template Updates',
                        'description' => 'Verify that no AWS URLs remain in templates.',
                        'command' => 'template-url-replacement/verify',
                        'duration' => '2-5 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'template-restore',
                        'title' => 'Restore Template Backups',
                        'description' => 'Restore templates from backups if needed.',
                        'command' => 'template-url-replacement/restore-backups',
                        'duration' => '2-5 min',
                        'critical' => false,
                    ],
                ]
            ],
            [
                'id' => 'migration',
                'title' => 'File Migration',
                'phase' => 4,
                'icon' => 'upload',
                'modules' => [
                    [
                        'id' => 'image-migration-status',
                        'title' => 'Migration Status',
                        'description' => 'List available checkpoints and migrations.',
                        'command' => 'image-migration/status',
                        'duration' => '1-2 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'image-migration',
                        'title' => 'Migrate Files',
                        'description' => 'Copy all files from AWS S3 to DigitalOcean Spaces with checkpoint/resume support.',
                        'command' => 'image-migration/migrate',
                        'duration' => '1-48 hours',
                        'critical' => true,
                        'supportsDryRun' => true,
                        'supportsResume' => true,
                    ],
                    [
                        'id' => 'image-migration-monitor',
                        'title' => 'Monitor Migration',
                        'description' => 'Monitor migration progress in real-time.',
                        'command' => 'image-migration/monitor',
                        'duration' => 'Continuous',
                        'critical' => false,
                    ],
                    [
                        'id' => 'image-migration-cleanup',
                        'title' => 'Cleanup Checkpoints',
                        'description' => 'Cleanup old checkpoints and logs after successful migration.',
                        'command' => 'image-migration/cleanup',
                        'duration' => '2-5 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'image-migration-force-cleanup',
                        'title' => 'Force Cleanup',
                        'description' => 'Force cleanup - removes ALL locks and old data. Use with caution!',
                        'command' => 'image-migration/force-cleanup',
                        'duration' => '2-5 min',
                        'critical' => false,
                    ],
                ]
            ],
            [
                'id' => 'switch',
                'title' => 'Filesystem Switch',
                'phase' => 5,
                'icon' => 'transfer',
                'modules' => [
                    [
                        'id' => 'switch-list',
                        'title' => 'List Filesystems',
                        'description' => 'List all filesystems defined in Project Config.',
                        'command' => 'filesystem-switch/list-filesystems',
                        'duration' => '1-2 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'switch-test',
                        'title' => 'Test Connectivity',
                        'description' => 'Test connectivity to all filesystems defined in Project Config.',
                        'command' => 'filesystem-switch/test-connectivity',
                        'duration' => '2-5 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'switch-preview',
                        'title' => 'Preview Switch',
                        'description' => 'Preview what will be changed (dry run).',
                        'command' => 'filesystem-switch/preview',
                        'duration' => '1-2 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'switch-to-do',
                        'title' => 'Switch to DO',
                        'description' => 'Switch all volumes to use DigitalOcean Spaces.',
                        'command' => 'filesystem-switch/to-do',
                        'duration' => '2-5 min',
                        'critical' => true,
                    ],
                    [
                        'id' => 'switch-verify',
                        'title' => 'Verify Filesystem Setup',
                        'description' => 'Verify current filesystem setup after switching.',
                        'command' => 'filesystem-switch/verify',
                        'duration' => '2-5 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'switch-to-aws',
                        'title' => 'Rollback to AWS',
                        'description' => 'Rollback to AWS S3 (use if migration fails).',
                        'command' => 'filesystem-switch/to-aws',
                        'duration' => '2-5 min',
                        'critical' => false,
                    ],
                ]
            ],
            [
                'id' => 'validation',
                'title' => 'Validation',
                'phase' => 6,
                'icon' => 'check-circle',
                'modules' => [
                    [
                        'id' => 'migration-diag',
                        'title' => 'Analyze Migration State',
                        'description' => 'Analyze current state after migration.',
                        'command' => 'migration-diag/analyze',
                        'duration' => '10-30 min',
                        'critical' => true,
                    ],
                    [
                        'id' => 'migration-diag-missing',
                        'title' => 'Check Missing Files',
                        'description' => 'Check for missing files that caused errors during migration.',
                        'command' => 'migration-diag/check-missing-files',
                        'duration' => '5-15 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'migration-diag-move',
                        'title' => 'Move Originals to Images',
                        'description' => 'Move assets from /originals to /images folder.',
                        'command' => 'migration-diag/move-originals',
                        'duration' => '10-30 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'post-migration-commands',
                        'title' => 'Post-Migration Commands (REQUIRED)',
                        'description' => 'CRITICAL: Run these commands IN ORDER after migration:<br><br>1. Rebuild asset indexes:<br><code>./craft index-assets/all</code><br><br>2. Rebuild search indexes:<br><code>./craft resave/entries --update-search-index=1</code><br><br>3. Resave all assets:<br><code>./craft resave/assets</code><br><br>4. Clear all Craft caches:<br><code>./craft clear-caches/all</code><br><code>./craft invalidate-tags/all</code><br><code>./craft clear-caches/template-caches</code><br><br>5. Purge CDN cache manually:<br>• CloudFlare: Dashboard → Caching → Purge Everything<br>• Fastly: Dashboard → Purge → Purge All<br><br>These steps are ESSENTIAL for proper site functionality!',
                        'command' => null,
                        'duration' => '15-30 min',
                        'critical' => true,
                        'requiresArgs' => true,
                    ],
                ]
            ],
            [
                'id' => 'transforms',
                'title' => 'Image Transforms',
                'phase' => 7,
                'icon' => 'image',
                'modules' => [
                    [
                        'id' => 'add-optimised-field',
                        'title' => 'Add optimisedImagesField (REQUIRED FIRST)',
                        'description' => 'CRITICAL: Add optimisedImagesField to Images (DO) volume BEFORE generating transforms.<br><br>Run in terminal:<br><code>./craft s3-spaces-migration/volume-config/add-optimised-field images</code><br><br>Or add manually via CP:<br>1. Settings → Assets → Volumes<br>2. Click "Images (DO)"<br>3. Go to "Field Layout" tab<br>4. In "Content" tab, click "+ Add field"<br>5. Select "optimisedImagesField"<br>6. Save<br><br>This ensures transforms are correctly generated and prevents errors.',
                        'command' => null,
                        'duration' => '2-5 min',
                        'critical' => true,
                        'requiresArgs' => true,
                    ],
                    [
                        'id' => 'transform-discovery-all',
                        'title' => 'Discover ALL Transforms',
                        'description' => 'Discover all transforms in both database and templates.',
                        'command' => 'transform-discovery/discover',
                        'duration' => '10-30 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'transform-discovery-db',
                        'title' => 'Scan Database Only',
                        'description' => 'Scan only database for transforms.',
                        'command' => 'transform-discovery/scan-database',
                        'duration' => '5-15 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'transform-discovery-templates',
                        'title' => 'Scan Templates Only',
                        'description' => 'Scan only Twig templates for transforms.',
                        'command' => 'transform-discovery/scan-templates',
                        'duration' => '5-15 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'transform-pregeneration-discover',
                        'title' => 'Discover Image Transforms',
                        'description' => 'Discover all image transforms being used in the database.',
                        'command' => 'transform-pre-generation/discover',
                        'duration' => '10-30 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'transform-pregeneration',
                        'title' => 'Generate Transforms',
                        'description' => 'Generate transforms based on discovery report.',
                        'command' => 'transform-pre-generation/generate',
                        'duration' => '30 min - 6 hours',
                        'critical' => false,
                    ],
                    [
                        'id' => 'transform-pregeneration-verify',
                        'title' => 'Verify Transforms',
                        'description' => 'Verify that transforms exist for all discovered references.',
                        'command' => 'transform-pre-generation/verify',
                        'duration' => '10-30 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'transform-pregeneration-warmup',
                        'title' => 'Warmup Transforms',
                        'description' => 'Warm up transforms by visiting pages (simulates real traffic).',
                        'command' => 'transform-pre-generation/warmup',
                        'duration' => '30 min - 2 hours',
                        'critical' => false,
                    ],
                ]
            ],
            [
                'id' => 'audit',
                'title' => 'Audit & Diagnostics',
                'phase' => 8,
                'icon' => 'search',
                'modules' => [
                    [
                        'id' => 'plugin-config-audit-list',
                        'title' => 'List Installed Plugins',
                        'description' => 'List all installed plugins in the system.',
                        'command' => 'plugin-config-audit/list-plugins',
                        'duration' => '1-2 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'plugin-config-audit',
                        'title' => 'Scan Plugin Configurations',
                        'description' => 'Scan plugin configurations for hardcoded AWS S3 URLs.',
                        'command' => 'plugin-config-audit/scan',
                        'duration' => '5-15 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'static-asset-scan',
                        'title' => 'Scan Static Assets',
                        'description' => 'Scan JS/CSS/SCSS files for hardcoded AWS S3 URLs.',
                        'command' => 'static-asset-scan/scan',
                        'duration' => '5-15 min',
                        'critical' => false,
                    ],
                    [
                        'id' => 'fs-diag-list',
                        'title' => 'List Filesystem Files',
                        'description' => 'List files in a filesystem by handle (NO VOLUME REQUIRED).<br>Requires filesystem handle argument.',
                        'command' => 'fs-diag/list-fs',
                        'duration' => '5-10 min',
                        'critical' => false,
                        'requiresArgs' => true,
                    ],
                    [
                        'id' => 'fs-diag-compare',
                        'title' => 'Compare Filesystems',
                        'description' => 'Compare two filesystems to find differences.<br>Requires two filesystem handles as arguments.',
                        'command' => 'fs-diag/compare-fs',
                        'duration' => '10-30 min',
                        'critical' => false,
                        'requiresArgs' => true,
                    ],
                    [
                        'id' => 'fs-diag-search',
                        'title' => 'Search Filesystem',
                        'description' => 'Search for specific files in a filesystem by handle.<br>Requires filesystem handle and search pattern.',
                        'command' => 'fs-diag/search-fs',
                        'duration' => '5-15 min',
                        'critical' => false,
                        'requiresArgs' => true,
                    ],
                    [
                        'id' => 'fs-diag-verify',
                        'title' => 'Verify File Exists',
                        'description' => 'Verify if specific file exists in filesystem.<br>Requires filesystem handle and file path.',
                        'command' => 'fs-diag/verify-fs',
                        'duration' => '1-5 min',
                        'critical' => false,
                        'requiresArgs' => true,
                    ],
                ]
            ],
        ];

        // Ensure all modules have consistent keys
        foreach ($definitions as &$phase) {
            if (isset($phase['modules'])) {
                foreach ($phase['modules'] as &$module) {
                    // Set default values for optional keys
                    $module['supportsDryRun'] = $module['supportsDryRun'] ?? false;
                    $module['supportsResume'] = $module['supportsResume'] ?? false;
                    $module['requiresArgs'] = $module['requiresArgs'] ?? false;
                }
            }
        }

        return $definitions;
    }

    /**
     * Get allowed console commands
     */
    private function getAllowedCommands(): array
    {
        return [
            // extended-url-replacement
            'extended-url-replacement/replace-additional',
            'extended-url-replacement/replace-json',
            'extended-url-replacement/scan-additional',

            // filesystem
            'filesystem/create',
            'filesystem/delete',
            'filesystem/list',

            // filesystem-fix
            'filesystem-fix/fix-endpoints',
            'filesystem-fix/show',

            // filesystem-switch
            'filesystem-switch/list-filesystems',
            'filesystem-switch/preview',
            'filesystem-switch/test-connectivity',
            'filesystem-switch/to-aws',
            'filesystem-switch/to-do',
            'filesystem-switch/verify',

            // fs-diag
            'fs-diag/compare-fs',
            'fs-diag/list-fs',
            'fs-diag/search-fs',
            'fs-diag/verify-fs',

            // image-migration
            'image-migration/cleanup',
            'image-migration/force-cleanup',
            'image-migration/migrate',
            'image-migration/monitor',
            'image-migration/rollback',
            'image-migration/status',

            // migration-check
            'migration-check/analyze',
            'migration-check/check',

            // migration-diag
            'migration-diag/analyze',
            'migration-diag/check-missing-files',
            'migration-diag/move-originals',

            // plugin-config-audit
            'plugin-config-audit/list-plugins',
            'plugin-config-audit/scan',

            // static-asset-scan
            'static-asset-scan/scan',

            // template-url-replacement
            'template-url-replacement/replace',
            'template-url-replacement/restore-backups',
            'template-url-replacement/scan',
            'template-url-replacement/verify',

            // transform-discovery
            'transform-discovery/discover',
            'transform-discovery/scan-database',
            'transform-discovery/scan-templates',

            // transform-pre-generation
            'transform-pre-generation/discover',
            'transform-pre-generation/generate',
            'transform-pre-generation/verify',
            'transform-pre-generation/warmup',

            // url-replacement
            'url-replacement/replace-s3-urls',
            'url-replacement/show-config',
            'url-replacement/verify',

            // volume-config
            'volume-config/add-optimised-field',
            'volume-config/configure-all',
            'volume-config/create-quarantine-volume',
            'volume-config/set-transform-filesystem',
            'volume-config/status',
        ];
    }

    /**
     * Get last N lines of a file
     */
    private function getTailOfFile(string $filepath, int $lines = 100): array
    {
        if (!file_exists($filepath)) {
            return [];
        }

        $file = new \SplFileObject($filepath, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $lines = min($lines, $lastLine);

        $result = [];
        for ($i = $lastLine - $lines; $i <= $lastLine; $i++) {
            $file->seek($i);
            $line = $file->current();
            if (trim($line)) {
                $result[] = $line;
            }
        }

        return $result;
    }
}
