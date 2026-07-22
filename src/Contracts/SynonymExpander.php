<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Query\SynonymExpansion;
use Zarbinco\PersianSearch\Search\QueryVariant;

interface SynonymExpander
{
    /** @return iterable<SynonymExpansion> */
    public function expand(QueryVariant $variant): iterable;
}
