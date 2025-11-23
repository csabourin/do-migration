<?php

namespace csabourin\spaghettiMigrator\services;

use Craft;

/**
 * Process Manager
 *
 * Manages running console command processes and their cancellation state.
 * Extracted from MigrationController to improve separation of concerns.
 */
class ProcessManager
{
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get session identifier for process tracking
     */
    public function getSessionIdentifier(): string
    {
        $session = Craft::$app->getSession();
        $wasActive = $session->getIsActive();

        if (!$wasActive) {
            $session->open();
        }

        $id = (string) $session->getId();

        if (!$wasActive) {
            $session->close();
        }

        if ($id === '') {
            $id = 'anonymous';
        }

        return $id;
    }

    /**
     * Register a running process
     */
    public function registerProcess(string $sessionId, string $command, int $pid): void
    {
        $runningProcesses = $this->loadRunningProcesses($sessionId);

        $runningProcesses[$command] = [
            'pid' => $pid,
            'startTime' => time(),
            'command' => $command,
            'cancelled' => false,
        ];

        $this->storeRunningProcesses($sessionId, $runningProcesses);
    }

    /**
     * Unregister a running process
     */
    public function unregisterProcess(string $sessionId, string $command): void
    {
        $runningProcesses = $this->loadRunningProcesses($sessionId);
        unset($runningProcesses[$command]);
        $this->storeRunningProcesses($sessionId, $runningProcesses);
    }

    /**
     * Check if a command is cancelled
     */
    public function isCancelled(string $sessionId, string $command): bool
    {
        $runningProcesses = $this->loadRunningProcesses($sessionId);

        return !isset($runningProcesses[$command]) ||
               ($runningProcesses[$command]['cancelled'] ?? false);
    }

    /**
     * Cancel a running command
     */
    public function cancelCommand(string $sessionId, string $command): array
    {
        $runningProcesses = $this->loadRunningProcesses($sessionId);

        if (!isset($runningProcesses[$command])) {
            return [
                'success' => false,
                'error' => 'Command is not currently running',
            ];
        }

        $processInfo = $runningProcesses[$command];
        $pid = $processInfo['pid'];

        Craft::info("Cancellation requested for command: {$command} (PID: {$pid})", __METHOD__);

        $runningProcesses[$command]['cancelled'] = true;
        $this->storeRunningProcesses($sessionId, $runningProcesses);

        return [
            'success' => true,
            'message' => 'Cancellation signal sent. Process will terminate shortly.',
            'pid' => $pid,
        ];
    }

    /**
     * Get all running processes for a session
     */
    public function getRunningProcesses(string $sessionId): array
    {
        return $this->loadRunningProcesses($sessionId);
    }

    /**
     * Load running processes from cache
     */
    private function loadRunningProcesses(string $sessionId): array
    {
        $cache = Craft::$app->getCache();

        if ($cache === null) {
            return [];
        }

        $data = $cache->get($this->getProcessStoreKey($sessionId));

        return is_array($data) ? $data : [];
    }

    /**
     * Store running processes to cache
     */
    private function storeRunningProcesses(string $sessionId, array $processes): void
    {
        $cache = Craft::$app->getCache();

        if ($cache === null) {
            return;
        }

        $key = $this->getProcessStoreKey($sessionId);

        if ($processes === []) {
            $cache->delete($key);
            return;
        }

        $cache->set($key, $processes, self::CACHE_TTL);
    }

    /**
     * Get cache key for process storage
     */
    private function getProcessStoreKey(string $sessionId): string
    {
        return 's3-spaces-migration:processes:' . $sessionId;
    }
}
