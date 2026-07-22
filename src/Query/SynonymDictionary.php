<?php

namespace Zarbinco\PersianSearch\Query;

final readonly class SynonymDictionary
{
    /** @param array<string, list<SynonymRule>> $rulesByLocale */
    public function __construct(
        public bool $enabled,
        private array $rulesByLocale,
    ) {}

    /** @return list<SynonymRule> */
    public function forLocale(string $locale): array
    {
        return $this->rulesByLocale[$locale] ?? [];
    }
}
