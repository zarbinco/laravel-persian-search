<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SearchFacet implements JsonSerializable
{
    /** @var list<SearchFacetBucket> */
    public array $buckets;

    /** @param array<int, mixed> $buckets */
    public function __construct(public SearchFacetField $field, array $buckets, public bool $countsAreExact)
    {
        foreach ($buckets as $bucket) {
            if (! $bucket instanceof SearchFacetBucket) {
                throw new InvalidArgumentException('Search facet buckets must be SearchFacetBucket values.');
            }
        }

        $values = array_values($buckets);
        $uniqueValues = [];

        foreach ($values as $bucket) {
            if (isset($uniqueValues[$bucket->value])) {
                throw new InvalidArgumentException('Search facet bucket values must be unique.');
            }

            $uniqueValues[$bucket->value] = true;
        }

        usort($values, static fn (SearchFacetBucket $left, SearchFacetBucket $right): int => $right->count <=> $left->count
            ?: strcmp($left->value, $right->value));
        $this->buckets = $values;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'field' => $this->field->value,
            'counts_are_exact' => $this->countsAreExact,
            'buckets' => array_map(static fn (SearchFacetBucket $bucket): array => $bucket->toArray(), $this->buckets),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
