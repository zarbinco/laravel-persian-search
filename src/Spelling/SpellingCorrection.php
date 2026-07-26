<?php

namespace Zarbinco\PersianSearch\Spelling;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SpellingCorrection implements JsonSerializable
{
    /** @var list<string> */
    public array $tokens;

    /** @var list<SpellingTokenCorrection> */
    public array $corrections;

    /**
     * @param  array<int, mixed>  $tokens
     * @param  array<int, mixed>  $corrections
     */
    public function __construct(
        public string $originalQuery,
        public string $correctedQuery,
        public string $locale,
        array $tokens,
        array $corrections,
        public int $weightedCost,
        public string $fingerprint,
    ) {
        if ($this->originalQuery === '' || $this->correctedQuery === ''
            || $this->originalQuery === $this->correctedQuery || trim($this->locale) === ''
            || $this->weightedCost < 1 || $this->fingerprint === '') {
            throw new InvalidArgumentException('Spelling correction metadata is invalid.');
        }

        $validatedTokens = [];
        foreach ($tokens as $token) {
            if (! is_string($token) || $token === '') {
                throw new InvalidArgumentException('Spelling correction tokens must be non-empty strings.');
            }
            $validatedTokens[] = $token;
        }
        if ($validatedTokens === []) {
            throw new InvalidArgumentException('Spelling correction tokens must not be empty.');
        }
        $this->tokens = $validatedTokens;

        $validatedCorrections = [];
        foreach ($corrections as $correction) {
            if (! $correction instanceof SpellingTokenCorrection) {
                throw new InvalidArgumentException('Spelling corrections must contain SpellingTokenCorrection instances.');
            }
            $validatedCorrections[] = $correction;
        }
        if ($validatedCorrections === []) {
            throw new InvalidArgumentException('A spelling correction must change at least one token.');
        }
        $this->corrections = $validatedCorrections;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'original_query' => $this->originalQuery,
            'corrected_query' => $this->correctedQuery,
            'locale' => $this->locale,
            'tokens' => $this->tokens,
            'corrections' => array_map(
                static fn (SpellingTokenCorrection $correction): array => $correction->toArray(),
                $this->corrections,
            ),
            'weighted_cost' => $this->weightedCost,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
