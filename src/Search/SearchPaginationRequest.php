<?php

namespace Zarbinco\PersianSearch\Search;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchPaginationException;

final readonly class SearchPaginationRequest
{
    public int $offset;

    public function __construct(public int $page, public int $perPage)
    {
        if ($this->page < 1 || $this->perPage < 1) {
            throw new InvalidSearchPaginationException('Search page and per-page values must be positive.');
        }

        if ($this->page - 1 > intdiv(PHP_INT_MAX, $this->perPage)) {
            throw new InvalidSearchPaginationException('Search pagination offset exceeds the platform integer range.');
        }

        $this->offset = ($this->page - 1) * $this->perPage;
    }
}
