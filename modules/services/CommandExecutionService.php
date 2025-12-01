<?php

namespace csabourin\spaghettiMigrator\services;

use Craft;
use yii\web\Response;

/**
 * Command Execution Service
 *
 * Handles console command execution and streaming for migration operations.
 * Extracted from MigrationController to improve separation of concerns.
 */
class CommandExecutionService
{

    /**
     * Allowed console commands that can be executed
     */
    private const ALLOWED_COMMANDS = [
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

        // transform-cleanup
        'transform-cleanup/clean',

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

        // missing-file-fix
        'missing-file-fix/analyze',
        'missing-file-fix/fix',

        // volume-consolidation
        'volume-consolidation/merge-optimized-to-images',
        'volume-consolidation/flatten-to-root',
        'volume-consolidation/status',

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

    private ProcessManager $processManager;
    private CommandBuilder $commandBuilder;

    public function __construct(?ProcessManager $processManager = null, ?CommandBuilder $commandBuilder = null)
    {
        $this->processManager = $processManager ?? new ProcessManager();
        $this->commandBuilder = $commandBuilder ?? new CommandBuilder();
    }

    /**
     * Get list of allowed console commands
     */
    public function getAllowedCommands(): array
    {
        return self::ALLOWED_COMMANDS;
    }

    /**
     * Validate if a command is allowed to be executed
     */
    public function isCommandAllowed(string $command): bool
    {
        return in_array($command, self::ALLOWED_COMMANDS, true);
    }

    /**
     * Execute a console command and return output
     */
    public function executeConsoleCommand(string $command, array $args = []): array
    {
        // Automatically add --yes flag for web automation if command supports it
        if ($this->commandSupportsYes($command) && !isset($args['yes'])) {
            $args['yes'] = true;
        }

        $commandParts = $this->buildCommandParts($command, $args);

        Craft::info(
            'Executing console command: ' . $this->redactSensitiveData($this->stringifyCommand($commandParts)),
            __METHOD__
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($commandParts, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start console command process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $output = trim($stdout . (empty($stderr) ? '' : "\n{$stderr}"));

        Craft::info("Command exit code: {$exitCode}", __METHOD__);
        if ($exitCode !== 0) {
            Craft::warning("Command failed with output: {$output}", __METHOD__);
        }

        return [
            'output' => $output,
            'exitCode' => $exitCode,
        ];
    }

    /**
     * Stream console command output in realtime using Server-Sent Events (SSE)
     */
    public function streamConsoleCommand(string $command, array $args = []): Response
    {
        if ($this->commandSupportsYes($command) && !isset($args['yes'])) {
            $args['yes'] = true;
        }

        $commandParts = $this->buildCommandParts($command, $args);

        Craft::info(
            'Streaming console command: ' . $this->redactSensitiveData($this->stringifyCommand($commandParts)),
            __METHOD__
        );

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        $response->stream = function() use ($commandParts, $command) {
            $this->executeStreamingCommand($commandParts, $command);
        };

        return $response;
    }

    /**
     * Build command array for console execution without shell expansion
     */
    private function buildCommandParts(string $command, array $args): array
    {
        $command = trim($command);
        if ($command === '') {
            throw new \InvalidArgumentException('Command cannot be empty');
        }

        $commandParts = [
            Craft::getAlias('@root/craft'),
            $command,
        ];

        foreach ($args as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Argument keys must be strings');
            }

            if (!preg_match('/^[A-Za-z][\w-]*$/', $key)) {
                throw new \InvalidArgumentException("Invalid argument name: {$key}");
            }

            // Only add --dry-run flag when explicitly requested for dry-run mode
            if ($key === 'dryRun') {
                if ($value === true || $value === '1' || $value === 1) {
                    $commandParts[] = '--dry-run=1';
                }
                // Skip if false/0 - let command use its default behavior
                continue;
            }

            // Skip false, empty string, '0', and 0 values for other parameters
            if ($value === false || $value === '' || $value === '0' || $value === 0) {
                continue;
            }

            // For boolean true, just add the flag without a value
            if ($value === true || $value === '1' || $value === 1) {
                $commandParts[] = "--{$key}";
            } else {
                // For other values, add key=value
                $commandParts[] = "--{$key}=" . (is_scalar($value) ? (string) $value : json_encode($value));
            }
        }

        return $commandParts;
    }

    /**
     * Convert a command parts array to a safe loggable string
     */
    private function stringifyCommand(array $commandParts): string
    {
        return implode(' ', array_map(static function($part) {
            return escapeshellarg((string) $part);
        }, $commandParts));
    }

    /**
     * Redact sensitive data from command strings before logging
     *
     * @param string $command The command string to redact
     * @return string The redacted command string
     */
    private function redactSensitiveData(string $command): string
    {
        $patterns = [
            // Password parameters
            '/--password[= ][^\s\'"]*/i' => '--password=***',
            '/--pass[= ][^\s\'"]*/i' => '--pass=***',
            '/\'--password=[^\']*\'/i' => '\'--password=***\'',
            '/\'--pass=[^\']*\'/i' => '\'--pass=***\'',

            // Secret and key parameters
            '/--secret[= ][^\s\'"]*/i' => '--secret=***',
            '/--key[= ][^\s\'"]*/i' => '--key=***',
            '/--secret-?key[= ][^\s\'"]*/i' => '--secret-key=***',
            '/--access-?key[= ][^\s\'"]*/i' => '--access-key=***',
            '/\'--secret=[^\']*\'/i' => '\'--secret=***\'',
            '/\'--key=[^\']*\'/i' => '\'--key=***\'',

            // Token parameters
            '/--token[= ][^\s\'"]*/i' => '--token=***',
            '/--api-?token[= ][^\s\'"]*/i' => '--api-token=***',
            '/\'--token=[^\']*\'/i' => '\'--token=***\'',

            // AWS credentials
            '/AWS_ACCESS_KEY_ID=[^\s\'"]*/i' => 'AWS_ACCESS_KEY_ID=***',
            '/AWS_SECRET_ACCESS_KEY=[^\s\'"]*/i' => 'AWS_SECRET_ACCESS_KEY=***',
            '/\'AWS_ACCESS_KEY_ID=[^\']*\'/i' => '\'AWS_ACCESS_KEY_ID=***\'',
            '/\'AWS_SECRET_ACCESS_KEY=[^\']*\'/i' => '\'AWS_SECRET_ACCESS_KEY=***\'',

            // DigitalOcean credentials
            '/DO_S3_ACCESS_KEY=[^\s\'"]*/i' => 'DO_S3_ACCESS_KEY=***',
            '/DO_S3_SECRET_KEY=[^\s\'"]*/i' => 'DO_S3_SECRET_KEY=***',
            '/\'DO_S3_ACCESS_KEY=[^\']*\'/i' => '\'DO_S3_ACCESS_KEY=***\'',
            '/\'DO_S3_SECRET_KEY=[^\']*\'/i' => '\'DO_S3_SECRET_KEY=***\'',

            // Generic credentials in environment variables
            '/_KEY=[^\s\'"]*/i' => '_KEY=***',
            '/_SECRET=[^\s\'"]*/i' => '_SECRET=***',
            '/_PASSWORD=[^\s\'"]*/i' => '_PASSWORD=***',
            '/_TOKEN=[^\s\'"]*/i' => '_TOKEN=***',
        ];

        $redacted = $command;
        foreach ($patterns as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $redacted);
        }

        return $redacted;
    }

    /**
     * Determine whether a command supports the --yes flag.
     *
     * This method checks the module definitions to see if the command requires
     * confirmation (--yes flag) when executed from the web interface.
     */
    public function commandSupportsYes(string $command): bool
    {
        return $this->commandBuilder->commandRequiresYes($command);
    }

    /**
     * Normalize a command to the base module/action format without prefixes.
     */
    private static function normalizeCommand(string $command): string
    {
        $command = ltrim($command, '/');

        if (str_starts_with($command, 'spaghetti-migrator/')) {
            $command = substr($command, strlen('spaghetti-migrator/'));
        }

        return $command;
    }

    /**
     * Execute streaming command with SSE output
     */
    private function executeStreamingCommand(array $commandParts, string $command): void
    {
        $flush = static function(): void {
            while (ob_get_level() > 0) {
                if (@ob_end_flush() === false) {
                    break;
                }
            }

            flush();
        };

        set_time_limit(0);
        ignore_user_abort(true);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($commandParts, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            echo "event: error\ndata: " . json_encode(['error' => 'Failed to start command']) . "\n\n";
            echo "event: complete\ndata: " . json_encode([
                'success' => false,
                'exitCode' => null,
                'cancelled' => false,
                'output' => '',
            ]) . "\n\n";
            $flush();
            return;
        }

        $completeEmitted = false;
        $processClosed = false;
        $sessionId = $this->processManager->getSessionIdentifier();
        $status = proc_get_status($process);
        $lastStatus = $status;
        $pid = $status['pid'];

        $this->processManager->registerProcess($sessionId, $command, $pid);

        Craft::info("Started process with PID {$pid} for command: {$command}", __METHOD__);

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        echo "event: start\ndata: " . json_encode(['message' => 'Command started', 'pid' => $pid]) . "\n\n";
        $flush();

        $buffer = '';
        $errorBuffer = '';
        $outputLines = [];
        $lastHeartbeat = microtime(true);

        try {
            while (true) {
                $status = proc_get_status($process);
                $lastStatus = $status;

                // Check for cancellation
                if ($this->processManager->isCancelled($sessionId, $command)) {
                    Craft::info("Cancellation detected for command: {$command}", __METHOD__);
                    proc_terminate($process, 15);

                    echo "event: cancelled\ndata: " . json_encode(['message' => 'Command cancellation requested']) . "\n\n";
                    $flush();

                    $waitStart = microtime(true);
                    while (microtime(true) - $waitStart < 3) {
                        $status = proc_get_status($process);
                        $lastStatus = $status;
                        if (!$status['running']) {
                            break;
                        }
                        usleep(100000);
                    }

                    $status = proc_get_status($process);
                    $lastStatus = $status;
                    if ($status['running']) {
                        Craft::warning("Process {$pid} did not terminate gracefully, sending SIGKILL", __METHOD__);
                        proc_terminate($process, 9);
                        usleep(500000);
                    }

                    break;
                }

                // Read stdout
                if (!feof($pipes[1])) {
                    $chunk = fread($pipes[1], 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $buffer .= $chunk;

                        while (($pos = strpos($buffer, "\n")) !== false) {
                            $line = substr($buffer, 0, $pos);
                            $buffer = substr($buffer, $pos + 1);
                            $outputLines[] = $line;

                            echo "event: output\ndata: " . json_encode(['line' => $line]) . "\n\n";
                            $flush();
                        }

                        $lastHeartbeat = microtime(true);
                    }
                }

                // Read stderr
                if (!feof($pipes[2])) {
                    $chunk = fread($pipes[2], 8192);
                    if ($chunk !== false && $chunk !== '') {
                        $errorBuffer .= $chunk;

                        while (($pos = strpos($errorBuffer, "\n")) !== false) {
                            $line = substr($errorBuffer, 0, $pos);
                            $errorBuffer = substr($errorBuffer, $pos + 1);
                            $outputLines[] = $line;

                            echo "event: output\ndata: " . json_encode(['line' => $line, 'type' => 'error']) . "\n\n";
                            $flush();
                        }

                        $lastHeartbeat = microtime(true);
                    }
                }

                if (!$status['running']) {
                    break;
                }

                if (microtime(true) - $lastHeartbeat >= 5) {
                    echo ": keep-alive\n\n";
                    $flush();
                    $lastHeartbeat = microtime(true);
                }

                usleep(50000);
            }

            // Read remaining output
            $buffer .= stream_get_contents($pipes[1]);
            $errorBuffer .= stream_get_contents($pipes[2]);

            foreach (explode("\n", $buffer) as $line) {
                if ($line !== '') {
                    $outputLines[] = $line;
                    echo "event: output\ndata: " . json_encode(['line' => $line]) . "\n\n";
                    $flush();
                }
            }

            foreach (explode("\n", $errorBuffer) as $line) {
                if ($line !== '') {
                    $outputLines[] = $line;
                    echo "event: output\ndata: " . json_encode(['line' => $line, 'type' => 'error']) . "\n\n";
                    $flush();
                }
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            $finalExitCode = $this->determineExitCode($process, $lastStatus, $outputLines);

            $closeResult = proc_close($process);
            $processClosed = true;

            Craft::info("proc_close result: " . var_export($closeResult, true), __METHOD__);

            // Use proc_close result if it's valid and we don't have an exit code yet
            if ($finalExitCode === null && $closeResult !== -1) {
                $finalExitCode = $closeResult;
                Craft::info("Exit code from proc_close: {$finalExitCode}", __METHOD__);
            } elseif ($finalExitCode === null && isset($lastStatus['exitcode']) && $lastStatus['exitcode'] !== -1) {
                $finalExitCode = (int) $lastStatus['exitcode'];
                Craft::info("Exit code from lastStatus: {$finalExitCode}", __METHOD__);
            }

            $exitCode = $finalExitCode ?? -1;
            $success = ($exitCode === 0);

            $wasCancelled = $this->processManager->isCancelled($sessionId, $command);
            $this->processManager->unregisterProcess($sessionId, $command);

            Craft::info("Process {$pid} for command {$command} completed. Exit code: {$exitCode}, Success: " . ($success ? 'true' : 'false') . ", Cancelled: " . ($wasCancelled ? 'true' : 'false'), __METHOD__);

            $completeData = [
                'success' => $success,
                'exitCode' => $exitCode,
                'cancelled' => $wasCancelled,
                'output' => implode("\n", $outputLines),
            ];

            echo "event: complete\ndata: " . json_encode($completeData) . "\n\n";
            $flush();
            $completeEmitted = true;

            Craft::info("Sent complete event: " . json_encode($completeData), __METHOD__);

            if ($exitCode !== 0 && !$wasCancelled) {
                Craft::warning("Command failed with output: " . implode("\n", $outputLines), __METHOD__);
            }
        } catch (\Throwable $e) {
            Craft::error('Error streaming command: ' . $e->getMessage(), __METHOD__);
            Craft::error($e->getTraceAsString(), __METHOD__);

            echo "event: error\ndata: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
            $flush();

            if (!$completeEmitted) {
                $wasCancelled = $this->processManager->isCancelled($sessionId, $command);

                echo "event: complete\ndata: " . json_encode([
                    'success' => false,
                    'exitCode' => null,
                    'cancelled' => $wasCancelled,
                    'output' => implode("\n", $outputLines),
                ]) . "\n\n";
                $flush();
                $completeEmitted = true;
            }
        } finally {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }

            if (!$processClosed && isset($process) && is_resource($process)) {
                proc_close($process);
            }

            $this->processManager->unregisterProcess($sessionId, $command);
        }
    }

    /**
     * Determine exit code from process status and output
     */
    private function determineExitCode($process, array $lastStatus, array $outputLines): ?int
    {
        $finalExitCode = null;
        $statusWaitStart = microtime(true);

        // Wait up to 2 seconds for exit code to become available
        while (microtime(true) - $statusWaitStart < 2.0) {
            $status = proc_get_status($process);
            if ($status === false) {
                Craft::warning("proc_get_status returned false during exit code detection", __METHOD__);
                break;
            }

            if (isset($status['exitcode']) && $status['exitcode'] !== -1) {
                $finalExitCode = (int) $status['exitcode'];
                Craft::info("Exit code captured from proc_get_status: {$finalExitCode}", __METHOD__);
                break;
            }

            if (!$status['running']) {
                usleep(50000);
                continue;
            }

            usleep(50000);
        }

        // Check for machine-readable exit markers (most reliable)
        if ($finalExitCode === null || $finalExitCode === -1) {
            $outputText = implode("\n", $outputLines);

            // Priority 1: Check for machine-readable exit markers
            if (strpos($outputText, '__CLI_EXIT_CODE_0__') !== false) {
                $finalExitCode = 0;
                Craft::info("Detected machine-readable success marker: __CLI_EXIT_CODE_0__", __METHOD__);
            } elseif (strpos($outputText, '__CLI_EXIT_CODE_1__') !== false) {
                $finalExitCode = 1;
                Craft::warning("Detected machine-readable error marker: __CLI_EXIT_CODE_1__", __METHOD__);
            }
            // Priority 2: Fallback to output pattern matching for commands without markers
            elseif ($finalExitCode === -1) {
                Craft::warning("No machine-readable marker found, attempting to infer from output patterns", __METHOD__);

                // Check for common success patterns in Craft CMS console commands
                $hasSuccessIndicators = (
                    stripos($outputText, 'Done') !== false ||
                    stripos($outputText, 'Success') !== false ||
                    stripos($outputText, 'COMPLETE') !== false ||
                    stripos($outputText, 'completed successfully') !== false ||
                    stripos($outputText, 'Filesystem successfully switched') !== false ||
                    stripos($outputText, 'successfully') !== false ||
                    strpos($outputText, '✓') !== false ||
                    stripos($outputText, 'All volumes on') !== false
                );

                // Check for error patterns (be specific to avoid false positives)
                $hasErrorIndicators = (
                    stripos($outputText, 'Error:') !== false ||
                    stripos($outputText, 'Exception:') !== false ||
                    stripos($outputText, 'Fatal error') !== false ||
                    preg_match('/\b(command|operation|process)\s+(failed|error)/i', $outputText) ||
                    stripos($outputText, 'could not') !== false ||
                    stripos($outputText, 'unable to') !== false
                );

                Craft::warning("Has success indicators: " . ($hasSuccessIndicators ? 'yes' : 'no') . ", Has error indicators: " . ($hasErrorIndicators ? 'yes' : 'no'), __METHOD__);

                // If output suggests success and no errors, assume exit code 0
                if ($hasSuccessIndicators && !$hasErrorIndicators) {
                    $finalExitCode = 0;
                    Craft::info("Inferred successful exit code (0) from output indicators", __METHOD__);
                }
            }

            // Priority 3: Still no exit code? Default to error
            if ($finalExitCode === null || $finalExitCode === -1) {
                $finalExitCode = -1;
                Craft::warning("Could not determine exit code from any method, defaulting to -1", __METHOD__);
            }
        }

        return $finalExitCode;
    }
}
