<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchableRelationException extends InvalidArgumentException
{
    public static function invalid(mixed $relation): self
    {
        return new self('Searchable relations must be non-empty strings; '.get_debug_type($relation).' given.');
    }
}
