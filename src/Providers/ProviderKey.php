<?php

namespace Zarbinco\PersianSearch\Providers;

use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDocumentProviderException;

final class ProviderKey
{
    private const UNSAFE_PATTERN = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    private const EDGE_WHITESPACE_PATTERN = '/\A[\p{Z}\s]|[\p{Z}\s]\z/u';

    private const TRIM_WHITESPACE_PATTERN = '/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u';

    public static function fromProvider(SearchDocumentProvider $provider): string
    {
        $key = $provider->key();

        if ($key !== $provider->key()) {
            throw InvalidSearchDocumentProviderException::unstableKey($provider::class);
        }

        if ($key === '') {
            throw InvalidSearchDocumentProviderException::emptyKey($provider::class);
        }

        if (! self::isValidUtf8($key) || preg_match(self::UNSAFE_PATTERN, $key) === 1) {
            throw InvalidSearchDocumentProviderException::unsafeKey($provider::class);
        }

        if (preg_match(self::EDGE_WHITESPACE_PATTERN, $key) === 1) {
            throw InvalidSearchDocumentProviderException::nonCanonicalKey($provider::class);
        }

        return $key;
    }

    public static function forLookup(string $key): string
    {
        if (! self::isValidUtf8($key)) {
            throw InvalidSearchDocumentProviderException::unsafeLookupKey(self::describe($key));
        }

        $canonical = preg_replace(self::TRIM_WHITESPACE_PATTERN, '', $key);

        if (! is_string($canonical) || preg_match(self::UNSAFE_PATTERN, $canonical) === 1) {
            throw InvalidSearchDocumentProviderException::unsafeLookupKey(self::describe($key));
        }

        if ($canonical === '') {
            throw InvalidSearchDocumentProviderException::emptyLookupKey();
        }

        return $canonical;
    }

    public static function describe(string $key): string
    {
        if (! self::isValidUtf8($key) || preg_match(self::UNSAFE_PATTERN, $key) === 1) {
            return 'unsafe-key-sha256:'.hash('sha256', $key);
        }

        return $key;
    }

    private static function isValidUtf8(string $key): bool
    {
        return preg_match('//u', $key) === 1;
    }
}
