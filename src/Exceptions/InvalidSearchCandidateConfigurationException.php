<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchCandidateConfigurationException extends InvalidArgumentException
{
    public static function forKey(string $key, int $maximum, mixed $value): self
    {
        return new self(
            "Search candidate configuration [{$key}] must be an integer from 1 through {$maximum}; ".
            get_debug_type($value).' given.',
        );
    }
}
