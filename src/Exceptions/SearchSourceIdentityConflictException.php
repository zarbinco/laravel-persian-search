<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;
use Zarbinco\PersianSearch\Providers\ProviderKey;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final class SearchSourceIdentityConflictException extends RuntimeException
{
    public static function forReference(SearchSourceReference $reference): self
    {
        return new self(
            'Persisted search documents conflict with source key ['.ProviderKey::describe($reference->sourceKey).
            '], type ['.ProviderKey::describe($reference->sourceType).'], and ID ['.
            ($reference->sourceId === null ? 'null' : ProviderKey::describe($reference->sourceId)).
            '] using the same logical source key.',
        );
    }

    public static function duplicateIdentity(string $partition, string $sourceKey, string $locale): self
    {
        return new self(
            'Persisted search document identity ['.ProviderKey::describe($partition).'|'.
            ProviderKey::describe($sourceKey).'|'.ProviderKey::describe($locale).'] is duplicated.',
        );
    }
}
