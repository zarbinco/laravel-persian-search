<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchLifecycleConfigurationException extends InvalidArgumentException
{
    public static function forKey(string $key, string $expectation, mixed $value): self
    {
        return new self("Search lifecycle configuration [{$key}] must be {$expectation}; ".get_debug_type($value).' given.');
    }
}
