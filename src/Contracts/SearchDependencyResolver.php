<?php

namespace Zarbinco\PersianSearch\Contracts;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyContext;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocator;

interface SearchDependencyResolver
{
    public function key(): string;

    /** @return class-string<Model> */
    public function dependencyModel(): string;

    /** @return iterable<SearchSourceLocator> */
    public function resolve(SearchDependencyContext $context): iterable;
}
