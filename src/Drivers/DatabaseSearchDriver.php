<?php

namespace Zarbinco\PersianSearch\Drivers;

use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Exceptions\SearchResultWindowExceededException;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Search\SearchExecutionProcessor;
use Zarbinco\PersianSearch\Search\SearchFacetBuilder;
use Zarbinco\PersianSearch\Search\SearchPage;
use Zarbinco\PersianSearch\Search\SearchPageMetadata;
use Zarbinco\PersianSearch\Search\SearchPaginationRequest;
use Zarbinco\PersianSearch\Search\SearchPresentedCandidate;
use Zarbinco\PersianSearch\Search\SearchPreview;
use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Search\SearchResultGroup;
use Zarbinco\PersianSearch\Search\SearchResultGroupCollection;
use Zarbinco\PersianSearch\Search\SearchResultHydrator;
use Zarbinco\PersianSearch\Search\SearchResultPolicy;
use Zarbinco\PersianSearch\Search\SearchResults;
use Zarbinco\PersianSearch\Search\SearchResultSlice;
use Zarbinco\PersianSearch\Search\SearchSuggestion;

final readonly class DatabaseSearchDriver implements SearchDriver
{
    public function __construct(
        private SearchExecutionProcessor $execution,
        private SearchResultPolicy $resultPolicy,
        private SearchFacetBuilder $facets,
        private SearchResultHydrator $hydrator,
    ) {}

    public function search(SearchQuery $query): SearchResults
    {
        $context = $this->execution->process($query);
        $query = $context->query ?? $query;
        $window = $context->window;
        $facets = $this->facets->build($window, $query->facetFields);
        $slice = SearchResultSlice::fromWindow($window, $query->offset, $query->limit);

        return new SearchResults(
            $query,
            $this->hydrator->hydrate($slice->candidates),
            $window,
            $facets,
            $slice->offset,
            $slice->limit,
            $this->visibleSuggestion($context->suggestion, $slice->candidates),
        );
    }

    public function paginate(SearchQuery $query, SearchPaginationRequest $request): SearchPage
    {
        $context = $this->execution->process($query);
        $query = $context->query ?? $query;
        $window = $context->window;

        if ($window->isTruncated() && $request->offset >= $window->knownTotal()) {
            throw SearchResultWindowExceededException::forPage(
                $request->page,
                $request->perPage,
                $window->knownTotal(),
                $window->candidateLimit,
            );
        }

        $slice = SearchResultSlice::fromWindow($window, $request->offset, $request->perPage);
        $facets = $this->facets->build($window, $query->facetFields);
        $metadata = new SearchPageMetadata(
            $request->page,
            $request->perPage,
            $slice->returned(),
            $window->knownTotal(),
            $window->totalIsExact(),
            $window->isTruncated(),
            $window->candidateLimit,
            $window->truncationReasons,
        );

        return new SearchPage(
            $this->hydrator->hydrate($slice->candidates),
            $metadata,
            $query->processedQuery,
            $query->variants(),
            $facets,
            $this->visibleSuggestion($context->suggestion, $slice->candidates),
        );
    }

    public function preview(SearchQuery $query, int $limit, int $perType): SearchPreview
    {
        $context = $this->execution->process($query, true);
        $query = $context->query ?? $query;
        $window = $context->window;
        $selected = [];
        $typeCounts = [];

        foreach ($window->candidates as $index => $candidate) {
            $type = $candidate->presentedDocument->source_type;

            if (($typeCounts[$type] ?? 0) < $perType) {
                $selected[$index] = $candidate;
                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            }

            if (count($selected) === $limit) {
                break;
            }
        }

        if (count($selected) < $limit) {
            foreach ($window->candidates as $index => $candidate) {
                $selected[$index] ??= $candidate;

                if (count($selected) === $limit) {
                    break;
                }
            }
        }

        ksort($selected);
        $facets = $this->facets->build($window, $query->facetFields);

        return new SearchPreview(
            $this->hydrator->hydrate(array_values($selected)),
            $limit,
            $perType,
            $window->knownTotal(),
            $window->totalIsExact(),
            $window->isTruncated(),
            $facets,
            $window->truncationReasons,
            $this->visibleSuggestion($context->suggestion, array_values($selected)),
        );
    }

    public function groupBySourceType(SearchQuery $query, int $perGroupLimit): SearchResultGroupCollection
    {
        $context = $this->execution->process($query);
        $window = $context->window;
        $grouped = [];

        foreach ($window->candidates as $index => $candidate) {
            $type = $candidate->presentedDocument->source_type;

            if (! isset($grouped[$type])) {
                $grouped[$type] = ['type' => $type, 'first' => $index, 'count' => 0, 'candidates' => []];
            }

            $grouped[$type]['count']++;

            if (count($grouped[$type]['candidates']) < $perGroupLimit) {
                $grouped[$type]['candidates'][] = $candidate;
            }
        }

        uasort($grouped, static fn (array $left, array $right): int => $left['first'] <=> $right['first']
            ?: $right['count'] <=> $left['count']
            ?: strcmp($left['type'], $right['type']));
        $knownGroupTotal = count($grouped);
        $grouped = array_slice($grouped, 0, $this->resultPolicy->maximumGroups, true);
        $selected = [];

        foreach ($grouped as $group) {
            foreach ($group['candidates'] as $candidate) {
                $selected[$candidate->identity()] = $candidate;
            }
        }

        $hydrated = $this->hydrator->hydrate(array_values($selected));
        $results = [];

        foreach ($hydrated as $result) {
            $results[(string) $result->record->getKey()] = $result;
        }

        $groups = [];

        foreach ($grouped as $type => $group) {
            $items = array_map(
                static fn (SearchPresentedCandidate $candidate) => $results[$candidate->identity()],
                $group['candidates'],
            );
            $groups[] = new SearchResultGroup($type, $group['count'], $window->totalIsExact(), $items);
        }

        $returnedGroups = count($groups);

        return new SearchResultGroupCollection(
            $groups,
            $window->totalIsExact(),
            $knownGroupTotal,
            $returnedGroups,
            $returnedGroups === $knownGroupTotal,
            $returnedGroups !== $knownGroupTotal,
            $this->resultPolicy->maximumGroups,
            $this->visibleSuggestion($context->suggestion, array_values($selected)),
        );
    }

    /** @param list<SearchPresentedCandidate> $candidates */
    private function visibleSuggestion(?SearchSuggestion $suggestion, array $candidates): ?SearchSuggestion
    {
        if ($suggestion === null || $suggestion->source !== QueryVariantSource::Contextual) {
            return $suggestion;
        }
        foreach ($candidates as $candidate) {
            foreach ($candidate->matchedCandidate->candidate->matches as $match) {
                if ($match->variant->fingerprint === $suggestion->variantFingerprint) {
                    return $suggestion;
                }
            }
        }

        return null;
    }
}
