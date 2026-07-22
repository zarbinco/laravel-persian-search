<?php

namespace Zarbinco\PersianSearch\Text;

final readonly class SearchLocaleResolver
{
    public function __construct(private string $undefinedLocale = 'und') {}

    public function resolve(?string $locale): string
    {
        $locale = trim((string) $locale);

        if ($locale !== '') {
            return $locale;
        }

        $undefined = trim($this->undefinedLocale);

        return $undefined !== '' ? $undefined : 'und';
    }

    public function isPersian(string $locale): bool
    {
        return $this->language($locale) === 'fa';
    }

    public function isEnglish(string $locale): bool
    {
        return $this->language($locale) === 'en';
    }

    private function language(string $locale): string
    {
        $parts = preg_split('/[-_]/', strtolower(trim($locale)), 2);

        return is_array($parts) ? ($parts[0] ?? '') : '';
    }
}
