<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use JsonSerializable;

final readonly class ContextualNgramBuildResult implements JsonSerializable
{
    /** @param array<string, int> $localeCounts */
    public function __construct(
        public int $documents,
        public int $ngrams,
        public array $localeCounts,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'documents' => $this->documents,
            'ngrams' => $this->ngrams,
            'locale_ngram_counts' => $this->localeCounts,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
