<?php

namespace Zarbinco\PersianSearch\Search;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, QueryVariant> */
final class QueryVariantCollection implements Countable, IteratorAggregate
{
    /** @var list<QueryVariant> */
    private array $variants = [];

    /** @param iterable<QueryVariant> $variants */
    public function __construct(private readonly int $maximumVariants, iterable $variants = [])
    {
        if ($this->maximumVariants < 1) {
            throw new InvalidArgumentException('Maximum query variants must be greater than zero.');
        }

        foreach ($variants as $variant) {
            $this->insert($variant);
        }
    }

    public function with(QueryVariant $variant): self
    {
        $collection = clone $this;
        $collection->insert($variant);

        return $collection;
    }

    private function insert(QueryVariant $variant): bool
    {
        foreach ($this->variants as $index => $existing) {
            if ($existing->fingerprint === $variant->fingerprint) {
                return false;
            }

            if ($existing->semanticKey() === $variant->semanticKey()) {
                if ($variant->priority > $existing->priority && $existing->source !== QueryVariantSource::Original) {
                    $this->variants[$index] = $variant;

                    return true;
                }

                return false;
            }
        }

        if ($this->isFull()) {
            return false;
        }

        $this->variants[] = $variant;

        return true;
    }

    public function original(): ?QueryVariant
    {
        foreach ($this->variants as $variant) {
            if ($variant->source === QueryVariantSource::Original) {
                return $variant;
            }
        }

        return null;
    }

    /** @return list<QueryVariant> */
    public function all(): array
    {
        return $this->variants;
    }

    public function isFull(): bool
    {
        return count($this->variants) >= $this->maximumVariants;
    }

    public function isEmpty(): bool
    {
        return $this->variants === [];
    }

    public function count(): int
    {
        return count($this->variants);
    }

    /** @return Traversable<int, QueryVariant> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->variants);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (QueryVariant $variant): array => $variant->toArray(), $this->variants);
    }
}
