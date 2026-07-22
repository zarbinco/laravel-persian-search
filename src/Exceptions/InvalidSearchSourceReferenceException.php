<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchSourceReferenceException extends InvalidArgumentException
{
    public static function empty(string $field): self
    {
        return new self("Search source reference {$field} must not be empty.");
    }

    public static function invalidId(mixed $id): self
    {
        return new self('Search source reference ID must be an integer, string, or null; '.get_debug_type($id).' given.');
    }
}
