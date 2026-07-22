<?php

namespace Zarbinco\PersianSearch\Exceptions;

use InvalidArgumentException;

final class InvalidSearchDocumentSetException extends InvalidArgumentException
{
    public static function invalidValue(string $providerKey, mixed $value): self
    {
        return new self("Search document provider [{$providerKey}] yielded invalid type [".get_debug_type($value).'].');
    }

    public static function sourceMismatch(string $providerKey, string $field): self
    {
        return new self("Search document provider [{$providerKey}] yielded a document with mismatched {$field}.");
    }

    public static function duplicateIdentity(string $providerKey, string $partition, string $sourceKey, string $locale): self
    {
        return new self("Search document provider [{$providerKey}] yielded duplicate identity [{$partition}|{$sourceKey}|{$locale}].");
    }
}
