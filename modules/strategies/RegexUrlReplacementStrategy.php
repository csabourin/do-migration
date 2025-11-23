<?php

namespace csabourin\spaghettiMigrator\strategies;

use csabourin\spaghettiMigrator\interfaces\UrlReplacementStrategyInterface;

/**
 * Regex URL Replacement Strategy
 *
 * Performs regex-based replacement for complex URL transformations.
 * Useful for pattern matching and capture groups.
 *
 * Example:
 *   Pattern: '#https://([^.]+)\.s3\.amazonaws\.com#'
 *   Replacement: 'https://$1.nyc3.digitaloceanspaces.com'
 *
 * @package csabourin\spaghettiMigrator\strategies
 * @since 2.0.0
 */
class RegexUrlReplacementStrategy implements UrlReplacementStrategyInterface
{
    private string $pattern;
    private string $replacement;
    private int $priority;

    /**
     * Constructor
     *
     * @param string $pattern Regex pattern (must include delimiters, e.g., '#pattern#')
     * @param string $replacement Replacement string (can use $1, $2 for capture groups)
     * @param int $priority Strategy priority (higher = runs first, default: 0)
     */
    public function __construct(string $pattern, string $replacement, int $priority = 0)
    {
        $this->pattern = $pattern;
        $this->replacement = $replacement;
        $this->priority = $priority;
    }

    /**
     * @inheritDoc
     */
    public function replace(string $content): string
    {
        return preg_replace($this->pattern, $this->replacement, $content);
    }

    /**
     * @inheritDoc
     */
    public function applies(string $content): bool
    {
        return preg_match($this->pattern, $content) === 1;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return "Regex replace: {$this->pattern} → {$this->replacement}";
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Get the pattern
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Get the replacement
     *
     * @return string
     */
    public function getReplacement(): string
    {
        return $this->replacement;
    }
}
