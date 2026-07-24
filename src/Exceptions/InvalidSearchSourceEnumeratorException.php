<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Support\SafeDiagnosticValue;

final class InvalidSearchSourceEnumeratorException extends InvalidArgumentException
{
    public static function forClass(string $class): self
    {
        return new self('Invalid search source enumerator ['.SafeDiagnosticValue::describe($class).'].');
    }

    public static function duplicateClass(string $class): self
    {
        return new self('Duplicate search source enumerator class ['.SafeDiagnosticValue::describe($class).'].');
    }

    public static function duplicateKey(string $key): self
    {
        return new self('Duplicate search source enumerator key ['.SafeDiagnosticValue::describe($key).'].');
    }

    public static function unknownKey(string $key): self
    {
        return new self('Unknown search source enumerator key ['.SafeDiagnosticValue::describe($key).'].');
    }

    public static function unknownProvider(string $key): self
    {
        return new self('Unknown search document provider key ['.SafeDiagnosticValue::describe($key).'].');
    }

    public static function unstable(string $class, string $field): self
    {
        return new self(
            'Search source enumerator ['.SafeDiagnosticValue::describe($class).'] returned unstable '
            .SafeDiagnosticValue::describe($field).' metadata.',
        );
    }
}
