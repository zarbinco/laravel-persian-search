<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchableFieldException extends InvalidArgumentException
{
    public static function invalidFieldName(mixed $field): self
    {
        return new self(sprintf(
            'Persian searchable field names must be strings; [%s] given.',
            get_debug_type($field),
        ));
    }

    public static function invalidWeight(string $field, mixed $weight): self
    {
        return new self(sprintf(
            'Persian searchable field [%s] must use a numeric weight; [%s] given.',
            $field,
            get_debug_type($weight),
        ));
    }

    public static function unresolvable(string $field, string $reason): self
    {
        return new self(sprintf(
            'Persian searchable field [%s] could not be resolved safely: %s',
            $field,
            $reason,
        ));
    }
}
