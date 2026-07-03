<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final readonly class SearchResults
{
    /**
     * @param  list<SearchResult>  $items
     */
    public function __construct(
        public SearchQuery $query,
        private array $items,
        public int $total,
    ) {}

    /**
     * @return list<SearchResult>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return Collection<int, Model>
     */
    public function models(): Collection
    {
        return collect($this->items)->map(
            static fn (SearchResult $result): Model => $result->model,
        )->values();
    }

    /**
     * @return list<int|float>
     */
    public function scores(): array
    {
        return array_map(
            static fn (SearchResult $result): int|float => $result->score,
            $this->items,
        );
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return array{
     *     query: array<string, mixed>,
     *     total: int,
     *     items: list<array{
     *         model: Model,
     *         record: SearchDocumentRecord,
     *         score: int|float,
     *         matched_tokens: array<int, string>,
     *         candidate_source: string|null,
     *         matched_query: string|null
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query->toArray(),
            'total' => $this->total,
            'items' => array_map(
                static fn (SearchResult $result): array => $result->toArray(),
                $this->items,
            ),
        ];
    }
}
