<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchIndexingConfigurationException extends InvalidArgumentException
{
    public static function transactionAttempts(mixed $value): self
    {
        return new self('Search index transaction attempts must be an integer from 1 through 10; '.get_debug_type($value).' given.');
    }
}
