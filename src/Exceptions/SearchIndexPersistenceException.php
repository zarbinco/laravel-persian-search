<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;
use Throwable;
use Zarbinco\PersianSearch\Indexing\SearchDocumentIdentity;
use Zarbinco\PersianSearch\Providers\ProviderKey;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final class SearchIndexPersistenceException extends RuntimeException
{
    public static function createRejected(SearchDocumentIdentity $identity, ?Throwable $previous = null): self
    {
        return self::rejected('create', $identity, $previous);
    }

    public static function updateRejected(SearchDocumentIdentity $identity, ?Throwable $previous = null): self
    {
        return self::rejected('update', $identity, $previous);
    }

    public static function deleteRejected(SearchDocumentIdentity $identity, ?Throwable $previous = null): self
    {
        return self::rejected('delete', $identity, $previous);
    }

    public static function snapshotMismatch(
        SearchSourceReference $reference,
        int $expected,
        int $actual,
        ?Throwable $previous = null,
    ): self {
        return new self(
            'Persisted search source snapshot ['.ProviderKey::describe($reference->sourceKey)."] expected {$expected} identity row(s), but found {$actual}.",
            0,
            $previous,
        );
    }

    /** @param list<string> $fields */
    public static function semanticMismatch(SearchDocumentIdentity $identity, array $fields): self
    {
        $fields = array_values(array_unique($fields));
        sort($fields, SORT_STRING);

        return new self(
            'Persisted search document semantic fields ['.implode(', ', $fields).'] do not match identity ['.
            self::describeIdentity($identity).'].',
        );
    }

    public static function persistedRowMissing(SearchDocumentIdentity $identity): self
    {
        return new self(
            'Persisted search document row is missing for identity ['.self::describeIdentity($identity).'].',
        );
    }

    private static function rejected(
        string $operation,
        SearchDocumentIdentity $identity,
        ?Throwable $previous,
    ): self {
        return new self(
            "Search index {$operation} was rejected for identity [".self::describeIdentity($identity).'].',
            0,
            $previous,
        );
    }

    private static function describeIdentity(SearchDocumentIdentity $identity): string
    {
        return ProviderKey::describe($identity->partition).'|'.
            ProviderKey::describe($identity->sourceKey).'|'.
            ProviderKey::describe($identity->locale);
    }
}
