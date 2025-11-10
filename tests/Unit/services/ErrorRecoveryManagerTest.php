<?php

namespace tests\Unit\services;

use PHPUnit\Framework\TestCase;
use csabourin\craftS3SpacesMigration\services\ErrorRecoveryManager;

/**
 * ErrorRecoveryManager Unit Tests
 */
class ErrorRecoveryManagerTest extends TestCase
{
    public function testSuccessfulOperationReturnsResult()
    {
        $manager = new ErrorRecoveryManager(3, 100);

        $result = $manager->retryOperation(function () {
            return 'success';
        }, 'test-op-1');

        $this->assertEquals('success', $result);
    }

    public function testRetryOperationRetriesOnFailure()
    {
        $manager = new ErrorRecoveryManager(3, 10); // 3 retries, 10ms delay
        $attemptCount = 0;

        $result = $manager->retryOperation(function () use (&$attemptCount) {
            $attemptCount++;
            if ($attemptCount < 3) {
                throw new \Exception('Temporary failure');
            }
            return 'success on third try';
        }, 'test-op-2');

        $this->assertEquals('success on third try', $result);
        $this->assertEquals(3, $attemptCount);
    }

    public function testThrowsExceptionAfterMaxRetries()
    {
        $manager = new ErrorRecoveryManager(2, 10); // Only 2 retries

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Operation failed after 2 attempts');

        $manager->retryOperation(function () {
            throw new \Exception('Persistent failure');
        }, 'test-op-3');
    }

    public function testFatalErrorsAreNotRetried()
    {
        $manager = new ErrorRecoveryManager(5, 10);
        $attemptCount = 0;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not exist');

        $manager->retryOperation(function () use (&$attemptCount) {
            $attemptCount++;
            throw new \Exception('File does not exist');
        }, 'test-op-4');

        // Should fail immediately without retries
        $this->assertEquals(1, $attemptCount);
    }

    public function testFatalErrorPatterns()
    {
        $manager = new ErrorRecoveryManager(5, 10);

        $fatalErrors = [
            'does not exist',
            'permission denied',
            'access denied',
            'invalid',
            'constraint violation',
        ];

        foreach ($fatalErrors as $error) {
            $attemptCount = 0;

            try {
                $manager->retryOperation(function () use (&$attemptCount, $error) {
                    $attemptCount++;
                    throw new \Exception($error);
                }, 'test-fatal-' . $error);

                $this->fail('Should have thrown exception');
            } catch (\Exception $e) {
                // Fatal errors should only attempt once
                $this->assertEquals(1, $attemptCount, "Fatal error '{$error}' should not be retried");
            }
        }
    }

    public function testRetrieableErrorsAreRetried()
    {
        $manager = new ErrorRecoveryManager(3, 10);
        $attemptCount = 0;

        try {
            $manager->retryOperation(function () use (&$attemptCount) {
                $attemptCount++;
                throw new \Exception('Network timeout');
            }, 'test-retriable');
        } catch (\Exception $e) {
            // Network errors should be retried
            $this->assertEquals(3, $attemptCount);
        }
    }

    public function testGetRetryStatsTracksRetries()
    {
        $manager = new ErrorRecoveryManager(3, 10);

        // Operation 1: succeeds on first try
        $manager->retryOperation(function () {
            return 'success';
        }, 'op1');

        // Operation 2: fails twice then succeeds
        $attempt2 = 0;
        $manager->retryOperation(function () use (&$attempt2) {
            $attempt2++;
            if ($attempt2 < 3) throw new \Exception('temp fail');
            return 'success';
        }, 'op2');

        $stats = $manager->getRetryStats();

        $this->assertArrayHasKey('total_retries', $stats);
        $this->assertArrayHasKey('operations_retried', $stats);
        $this->assertGreaterThanOrEqual(2, $stats['total_retries']);
    }

    public function testSuccessResetsRetryCount()
    {
        $manager = new ErrorRecoveryManager(3, 10);
        $firstAttempt = 0;

        // First operation fails twice then succeeds
        $manager->retryOperation(function () use (&$firstAttempt) {
            $firstAttempt++;
            if ($firstAttempt < 3) throw new \Exception('temp');
            return 'success';
        }, 'op-reset');

        $stats1 = $manager->getRetryStats();

        // Second call to same operation ID should succeed immediately
        $manager->retryOperation(function () {
            return 'success immediately';
        }, 'op-reset');

        $stats2 = $manager->getRetryStats();

        // Retry count for this operation should be reset after success
        $this->assertEquals($stats1['operations_retried'], $stats2['operations_retried']);
    }
}
