<?php

namespace Zarbinco\PersianSearch\Query;

final readonly class KeyboardCorrection
{
    /** @param list<string> $tokens */
    public function __construct(
        public string $originalQuery,
        public string $correctedQuery,
        public array $tokens,
        public string $sourceLocale,
        public string $targetLocale,
        public KeyboardCorrectionDirection $direction,
        public bool $meaningful,
        public string $fingerprint,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'original_query' => $this->originalQuery,
            'corrected_query' => $this->correctedQuery,
            'tokens' => $this->tokens,
            'source_locale' => $this->sourceLocale,
            'target_locale' => $this->targetLocale,
            'direction' => $this->direction->value,
            'meaningful' => $this->meaningful,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
