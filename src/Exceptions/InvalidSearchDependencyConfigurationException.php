<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchDependencyConfigurationException extends InvalidArgumentException
{
    public static function forKey(string $key, string $expected, mixed $value): self
    {
        return new self("Invalid search dependency configuration [{$key}]; expected {$expected}, got ".get_debug_type($value).'.');
    }
}
