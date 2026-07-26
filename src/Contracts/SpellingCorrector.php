<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Spelling\SpellingCorrectionCollection;

interface SpellingCorrector
{
    public function correct(QueryVariant $variant): SpellingCorrectionCollection;
}
