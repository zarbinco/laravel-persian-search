<?php

namespace Zarbinco\PersianSearch\Candidates;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, SearchCandidate> */
final class SearchCandidateCollection implements Countable, IteratorAggregate
{
    /** @var array<string, SearchCandidate> */
    private array $candidates = [];

    public function __construct(private readonly int $maximumCandidates)
    {
        if ($this->maximumCandidates < 1) {
            throw new InvalidArgumentException('Maximum search candidates must be positive.');
        }
    }

    public function with(SearchCandidate $candidate): self
    {
        $collection = clone $this;
        $identity = $candidate->identity();
        $existing = $collection->candidates[$identity] ?? null;

        if ($existing !== null) {
            foreach ($candidate->matches as $match) {
                $existing = $existing->withMatch($match);
            }

            $collection->candidates[$identity] = $existing;

            return $collection;
        }

        if (! $collection->isFull()) {
            $collection->candidates[$identity] = $candidate;
        }

        return $collection;
    }

    public function contains(string $documentId): bool
    {
        return isset($this->candidates[$documentId]);
    }

    public function isFull(): bool
    {
        return count($this->candidates) >= $this->maximumCandidates;
    }

    public function count(): int
    {
        return count($this->candidates);
    }

    /** @return list<SearchCandidate> */
    public function all(): array
    {
        return array_values($this->candidates);
    }

    /** @return Traversable<int, SearchCandidate> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (SearchCandidate $candidate): array => $candidate->toArray(), $this->all());
    }
}
