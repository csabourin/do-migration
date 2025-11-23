<?php

namespace csabourin\spaghettiMigrator\tests\Unit\controllers;

use csabourin\spaghettiMigrator\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Settings Import/Export functionality
 */
class SettingsImportExportTest extends TestCase
{
    /**
     * Test that exportToArray includes all required settings
     */
    public function testExportToArrayIncludesAllSettings(): void
    {
        $settings = new Settings();

        // Set some sample values
        $settings->awsBucket = 'test-bucket';
        $settings->awsRegion = 'us-east-1';
        $settings->doRegion = 'tor1';
        $settings->filesystemMappings = ['images' => 'images_do'];
        $settings->sourceVolumeHandles = ['images', 'documents'];

        $exported = $settings->exportToArray();

        // Verify structure contains all key fields
        $this->assertIsArray($exported);
        $this->assertArrayHasKey('awsBucket', $exported);
        $this->assertArrayHasKey('awsRegion', $exported);
        $this->assertArrayHasKey('doRegion', $exported);
        $this->assertArrayHasKey('filesystemMappings', $exported);
        $this->assertArrayHasKey('sourceVolumeHandles', $exported);
        $this->assertArrayHasKey('batchSize', $exported);
        $this->assertArrayHasKey('errorThreshold', $exported);

        // Verify values are correct
        $this->assertEquals('test-bucket', $exported['awsBucket']);
        $this->assertEquals('us-east-1', $exported['awsRegion']);
        $this->assertEquals('tor1', $exported['doRegion']);
        $this->assertEquals(['images' => 'images_do'], $exported['filesystemMappings']);
        $this->assertEquals(['images', 'documents'], $exported['sourceVolumeHandles']);
    }

    /**
     * Test that import data structure matches export structure
     */
    public function testImportExportSymmetry(): void
    {
        $originalSettings = new Settings();

        // Set various types of data
        $originalSettings->awsBucket = 'test-bucket';
        $originalSettings->awsRegion = 'us-west-2';
        $originalSettings->doRegion = 'nyc3';
        $originalSettings->filesystemMappings = ['images' => 'images_do', 'docs' => 'docs_do'];
        $originalSettings->sourceVolumeHandles = ['images', 'documents', 'videos'];
        $originalSettings->volumesAtBucketRoot = ['images'];
        $originalSettings->batchSize = 150;
        $originalSettings->errorThreshold = 75;
        $originalSettings->maxConcurrentTransforms = 10;

        // Export to array (simulating JSON export)
        $exported = $originalSettings->exportToArray();

        // Create new settings and import (simulating JSON import)
        $importedSettings = new Settings();
        $importedSettings->setAttributes($exported, false);

        // Verify all values match
        $this->assertEquals($originalSettings->awsBucket, $importedSettings->awsBucket);
        $this->assertEquals($originalSettings->awsRegion, $importedSettings->awsRegion);
        $this->assertEquals($originalSettings->doRegion, $importedSettings->doRegion);
        $this->assertEquals($originalSettings->filesystemMappings, $importedSettings->filesystemMappings);
        $this->assertEquals($originalSettings->sourceVolumeHandles, $importedSettings->sourceVolumeHandles);
        $this->assertEquals($originalSettings->volumesAtBucketRoot, $importedSettings->volumesAtBucketRoot);
        $this->assertEquals($originalSettings->batchSize, $importedSettings->batchSize);
        $this->assertEquals($originalSettings->errorThreshold, $importedSettings->errorThreshold);
        $this->assertEquals($originalSettings->maxConcurrentTransforms, $importedSettings->maxConcurrentTransforms);

        // Verify re-export matches original export
        $reExported = $importedSettings->exportToArray();
        $this->assertEquals($exported, $reExported);
    }

    /**
     * Test that import handles JSON structure with metadata
     */
    public function testImportWithMetadata(): void
    {
        // Simulate a JSON export with metadata
        $importData = [
            'exportDate' => '2025-11-15 12:00:00',
            'pluginVersion' => '1.0.0',
            'settings' => [
                'awsBucket' => 'imported-bucket',
                'awsRegion' => 'eu-west-1',
                'doRegion' => 'ams3',
                'batchSize' => 200,
            ]
        ];

        // Verify structure is valid
        $this->assertArrayHasKey('settings', $importData);
        $this->assertIsArray($importData['settings']);

        // Import just the settings portion
        $settings = new Settings();
        $settings->setAttributes($importData['settings'], false);

        // Verify imported values
        $this->assertEquals('imported-bucket', $settings->awsBucket);
        $this->assertEquals('eu-west-1', $settings->awsRegion);
        $this->assertEquals('ams3', $settings->doRegion);
        $this->assertEquals(200, $settings->batchSize);
    }

    /**
     * Test that array fields are properly handled
     */
    public function testArrayFieldsHandling(): void
    {
        $settings = new Settings();

        // Test with arrays (as they would be in JSON)
        $settings->setAttributes([
            'sourceVolumeHandles' => ['images', 'documents'],
            'volumesAtBucketRoot' => ['images'],
            'filesystemMappings' => ['images' => 'images_do'],
        ], false);

        $this->assertEquals(['images', 'documents'], $settings->sourceVolumeHandles);
        $this->assertEquals(['images'], $settings->volumesAtBucketRoot);
        $this->assertEquals(['images' => 'images_do'], $settings->filesystemMappings);

        // Test with comma-separated strings (as they would be from form input)
        $settings->setAttributes([
            'sourceVolumeHandles' => 'images, documents, videos',
            'volumesAtBucketRoot' => 'images, documents',
        ], false);

        $this->assertEquals(['images', 'documents', 'videos'], $settings->sourceVolumeHandles);
        $this->assertEquals(['images', 'documents'], $settings->volumesAtBucketRoot);
    }

    /**
     * Test that JSON string fields are properly decoded
     */
    public function testJsonFieldsHandling(): void
    {
        $settings = new Settings();

        // Test with already-decoded arrays (from JSON import)
        $settings->setAttributes([
            'filesystemMappings' => ['images' => 'images_do', 'docs' => 'docs_do'],
            'filesystemDefinitions' => [
                ['name' => 'images_do', 'path' => '/images']
            ],
        ], false);

        $this->assertEquals(['images' => 'images_do', 'docs' => 'docs_do'], $settings->filesystemMappings);
        $this->assertEquals([['name' => 'images_do', 'path' => '/images']], $settings->filesystemDefinitions);

        // Test with JSON strings (from form input)
        $settings->setAttributes([
            'filesystemMappings' => json_encode(['videos' => 'videos_do']),
            'filesystemDefinitions' => json_encode([['name' => 'videos_do', 'path' => '/videos']]),
        ], false);

        $this->assertEquals(['videos' => 'videos_do'], $settings->filesystemMappings);
        $this->assertEquals([['name' => 'videos_do', 'path' => '/videos']], $settings->filesystemDefinitions);
    }
}
