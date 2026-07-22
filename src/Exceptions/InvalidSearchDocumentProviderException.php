<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Providers\ProviderKey;

final class InvalidSearchDocumentProviderException extends InvalidArgumentException
{
    public static function missingClass(string $class): self
    {
        return new self("Configured search document provider class [{$class}] does not exist.");
    }

    public static function invalidClass(string $class): self
    {
        return new self("Configured class [{$class}] must implement the search document provider contract.");
    }

    public static function duplicateClass(string $class): self
    {
        return new self("Search document provider class [{$class}] is configured more than once.");
    }

    public static function emptyKey(string $class): self
    {
        return new self("Search document provider [{$class}] returned an empty key.");
    }

    public static function nonCanonicalKey(string $class): self
    {
        return new self("Search document provider [{$class}] returned a non-canonical key with surrounding Unicode whitespace.");
    }

    public static function unsafeKey(string $class): self
    {
        return new self("Search document provider [{$class}] returned a key containing unsafe control or formatting characters.");
    }

    public static function unstableKey(string $class): self
    {
        return new self("Search document provider [{$class}] must return an exactly stable key.");
    }

    public static function emptyLookupKey(): self
    {
        return new self('Search document provider lookup key must not be empty.');
    }

    public static function unsafeLookupKey(string $description): self
    {
        return new self("Search document provider lookup key [{$description}] contains unsafe control or formatting characters.");
    }

    public static function duplicateKey(string $key): self
    {
        return new self('Search document provider key ['.ProviderKey::describe($key).'] is duplicated.');
    }

    public static function invalidConfiguration(mixed $value): self
    {
        return new self('Search document provider configuration must be a list of class strings; '.get_debug_type($value).' given.');
    }
}
