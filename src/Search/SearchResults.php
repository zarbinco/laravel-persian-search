<?php

namespace Zarbinco\PersianSearch\Search;

use ArrayIterator;
use Countable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/** @implements IteratorAggregate<int, SearchResult> */
final readonly class SearchResults implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var list<SearchResult> */
    public array $items;

    /** @var list<SearchResultTruncationReason> */
    public array $truncationReasons;

    public int $knownTotal;

    public bool $totalIsExact;

    public int $returned;

    public bool $isTruncated;

    public int $candidateLimit;

    public QueryVariantCollection $variants;

    public ProcessedSearchQuery $processedQuery;

    /**
     * @param  array<int, mixed>  $items
     */
    public function __construct(
        public SearchQuery $query,
        array $items,
        SearchResultWindow $window,
        public SearchFacetCollection $facets,
        public int $offset,
        public int $limit,
        public ?SearchSuggestion $suggestion = null,
    ) {
        foreach ($items as $item) {
            if (! $item instanceof SearchResult) {
                throw new InvalidArgumentException('Search result items must be SearchResult values.');
            }
        }

        if ($this->offset < 0 || $this->limit < 1 || count($items) > $this->limit
            || count($items) > $window->knownTotal()) {
            throw new InvalidArgumentException('Search result slice metadata is inconsistent.');
        }

        $this->items = array_values($items);
        $this->knownTotal = $window->knownTotal();
        $this->totalIsExact = $window->totalIsExact();
        $this->returned = count($this->items);
        $this->isTruncated = $window->isTruncated();
        $this->truncationReasons = $window->truncationReasons;
        $this->candidateLimit = $window->candidateLimit;
        $this->variants = $this->query->variants();
        $this->processedQuery = $this->query->processedQuery;
    }

    /** @return list<SearchResult> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return Collection<int, Model> */
    public function models(): Collection
    {
        return collect($this->items)
            ->map(static fn (SearchResult $result): ?Model => $result->model)
            ->filter(static fn (?Model $model): bool => $model !== null)
            ->values();
    }

    /** @return array<string, int> */
    public function typeCounts(): array
    {
        return $this->facets->sourceTypeCounts();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function status(): SearchQueryStatus
    {
        return $this->query->processedQuery->status;
    }

    public function isSearchableQuery(): bool
    {
        return $this->query->processedQuery->isSearchable();
    }

    public function count(): int
    {
        return $this->returned;
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
            'query' => $this->query->toArray(),
            'processed_query' => $this->processedQuery->toArray(),
            'variants' => $this->variants->toArray(),
            'known_total' => $this->knownTotal,
            'total_is_exact' => $this->totalIsExact,
            'returned' => $this->returned,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'is_truncated' => $this->isTruncated,
            'candidate_limit' => $this->candidateLimit,
            'suggestion' => $this->suggestion?->toArray(),
            'truncation_reasons' => array_map(
                static fn (SearchResultTruncationReason $reason): string => $reason->value,
                $this->truncationReasons,
            ),
            'facets' => $this->facets->toArray(),
            'items' => array_map(static fn (SearchResult $result): array => $result->toArray(), $this->items),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
