<?php

namespace tests\Unit\services;

use PHPUnit\Framework\TestCase;
use csabourin\craftS3SpacesMigration\services\ProgressTracker;

/**
 * ProgressTracker Unit Tests
 */
class ProgressTrackerTest extends TestCase
{
    public function testConstructorInitializesCorrectly()
    {
        $tracker = new ProgressTracker('Test Phase', 100, 10);
        $report = $tracker->getReport();

        $this->assertEquals('Test Phase', $report['phase']);
        $this->assertEquals(0, $report['processed']);
        $this->assertEquals(100, $report['total']);
        $this->assertEquals(0, $report['percent']);
    }

    public function testIncrementUpdatesProgress()
    {
        $tracker = new ProgressTracker('Test', 100);

        $tracker->increment(10);
        $report = $tracker->getReport();

        $this->assertEquals(10, $report['processed']);
        $this->assertEquals(10.0, $report['percent']);
    }

    public function testIncrementReturnsTrueAtReportInterval()
    {
        $tracker = new ProgressTracker('Test', 100, 5);

        // Should return false for non-interval items
        $this->assertFalse($tracker->increment());
        $this->assertFalse($tracker->increment());
        $this->assertFalse($tracker->increment());
        $this->assertFalse($tracker->increment());

        // Should return true at interval (5th item)
        $this->assertTrue($tracker->increment());
    }

    public function testIncrementReturnsTrueAtCompletion()
    {
        $tracker = new ProgressTracker('Test', 10, 50); // High interval

        // Increment to completion
        for ($i = 0; $i < 9; $i++) {
            $tracker->increment();
        }

        // Last item should always return true
        $this->assertTrue($tracker->increment());

        $report = $tracker->getReport();
        $this->assertEquals(10, $report['processed']);
        $this->assertEquals(100.0, $report['percent']);
    }

    public function testProgressReportIncludesAllFields()
    {
        $tracker = new ProgressTracker('Test Phase', 100);
        $tracker->increment(50);

        $report = $tracker->getReport();

        $this->assertArrayHasKey('phase', $report);
        $this->assertArrayHasKey('processed', $report);
        $this->assertArrayHasKey('total', $report);
        $this->assertArrayHasKey('percent', $report);
        $this->assertArrayHasKey('items_per_second', $report);
        $this->assertArrayHasKey('eta_seconds', $report);
        $this->assertArrayHasKey('eta_formatted', $report);
        $this->assertArrayHasKey('elapsed_seconds', $report);
        $this->assertArrayHasKey('elapsed_formatted', $report);
    }

    public function testGetProgressStringFormatsCorrectly()
    {
        $tracker = new ProgressTracker('Test', 100);
        $tracker->increment(25);

        $progressString = $tracker->getProgressString();

        $this->assertStringContainsString('[25/100', $progressString);
        $this->assertStringContainsString('25.0%', $progressString);
        $this->assertStringContainsString('ETA:', $progressString);
    }

    public function testHandlesZeroTotalItems()
    {
        $tracker = new ProgressTracker('Test', 0); // Should convert to 1
        $report = $tracker->getReport();

        $this->assertEquals(1, $report['total']); // Minimum is 1
    }

    public function testPerformanceMetricsAreCalculated()
    {
        $tracker = new ProgressTracker('Test', 1000);

        // Simulate processing
        usleep(100000); // 0.1 seconds
        $tracker->increment(100);

        $report = $tracker->getReport();

        $this->assertGreaterThan(0, $report['items_per_second']);
        $this->assertGreaterThan(0, $report['elapsed_seconds']);
        $this->assertGreaterThan(0, $report['eta_seconds']);
    }

    public function testTimeFormattingInSeconds()
    {
        $tracker = new ProgressTracker('Test', 100);
        $tracker->increment(50);

        $report = $tracker->getReport();

        // ETA should be formatted based on time remaining
        $this->assertMatchesRegularExpression('/\d+[smh]/', $report['eta_formatted']);
        $this->assertMatchesRegularExpression('/\d+[smh]/', $report['elapsed_formatted']);
    }
}
