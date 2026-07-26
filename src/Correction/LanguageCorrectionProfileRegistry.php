<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Contracts\LanguageCorrectionProfile;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class LanguageCorrectionProfileRegistry
{
    /** @var array<string, LanguageCorrectionProfile> */
    private array $profiles;

    /** @param iterable<LanguageCorrectionProfile> $profiles */
    public function __construct(iterable $profiles)
    {
        $indexed = [];
        foreach ($profiles as $profile) {
            $locale = $profile->locale();
            if (! CanonicalConfigurationName::isValid($locale) || str_contains($locale, '-') || str_contains($locale, '_')) {
                throw new InvalidArgumentException('Language correction profile locales must be canonical base locale names.');
            }
            if (isset($indexed[$locale])) {
                throw new InvalidArgumentException("Duplicate language correction profile locale [{$locale}].");
            }
            $this->validateSeparators($profile);
            $indexed[$locale] = $profile;
        }
        ksort($indexed, SORT_STRING);
        $this->profiles = $indexed;
    }

    public function forLocale(string $locale): ?LanguageCorrectionProfile
    {
        foreach ($this->localeChain($locale) as $candidate) {
            if (isset($this->profiles[$candidate])) {
                return $this->profiles[$candidate];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function locales(): array
    {
        return array_keys($this->profiles);
    }

    /** @return list<string> */
    private function localeChain(string $locale): array
    {
        $locale = trim($locale);
        $parts = preg_split('/[-_]/', $locale, 2);
        $language = is_array($parts) ? ($parts[0] ?? '') : '';

        return array_values(array_unique(array_filter([$locale, $language])));
    }

    private function validateSeparators(LanguageCorrectionProfile $profile): void
    {
        $separators = $profile->separators();
        foreach ($separators as $separator) {
            if ($separator === '' || self::length($separator) > 1) {
                throw new InvalidArgumentException('Language correction profile separators must be one-character strings.');
            }
        }
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
