<?php

namespace Zarbinco\PersianSearch\Spelling;

use JsonSerializable;

final readonly class SpellingDictionaryBuildResult implements JsonSerializable
{
    /**
     * @param  array<string, int>  $localeTermCounts
     * @param  array<string, int>  $localeNgramCounts
     */
    public function __construct(
        public int $documents,
        public int $terms,
        public int $deletes,
        public array $localeTermCounts,
        public int $ngrams = 0,
        public array $localeNgramCounts = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'documents' => $this->documents,
            'terms' => $this->terms,
            'deletes' => $this->deletes,
            'locale_term_counts' => $this->localeTermCounts,
            'ngrams' => $this->ngrams,
            'locale_ngram_counts' => $this->localeNgramCounts,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
