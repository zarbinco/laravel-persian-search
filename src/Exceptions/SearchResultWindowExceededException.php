<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;

final class SearchResultWindowExceededException extends RuntimeException
{
    public static function forPage(int $page, int $perPage, int $knownTotal, int $candidateLimit): self
    {
        return new self(
            "Search page [{$page}] with per-page [{$perPage}] exceeds the truncated known result window "
            ."[{$knownTotal}] and candidate limit [{$candidateLimit}]. Increase candidate limits or apply narrower filters.",
        );
    }
}
