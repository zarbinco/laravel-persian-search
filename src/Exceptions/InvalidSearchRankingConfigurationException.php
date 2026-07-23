<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchRankingConfigurationException extends InvalidArgumentException
{
    public static function forValue(string $key, mixed $value, string $reason): self
    {
        $display = is_scalar($value) || $value === null
            ? var_export($value, true)
            : get_debug_type($value);

        return new self("Invalid search ranking configuration [{$key}] value [{$display}]: {$reason}.");
    }
}
