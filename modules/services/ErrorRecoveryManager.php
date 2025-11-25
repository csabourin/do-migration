<?php

namespace csabourin\spaghettiMigrator\services;

/**
 * Error Recovery Manager
 * Provides retry logic and error handling for migration operations
 */
class ErrorRecoveryManager
{
    private $maxRetries;
    private $retryDelay;
    private $retryCount = [];
    private $totalRetriesHistorical = 0;
    private $operationsRetriedHistorical = [];

    public function __construct($maxRetries = 3, $retryDelay = 1000)
    {
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $retryDelay;
    }

    public function retryOperation(callable $operation, $operationId)
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $result = $operation();

                // Reset current retry count on success (but keep historical count)
                if (isset($this->retryCount[$operationId])) {
                    unset($this->retryCount[$operationId]);
                }

                return $result;

            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;

                // Track retries (both current and historical)
                if (!isset($this->retryCount[$operationId])) {
                    $this->retryCount[$operationId] = 0;
                }
                $this->retryCount[$operationId]++;

                // Track historical totals
                $this->totalRetriesHistorical++;
                if (!in_array($operationId, $this->operationsRetriedHistorical)) {
                    $this->operationsRetriedHistorical[] = $operationId;
                }

                // Don't retry fatal errors
                if ($this->isFatalError($e)) {
                    throw $e;
                }

                // Wait before retry (exponential backoff)
                if ($attempt < $this->maxRetries) {
                    usleep($this->retryDelay * 1000 * pow(2, $attempt - 1));
                }
            }
        }

        // All retries failed
        throw new \Exception("Operation failed after {$this->maxRetries} attempts: " . $lastException->getMessage(), 0, $lastException);
    }

    private function isFatalError(\Exception $e)
    {
        $message = strtolower($e->getMessage());

        // These errors should not be retried
        $fatalPatterns = [
            'does not exist',
            'permission denied',
            'access denied',
            'invalid',
            'constraint violation'
        ];

        foreach ($fatalPatterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    public function getRetryStats()
    {
        return [
            'total_retries' => $this->totalRetriesHistorical,
            'operations_retried' => count($this->operationsRetriedHistorical),
            'current_failures' => array_sum($this->retryCount)
        ];
    }
}
