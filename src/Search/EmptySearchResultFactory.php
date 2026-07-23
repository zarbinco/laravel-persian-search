<?php

namespace Zarbinco\PersianSearch\Search;

use Zarbinco\PersianSearch\Candidates\SearchCandidatePolicy;

final readonly class EmptySearchResultFactory
{
    public function __construct(
        private SearchCandidatePolicy $candidatePolicy,
        private SearchResultPolicy $resultPolicy,
    ) {}

    public function results(SearchQuery $query): SearchResults
    {
        return new SearchResults(
            $query,
            [],
            $this->window(),
            new SearchFacetCollection,
            $query->offset,
            $query->limit,
        );
    }

    public function page(SearchQuery $query, SearchPaginationRequest $request): SearchPage
    {
        return new SearchPage(
            [],
            new SearchPageMetadata(
                $request->page,
                $request->perPage,
                0,
                0,
                true,
                false,
                $this->candidatePolicy->maximumCandidates,
            ),
            $query->processedQuery,
            $query->variants(),
            new SearchFacetCollection,
        );
    }

    public function preview(SearchQuery $query, int $limit, int $perType): SearchPreview
    {
        return new SearchPreview(
            [],
            $limit,
            $perType,
            0,
            true,
            false,
            new SearchFacetCollection,
        );
    }

    public function groups(): SearchResultGroupCollection
    {
        return new SearchResultGroupCollection(
            [],
            true,
            0,
            0,
            true,
            false,
            $this->resultPolicy->maximumGroups,
        );
    }

    private function window(): SearchResultWindow
    {
        return new SearchResultWindow([], [], $this->candidatePolicy->maximumCandidates);
    }
}
