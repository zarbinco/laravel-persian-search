<?php

namespace Zarbinco\PersianSearch\Query;

final readonly class SynonymExpansion
{
    /** @param list<string> $tokens */
    public function __construct(
        public string $sourceTerm,
        public string $replacementTerm,
        public string $query,
        public array $tokens,
        public string $locale,
        public int $tokenStart,
        public int $tokenLength,
        public string $fingerprint,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_term' => $this->sourceTerm,
            'replacement_term' => $this->replacementTerm,
            'query' => $this->query,
            'tokens' => $this->tokens,
            'locale' => $this->locale,
            'token_start' => $this->tokenStart,
            'token_length' => $this->tokenLength,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
