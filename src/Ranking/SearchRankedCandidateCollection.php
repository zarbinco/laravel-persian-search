<?php

namespace Zarbinco\PersianSearch\Ranking;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, SearchRankedCandidate> */
final readonly class SearchRankedCandidateCollection implements Countable, IteratorAggregate
{
    /** @var list<SearchRankedCandidate> */
    private array $candidates;

    /** @param array<int, mixed> $candidates */
    public function __construct(array $candidates)
    {
        $unique = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof SearchRankedCandidate) {
                throw new InvalidArgumentException('Ranked candidates must be SearchRankedCandidate values.');
            }

            $identity = $candidate->candidate->identity();
            $existing = $unique[$identity] ?? null;

            if ($existing === null || SearchRank::compare($candidate->rank, $existing->rank) < 0) {
                $unique[$identity] = $candidate;
            }
        }

        $values = array_values($unique);
        usort($values, self::compare(...));
        $this->candidates = $values;
    }

    public static function compare(SearchRankedCandidate $left, SearchRankedCandidate $right): int
    {
        $leftDocument = $left->candidate->document;
        $rightDocument = $right->candidate->document;

        return $left->rank->tier->precedence() <=> $right->rank->tier->precedence()
            ?: $right->rank->variant->priority <=> $left->rank->variant->priority
            ?: $right->rank->coverageBasisPoints <=> $left->rank->coverageBasisPoints
            ?: $right->rank->matchedTokenCount <=> $left->rank->matchedTokenCount
            ?: $rightDocument->priority <=> $leftDocument->priority
            ?: $left->normalizedTitleLength <=> $right->normalizedTitleLength
            ?: strcmp($leftDocument->source_key, $rightDocument->source_key)
            ?: strcmp($leftDocument->partition, $rightDocument->partition)
            ?: strcmp($leftDocument->locale, $rightDocument->locale)
            ?: UnsignedDecimalStringComparator::compare(
                $left->candidate->identity(),
                $right->candidate->identity(),
            );
    }

    public function count(): int
    {
        return count($this->candidates);
    }

    /** @return list<SearchRankedCandidate> */
    public function all(): array
    {
        return $this->candidates;
    }

    /** @return Traversable<int, SearchRankedCandidate> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->candidates);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (SearchRankedCandidate $candidate): array => $candidate->toArray(), $this->candidates);
    }
}
