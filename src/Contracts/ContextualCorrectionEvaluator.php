<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Contextual\CandidateResultCount;
use Zarbinco\PersianSearch\Contextual\ContextualCorrectionCollection;
use Zarbinco\PersianSearch\Search\SearchQuery;

interface ContextualCorrectionEvaluator
{
    public function evaluate(
        SearchQuery $query,
        CandidateResultCount $directResults,
        bool $preview = false,
    ): ContextualCorrectionCollection;
}
