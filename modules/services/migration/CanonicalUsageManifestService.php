<?php

namespace csabourin\spaghettiMigrator\services\migration;

use Craft;
use craft\helpers\FileHelper;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;

/**
 * Canonical Usage Manifest Service
 *
 * Builds and persists a canonical manifest of target-volume files that are
 * considered used or protected during migration. The manifest becomes the
 * authoritative input for quarantine filtering.
 *
 * Key guarantees:
 * - Referenced files are persisted as protected before quarantine runs
 * - Referenced files without Craft asset records are never quarantined blindly
 * - Best-effort indexing is attempted for safe, path-identifiable files
 * - If indexing cannot be performed safely, files remain protected for review
 */
class CanonicalUsageManifestService
{
    /**
     * @var \craft\console\Controller
     */
    private $controller;

    /**
     * @var MigrationConfig
     */
    private $config;

    /**
     * @var string
     */
    private $migrationId;

    /**
     * @var string
     */
    private $storageDirectory;

    /**
     * @var PreQuarantineScanService
     */
    private $referenceScanner;

    /**
     * @param \craft\console\Controller $controller
     * @param MigrationConfig $config
     * @param string $migrationId
     * @param PreQuarantineScanService|null $referenceScanner
     * @param string|null $storageDirectory
     */
    public function __construct(
        $controller,
        MigrationConfig $config,
        string $migrationId,
        ?PreQuarantineScanService $referenceScanner = null,
        ?string $storageDirectory = null
    ) {
        $this->controller = $controller;
        $this->config = $config;
        $this->migrationId = $migrationId;

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $this->migrationId)) {
            throw new \InvalidArgumentException(
                'Invalid migrationId: must contain only alphanumeric characters, hyphens, and underscores.'
            );
        }

        $this->referenceScanner = $referenceScanner ?? new PreQuarantineScanService($controller, $config, new MigrationReporter($controller, $migrationId));
        $this->storageDirectory = $storageDirectory ?? Craft::getAlias('@storage/migration-usage-manifests');
    }

    /**
     * Build, persist, and return the canonical usage manifest.
     *
     * @param array $analysis
     * @param array $assetInventory
     * @param array $fileInventory
     * @param int $targetVolumeId
     * @return array
     */
    public function buildManifest(array $analysis, array $assetInventory, array $fileInventory, int $targetVolumeId): array
    {
        $targetFiles = array_values(array_filter($fileInventory, static function(array $file) use ($targetVolumeId): bool {
            return (int) ($file['volumeId'] ?? 0) === $targetVolumeId;
        }));

        $filenameCounts = [];
        foreach ($targetFiles as $file) {
            $filename = (string) ($file['filename'] ?? '');
            if ($filename === '') {
                continue;
            }

            if (!isset($filenameCounts[$filename])) {
                $filenameCounts[$filename] = 0;
            }
            $filenameCounts[$filename]++;
        }

        $manifest = [
            'manifestVersion' => '1.0',
            'migrationId' => $this->migrationId,
            'targetVolumeId' => $targetVolumeId,
            'generatedAt' => date(DATE_ATOM),
            'files' => [],
            'summary' => [],
        ];

        foreach ($targetFiles as $file) {
            $fileKey = $this->buildFileKey((int) $file['volumeId'], (string) $file['path']);
            $manifest['files'][$fileKey] = $this->createBaseEntry($file, $filenameCounts);
        }

        $usedAssets = array_merge(
            $analysis['used_assets_correct_location'] ?? [],
            $analysis['used_assets_wrong_location'] ?? []
        );

        foreach ($usedAssets as $asset) {
            if ((int) ($asset['volumeId'] ?? 0) !== $targetVolumeId) {
                continue;
            }

            $path = $this->resolveAssetPath($asset);
            if ($path === '') {
                continue;
            }

            $fileKey = $this->buildFileKey((int) $asset['volumeId'], $path);
            if (!isset($manifest['files'][$fileKey])) {
                $manifest['files'][$fileKey] = $this->createFallbackEntryFromAsset($asset, $filenameCounts, $path);
            }

            $manifest['files'][$fileKey]['hasAssetRecord'] = true;
            $manifest['files'][$fileKey]['usedByCraftRelations'] = true;
            $manifest['files'][$fileKey]['protected'] = true;
            $manifest['files'][$fileKey]['assetIds'][(int) $asset['id']] = (int) $asset['id'];
            $manifest['files'][$fileKey]['canonicalStatus'] = 'used_via_asset_relations';
        }

        $candidates = $this->buildCandidateMap($analysis);
        $referenceEvidence = $this->referenceScanner->collectReferenceEvidence($candidates);

        foreach ($candidates as $candidateKey => $candidate) {
            $fileKey = $candidate['fileKey'];

            if (!isset($manifest['files'][$fileKey])) {
                $manifest['files'][$fileKey] = $this->createFallbackEntryFromCandidate($candidate, $filenameCounts);
            }

            $entry = &$manifest['files'][$fileKey];
            $entry['quarantineCandidate'] = true;
            $entry['candidateTypes'][$candidate['type']] = true;

            foreach ($candidate['assetIds'] as $assetId) {
                $entry['assetIds'][(int) $assetId] = (int) $assetId;
            }

            if (!empty($candidate['assetIds'])) {
                $entry['hasAssetRecord'] = true;
            }

            if (!empty($referenceEvidence[$candidateKey])) {
                $candidateEvidence = $referenceEvidence[$candidateKey];
                $entry['protected'] = true;

                foreach (array_keys($candidateEvidence['sources']) as $source) {
                    $entry['externalEvidence'][$source] = true;
                }

                foreach (array_keys($candidateEvidence['matchedTerms']) as $term) {
                    $entry['matchedTerms'][$term] = $term;
                }

                foreach ($candidateEvidence['matches'] as $match) {
                    $entry['matches'][] = $match;
                }
            }

            unset($entry);
        }

        foreach ($manifest['files'] as &$entry) {
            $entry['assetIds'] = array_values($entry['assetIds']);
            $entry['candidateTypes'] = array_values(array_keys($entry['candidateTypes']));
            $entry['externalEvidence'] = array_values(array_keys($entry['externalEvidence']));
            $entry['matchedTerms'] = array_values($entry['matchedTerms']);
            $entry['matchConfidence'] = $this->determineMatchConfidence($entry);

            if ($entry['usedByCraftRelations']) {
                $entry['canonicalStatus'] = 'used_via_asset_relations';
                continue;
            }

            if ($entry['protected'] && !$entry['hasAssetRecord']) {
                $entry['canonicalStatus'] = 'referenced_unindexed';
                continue;
            }

            if ($entry['protected']) {
                $entry['canonicalStatus'] = 'protected_external_reference';
                continue;
            }

            if (in_array('unused_asset', $entry['candidateTypes'], true)) {
                $entry['canonicalStatus'] = 'quarantine_candidate_unused_asset';
                continue;
            }

            if (in_array('orphaned_file', $entry['candidateTypes'], true)) {
                $entry['canonicalStatus'] = 'quarantine_candidate_orphaned_file';
            }
        }
        unset($entry);

        $manifest['summary'] = $this->buildSummary($manifest);
        $this->persistManifestOrFail($manifest);

        return $manifest;
    }

    /**
     * Attempt to index referenced files that have no asset record yet.
     *
     * Files that cannot be indexed confidently remain protected for manual review.
     *
     * @param array $manifest
     * @param mixed $targetVolume
     * @return array
     */
    public function indexReferencedFiles(array $manifest, $targetVolume): array
    {
        $results = [
            'attempted' => 0,
            'indexed' => 0,
            'protectedManualReview' => 0,
            'errors' => 0,
            'manifest' => $manifest,
        ];

        $assetIndexer = $this->getAssetIndexer();
        if ($assetIndexer === null) {
            foreach ($results['manifest']['files'] as &$entry) {
                if (($entry['canonicalStatus'] ?? '') !== 'referenced_unindexed') {
                    continue;
                }

                $entry['protected'] = true;
                $entry['indexing'] = [
                    'status' => 'manual_review',
                    'message' => 'Craft asset indexer service unavailable; file remains protected.',
                ];
                $results['protectedManualReview']++;
            }
            unset($entry);

            $results['manifest']['summary'] = $this->buildSummary($results['manifest']);
            $this->persistManifestOrFail($results['manifest']);

            return $results;
        }

        $sessionId = method_exists($assetIndexer, 'getIndexingSessionId')
            ? $assetIndexer->getIndexingSessionId()
            : 0;

        // Process in batches of 50 so intermediate state is persisted and memory is
        // reclaimed periodically — each indexFile() call may hit remote cloud storage.
        $indexBatchSize = 50;
        $indexedInBatch = 0;
        $fileKeys = array_keys($results['manifest']['files']);

        foreach ($fileKeys as $fileKey) {
            $entry = &$results['manifest']['files'][$fileKey];

            if (($entry['canonicalStatus'] ?? '') !== 'referenced_unindexed') {
                unset($entry);
                continue;
            }

            if (($entry['matchConfidence'] ?? 'none') === 'filename_ambiguous') {
                $entry['protected'] = true;
                $entry['indexing'] = [
                    'status' => 'manual_review',
                    'message' => 'Reference matched only by an ambiguous filename; file remains protected.',
                ];
                $results['protectedManualReview']++;
                unset($entry);
                continue;
            }

            $results['attempted']++;

            try {
                $indexedAsset = $assetIndexer->indexFile($targetVolume, $entry['path'], $sessionId, false, true);
                $entry['protected'] = true;
                $entry['canonicalStatus'] = 'indexed_from_reference';
                $entry['indexing'] = [
                    'status' => 'indexed',
                    'message' => 'Indexed into Craft from canonical manifest evidence.',
                ];

                if (is_object($indexedAsset) && isset($indexedAsset->id)) {
                    $entry['hasAssetRecord'] = true;
                    $entry['assetIds'][] = (int) $indexedAsset->id;
                    $entry['assetIds'] = array_values(array_unique(array_map('intval', $entry['assetIds'])));
                }

                $results['indexed']++;
            } catch (\Throwable $e) {
                $entry['protected'] = true;
                $entry['indexing'] = [
                    'status' => 'manual_review',
                    'message' => 'Indexing failed: ' . $e->getMessage(),
                ];
                $results['errors']++;
                $results['protectedManualReview']++;
                Craft::warning('Canonical manifest indexing failed for ' . $entry['path'] . ': ' . $e->getMessage(), __METHOD__);
            }

            unset($entry);
            $indexedInBatch++;

            if ($indexedInBatch % $indexBatchSize === 0) {
                $results['manifest']['summary'] = $this->buildSummary($results['manifest']);
                $this->persistManifestOrFail($results['manifest']);
                gc_collect_cycles();
            }
        }

        $results['manifest']['summary'] = $this->buildSummary($results['manifest']);
        $this->persistManifestOrFail($results['manifest']);

        return $results;
    }

    /**
     * Preserve indexing/manual-review state across manifest rebuilds.
     *
     * When referenced files are indexed and inventories are rebuilt, the fresh
     * manifest should retain what happened during indexing so the persisted
     * manifest remains a useful operational record.
     *
     * @param array $manifest
     * @param array $previousManifest
     * @return array
     */
    public function mergeIndexingState(array $manifest, array $previousManifest): array
    {
        if (empty($manifest['files'])) {
            return $manifest;
        }

        foreach ($manifest['files'] as $fileKey => &$entry) {
            $previousEntry = $previousManifest['files'][$fileKey] ?? null;
            if (!is_array($previousEntry)) {
                continue;
            }

            $previousIndexing = $previousEntry['indexing'] ?? [];
            $previousStatus = (string) ($previousIndexing['status'] ?? '');
            if ($previousStatus === '' || $previousStatus === 'not_required') {
                continue;
            }

            if ($previousStatus === 'manual_review' && (($entry['canonicalStatus'] ?? '') === 'referenced_unindexed')) {
                $entry['protected'] = true;
                $entry['indexing'] = $previousIndexing;
                continue;
            }

            if ($previousStatus === 'indexed' && !empty($entry['hasAssetRecord'])) {
                $entry['protected'] = true;
                $entry['indexing'] = $previousIndexing;

                if (($entry['canonicalStatus'] ?? '') !== 'used_via_asset_relations') {
                    $entry['canonicalStatus'] = 'indexed_from_reference';
                }
            }
        }
        unset($entry);

        $manifest['summary'] = $this->buildSummary($manifest);
        $this->persistManifestOrFail($manifest);

        return $manifest;
    }

    /**
     * Apply manifest protections to the current analysis arrays.
     *
     * @param array $analysis
     * @param array $manifest
     * @return array
     */
    public function applyManifestToAnalysis(array $analysis, array $manifest): array
    {
        $protectedAssetIds = [];
        $protectedFileKeys = [];

        foreach ($manifest['files'] ?? [] as $entry) {
            if (empty($entry['protected'])) {
                continue;
            }

            if (in_array('unused_asset', $entry['candidateTypes'] ?? [], true)) {
                foreach ($entry['assetIds'] ?? [] as $assetId) {
                    $protectedAssetIds[(int) $assetId] = true;
                }
            }

            if (in_array('orphaned_file', $entry['candidateTypes'] ?? [], true)) {
                $protectedFileKeys[$this->buildFileKey((int) ($entry['volumeId'] ?? 0), (string) ($entry['path'] ?? ''))] = true;
            }
        }

        $analysis['unused_assets'] = array_values(array_filter(
            $analysis['unused_assets'] ?? [],
            static function(array $asset) use ($protectedAssetIds): bool {
                return !isset($protectedAssetIds[(int) ($asset['id'] ?? 0)]);
            }
        ));

        $analysis['orphaned_files'] = array_values(array_filter(
            $analysis['orphaned_files'] ?? [],
            function(array $file) use ($protectedFileKeys): bool {
                $fileKey = $this->buildFileKey((int) ($file['volumeId'] ?? 0), (string) ($file['path'] ?? ''));
                return !isset($protectedFileKeys[$fileKey]);
            }
        ));

        return $analysis;
    }

    /**
     * Load the latest manifest for the migration, if present.
     *
     * @return array|null
     */
    public function loadManifest(): ?array
    {
        $path = $this->getManifestPath();
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Load the persisted manifest when available, otherwise rebuild it.
     *
     * This is used by resume flows that need the canonical master list to exist
     * before continuing, without forcing a rebuild on every successful run.
     *
     * @param array $analysis
     * @param array $assetInventory
     * @param array $fileInventory
     * @param int $targetVolumeId
     * @return array
     */
    public function loadOrBuildManifest(
        array $analysis,
        array $assetInventory,
        array $fileInventory,
        int $targetVolumeId
    ): array {
        $manifest = $this->loadManifest();
        if (is_array($manifest) && !empty($manifest['files'])) {
            return $manifest;
        }

        return $this->buildManifest($analysis, $assetInventory, $fileInventory, $targetVolumeId);
    }

    /**
     * Persist the manifest as JSON.
     *
     * @param array $manifest
     * @return bool
     */
    public function saveManifest(array $manifest): bool
    {
        FileHelper::createDirectory($this->storageDirectory);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->getManifestPath(), $json, LOCK_EX) !== false;
    }

    /**
     * Return the manifest path for this migration.
     *
     * @return string
     */
    public function getManifestPath(): string
    {
        return rtrim($this->storageDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->migrationId . '.json';
    }

    /**
     * Build candidate map from current analysis.
     *
     * @param array $analysis
     * @return array
     */
    public function buildCandidateMap(array $analysis): array
    {
        $candidates = [];

        foreach ($analysis['unused_assets'] ?? [] as $asset) {
            $path = $this->resolveAssetPath($asset);
            $fileKey = $this->buildFileKey((int) ($asset['volumeId'] ?? 0), $path);
            $candidateKey = 'unused-asset-' . (int) ($asset['id'] ?? 0);

            $candidates[$candidateKey] = [
                'candidateKey' => $candidateKey,
                'type' => 'unused_asset',
                'volumeId' => (int) ($asset['volumeId'] ?? 0),
                'path' => $path,
                'filename' => (string) ($asset['filename'] ?? ''),
                'assetIds' => [(int) ($asset['id'] ?? 0)],
                'fileKey' => $fileKey,
            ];
        }

        foreach ($analysis['orphaned_files'] ?? [] as $file) {
            $fileKey = $this->buildFileKey((int) ($file['volumeId'] ?? 0), (string) ($file['path'] ?? ''));
            $candidateKey = 'orphaned-file-' . md5($fileKey);

            $candidates[$candidateKey] = [
                'candidateKey' => $candidateKey,
                'type' => 'orphaned_file',
                'volumeId' => (int) ($file['volumeId'] ?? 0),
                'path' => trim((string) ($file['path'] ?? ''), '/'),
                'filename' => (string) ($file['filename'] ?? basename((string) ($file['path'] ?? ''))),
                'assetIds' => [],
                'fileKey' => $fileKey,
            ];
        }

        return $candidates;
    }

    /**
     * Build a stable file key for manifest lookups.
     *
     * @param int $volumeId
     * @param string $path
     * @return string
     */
    public function buildFileKey(int $volumeId, string $path): string
    {
        return $volumeId . '::' . trim($path, '/');
    }

    /**
     * Resolve an asset array to a relative path.
     *
     * @param array $asset
     * @return string
     */
    public function resolveAssetPath(array $asset): string
    {
        if (!empty($asset['filePath'])) {
            return trim((string) $asset['filePath'], '/');
        }

        $folderPath = trim((string) ($asset['folderPath'] ?? ''), '/');
        $filename = trim((string) ($asset['filename'] ?? ''));

        if ($folderPath !== '' && $filename !== '') {
            return $folderPath . '/' . $filename;
        }

        return $filename;
    }

    /**
     * @param array $file
     * @param array $filenameCounts
     * @return array
     */
    private function createBaseEntry(array $file, array $filenameCounts): array
    {
        $filename = (string) ($file['filename'] ?? basename((string) ($file['path'] ?? '')));

        return [
            'fileKey' => $this->buildFileKey((int) ($file['volumeId'] ?? 0), (string) ($file['path'] ?? '')),
            'volumeId' => (int) ($file['volumeId'] ?? 0),
            'path' => trim((string) ($file['path'] ?? ''), '/'),
            'filename' => $filename,
            'hasAssetRecord' => false,
            'assetIds' => [],
            'usedByCraftRelations' => false,
            'protected' => false,
            'quarantineCandidate' => false,
            'candidateTypes' => [],
            'externalEvidence' => [],
            'matchedTerms' => [],
            'matches' => [],
            'canonicalStatus' => 'tracked_target_file',
            'matchConfidence' => 'none',
            'targetFilenameCount' => (int) ($filenameCounts[$filename] ?? 1),
            'indexing' => [
                'status' => 'not_required',
                'message' => '',
            ],
        ];
    }

    /**
     * @param array $asset
     * @param array $filenameCounts
     * @param string $path
     * @return array
     */
    private function createFallbackEntryFromAsset(array $asset, array $filenameCounts, string $path): array
    {
        return $this->createBaseEntry([
            'volumeId' => (int) ($asset['volumeId'] ?? 0),
            'path' => $path,
            'filename' => (string) ($asset['filename'] ?? basename($path)),
        ], $filenameCounts);
    }

    /**
     * @param array $candidate
     * @param array $filenameCounts
     * @return array
     */
    private function createFallbackEntryFromCandidate(array $candidate, array $filenameCounts): array
    {
        return $this->createBaseEntry([
            'volumeId' => (int) ($candidate['volumeId'] ?? 0),
            'path' => (string) ($candidate['path'] ?? ''),
            'filename' => (string) ($candidate['filename'] ?? ''),
        ], $filenameCounts);
    }

    /**
     * @param array $entry
     * @return string
     */
    private function determineMatchConfidence(array $entry): string
    {
        if (empty($entry['externalEvidence'])) {
            return 'none';
        }

        $pathTerms = [
            trim((string) ($entry['path'] ?? ''), '/'),
            '/' . trim((string) ($entry['path'] ?? ''), '/'),
        ];

        foreach ($entry['matchedTerms'] ?? [] as $matchedTerm) {
            if (in_array($matchedTerm, $pathTerms, true)) {
                return 'path';
            }
        }

        return ((int) ($entry['targetFilenameCount'] ?? 0) <= 1)
            ? 'filename_unique'
            : 'filename_ambiguous';
    }

    /**
     * @param array $manifest
     * @return array
     */
    private function buildSummary(array $manifest): array
    {
        $summary = [
            'trackedFiles' => 0,
            'protectedFiles' => 0,
            'usedFiles' => 0,
            'protectedUnusedAssets' => 0,
            'protectedOrphanedFiles' => 0,
            'referencedUnindexedFiles' => 0,
            'quarantineCandidateUnusedAssets' => 0,
            'quarantineCandidateOrphanedFiles' => 0,
            'indexedFromReferences' => 0,
            'manualReviewFiles' => 0,
        ];

        foreach ($manifest['files'] ?? [] as $entry) {
            $summary['trackedFiles']++;

            if (!empty($entry['protected'])) {
                $summary['protectedFiles']++;
            }

            switch ($entry['canonicalStatus'] ?? '') {
                case 'used_via_asset_relations':
                    $summary['usedFiles']++;
                    break;
                case 'referenced_unindexed':
                    $summary['referencedUnindexedFiles']++;
                    break;
                case 'quarantine_candidate_unused_asset':
                    $summary['quarantineCandidateUnusedAssets']++;
                    break;
                case 'quarantine_candidate_orphaned_file':
                    $summary['quarantineCandidateOrphanedFiles']++;
                    break;
                case 'indexed_from_reference':
                    $summary['indexedFromReferences']++;
                    break;
            }

            if (
                in_array('unused_asset', $entry['candidateTypes'] ?? [], true) &&
                !empty($entry['protected'])
            ) {
                $summary['protectedUnusedAssets']++;
            }

            if (
                in_array('orphaned_file', $entry['candidateTypes'] ?? [], true) &&
                !empty($entry['protected'])
            ) {
                $summary['protectedOrphanedFiles']++;
            }

            if (($entry['indexing']['status'] ?? '') === 'manual_review') {
                $summary['manualReviewFiles']++;
            }
        }

        return $summary;
    }

    /**
     * @return mixed|null
     */
    private function getAssetIndexer()
    {
        if (!is_object(Craft::$app) || !method_exists(Craft::$app, 'getAssetIndexer')) {
            return null;
        }

        try {
            return Craft::$app->getAssetIndexer();
        } catch (\Throwable $e) {
            Craft::warning('Could not access asset indexer: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Persist the manifest and fail closed if that is not possible.
     *
     * Quarantine must never rely on an in-memory manifest only.
     *
     * @param array $manifest
     * @return void
     */
    private function persistManifestOrFail(array $manifest): void
    {
        if ($this->saveManifest($manifest)) {
            return;
        }

        throw new \RuntimeException(
            'Could not persist canonical usage manifest to ' . $this->getManifestPath()
        );
    }
}
