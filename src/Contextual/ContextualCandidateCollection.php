<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, ContextualCandidate> */
final class ContextualCandidateCollection implements Countable, IteratorAggregate
{
    /** @var list<ContextualCandidate> */
    private array $items = [];

    /** @param iterable<ContextualCandidate> $items */
    public function __construct(private readonly int $maximum, iterable $items = [])
    {
        if ($this->maximum < 1) {
            throw new InvalidArgumentException('Maximum contextual candidates must be positive.');
        }
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function add(ContextualCandidate $candidate): void
    {
        if ($this->isFull()) {
            return;
        }
        foreach ($this->items as $existing) {
            if ($existing->fingerprint === $candidate->fingerprint
                || ($existing->locale === $candidate->locale && $existing->correctedQuery === $candidate->correctedQuery)) {
                return;
            }
        }
        $this->items[] = $candidate;
    }

    /** @return list<ContextualCandidate> */
    public function all(): array
    {
        return $this->items;
    }

    public function isFull(): bool
    {
        return count($this->items) >= $this->maximum;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int, ContextualCandidate> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
