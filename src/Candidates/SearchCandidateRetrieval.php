<?php

namespace Zarbinco\PersianSearch\Candidates;

use Countable;
use IteratorAggregate;
use Traversable;
use Zarbinco\PersianSearch\Search\SearchResultTruncationReason;

/** @implements IteratorAggregate<int, SearchCandidate> */
final readonly class SearchCandidateRetrieval implements Countable, IteratorAggregate
{
    /** @var list<SearchResultTruncationReason> */
    public array $truncationReasons;

    /** @param array<int, SearchResultTruncationReason> $truncationReasons */
    public function __construct(
        public SearchCandidateCollection $candidates,
        array $truncationReasons,
        public int $candidateLimit,
    ) {
        $requested = array_fill_keys(
            array_map(static fn (SearchResultTruncationReason $reason): string => $reason->value, $truncationReasons),
            true,
        );
        $this->truncationReasons = array_values(array_filter(
            SearchResultTruncationReason::ordered(),
            static fn (SearchResultTruncationReason $reason): bool => isset($requested[$reason->value]),
        ));
    }

    public function isTruncated(): bool
    {
        return $this->truncationReasons !== [];
    }

    public function count(): int
    {
        return count($this->candidates);
    }

    /** @return list<SearchCandidate> */
    public function all(): array
    {
        return $this->candidates->all();
    }

    public function isFull(): bool
    {
        return $this->candidates->isFull();
    }

    /** @return Traversable<int, SearchCandidate> */
    public function getIterator(): Traversable
    {
        return $this->candidates->getIterator();
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return $this->candidates->toArray();
    }
}
