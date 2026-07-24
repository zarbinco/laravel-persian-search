<?php

namespace Zarbinco\PersianSearch\Operations;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Contracts\SearchSourceEnumerator;

final readonly class SearchSourceEnumeratorRegistration
{
    /** @param class-string<SearchSourceEnumerator> $enumeratorClass
     * @param  class-string<Model>|null  $sourceModel
     */
    public function __construct(
        public SearchSourceEnumerator $enumerator,
        public string $enumeratorClass,
        public string $key,
        public string $providerKey,
        public ?string $sourceModel,
        public bool $authoritative,
    ) {}
}
