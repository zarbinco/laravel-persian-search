<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchOperationsConfigurationException extends InvalidArgumentException
{
    public static function forKey(string $key, string $expected): self
    {
        return new self("Search operations configuration [{$key}] must be {$expected}.");
    }
}
