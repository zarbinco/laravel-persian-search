<?php

namespace Zarbinco\PersianSearch\Search;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/** @implements IteratorAggregate<int, SearchFacet> */
final readonly class SearchFacetCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var list<SearchFacet> */
    private array $facets;

    /** @param array<int, SearchFacet> $facets */
    public function __construct(array $facets = [])
    {
        $map = [];

        foreach ($facets as $facet) {
            $map[$facet->field->value] ??= $facet;
        }

        $this->facets = array_values(array_filter(array_map(
            static fn (SearchFacetField $field): ?SearchFacet => $map[$field->value] ?? null,
            SearchFacetField::cases(),
        )));
    }

    public function get(SearchFacetField $field): ?SearchFacet
    {
        foreach ($this->facets as $facet) {
            if ($facet->field === $field) {
                return $facet;
            }
        }

        return null;
    }

    /** @return array<string, int> */
    public function sourceTypeCounts(): array
    {
        $counts = [];
        $facet = $this->get(SearchFacetField::SourceType);

        foreach ($facet === null ? [] : $facet->buckets as $bucket) {
            $counts[$bucket->value] = $bucket->count;
        }

        return $counts;
    }

    public function count(): int
    {
        return count($this->facets);
    }

    /** @return Traversable<int, SearchFacet> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->facets);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (SearchFacet $facet): array => $facet->toArray(), $this->facets);
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
