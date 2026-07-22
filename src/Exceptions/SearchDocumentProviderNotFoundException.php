<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;
use Zarbinco\PersianSearch\Providers\ProviderKey;

final class SearchDocumentProviderNotFoundException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self('No search document provider is registered with key ['.ProviderKey::describe($key).'].');
    }

    public static function forSource(mixed $source): self
    {
        return new self('No search document provider supports source type ['.get_debug_type($source).'].');
    }
}
