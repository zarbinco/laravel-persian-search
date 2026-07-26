<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use InvalidArgumentException;
use JsonSerializable;
use Zarbinco\PersianSearch\Search\QueryVariant;

final readonly class ContextualCandidate implements JsonSerializable
{
    /** @var list<string> */
    public array $tokens;

    /** @var list<ContextualTokenCorrection> */
    public array $corrections;

    /**
     * @param  array<int, mixed>  $tokens
     * @param  array<int, mixed>  $corrections
     */
    public function __construct(
        public string $originalQuery,
        public QueryVariant $parent,
        public string $correctedQuery,
        public string $locale,
        array $tokens,
        array $corrections,
        public int $lexicalCost,
        public int $originalCorpusScore,
        public int $candidateCorpusScore,
        public string $fingerprint,
    ) {
        if ($this->originalQuery === '' || $this->correctedQuery === ''
            || $this->correctedQuery === $this->parent->query || trim($this->locale) === ''
            || $this->lexicalCost < 1 || $this->originalCorpusScore < 1
            || $this->candidateCorpusScore < 1 || $this->fingerprint === '') {
            throw new InvalidArgumentException('Contextual candidate metadata is invalid.');
        }

        $validatedTokens = [];
        foreach ($tokens as $token) {
            if (! is_string($token) || $token === '') {
                throw new InvalidArgumentException('Contextual candidate tokens must be non-empty strings.');
            }
            $validatedTokens[] = $token;
        }
        if ($validatedTokens === []) {
            throw new InvalidArgumentException('Contextual candidate tokens must not be empty.');
        }
        $this->tokens = $validatedTokens;

        $validatedCorrections = [];
        foreach ($corrections as $correction) {
            if (! $correction instanceof ContextualTokenCorrection) {
                throw new InvalidArgumentException('Contextual candidates must contain token corrections.');
            }
            $validatedCorrections[] = $correction;
        }
        if ($validatedCorrections === []) {
            throw new InvalidArgumentException('A contextual candidate must change at least one token.');
        }
        $this->corrections = $validatedCorrections;
    }

    public function source(): ContextualCandidateSource
    {
        $sources = array_fill_keys(
            array_map(static fn (ContextualTokenCorrection $correction): string => $correction->source->value, $this->corrections),
            true,
        );

        return count($sources) === 1
            ? $this->corrections[0]->source
            : ContextualCandidateSource::Combined;
    }

    /** @return list<int> */
    public function correctedTokenIndexes(): array
    {
        return array_map(
            static fn (ContextualTokenCorrection $correction): int => $correction->tokenIndex,
            $this->corrections,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'original_query' => $this->originalQuery,
            'parent_query' => $this->parent->query,
            'corrected_query' => $this->correctedQuery,
            'locale' => $this->locale,
            'tokens' => $this->tokens,
            'corrected_token_indexes' => $this->correctedTokenIndexes(),
            'source' => $this->source()->value,
            'corrections' => array_map(
                static fn (ContextualTokenCorrection $correction): array => $correction->toArray(),
                $this->corrections,
            ),
            'lexical_cost' => $this->lexicalCost,
            'original_corpus_score' => $this->originalCorpusScore,
            'candidate_corpus_score' => $this->candidateCorpusScore,
            'fingerprint' => $this->fingerprint,
            'parent_fingerprint' => $this->parent->fingerprint,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
