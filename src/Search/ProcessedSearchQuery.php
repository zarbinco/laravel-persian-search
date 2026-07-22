<?php

namespace Zarbinco\PersianSearch\Search;

final readonly class ProcessedSearchQuery
{
    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $searchableTokens
     */
    public function __construct(
        public string $rawQuery,
        public string $processedRawQuery,
        public string $locale,
        public string $sanitizedQuery,
        public string $normalizedQuery,
        public array $tokens,
        public array $searchableTokens,
        public SearchQueryStatus $status,
        public bool $wasTruncated,
        public int $originalLength,
        public int $processedLength,
    ) {}

    public function isSearchable(): bool
    {
        return $this->status === SearchQueryStatus::Ready;
    }

    /**
     * @return array{
     *     raw_query: string,
     *     processed_raw_query: string,
     *     locale: string,
     *     sanitized_query: string,
     *     normalized_query: string,
     *     tokens: list<string>,
     *     searchable_tokens: list<string>,
     *     status: string,
     *     was_truncated: bool,
     *     original_length: int,
     *     processed_length: int
     * }
     */
    public function toArray(): array
    {
        return [
            'raw_query' => $this->rawQuery,
            'processed_raw_query' => $this->processedRawQuery,
            'locale' => $this->locale,
            'sanitized_query' => $this->sanitizedQuery,
            'normalized_query' => $this->normalizedQuery,
            'tokens' => $this->tokens,
            'searchable_tokens' => $this->searchableTokens,
            'status' => $this->status->value,
            'was_truncated' => $this->wasTruncated,
            'original_length' => $this->originalLength,
            'processed_length' => $this->processedLength,
        ];
    }
}
