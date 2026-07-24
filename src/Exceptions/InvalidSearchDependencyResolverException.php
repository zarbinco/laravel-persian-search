<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final class InvalidSearchDependencyResolverException extends InvalidArgumentException
{
    public static function forClass(string $class): self
    {
        return new self('Configured search dependency resolver ['.self::describe($class).'] is invalid.');
    }

    public static function duplicateClass(string $class): self
    {
        return new self('Search dependency resolver class ['.self::describe($class).'] is duplicated.');
    }

    public static function duplicateKey(string $key): self
    {
        return new self('Search dependency resolver key ['.self::describe($key).'] is duplicated.');
    }

    public static function unstableKey(string $class): self
    {
        return new self('Search dependency resolver ['.self::describe($class).'] returned an unstable key.');
    }

    public static function unstableDependencyModel(string $class): self
    {
        return new self('Search dependency resolver ['.self::describe($class).'] returned an unstable dependency model.');
    }

    private static function describe(string $value): string
    {
        if (CanonicalConfigurationName::isValid($value)) {
            return $value;
        }

        return 'unsafe-sha256:'.hash('sha256', $value).';bytes='.strlen($value);
    }
}
