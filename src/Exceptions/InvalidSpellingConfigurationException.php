<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSpellingConfigurationException extends InvalidArgumentException
{
    public static function forValue(string $key, mixed $value, string $expectation): self
    {
        $type = get_debug_type($value);

        return new self("Invalid Persian search spelling configuration [{$key}] ({$type}): {$expectation}.");
    }
}
