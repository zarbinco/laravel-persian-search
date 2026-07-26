<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Contextual\CandidateResultCount;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\SearchQuery;

interface QueryVariantResultCounter
{
    public function countVariant(QueryVariant $variant, SearchQuery $query): CandidateResultCount;
}
