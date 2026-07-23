<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchResultConfigurationException extends InvalidArgumentException
{
    public static function forValue(string $key, mixed $value, string $reason): self
    {
        $display = is_scalar($value) || $value === null ? var_export($value, true) : get_debug_type($value);

        return new self("Invalid search result configuration [{$key}] value [{$display}]: {$reason}.");
    }
}
