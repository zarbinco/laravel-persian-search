<?php

namespace Zarbinco\PersianSearch\Candidates;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Search\QueryVariant;

final readonly class SearchCandidatePlan
{
    /** @var list<string> */
    public array $terms;

    /** @var list<SearchDocumentField> */
    public array $fields;

    /** @var list<string> */
    public array $sourceTypes;

    /**
     * @param  array<int, mixed>  $terms
     * @param  array<int, mixed>  $fields
     * @param  array<int, mixed>  $sourceTypes
     */
    public function __construct(
        public QueryVariant $variant,
        array $terms,
        array $fields,
        public ?string $partition,
        array $sourceTypes,
        public int $limit,
    ) {
        $this->terms = $this->nonEmptyUniqueStrings($terms, 'terms');

        if ($this->terms === []) {
            throw new InvalidArgumentException('Search candidate plan terms must not be empty.');
        }

        if ($fields !== SearchDocumentField::cases()) {
            throw new InvalidArgumentException('Search candidate plan fields must use the complete ordered field set.');
        }

        $this->fields = $fields;
        $this->sourceTypes = $this->sourceTypes($sourceTypes);

        if ($this->partition !== null && ($this->partition === '' || trim($this->partition) !== $this->partition)) {
            throw new InvalidArgumentException('Search candidate plan partition must be null or non-empty.');
        }

        if ($this->limit < 1) {
            throw new InvalidArgumentException('Search candidate plan limit must be positive.');
        }
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function nonEmptyUniqueStrings(array $values, string $name): array
    {
        $unique = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("Search candidate plan {$name} must be non-empty strings.");
            }

            $unique[$value] = $value;
        }

        return array_values($unique);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function sourceTypes(array $values): array
    {
        $types = $this->nonEmptyUniqueStrings($values, 'source types');

        foreach ($types as $type) {
            if (trim($type) !== $type) {
                throw new InvalidArgumentException('Search candidate plan source types must be canonical strings.');
            }
        }

        return $types;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'variant' => $this->variant->toArray(),
            'terms' => $this->terms,
            'fields' => array_map(static fn (SearchDocumentField $field): string => $field->value, $this->fields),
            'partition' => $this->partition,
            'source_types' => $this->sourceTypes,
            'limit' => $this->limit,
        ];
    }
}
