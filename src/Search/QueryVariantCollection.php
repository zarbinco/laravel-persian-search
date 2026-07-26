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

    public function withPriorityReplacement(QueryVariant $variant): self
    {
        $collection = clone $this;
        if (! $collection->isFull()) {
            $collection->insert($variant);

            return $collection;
        }

        foreach ($collection->variants as $existing) {
            if ($existing->fingerprint === $variant->fingerprint) {
                return $collection;
            }
        }

        $byFingerprint = [];
        $parents = [];
        foreach ($collection->variants as $existing) {
            $byFingerprint[$existing->fingerprint] = $existing;
            if ($existing->parentFingerprint !== null) {
                $parents[$existing->parentFingerprint] = true;
            }
        }

        $protected = [];
        $fingerprint = $variant->parentFingerprint;
        while ($fingerprint !== null) {
            $parent = $byFingerprint[$fingerprint] ?? null;
            if ($parent === null || isset($protected[$fingerprint])) {
                return $collection;
            }
            $protected[$fingerprint] = true;
            $fingerprint = $parent->parentFingerprint;
        }

        $victim = null;
        foreach ($collection->variants as $index => $existing) {
            if ($existing->semanticKey() === $variant->semanticKey()) {
                if ($existing->source === QueryVariantSource::Original
                    || isset($protected[$existing->fingerprint])
                    || isset($parents[$existing->fingerprint])
                    || $existing->priority >= $variant->priority) {
                    return $collection;
                }

                $victim = ['index' => $index, 'variant' => $existing];

                break;
            }
        }

        if ($victim === null) {
            $victims = [];
            foreach ($collection->variants as $index => $existing) {
                if ($existing->source === QueryVariantSource::Original
                    || isset($protected[$existing->fingerprint])
                    || isset($parents[$existing->fingerprint])
                    || $existing->priority >= $variant->priority) {
                    continue;
                }
                $victims[] = ['index' => $index, 'variant' => $existing];
            }
            usort($victims, static fn (array $left, array $right): int => $left['variant']->priority <=> $right['variant']->priority
                ?: strcmp($left['variant']->source->value, $right['variant']->source->value)
                ?: strcmp($left['variant']->fingerprint, $right['variant']->fingerprint));
            $victim = $victims[0] ?? null;
            if ($victim === null) {
                return $collection;
            }
        }

        $replaced = [];
        $inserted = false;
        foreach ($collection->variants as $index => $existing) {
            if ($index === $victim['index']) {
                continue;
            }
            if (! $inserted && $existing->priority < $variant->priority) {
                $replaced[] = $variant;
                $inserted = true;
            }
            $replaced[] = $existing;
        }
        if (! $inserted) {
            $replaced[] = $variant;
        }
        $collection->variants = $replaced;

        return $collection;
    }

    public function contains(string $fingerprint): bool
    {
        foreach ($this->variants as $variant) {
            if ($variant->fingerprint === $fingerprint) {
                return true;
            }
        }

        return false;
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
