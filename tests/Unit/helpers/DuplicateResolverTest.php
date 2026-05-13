<?php

namespace csabourin\spaghettiMigrator\tests\Unit\helpers;

use Craft;
use csabourin\spaghettiMigrator\helpers\DuplicateResolver;
use csabourin\spaghettiMigrator\helpers\MigrationConfig;
use craft\elements\Asset;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class DuplicateResolverTest extends TestCase
{
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetConfig();
        Asset::$store = [];
        \Craft::$app->getDb()->tables['relations'] = [];
    }

    protected function tearDown(): void
    {
        $this->resetConfig();

        foreach ($this->tempDirs as $dir) {
            @unlink($dir . '/migration-config.php');
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function testDuplicateFinderUtilityIsNotExposed(): void
    {
        $this->assertFalse(
            method_exists(DuplicateResolver::class, 'findAssetsPointingToSameFile'),
            'Memory-heavy duplicate finder should remain removed.'
        );
    }

    public function testMergeAssetMetadataCopiesEmptyWinnerValues(): void
    {
        $winner = new Asset(10);
        $loser = new Asset(20);
        $loser->title = 'Loser title';
        $loser->setFieldValue('altText', 'Useful alt text');

        $summary = DuplicateResolver::mergeAssetMetadata($winner, $loser);

        $this->assertSame('Loser title', $winner->title);
        $this->assertSame('Useful alt text', $winner->getFieldValue('altText'));
        $this->assertSame(2, $summary['copied']);
        $this->assertSame(0, $summary['conflicts']);
    }

    public function testMergeAssetMetadataUnionsListValues(): void
    {
        $winner = new Asset(10);
        $loser = new Asset(20);
        $winner->setFieldValue('keywords', ['hero', 'summer']);
        $loser->setFieldValue('keywords', ['summer', 'campaign']);

        $summary = DuplicateResolver::mergeAssetMetadata($winner, $loser);

        $this->assertSame(['hero', 'summer', 'campaign'], $winner->getFieldValue('keywords'));
        $this->assertSame(1, $summary['merged']);
        $this->assertSame(0, $summary['conflicts']);
    }

    public function testMergeAssetMetadataKeepsWinnerAndReportsScalarConflicts(): void
    {
        $events = [];
        $winner = new Asset(10);
        $loser = new Asset(20);
        $winner->setFieldValue('credit', 'Winner credit');
        $loser->setFieldValue('credit', 'Loser credit');

        $summary = DuplicateResolver::mergeAssetMetadata(
            $winner,
            $loser,
            static function (array $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $this->assertSame('Winner credit', $winner->getFieldValue('credit'));
        $this->assertSame(1, $summary['conflicts']);
        $this->assertSame('metadata_conflict', $events[0]['type']);
        $this->assertSame('kept_winner', $events[0]['resolution']);
        $this->assertSame('Loser credit', $events[0]['loserValue']);
    }

    public function testMergeAssetMetadataMovesAssetOwnedRelationsAndDeduplicatesExistingRows(): void
    {
        $winner = new Asset(10);
        $loser = new Asset(20);

        \Craft::$app->getDb()->tables['relations'] = [
            ['id' => 1, 'sourceId' => 20, 'fieldId' => 5, 'targetId' => 100, 'sourceSiteId' => 1],
            ['id' => 2, 'sourceId' => 20, 'fieldId' => 5, 'targetId' => 101, 'sourceSiteId' => 1],
            ['id' => 3, 'sourceId' => 10, 'fieldId' => 5, 'targetId' => 101, 'sourceSiteId' => 1],
        ];

        $summary = DuplicateResolver::mergeAssetMetadata($winner, $loser);
        $relations = array_values(\Craft::$app->getDb()->tables['relations']);

        $this->assertSame(1, $summary['relations_moved']);
        $this->assertSame(1, $summary['relations_deduplicated']);
        $this->assertSame(10, $relations[0]['sourceId']);
        $this->assertCount(2, $relations);
    }

    public function testMergeAssetMetadataSanitizesWinnerSourceUrlsBeforeCopyingCleanLoserValue(): void
    {
        $this->useConfig([
            'aws' => [
                'bucket' => 'source-bucket',
                'region' => 'us-east-1',
                'urls' => ['https://source-bucket.s3.amazonaws.com'],
            ],
        ]);

        $events = [];
        $winner = new Asset(10);
        $loser = new Asset(20);
        $winner->setFieldValue('caption', 'https://source-bucket.s3.amazonaws.com/photo.jpg');
        $loser->setFieldValue('caption', 'Current caption');

        $summary = DuplicateResolver::mergeAssetMetadata(
            $winner,
            $loser,
            static function (array $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $this->assertSame('Current caption', $winner->getFieldValue('caption'));
        $this->assertSame(1, $summary['copied']);
        $this->assertSame(1, $summary['source_urls_stripped']);
        $this->assertSame('winner', $events[0]['assetRole']);
    }

    public function testMergeAssetMetadataUsesExplicitUrlMappingsWhenStrippingSourceUrls(): void
    {
        $this->useConfig([
            'aws' => [
                'bucket' => 'current-source',
                'region' => 'us-east-1',
                'urls' => ['https://current-source.s3.amazonaws.com'],
            ],
            'urlReplacement' => [
                'mappings' => [
                    'https://legacy-bucket.example.com/assets' => 'https://target.example.com/assets',
                ],
            ],
        ]);

        $winner = new Asset(10);
        $loser = new Asset(20);
        $loser->setFieldValue('keywords', [
            'https://legacy-bucket.example.com/assets/photo.jpg',
            'keep-me',
        ]);

        $summary = DuplicateResolver::mergeAssetMetadata($winner, $loser);

        $this->assertSame(['keep-me'], $winner->getFieldValue('keywords'));
        $this->assertSame(1, $summary['copied']);
        $this->assertSame(1, $summary['source_urls_stripped']);
    }

    public function testMergeAssetMetadataKeepsSanitizedWinnerValueWhenConflictRemains(): void
    {
        $this->useConfig([
            'aws' => [
                'bucket' => 'source-bucket',
                'region' => 'us-east-1',
                'urls' => ['https://source-bucket.s3.amazonaws.com'],
            ],
        ]);

        $winner = new Asset(10);
        $loser = new Asset(20);
        $winner->setFieldValue('credits', [
            'https://source-bucket.s3.amazonaws.com/photo.jpg',
            'winner-credit',
        ]);
        $loser->setFieldValue('credits', 'loser-credit');

        $summary = DuplicateResolver::mergeAssetMetadata($winner, $loser);

        $this->assertSame(['winner-credit'], $winner->getFieldValue('credits'));
        $this->assertSame(1, $summary['conflicts']);
        $this->assertSame(1, $summary['source_urls_stripped']);
    }

    private function useConfig(array $config): void
    {
        $dir = sys_get_temp_dir() . '/duplicate_resolver_config_' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/migration-config.php', '<?php return ' . var_export($config, true) . ';');

        $this->tempDirs[] = $dir;
        Craft::setAlias('@config', $dir);
        $this->resetConfig();
    }

    private function resetConfig(): void
    {
        $ref = new ReflectionClass(MigrationConfig::class);

        foreach (['config', 'settings', 'instance'] as $property) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        $usePluginSettings = $ref->getProperty('usePluginSettings');
        $usePluginSettings->setAccessible(true);
        $usePluginSettings->setValue(null, false);
    }
}
