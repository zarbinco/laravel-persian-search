<?php

namespace Zarbinco\PersianSearch\Indexing;

final readonly class SearchDocument
{
    /**
     * @param  array<int, string>  $tokens
     * @param  list<SearchField>  $fields
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $searchableType,
        public int|string|null $searchableId,
        public ?string $locale,
        public string $title,
        public string $content,
        public array $tokens,
        public array $fields,
        public array $metadata,
    ) {}

    /**
     * @return array{
     *     searchable_type: string,
     *     searchable_id: int|string|null,
     *     locale: string|null,
     *     title: string,
     *     content: string,
     *     tokens: array<int, string>,
     *     fields: list<array{name: string, raw_value: mixed, value: string, tokens: array<int, string>, weight: int|float}>,
     *     metadata: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'searchable_type' => $this->searchableType,
            'searchable_id' => $this->searchableId,
            'locale' => $this->locale,
            'title' => $this->title,
            'content' => $this->content,
            'tokens' => $this->tokens,
            'fields' => array_map(
                static fn (SearchField $field): array => $field->toArray(),
                $this->fields,
            ),
            'metadata' => $this->metadata,
        ];
    }
}
