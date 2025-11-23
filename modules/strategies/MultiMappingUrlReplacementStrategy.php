<?php

namespace csabourin\spaghettiMigrator\strategies;

use csabourin\spaghettiMigrator\interfaces\UrlReplacementStrategyInterface;

/**
 * Multi-Mapping URL Replacement Strategy
 *
 * Performs multiple search/replace operations in one pass.
 * Useful for migrating multiple CDN domains to a single destination.
 *
 * Example:
 *   Mappings: [
 *     'cdn1.example.com' => 'cdn-new.example.com',
 *     'cdn2.example.com' => 'cdn-new.example.com',
 *     'cdn3.example.com' => 'cdn-new.example.com',
 *   ]
 *
 * @package csabourin\spaghettiMigrator\strategies
 * @since 2.0.0
 */
class MultiMappingUrlReplacementStrategy implements UrlReplacementStrategyInterface
{
    private array $mappings;
    private int $priority;

    /**
     * Constructor
     *
     * @param array $mappings Associative array of [search => replace]
     * @param int $priority Strategy priority (higher = runs first, default: 0)
     */
    public function __construct(array $mappings, int $priority = 0)
    {
        $this->mappings = $mappings;
        $this->priority = $priority;
    }

    /**
     * @inheritDoc
     */
    public function replace(string $content): string
    {
        // Sort mappings by search length (longest first) to prevent partial replacements
        $sortedMappings = $this->mappings;
        uksort($sortedMappings, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($sortedMappings as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function applies(string $content): bool
    {
        foreach ($this->mappings as $search => $replace) {
            if (str_contains($content, $search)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $count = count($this->mappings);
        $examples = array_slice($this->mappings, 0, 2);
        $exampleStr = implode(', ', array_map(
            fn($k, $v) => "'$k' → '$v'",
            array_keys($examples),
            array_values($examples)
        ));

        if ($count > 2) {
            $exampleStr .= ", and " . ($count - 2) . " more";
        }

        return "Apply {$count} domain mappings: {$exampleStr}";
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Get the mappings
     *
     * @return array
     */
    public function getMappings(): array
    {
        return $this->mappings;
    }

    /**
     * Add a mapping
     *
     * @param string $search
     * @param string $replace
     * @return void
     */
    public function addMapping(string $search, string $replace): void
    {
        $this->mappings[$search] = $replace;
    }

    /**
     * Get mapping count
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->mappings);
    }
}
