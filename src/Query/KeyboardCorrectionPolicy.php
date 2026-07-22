<?php

namespace Zarbinco\PersianSearch\Query;

use Zarbinco\PersianSearch\Exceptions\InvalidQueryVariantConfigurationException;
use Zarbinco\PersianSearch\Text\SearchLocaleResolver;

final readonly class KeyboardCorrectionPolicy
{
    /** @param array<string, mixed> $values */
    public static function fromArray(array $values, SearchLocaleResolver $locales): self
    {
        $enabled = $values['enabled'] ?? true;
        $minimumLength = $values['minimum_length'] ?? 2;
        $enToFa = $values['en_to_fa'] ?? [];

        if (! is_bool($enabled)) {
            throw InvalidQueryVariantConfigurationException::forValue('keyboard.enabled', $enabled, 'must be boolean');
        }

        if (! is_int($minimumLength) || $minimumLength < 1) {
            throw InvalidQueryVariantConfigurationException::forValue('keyboard.minimum_length', $minimumLength, 'must be an integer of at least one');
        }

        if (! is_array($enToFa)) {
            throw InvalidQueryVariantConfigurationException::forValue('keyboard.en_to_fa', $enToFa, 'must be an array');
        }

        $directionEnabled = $enToFa['enabled'] ?? true;
        $sourceLocale = $enToFa['source_locale'] ?? 'en';
        $targetLocale = $enToFa['target_locale'] ?? 'fa';

        if (! is_bool($directionEnabled)) {
            throw InvalidQueryVariantConfigurationException::forValue('keyboard.en_to_fa.enabled', $directionEnabled, 'must be boolean');
        }

        if (! is_string($sourceLocale) || ! $locales->isEnglish($sourceLocale)) {
            throw InvalidQueryVariantConfigurationException::forValue('keyboard.en_to_fa.source_locale', $sourceLocale, 'must be an English-family locale');
        }

        if (! is_string($targetLocale) || ! $locales->isPersian($targetLocale)) {
            throw InvalidQueryVariantConfigurationException::forValue('keyboard.en_to_fa.target_locale', $targetLocale, 'must be a Persian-family locale');
        }

        return new self(
            enabled: $enabled,
            minimumLength: $minimumLength,
            englishToPersianEnabled: $directionEnabled,
            sourceLocale: $locales->resolve($sourceLocale),
            targetLocale: $locales->resolve($targetLocale),
        );
    }

    private function __construct(
        public bool $enabled,
        public int $minimumLength,
        public bool $englishToPersianEnabled,
        public string $sourceLocale,
        public string $targetLocale,
    ) {}

    public function supportsSourceLocale(string $locale, SearchLocaleResolver $locales): bool
    {
        if (strcasecmp($locale, $this->sourceLocale) === 0) {
            return true;
        }

        return ! str_contains($this->sourceLocale, '-')
            && ! str_contains($this->sourceLocale, '_')
            && $locales->isEnglish($locale);
    }
}
