<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidQueryVariantConfigurationException extends InvalidArgumentException
{
    public static function forValue(string $key, mixed $value, string $requirement): self
    {
        $display = is_scalar($value) || $value === null ? var_export($value, true) : get_debug_type($value);

        return new self("Invalid [persian-search.{$key}] value [{$display}]; {$requirement}.");
    }
}
