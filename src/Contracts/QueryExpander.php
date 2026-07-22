<?php

namespace Zarbinco\PersianSearch\Contracts;

use Zarbinco\PersianSearch\Search\ProcessedSearchQuery;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;

interface QueryExpander
{
    public function expand(ProcessedSearchQuery $query): QueryVariantCollection;

    public function original(ProcessedSearchQuery $query): QueryVariantCollection;
}
