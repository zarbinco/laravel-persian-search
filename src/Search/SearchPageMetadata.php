<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SearchPageMetadata implements JsonSerializable
{
    /** @var list<SearchResultTruncationReason> */
    public array $truncationReasons;

    public ?int $lastPage;

    public bool $hasPreviousPage;

    public bool $hasNextPage;

    public ?int $from;

    public ?int $to;

    /** @param array<int, mixed> $truncationReasons */
    public function __construct(
        public int $page,
        public int $perPage,
        public int $returned,
        public int $knownTotal,
        public bool $totalIsExact,
        public bool $isTruncated,
        public int $candidateLimit,
        array $truncationReasons = [],
    ) {
        if ($this->page < 1 || $this->perPage < 1) {
            throw new InvalidArgumentException('Search page and per-page values must be positive.');
        }

        if ($this->returned < 0 || $this->knownTotal < 0 || $this->returned > $this->perPage
            || $this->returned > $this->knownTotal) {
            throw new InvalidArgumentException('Search page counts are inconsistent.');
        }

        if ($this->candidateLimit < 1) {
            throw new InvalidArgumentException('Search page candidate limit must be positive.');
        }

        if ($this->totalIsExact === $this->isTruncated) {
            throw new InvalidArgumentException('Search page exactness and truncation flags are inconsistent.');
        }

        if ($this->page - 1 > intdiv(PHP_INT_MAX, $this->perPage)) {
            throw new InvalidArgumentException('Search page offset exceeds the platform integer range.');
        }

        $this->truncationReasons = SearchResultTruncationReason::normalize($truncationReasons);

        if (($this->isTruncated && $this->truncationReasons === [])
            || (! $this->isTruncated && $this->truncationReasons !== [])) {
            throw new InvalidArgumentException('Search page truncation reasons do not match its truncation state.');
        }

        $offset = ($this->page - 1) * $this->perPage;

        if ($this->returned > 0
            && ($offset >= $this->knownTotal || $this->returned > $this->knownTotal - $offset)) {
            throw new InvalidArgumentException('Search page positions exceed the known result window.');
        }

        $this->lastPage = $this->totalIsExact
            ? ($this->knownTotal === 0 ? 1 : intdiv($this->knownTotal - 1, $this->perPage) + 1)
            : null;
        $this->hasPreviousPage = $this->page > 1;
        $this->hasNextPage = $offset + $this->returned < $this->knownTotal || $this->isTruncated;
        $this->from = $this->returned === 0 ? null : $offset + 1;
        $this->to = $this->returned === 0 ? null : $offset + $this->returned;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'returned' => $this->returned,
            'known_total' => $this->knownTotal,
            'total_is_exact' => $this->totalIsExact,
            'last_page' => $this->lastPage,
            'has_previous_page' => $this->hasPreviousPage,
            'has_next_page' => $this->hasNextPage,
            'from' => $this->from,
            'to' => $this->to,
            'is_truncated' => $this->isTruncated,
            'truncation_reasons' => array_map(
                static fn (SearchResultTruncationReason $reason): string => $reason->value,
                $this->truncationReasons,
            ),
            'candidate_limit' => $this->candidateLimit,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
