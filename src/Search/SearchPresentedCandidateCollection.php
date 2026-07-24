<?php

namespace Zarbinco\PersianSearch\Search;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
use Zarbinco\PersianSearch\Ranking\SearchRank;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidateCollection;

/** @implements IteratorAggregate<int, SearchPresentedCandidate> */
final readonly class SearchPresentedCandidateCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var list<SearchPresentedCandidate> */
    private array $candidates;

    /** @param array<int, mixed> $candidates */
    public function __construct(array $candidates)
    {
        $unique = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof SearchPresentedCandidate) {
                throw new InvalidArgumentException('Presented candidates must be typed values.');
            }

            $identity = $candidate->identity();
            $existing = $unique[$identity] ?? null;

            if ($existing === null || SearchRank::compare(
                $candidate->matchedCandidate->rank,
                $existing->matchedCandidate->rank,
            ) < 0) {
                $unique[$identity] = $candidate;
            }
        }

        $values = array_values($unique);
        usort($values, static fn (SearchPresentedCandidate $left, SearchPresentedCandidate $right): int => SearchRankedCandidateCollection::compare($left->matchedCandidate, $right->matchedCandidate));
        $this->candidates = $values;
    }

    /** @return list<SearchPresentedCandidate> */
    public function all(): array
    {
        return $this->candidates;
    }

    public function count(): int
    {
        return count($this->candidates);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->candidates);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(
            static fn (SearchPresentedCandidate $candidate): array => $candidate->toArray(),
            $this->candidates,
        );
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
