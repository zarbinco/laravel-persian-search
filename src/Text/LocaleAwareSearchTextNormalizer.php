<?php

namespace Zarbinco\PersianSearch\Text;

use Zarbinco\PersianCore\Contracts\PersianSearchNormalizerContract;
use Zarbinco\PersianSearch\Contracts\SearchTextNormalizer;

final readonly class LocaleAwareSearchTextNormalizer implements SearchTextNormalizer
{
    public function __construct(
        private PersianSearchNormalizerContract $persian,
        private SearchLocaleResolver $locales,
    ) {}

    public function normalize(string $value, string $locale): string
    {
        if ($this->locales->isPersian($locale)) {
            return $this->lower($this->persian->normalize($value));
        }

        return $this->normalizeWhitespace($this->lower($value));
    }

    private function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $value));
    }
}
