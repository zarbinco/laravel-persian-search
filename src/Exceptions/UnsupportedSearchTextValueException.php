<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class UnsupportedSearchTextValueException extends InvalidArgumentException
{
    public static function forType(string $type): self
    {
        return new self("Search text value type [{$type}] is not supported.");
    }
}
