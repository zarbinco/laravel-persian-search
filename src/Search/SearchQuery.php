<?php

namespace Zarbinco\PersianSearch\Search;

final readonly class SearchQuery
{
    /**
     * @param  array<int, string>  $tokens
     * @param  list<class-string>  $searchableTypes
     */
    public function __construct(
        public string $original,
        public string $normalized,
        public array $tokens,
        public array $searchableTypes,
        public ?string $locale,
        public int $limit,
        public int $offset,
        public bool $includeScores,
    ) {}

    public function hasSearchableTypes(): bool
    {
        return $this->searchableTypes !== [];
    }

    public function isEmpty(): bool
    {
        return trim($this->normalized) === '' && $this->tokens === [];
    }

    /**
     * @return array{
     *     original: string,
     *     normalized: string,
     *     tokens: array<int, string>,
     *     searchable_types: list<class-string>,
     *     locale: string|null,
     *     limit: int,
     *     offset: int,
     *     include_scores: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'original' => $this->original,
            'normalized' => $this->normalized,
            'tokens' => $this->tokens,
            'searchable_types' => $this->searchableTypes,
            'locale' => $this->locale,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'include_scores' => $this->includeScores,
        ];
    }
}
