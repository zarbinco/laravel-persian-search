<?php

namespace Zarbinco\PersianSearch\Search;

use Zarbinco\PersianSearch\Ranking\SearchRankedCandidate;

final class SearchFacetBuilder
{
    /** @param list<SearchFacetField> $fields */
    public function build(SearchResultWindow $window, array $fields): SearchFacetCollection
    {
        if ($fields === []) {
            return new SearchFacetCollection;
        }

        $requested = array_fill_keys(array_map(static fn (SearchFacetField $field): string => $field->value, $fields), true);
        $facets = [];

        foreach (SearchFacetField::cases() as $field) {
            if (! isset($requested[$field->value])) {
                continue;
            }

            $counts = [];

            foreach ($window->candidates as $candidate) {
                $value = $this->value($candidate, $field);
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }

            $buckets = [];

            foreach ($counts as $value => $count) {
                $buckets[] = new SearchFacetBucket($value, $count);
            }

            $facets[] = new SearchFacet($field, $buckets, $window->totalIsExact());
        }

        return new SearchFacetCollection($facets);
    }

    private function value(SearchRankedCandidate $candidate, SearchFacetField $field): string
    {
        $document = $candidate->candidate->document;

        return match ($field) {
            SearchFacetField::SourceType => $document->source_type,
            SearchFacetField::Partition => $document->partition,
            SearchFacetField::Locale => $document->locale,
        };
    }
}
