<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Candidates\SearchCandidateCollection;
use Zarbinco\PersianSearch\Search\SearchQuery;

interface SearchCandidateDriver
{
    public function candidates(SearchQuery $query): SearchCandidateCollection;
}
