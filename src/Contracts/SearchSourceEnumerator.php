<?php

namespace Zarbinco\PersianSearch\Contracts;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocator;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumerationContext;

interface SearchSourceEnumerator
{
    public function key(): string;

    public function providerKey(): string;

    /** @return class-string<Model>|null */
    public function sourceModel(): ?string;

    /** @return iterable<SearchSourceLocator> */
    public function enumerate(SearchSourceEnumerationContext $context): iterable;
}
