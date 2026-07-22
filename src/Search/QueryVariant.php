<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Query\KeyboardCorrection;
use Zarbinco\PersianSearch\Query\SynonymExpansion;

final readonly class QueryVariant
{
    /** @var list<string> */
    public array $tokens;

    /** @var list<SynonymExpansion> */
    public array $appliedSynonyms;

    /**
     * @param  array<int, mixed>  $tokens
     * @param  array<int, mixed>  $appliedSynonyms
     */
    public function __construct(
        public string $query,
        public string $locale,
        array $tokens,
        public QueryVariantSource $source,
        public int $priority,
        public string $fingerprint,
        public ?string $parentFingerprint = null,
        public ?KeyboardCorrection $keyboardCorrection = null,
        array $appliedSynonyms = [],
    ) {
        if ($this->query === '') {
            throw new InvalidArgumentException('Query variant text must not be empty.');
        }

        if (trim($this->locale) === '') {
            throw new InvalidArgumentException('Query variant locale must not be empty.');
        }

        if ($tokens === []) {
            throw new InvalidArgumentException('Query variant tokens must not be empty.');
        }

        $validatedTokens = [];

        foreach ($tokens as $token) {
            if (! is_string($token) || $token === '') {
                throw new InvalidArgumentException('Query variant tokens must be non-empty strings.');
            }

            $validatedTokens[] = $token;
        }

        $this->tokens = $validatedTokens;

        if ($this->priority < 0) {
            throw new InvalidArgumentException('Query variant priority must be zero or greater.');
        }

        if ($this->fingerprint === '') {
            throw new InvalidArgumentException('Query variant fingerprint must not be empty.');
        }

        $validatedSynonyms = [];

        foreach ($appliedSynonyms as $synonym) {
            if (! $synonym instanceof SynonymExpansion) {
                throw new InvalidArgumentException('Applied query variant synonyms must be SynonymExpansion instances.');
            }

            $validatedSynonyms[] = $synonym;
        }

        $this->appliedSynonyms = $validatedSynonyms;
    }

    public function semanticKey(): string
    {
        return hash('sha256', $this->locale."\0".$this->query);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'locale' => $this->locale,
            'tokens' => $this->tokens,
            'source' => $this->source->value,
            'priority' => $this->priority,
            'fingerprint' => $this->fingerprint,
            'parent_fingerprint' => $this->parentFingerprint,
            'keyboard_correction' => $this->keyboardCorrection?->toArray(),
            'applied_synonyms' => array_map(
                static fn (SynonymExpansion $expansion): array => $expansion->toArray(),
                $this->appliedSynonyms,
            ),
        ];
    }
}
