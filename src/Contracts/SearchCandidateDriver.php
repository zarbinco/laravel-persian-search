<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Candidates\SearchCandidateRetrieval;
use Zarbinco\PersianSearch\Search\SearchQuery;

interface SearchCandidateDriver
{
    public function candidates(SearchQuery $query): SearchCandidateRetrieval;
}
