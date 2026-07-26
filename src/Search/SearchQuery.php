<?php

namespace Zarbinco\PersianSearch\Search;

final readonly class SearchQuery
{
    /**
     * @param  array<int, string>  $tokens
     * @param  list<string>  $sourceTypes
     * @param  list<SearchFacetField>  $facetFields
     */
    public function __construct(
        public string $original,
        public string $normalized,
        public array $tokens,
        public array $sourceTypes,
        public ?string $locale,
        public string $partition,
        public int $limit,
        public int $offset,
        public ProcessedSearchQuery $processedQuery,
        private QueryVariantCollection $variants,
        public array $facetFields = [],
    ) {}

    public function hasSourceTypes(): bool
    {
        return $this->sourceTypes !== [];
    }

    public function isEmpty(): bool
    {
        return $this->variants->isEmpty();
    }

    public function variants(): QueryVariantCollection
    {
        return $this->variants;
    }

    public function withVariants(QueryVariantCollection $variants): self
    {
        return new self(
            original: $this->original,
            normalized: $this->normalized,
            tokens: $this->tokens,
            sourceTypes: $this->sourceTypes,
            locale: $this->locale,
            partition: $this->partition,
            limit: $this->limit,
            offset: $this->offset,
            processedQuery: $this->processedQuery,
            variants: $variants,
            facetFields: $this->facetFields,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'original' => $this->original,
            'normalized' => $this->normalized,
            'tokens' => $this->tokens,
            'source_types' => $this->sourceTypes,
            'locale' => $this->locale,
            'partition' => $this->partition,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'facets' => array_map(static fn (SearchFacetField $field): string => $field->value, $this->facetFields),
            'processed_query' => $this->processedQuery->toArray(),
            'variants' => $this->variants->toArray(),
        ];
    }
}
