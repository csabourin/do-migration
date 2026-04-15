<?php

namespace csabourin\spaghettiMigrator\tests\Unit\services;

use Craft;
use CraftAppStub;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use csabourin\spaghettiMigrator\services\migration\CanonicalUsageManifestService;
use csabourin\spaghettiMigrator\services\migration\PreQuarantineScanService;
use PHPUnit\Framework\TestCase;

class CanonicalUsageManifestServiceTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/canonical-usage-' . uniqid('', true);
        Craft::$app = new CraftAppStub();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storageDir)) {
            return;
        }

        foreach (glob($this->storageDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->storageDir);
    }

    public function testBuildManifestPersistsProtectedUsageForReferencedCandidates(): void
    {
        $service = $this->createService([
            'unused-asset-2' => [
                'sources' => ['template' => true],
                'matchedTerms' => ['gallery/bar.jpg' => true],
                'matches' => [
                    ['source' => 'template', 'term' => 'gallery/bar.jpg'],
                ],
            ],
            'orphaned-file-' . md5('10::legacy/baz.jpg') => [
                'sources' => ['database' => true],
                'matchedTerms' => ['legacy/baz.jpg' => true],
                'matches' => [
                    ['source' => 'database', 'term' => 'legacy/baz.jpg', 'location' => 'content.field_body'],
                ],
            ],
        ]);

        $analysis = [
            'used_assets_correct_location' => [
                ['id' => 1, 'volumeId' => 10, 'filePath' => 'used/foo.jpg', 'filename' => 'foo.jpg'],
            ],
            'used_assets_wrong_location' => [],
            'unused_assets' => [
                ['id' => 2, 'volumeId' => 10, 'filePath' => 'gallery/bar.jpg', 'filename' => 'bar.jpg'],
            ],
            'orphaned_files' => [
                ['volumeId' => 10, 'path' => 'legacy/baz.jpg', 'filename' => 'baz.jpg'],
            ],
        ];

        $fileInventory = [
            ['volumeId' => 10, 'path' => 'used/foo.jpg', 'filename' => 'foo.jpg'],
            ['volumeId' => 10, 'path' => 'gallery/bar.jpg', 'filename' => 'bar.jpg'],
            ['volumeId' => 10, 'path' => 'legacy/baz.jpg', 'filename' => 'baz.jpg'],
        ];

        $manifest = $service->buildManifest($analysis, [], $fileInventory, 10);

        $this->assertFileExists($service->getManifestPath());
        $this->assertSame(1, $manifest['summary']['referencedUnindexedFiles']);
        $this->assertSame(1, $manifest['summary']['protectedUnusedAssets']);
        $this->assertSame(1, $manifest['summary']['protectedOrphanedFiles']);

        $this->assertSame(
            'used_via_asset_relations',
            $manifest['files']['10::used/foo.jpg']['canonicalStatus']
        );
        $this->assertSame(
            'protected_external_reference',
            $manifest['files']['10::gallery/bar.jpg']['canonicalStatus']
        );
        $this->assertSame(
            'referenced_unindexed',
            $manifest['files']['10::legacy/baz.jpg']['canonicalStatus']
        );
    }

    public function testApplyManifestToAnalysisRemovesProtectedCandidatesFromQuarantine(): void
    {
        $service = $this->createService([]);

        $analysis = [
            'unused_assets' => [
                ['id' => 2, 'volumeId' => 10, 'filePath' => 'gallery/bar.jpg', 'filename' => 'bar.jpg'],
                ['id' => 3, 'volumeId' => 10, 'filePath' => 'gallery/keep-me.jpg', 'filename' => 'keep-me.jpg'],
            ],
            'orphaned_files' => [
                ['volumeId' => 10, 'path' => 'legacy/baz.jpg', 'filename' => 'baz.jpg'],
                ['volumeId' => 10, 'path' => 'legacy/quarantine.jpg', 'filename' => 'quarantine.jpg'],
            ],
        ];

        $manifest = [
            'files' => [
                '10::gallery/bar.jpg' => [
                    'volumeId' => 10,
                    'path' => 'gallery/bar.jpg',
                    'protected' => true,
                    'candidateTypes' => ['unused_asset'],
                    'assetIds' => [2],
                ],
                '10::legacy/baz.jpg' => [
                    'volumeId' => 10,
                    'path' => 'legacy/baz.jpg',
                    'protected' => true,
                    'candidateTypes' => ['orphaned_file'],
                    'assetIds' => [],
                ],
            ],
        ];

        $filtered = $service->applyManifestToAnalysis($analysis, $manifest);

        $this->assertCount(1, $filtered['unused_assets']);
        $this->assertSame(3, $filtered['unused_assets'][0]['id']);
        $this->assertCount(1, $filtered['orphaned_files']);
        $this->assertSame('legacy/quarantine.jpg', $filtered['orphaned_files'][0]['path']);
    }

    public function testIndexReferencedFilesFallsBackToManualReviewWithoutAssetIndexer(): void
    {
        $service = $this->createService([]);

        $manifest = [
            'files' => [
                '10::legacy/baz.jpg' => [
                    'volumeId' => 10,
                    'path' => 'legacy/baz.jpg',
                    'filename' => 'baz.jpg',
                    'protected' => true,
                    'candidateTypes' => ['orphaned_file'],
                    'externalEvidence' => ['database'],
                    'assetIds' => [],
                    'hasAssetRecord' => false,
                    'canonicalStatus' => 'referenced_unindexed',
                    'matchConfidence' => 'path',
                    'indexing' => ['status' => 'not_required', 'message' => ''],
                ],
            ],
            'summary' => [],
        ];

        $result = $service->indexReferencedFiles($manifest, (object) ['id' => 10]);

        $this->assertSame(0, $result['attempted']);
        $this->assertSame(1, $result['protectedManualReview']);
        $this->assertSame(
            'manual_review',
            $result['manifest']['files']['10::legacy/baz.jpg']['indexing']['status']
        );
    }

    public function testMergeIndexingStatePreservesOperationalDecisionsAcrossRebuilds(): void
    {
        $service = $this->createService([]);

        $rebuiltManifest = [
            'files' => [
                '10::legacy/indexed.jpg' => [
                    'volumeId' => 10,
                    'path' => 'legacy/indexed.jpg',
                    'filename' => 'indexed.jpg',
                    'protected' => true,
                    'candidateTypes' => ['orphaned_file'],
                    'externalEvidence' => ['template'],
                    'assetIds' => [42],
                    'hasAssetRecord' => true,
                    'canonicalStatus' => 'protected_external_reference',
                    'matchConfidence' => 'path',
                    'indexing' => ['status' => 'not_required', 'message' => ''],
                ],
                '10::legacy/review.jpg' => [
                    'volumeId' => 10,
                    'path' => 'legacy/review.jpg',
                    'filename' => 'review.jpg',
                    'protected' => true,
                    'candidateTypes' => ['orphaned_file'],
                    'externalEvidence' => ['database'],
                    'assetIds' => [],
                    'hasAssetRecord' => false,
                    'canonicalStatus' => 'referenced_unindexed',
                    'matchConfidence' => 'filename_ambiguous',
                    'indexing' => ['status' => 'not_required', 'message' => ''],
                ],
            ],
            'summary' => [],
        ];

        $previousManifest = [
            'files' => [
                '10::legacy/indexed.jpg' => [
                    'indexing' => [
                        'status' => 'indexed',
                        'message' => 'Indexed into Craft from canonical manifest evidence.',
                    ],
                ],
                '10::legacy/review.jpg' => [
                    'indexing' => [
                        'status' => 'manual_review',
                        'message' => 'Reference matched only by an ambiguous filename; file remains protected.',
                    ],
                ],
            ],
            'summary' => [],
        ];

        $merged = $service->mergeIndexingState($rebuiltManifest, $previousManifest);

        $this->assertSame(
            'indexed_from_reference',
            $merged['files']['10::legacy/indexed.jpg']['canonicalStatus']
        );
        $this->assertSame(
            'indexed',
            $merged['files']['10::legacy/indexed.jpg']['indexing']['status']
        );
        $this->assertSame(
            'manual_review',
            $merged['files']['10::legacy/review.jpg']['indexing']['status']
        );
        $this->assertSame(1, $merged['summary']['indexedFromReferences']);
        $this->assertSame(1, $merged['summary']['manualReviewFiles']);
    }

    public function testLoadOrBuildManifestBuildsCanonicalListWhenMissing(): void
    {
        $service = $this->createService([]);

        $analysis = [
            'used_assets_correct_location' => [
                ['id' => 7, 'volumeId' => 10, 'filePath' => 'used/resume.jpg', 'filename' => 'resume.jpg'],
            ],
            'used_assets_wrong_location' => [],
            'unused_assets' => [],
            'orphaned_files' => [],
        ];

        $fileInventory = [
            ['volumeId' => 10, 'path' => 'used/resume.jpg', 'filename' => 'resume.jpg'],
        ];

        $manifest = $service->loadOrBuildManifest($analysis, [], $fileInventory, 10);

        $this->assertFileExists($service->getManifestPath());
        $this->assertArrayHasKey('10::used/resume.jpg', $manifest['files']);
        $this->assertSame('used_via_asset_relations', $manifest['files']['10::used/resume.jpg']['canonicalStatus']);
    }

    private function createService(array $scannerEvidence): CanonicalUsageManifestService
    {
        $config = $this->getMockBuilder(MigrationConfig::class)
            ->disableOriginalConstructor()
            ->getMock();

        $scanner = $this->getMockBuilder(PreQuarantineScanService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collectReferenceEvidence'])
            ->getMock();

        $scanner->method('collectReferenceEvidence')->willReturn($scannerEvidence);

        return new CanonicalUsageManifestService(
            new \craft\console\Controller(),
            $config,
            'test-migration',
            $scanner,
            $this->storageDir
        );
    }
}
