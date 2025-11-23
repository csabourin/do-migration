<?php

namespace csabourin\spaghettiMigrator\interfaces;

/**
 * URL Replacement Strategy Interface
 *
 * Defines how URLs should be transformed during migration.
 * Allows for flexible, configurable URL replacement patterns.
 *
 * @package csabourin\spaghettiMigrator\interfaces
 * @since 2.0.0
 */
interface UrlReplacementStrategyInterface
{
    /**
     * Replace URLs in content according to this strategy
     *
     * @param string $content Content to process
     * @return string Content with URLs replaced
     */
    public function replace(string $content): string;

    /**
     * Test if this strategy applies to given content
     *
     * Used for optimization - skip strategies that won't match.
     *
     * @param string $content Content to test
     * @return bool True if strategy might match content
     */
    public function applies(string $content): bool;

    /**
     * Get human-readable description of what this strategy does
     *
     * @return string Description
     */
    public function getDescription(): string;

    /**
     * Get priority for this strategy
     *
     * Strategies with higher priority run first.
     * Default priority is 0.
     *
     * @return int Priority (higher = runs first)
     */
    public function getPriority(): int;
}
