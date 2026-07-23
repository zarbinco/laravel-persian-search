<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Candidates\SearchCandidateCollection;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidateCollection;

interface SearchRanker
{
    public function rank(SearchCandidateCollection $candidates): SearchRankedCandidateCollection;
}
