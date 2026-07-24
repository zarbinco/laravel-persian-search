<?php

namespace Zarbinco\PersianSearch\Search;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/** @implements IteratorAggregate<int, SearchResult> */
final readonly class SearchPage implements Countable, IteratorAggregate, JsonSerializable
{
    /** @param list<SearchResult> $items */
    public function __construct(
        public array $items,
        public SearchPageMetadata $metadata,
        public ProcessedSearchQuery $processedQuery,
        public QueryVariantCollection $variants,
        public SearchFacetCollection $facets,
        public ?SearchSuggestion $suggestion = null,
    ) {}

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
            'metadata' => $this->metadata->toArray(),
            'processed_query' => $this->processedQuery->toArray(),
            'variants' => $this->variants->toArray(),
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
