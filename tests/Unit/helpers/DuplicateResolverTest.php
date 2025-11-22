<?php

namespace csabourin\craftS3SpacesMigration\tests\Unit\helpers;

use csabourin\craftS3SpacesMigration\helpers\DuplicateResolver;
use PHPUnit\Framework\TestCase;

class DuplicateResolverTest extends TestCase
{
    public function testDuplicateFinderUtilityIsNotExposed(): void
    {
        $this->assertFalse(
            method_exists(DuplicateResolver::class, 'findAssetsPointingToSameFile'),
            'Memory-heavy duplicate finder should remain removed.'
        );
    }
}
