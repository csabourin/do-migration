<?php

namespace csabourin\spaghettiMigrator\tests\Unit\strategies;

use csabourin\spaghettiMigrator\strategies\MultiMappingUrlReplacementStrategy;
use csabourin\spaghettiMigrator\strategies\RegexUrlReplacementStrategy;
use csabourin\spaghettiMigrator\strategies\SimpleUrlReplacementStrategy;
use PHPUnit\Framework\TestCase;

class UrlReplacementStrategiesTest extends TestCase
{
    public function testSimpleStrategyReplacesAndApplies(): void
    {
        $strategy = new SimpleUrlReplacementStrategy('old.example.com', 'new.example.com', 5);

        $content = 'Visit https://old.example.com/assets/img.png for details';
        $this->assertTrue($strategy->applies($content));
        $this->assertSame(
            'Visit https://new.example.com/assets/img.png for details',
            $strategy->replace($content)
        );
        $this->assertSame("Replace 'old.example.com' with 'new.example.com'", $strategy->getDescription());
        $this->assertSame(5, $strategy->getPriority());
        $this->assertSame('old.example.com', $strategy->getSearch());
        $this->assertSame('new.example.com', $strategy->getReplace());
    }

    public function testRegexStrategyCapturesAndApplies(): void
    {
        $strategy = new RegexUrlReplacementStrategy('#https://([^/]+)/(?<path>.*)#', 'https://cdn.$1/$2', 7);

        $content = 'https://assets.example.com/images/photo.jpg';
        $this->assertTrue($strategy->applies($content));
        $this->assertSame('https://cdn.assets.example.com/images/photo.jpg', $strategy->replace($content));
        $this->assertSame('Regex replace: #https://([^/]+)/(?<path>.*)# → https://cdn.$1/$2', $strategy->getDescription());
        $this->assertSame(7, $strategy->getPriority());

        $this->assertFalse($strategy->applies('no url here'));
    }

    public function testMultiMappingPrefersLongerMatchesAndDescribes(): void
    {
        $strategy = new MultiMappingUrlReplacementStrategy([
            'cdn.long-domain.example.com' => 'cdn.new.example.com',
            'cdn.example.com' => 'edge.example.com',
            'legacy.example.org/assets' => 'edge.example.org/media',
        ], 3);

        $content = 'cdn.long-domain.example.com/file.jpg and legacy.example.org/assets/123';
        $this->assertTrue($strategy->applies($content));
        $rewritten = $strategy->replace($content);

        $this->assertStringContainsString('cdn.new.example.com/file.jpg', $rewritten);
        $this->assertStringContainsString('edge.example.org/media/123', $rewritten);
        $this->assertSame(3, $strategy->getPriority());
        $this->assertSame(3, $strategy->count());
        $this->assertStringContainsString('Apply 3 domain mappings', $strategy->getDescription());
        $this->assertStringContainsString("'cdn.long-domain.example.com' → 'cdn.new.example.com'", $strategy->getDescription());
    }
}
