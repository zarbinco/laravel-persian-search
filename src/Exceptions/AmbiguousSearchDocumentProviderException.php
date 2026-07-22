<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;
use Zarbinco\PersianSearch\Providers\ProviderKey;

final class AmbiguousSearchDocumentProviderException extends RuntimeException
{
    /** @param list<string> $keys */
    public static function forKeys(array $keys): self
    {
        return new self('Multiple search document providers support the source: ['.implode(', ', array_map(
            ProviderKey::describe(...),
            $keys,
        )).'].');
    }
}
