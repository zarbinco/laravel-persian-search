<?php

namespace Zarbinco\PersianSearch\Indexing;

use InvalidArgumentException;

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
            return $locale;
        }

        $undefined = trim((string) config('persian-search.index.undefined_locale', 'und'));

        return $undefined !== '' ? $undefined : 'und';
    }

    private static function required(string $value, string $name): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("Search document {$name} must not be empty.");
        }

        return $value;
    }
}
