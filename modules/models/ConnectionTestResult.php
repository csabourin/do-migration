<?php

namespace csabourin\spaghettiMigrator\models;

/**
 * Connection Test Result
 *
 * Result of testing a provider connection.
 *
 * @package csabourin\spaghettiMigrator\models
 * @since 2.0.0
 */
class ConnectionTestResult
{
    /**
     * @var bool Whether the connection test succeeded
     */
    public bool $success;

    /**
     * @var string Result message (success message or error description)
     */
    public string $message;

    /**
     * @var array Additional diagnostic information
     */
    public array $details = [];

    /**
     * @var float|null Response time in seconds
     */
    public ?float $responseTime = null;

    /**
     * @var \Exception|null Exception that occurred (if failed)
     */
    public ?\Exception $exception = null;

    /**
     * Constructor
     *
     * @param bool $success
     * @param string $message
     * @param array $details
     */
    public function __construct(bool $success, string $message, array $details = [])
    {
        $this->success = $success;
        $this->message = $message;
        $this->details = $details;
    }

    /**
     * Create a successful result
     *
     * @param string $message
     * @param array $details
     * @return self
     */
    public static function success(string $message = 'Connection successful', array $details = []): self
    {
        return new self(true, $message, $details);
    }

    /**
     * Create a failed result
     *
     * @param string $message
     * @param \Exception|null $exception
     * @param array $details
     * @return self
     */
    public static function failure(string $message, ?\Exception $exception = null, array $details = []): self
    {
        $result = new self(false, $message, $details);
        $result->exception = $exception;
        return $result;
    }

    /**
     * Get formatted output for display
     *
     * @return string
     */
    public function getFormattedMessage(): string
    {
        $output = $this->success ? '✓ ' : '✗ ';
        $output .= $this->message;

        if ($this->responseTime !== null) {
            $output .= sprintf(' (%.2fs)', $this->responseTime);
        }

        if (!empty($this->details)) {
            $output .= "\n  " . implode("\n  ", array_map(
                fn($k, $v) => "{$k}: {$v}",
                array_keys($this->details),
                array_values($this->details)
            ));
        }

        if ($this->exception !== null) {
            $output .= "\n  Error: " . $this->exception->getMessage();
        }

        return $output;
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'details' => $this->details,
            'response_time' => $this->responseTime,
            'error' => $this->exception ? $this->exception->getMessage() : null,
        ];
    }
}
