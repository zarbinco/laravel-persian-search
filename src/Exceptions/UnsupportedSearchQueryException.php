<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class UnsupportedSearchQueryException extends InvalidArgumentException
{
    public static function forType(string $type): self
    {
        return new self("Search query type [{$type}] is not supported; expected string, Stringable, or null.");
    }
}
