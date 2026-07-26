<?php

namespace Zarbinco\PersianSearch\Search;

final readonly class SearchExecutionContext
{
    public function __construct(
        public SearchResultWindow $window,
        public ?SearchSuggestion $suggestion,
        public ?SearchQuery $query = null,
    ) {}
}
