<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class SearchResults
{
    /** @param  list<SearchResult>  $items */
    public function __construct(
        public SearchQuery $query,
        public ProcessedSearchQuery $processedQuery,
        private array $items,
        public int $total,
    ) {}

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

    /** @return list<int|float> */
    public function scores(): array
    {
        return array_map(static fn (SearchResult $result): int|float => $result->score, $this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function status(): SearchQueryStatus
    {
        return $this->processedQuery->status;
    }

    public function isSearchableQuery(): bool
    {
        return $this->processedQuery->isSearchable();
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'query' => $this->query->toArray(),
            'processed_query' => $this->processedQuery->toArray(),
            'total' => $this->total,
            'items' => array_map(static fn (SearchResult $result): array => $result->toArray(), $this->items),
        ];
    }
}
