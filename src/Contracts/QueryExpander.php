<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Search\QueryCandidate;
use Zarbinco\PersianSearch\Search\SearchQuery;

interface QueryExpander
{
    /**
     * @return list<QueryCandidate>
     */
    public function expand(SearchQuery $query): array;
}
