<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Search\SearchResults;

interface SearchDriver
{
    public function search(SearchQuery $query): SearchResults;
}
