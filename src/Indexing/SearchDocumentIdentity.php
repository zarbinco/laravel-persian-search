<?php

namespace Zarbinco\PersianSearch\Indexing;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchDocumentIdentity
{
    public string $partition;

    public string $sourceKey;

    public string $locale;

    public function __construct(string $partition, string $sourceKey, ?string $locale = null)
    {
        $this->partition = self::required($partition, 'partition');
        $this->sourceKey = self::required($sourceKey, 'source key');
        $this->locale = self::normalizeLocale($locale);
    }

    /**
     * @return array{partition: string, source_key: string, locale: string}
     */
    public function toArray(): array
    {
        return [
            'partition' => $this->partition,
            'source_key' => $this->sourceKey,
            'locale' => $this->locale,
        ];
    }

    public static function normalizeLocale(?string $locale): string
    {
        $locale = trim((string) $locale);

        if ($locale !== '') {
            if (! CanonicalConfigurationName::isValid($locale)) {
                throw new InvalidArgumentException('Search document locale must be a safe non-empty string.');
            }

            return $locale;
        }

        $undefined = trim((string) config('persian-search.index.undefined_locale', 'und'));

        $normalized = $undefined !== '' ? $undefined : 'und';

        if (! CanonicalConfigurationName::isValid($normalized)) {
            throw new InvalidArgumentException('Undefined search document locale must be a safe non-empty string.');
        }

        return $normalized;
    }

    private static function required(string $value, string $name): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("Search document {$name} must not be empty.");
        }

        if ($name === 'partition' && ! CanonicalConfigurationName::isValid($value)) {
            throw new InvalidArgumentException('Search document partition must be a safe non-empty string.');
        }

        return $value;
    }
}
