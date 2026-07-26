<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Contextual\CandidateResultCount;
use Zarbinco\PersianSearch\Contextual\ContextualCandidate;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidateCollection;
use Zarbinco\PersianSearch\Search\SearchQuery;

interface CandidateResultCounter
{
    public function reset(): void;

    public function directCount(
        SearchQuery $query,
        SearchRankedCandidateCollection $ranked,
        bool $exact,
    ): CandidateResultCount;

    public function countResults(ContextualCandidate $candidate, SearchQuery $query): CandidateResultCount;
}
