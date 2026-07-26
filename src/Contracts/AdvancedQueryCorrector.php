<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Correction\AdvancedCorrectionCollection;
use Zarbinco\PersianSearch\Search\QueryVariant;

interface AdvancedQueryCorrector
{
    public function correct(QueryVariant $variant): AdvancedCorrectionCollection;
}
