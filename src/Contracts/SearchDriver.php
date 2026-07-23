<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Search\SearchPage;
use Zarbinco\PersianSearch\Search\SearchPaginationRequest;
use Zarbinco\PersianSearch\Search\SearchPreview;
use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Search\SearchResultGroupCollection;
use Zarbinco\PersianSearch\Search\SearchResults;

interface SearchDriver
{
    public function search(SearchQuery $query): SearchResults;

    public function paginate(SearchQuery $query, SearchPaginationRequest $request): SearchPage;

    public function preview(SearchQuery $query, int $limit, int $perType): SearchPreview;

    public function groupBySourceType(SearchQuery $query, int $perGroupLimit): SearchResultGroupCollection;
}
