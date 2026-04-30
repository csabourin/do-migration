<?php

namespace csabourin\spaghettiMigrator\tests\Unit\helpers;

use csabourin\spaghettiMigrator\helpers\DuplicateResolver;
use craft\elements\Asset;
use PHPUnit\Framework\TestCase;

class DuplicateResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Asset::$store = [];
        \Craft::$app->getDb()->tables['relations'] = [];
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
}
