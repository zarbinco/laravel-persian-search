<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Contracts\SearchDependencyResolver;

final readonly class SearchDependencyResolverRegistration
{
    /**
     * @param  class-string<SearchDependencyResolver>  $resolverClass
     * @param  class-string<Model>  $dependencyModel
     */
    public function __construct(
        public SearchDependencyResolver $resolver,
        public string $resolverClass,
        public string $key,
        public string $dependencyModel,
    ) {}
}
