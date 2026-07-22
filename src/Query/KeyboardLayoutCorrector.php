<?php

namespace Zarbinco\PersianSearch\Query;

use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Text\SearchLocaleResolver;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final readonly class KeyboardLayoutCorrector
{
    public function __construct(
        private KeyboardCorrectionPolicy $policy,
        private WindowsPersianKeyboardMap $map,
        private SearchTextPipeline $pipeline,
        private SearchLocaleResolver $locales,
    ) {}

    public function correct(QueryVariant $variant, ?string $physicalInput = null): ?KeyboardCorrection
    {
        if (! $this->policy->enabled || ! $this->policy->englishToPersianEnabled) {
            return null;
        }

        if (! $this->policy->supportsSourceLocale($variant->locale, $this->locales)) {
            return null;
        }

        $physicalInput ??= $variant->query;

        if ($this->length($physicalInput) < $this->policy->minimumLength) {
            return null;
        }

        $corrected = '';
        $changed = false;
        $map = $this->map->map();

        foreach ($this->characters($physicalInput) as $character) {
            $mapped = $map[$character] ?? null;

            if ($mapped === null) {
                $corrected .= $character;

                continue;
            }

            $corrected .= $mapped;
            $changed = $changed || $mapped !== $character;
        }

        if (! $changed || trim($corrected) === '' || $corrected === $physicalInput) {
            return null;
        }

        $prepared = $this->pipeline->prepare($corrected, $this->policy->targetLocale);

        if ($prepared->normalized === '' || $prepared->tokens === []
            || preg_match('/[\p{L}\p{N}]/u', $prepared->normalized) !== 1) {
            return null;
        }

        $fingerprint = hash('sha256', implode("\0", [
            KeyboardCorrectionDirection::EnglishToPersian->value,
            $physicalInput,
            $prepared->normalized,
            $variant->locale,
            $prepared->locale,
        ]));

        return new KeyboardCorrection(
            originalQuery: $physicalInput,
            correctedQuery: $prepared->normalized,
            tokens: $prepared->tokens,
            sourceLocale: $variant->locale,
            targetLocale: $prepared->locale,
            direction: KeyboardCorrectionDirection::EnglishToPersian,
            meaningful: true,
            fingerprint: $fingerprint,
        );
    }

    private function length(string $query): int
    {
        return count($this->characters(trim($query)));
    }

    /**
     * @return list<string>
     */
    private function characters(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (is_array($characters)) {
            return $characters;
        }

        return str_split($value);
    }
}
