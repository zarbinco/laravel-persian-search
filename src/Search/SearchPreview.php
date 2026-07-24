<?php

namespace Zarbinco\PersianSearch\Search;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/** @implements IteratorAggregate<int, SearchResult> */
final readonly class SearchPreview implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var list<SearchResultTruncationReason> */
    public array $truncationReasons;

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, mixed>  $truncationReasons
     */
    public function __construct(
        public array $items,
        public int $limit,
        public int $perType,
        public int $knownTotal,
        public bool $totalIsExact,
        public bool $isTruncated,
        public SearchFacetCollection $facets,
        array $truncationReasons = [],
        public ?SearchSuggestion $suggestion = null,
    ) {
        if (! array_is_list($this->items)) {
            throw new InvalidArgumentException('Search preview items must be a list.');
        }

        foreach ($this->items as $item) {
            if (! $item instanceof SearchResult) {
                throw new InvalidArgumentException('Search preview items must be search results.');
            }
        }

        if ($this->limit < 1 || $this->perType < 1 || $this->knownTotal < 0
            || count($this->items) > $this->limit || count($this->items) > $this->knownTotal) {
            throw new InvalidArgumentException('Search preview limits and counts are inconsistent.');
        }

        if ($this->totalIsExact === $this->isTruncated) {
            throw new InvalidArgumentException('Search preview exactness and truncation flags are inconsistent.');
        }

        $this->truncationReasons = SearchResultTruncationReason::normalize($truncationReasons);

        if (($this->isTruncated && $this->truncationReasons === [])
            || (! $this->isTruncated && $this->truncationReasons !== [])) {
            throw new InvalidArgumentException('Search preview truncation reasons do not match its truncation state.');
        }
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int, SearchResult> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'per_type' => $this->perType,
            'returned' => count($this->items),
            'known_total' => $this->knownTotal,
            'total_is_exact' => $this->totalIsExact,
            'is_truncated' => $this->isTruncated,
            'truncation_reasons' => array_map(
                static fn (SearchResultTruncationReason $reason): string => $reason->value,
                $this->truncationReasons,
            ),
            'facets' => $this->facets->toArray(),
            'suggestion' => $this->suggestion?->toArray(),
            'items' => array_map(static fn (SearchResult $result): array => $result->toArray(), $this->items),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
