<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Providers\ProviderKey;

final class InvalidEloquentSearchSourceLocatorException extends InvalidArgumentException
{
    public static function invalidModel(string $class): self
    {
        return new self('Eloquent search source locator model ['.ProviderKey::describe($class).'] is invalid.');
    }

    public static function unpersisted(string $class): self
    {
        return new self('Eloquent search source ['.ProviderKey::describe($class).'] has not been persisted.');
    }

    public static function invalidField(string $field): self
    {
        return new self("Eloquent search source locator [{$field}] must be a non-empty canonical string.");
    }

    public static function keyNameMismatch(string $expected, string $actual): self
    {
        return new self(
            'Eloquent search source locator key name ['.ProviderKey::describe($expected).'] does not match model key ['.
            ProviderKey::describe($actual).'].',
        );
    }
}
