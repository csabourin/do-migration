<?php

namespace csabourin\craftS3SpacesMigration\models;

/**
 * Object Iterator
 *
 * Abstract iterator for listing objects from a storage provider.
 * Handles pagination automatically.
 *
 * @package csabourin\craftS3SpacesMigration\models
 * @since 2.0.0
 */
abstract class ObjectIterator implements \Iterator, \Countable
{
    /**
     * @var string Path prefix to filter
     */
    protected string $prefix;

    /**
     * @var array Options for listing
     */
    protected array $options;

    /**
     * @var array Current batch of objects
     */
    protected array $objects = [];

    /**
     * @var int Current position in batch
     */
    protected int $position = 0;

    /**
     * @var string|null Continuation token for next page
     */
    protected ?string $nextToken = null;

    /**
     * @var bool Whether all objects have been fetched
     */
    protected bool $complete = false;

    /**
     * @var int|null Total count (if available without iteration)
     */
    protected ?int $totalCount = null;

    /**
     * Constructor
     *
     * @param string $prefix
     * @param array $options
     */
    public function __construct(string $prefix = '', array $options = [])
    {
        $this->prefix = $prefix;
        $this->options = array_merge([
            'recursive' => true,
            'maxKeys' => 1000,
        ], $options);
    }

    /**
     * Fetch next batch of objects from storage provider
     *
     * Must be implemented by concrete classes.
     *
     * @return void
     */
    abstract protected function fetchNextBatch(): void;

    /**
     * Current object
     *
     * @return ObjectMetadata|null
     */
    public function current(): ?ObjectMetadata
    {
        if ($this->position < count($this->objects)) {
            return $this->objects[$this->position];
        }
        return null;
    }

    /**
     * Current key/position
     *
     * @return int
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Move to next object
     *
     * @return void
     */
    public function next(): void
    {
        $this->position++;

        // If we've exhausted current batch and there's more to fetch
        if ($this->position >= count($this->objects) && !$this->complete) {
            $this->fetchNextBatch();
            $this->position = 0;
        }
    }

    /**
     * Rewind to start
     *
     * Note: This refetches from the beginning. For large sets, avoid rewinding.
     *
     * @return void
     */
    public function rewind(): void
    {
        $this->objects = [];
        $this->position = 0;
        $this->nextToken = null;
        $this->complete = false;
        $this->fetchNextBatch();
    }

    /**
     * Check if current position is valid
     *
     * @return bool
     */
    public function valid(): bool
    {
        return $this->position < count($this->objects) || !$this->complete;
    }

    /**
     * Count total objects
     *
     * Warning: This may iterate through all objects if totalCount is not available.
     *
     * @return int
     */
    public function count(): int
    {
        if ($this->totalCount !== null) {
            return $this->totalCount;
        }

        // Have to iterate to count
        $count = 0;
        foreach ($this as $object) {
            $count++;
        }

        $this->totalCount = $count;
        return $count;
    }

    /**
     * Get all objects as array
     *
     * Warning: Loads all objects into memory. Use only for small sets.
     *
     * @return ObjectMetadata[]
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this as $object) {
            $result[] = $object;
        }
        return $result;
    }

    /**
     * Filter objects by a callback
     *
     * @param callable $callback Function that takes ObjectMetadata and returns bool
     * @return \Generator
     */
    public function filter(callable $callback): \Generator
    {
        foreach ($this as $object) {
            if ($callback($object)) {
                yield $object;
            }
        }
    }

    /**
     * Map objects with a callback
     *
     * @param callable $callback Function that takes ObjectMetadata and returns transformed value
     * @return \Generator
     */
    public function map(callable $callback): \Generator
    {
        foreach ($this as $object) {
            yield $callback($object);
        }
    }

    /**
     * Get only images
     *
     * @return \Generator
     */
    public function images(): \Generator
    {
        return $this->filter(fn($obj) => $obj->isImage());
    }

    /**
     * Get objects larger than size
     *
     * @param int $bytes Minimum size in bytes
     * @return \Generator
     */
    public function largerThan(int $bytes): \Generator
    {
        return $this->filter(fn($obj) => $obj->size > $bytes);
    }

    /**
     * Get objects smaller than size
     *
     * @param int $bytes Maximum size in bytes
     * @return \Generator
     */
    public function smallerThan(int $bytes): \Generator
    {
        return $this->filter(fn($obj) => $obj->size < $bytes);
    }
}
