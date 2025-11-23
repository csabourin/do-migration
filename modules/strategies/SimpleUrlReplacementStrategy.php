<?php

namespace csabourin\craftS3SpacesMigration\strategies;

use csabourin\craftS3SpacesMigration\interfaces\UrlReplacementStrategyInterface;

/**
 * Simple URL Replacement Strategy
 *
 * Performs simple string replacement: search → replace
 * Most common use case for URL migrations.
 *
 * Example:
 *   "https://bucket.s3.amazonaws.com" → "https://bucket.nyc3.digitaloceanspaces.com"
 *
 * @package csabourin\craftS3SpacesMigration\strategies
 * @since 2.0.0
 */
class SimpleUrlReplacementStrategy implements UrlReplacementStrategyInterface
{
    private string $search;
    private string $replace;
    private int $priority;

    /**
     * Constructor
     *
     * @param string $search String to search for
     * @param string $replace String to replace with
     * @param int $priority Strategy priority (higher = runs first, default: 0)
     */
    public function __construct(string $search, string $replace, int $priority = 0)
    {
        $this->search = $search;
        $this->replace = $replace;
        $this->priority = $priority;
    }

    /**
     * @inheritDoc
     */
    public function replace(string $content): string
    {
        return str_replace($this->search, $this->replace, $content);
    }

    /**
     * @inheritDoc
     */
    public function applies(string $content): bool
    {
        return str_contains($content, $this->search);
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return "Replace '{$this->search}' with '{$this->replace}'";
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Get the search string
     *
     * @return string
     */
    public function getSearch(): string
    {
        return $this->search;
    }

    /**
     * Get the replacement string
     *
     * @return string
     */
    public function getReplace(): string
    {
        return $this->replace;
    }
}
