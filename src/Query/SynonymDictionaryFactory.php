<?php

namespace Zarbinco\PersianSearch\Query;

use Zarbinco\PersianSearch\Exceptions\InvalidQueryVariantConfigurationException;
use Zarbinco\PersianSearch\Text\SearchLocaleResolver;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final readonly class SynonymDictionaryFactory
{
    public function __construct(
        private SearchTextPipeline $pipeline,
        private SearchLocaleResolver $locales,
    ) {}

    /** @param array<string, mixed> $values */
    public function make(array $values): SynonymDictionary
    {
        $enabled = $values['enabled'] ?? false;
        $locales = $values['locales'] ?? [];

        if (! is_bool($enabled)) {
            throw InvalidQueryVariantConfigurationException::forValue('synonyms.enabled', $enabled, 'must be boolean');
        }

        if (! is_array($locales)) {
            throw InvalidQueryVariantConfigurationException::forValue('synonyms.locales', $locales, 'must be an array');
        }

        $rulesByLocale = [];

        foreach ($locales as $locale => $dictionary) {
            if (! is_string($locale) || trim($locale) === '') {
                throw InvalidQueryVariantConfigurationException::forValue('synonyms.locales', $locale, 'locale keys must be non-empty strings');
            }

            if (! is_array($dictionary)) {
                throw InvalidQueryVariantConfigurationException::forValue("synonyms.locales.{$locale}", $dictionary, 'must be an array');
            }

            $resolvedLocale = $this->locales->resolve($locale);
            $rulesByLocale[$resolvedLocale] = $this->rules($dictionary, $resolvedLocale);
        }

        return new SynonymDictionary($enabled, $rulesByLocale);
    }

    /**
     * @param  array<array-key, mixed>  $dictionary
     * @return list<SynonymRule>
     */
    private function rules(array $dictionary, string $locale): array
    {
        $rules = [];
        $seen = [];

        foreach ($dictionary as $source => $replacements) {
            if (! is_string($source) || trim($source) === '') {
                throw InvalidQueryVariantConfigurationException::forValue("synonyms.locales.{$locale}.source", $source, 'must be a non-empty string');
            }

            if (! is_array($replacements)) {
                throw InvalidQueryVariantConfigurationException::forValue("synonyms.locales.{$locale}.{$source}", $replacements, 'must be a list of replacement strings');
            }

            if (! array_is_list($replacements)) {
                throw InvalidQueryVariantConfigurationException::forValue("synonyms.locales.{$locale}.{$source}", $replacements, 'must be a list of replacement strings');
            }

            $preparedSource = $this->pipeline->prepare($source, $locale);

            if ($preparedSource->normalized === '' || $preparedSource->tokens === []) {
                throw InvalidQueryVariantConfigurationException::forValue("synonyms.locales.{$locale}.source", $source, 'must normalize to searchable tokens');
            }

            foreach ($replacements as $replacement) {
                if (! is_string($replacement) || trim($replacement) === '') {
                    throw InvalidQueryVariantConfigurationException::forValue("synonyms.locales.{$locale}.{$source}.replacement", $replacement, 'must be a non-empty string');
                }

                $preparedReplacement = $this->pipeline->prepare($replacement, $locale);

                if ($preparedReplacement->normalized === '' || $preparedReplacement->tokens === []) {
                    throw InvalidQueryVariantConfigurationException::forValue("synonyms.locales.{$locale}.{$source}.replacement", $replacement, 'must normalize to searchable tokens');
                }

                if ($preparedReplacement->normalized === $preparedSource->normalized) {
                    throw InvalidQueryVariantConfigurationException::forValue("synonyms.locales.{$locale}.{$source}.replacement", $replacement, 'must not normalize to the source term');
                }

                $key = $preparedSource->normalized."\0".$preparedReplacement->normalized;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rules[] = new SynonymRule(
                    source: $preparedSource->normalized,
                    sourceTokens: $preparedSource->tokens,
                    replacement: $preparedReplacement->normalized,
                    replacementTokens: $preparedReplacement->tokens,
                );
            }
        }

        return $rules;
    }
}
