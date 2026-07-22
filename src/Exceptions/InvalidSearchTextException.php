<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;

final class InvalidSearchTextException extends RuntimeException
{
    public static function invalidUtf8(): self
    {
        return new self('Search text must contain valid UTF-8.');
    }

    public static function invalidTokens(): self
    {
        return new self('Search tokenizer returned invalid tokens.');
    }
}
