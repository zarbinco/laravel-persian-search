<?php

namespace Zarbinco\PersianSearch\Spelling;

use JsonSerializable;

final readonly class SpellingDictionaryBuildResult implements JsonSerializable
{
    /** @param array<string, int> $localeTermCounts */
    public function __construct(
        public int $documents,
        public int $terms,
        public int $deletes,
        public array $localeTermCounts,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'documents' => $this->documents,
            'terms' => $this->terms,
            'deletes' => $this->deletes,
            'locale_term_counts' => $this->localeTermCounts,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
