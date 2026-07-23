<?php

namespace Zarbinco\PersianSearch\Candidates;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Search\QueryVariant;

final readonly class SearchCandidateMatch
{
    /** @var list<SearchDocumentField> */
    public array $fields;

    /** @var list<string> */
    public array $terms;

    /**
     * @param  array<int, mixed>  $fields
     * @param  array<int, mixed>  $terms
     */
    public function __construct(
        public QueryVariant $variant,
        array $fields,
        array $terms,
    ) {
        $fieldMap = [];

        foreach ($fields as $field) {
            if (! $field instanceof SearchDocumentField) {
                throw new InvalidArgumentException('Search candidate match fields must be SearchDocumentField values.');
            }

            $fieldMap[$field->value] = $field;
        }

        $this->fields = array_values($fieldMap);
        $termMap = [];

        foreach ($terms as $term) {
            if (! is_string($term) || $term === '') {
                throw new InvalidArgumentException('Search candidate match terms must be non-empty strings.');
            }

            $termMap[$term] = $term;
        }

        $this->terms = array_values($termMap);

        if ($this->fields === [] || $this->terms === []) {
            throw new InvalidArgumentException('Search candidate match evidence must not be empty.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'variant' => $this->variant->toArray(),
            'fields' => array_map(static fn (SearchDocumentField $field): string => $field->value, $this->fields),
            'terms' => $this->terms,
        ];
    }
}
