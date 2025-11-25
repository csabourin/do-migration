<?php

namespace tests\Unit\services;

use PHPUnit\Framework\TestCase;
use csabourin\spaghettiMigrator\services\ProgressTracker;

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

        // Process first, then wait to ensure measurable elapsed time
        // This ensures the timer starts before we increment
        $tracker->increment(50); // Start tracking immediately

        // Wait to accumulate measurable time
        usleep(200000); // 0.2 seconds - longer delay for reliable measurement

        // Process more items
        $tracker->increment(50);

        $report = $tracker->getReport();

        // Elapsed time should definitely be > 0 after sleeping
        $this->assertGreaterThan(0, $report['elapsed_seconds'], 'Elapsed seconds should be greater than 0');

        // With 100 items processed over 0.2+ seconds, we should have a measurable rate
        // Rate should be at least 1 item/second (100 items / 200+ seconds would be way more)
        $this->assertGreaterThan(0, $report['items_per_second'], 'Items per second should be > 0');

        // We should have an ETA since we haven't finished all 1000 items
        $this->assertGreaterThan(0, $report['eta_seconds'], 'ETA should be > 0 for incomplete work');
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
