<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use InvalidArgumentException;
use JsonSerializable;

final readonly class CorrectionEvidence implements JsonSerializable
{
    public function __construct(
        public string $candidateFingerprint,
        public int $originalUnigramScore,
        public int $candidateUnigramScore,
        public int $originalContextScore,
        public int $candidateContextScore,
        public int $originalPhraseFrequency,
        public int $candidatePhraseFrequency,
        public bool $contextApplicable,
        public bool $ngramsReady,
        public float $popularitySignal = 0.0,
        public float $clickSignal = 0.0,
        public bool $contextAvailable = true,
    ) {
        if ($this->candidateFingerprint === '' || $this->originalUnigramScore < 0
            || $this->candidateUnigramScore < 0 || $this->originalContextScore < 0
            || $this->candidateContextScore < 0 || $this->originalPhraseFrequency < 0
            || $this->candidatePhraseFrequency < 0 || ! is_finite($this->popularitySignal)
            || ! is_finite($this->clickSignal) || $this->popularitySignal < 0.0
            || $this->popularitySignal > 1.0 || $this->clickSignal < 0.0
            || $this->clickSignal > 1.0) {
            throw new InvalidArgumentException('Contextual correction evidence is invalid.');
        }
    }

    public function corpusGain(): int
    {
        return $this->candidateUnigramScore - $this->originalUnigramScore;
    }

    public function contextGain(): int
    {
        return $this->candidateContextScore - $this->originalContextScore;
    }

    /** @return array<string, int|float|string|bool> */
    public function toArray(): array
    {
        return [
            'candidate_fingerprint' => $this->candidateFingerprint,
            'original_unigram_score' => $this->originalUnigramScore,
            'candidate_unigram_score' => $this->candidateUnigramScore,
            'corpus_gain' => $this->corpusGain(),
            'original_context_score' => $this->originalContextScore,
            'candidate_context_score' => $this->candidateContextScore,
            'context_gain' => $this->contextGain(),
            'original_phrase_frequency' => $this->originalPhraseFrequency,
            'candidate_phrase_frequency' => $this->candidatePhraseFrequency,
            'context_applicable' => $this->contextApplicable,
            'context_available' => $this->contextAvailable,
            'ngrams_ready' => $this->ngramsReady,
            'popularity_signal' => $this->popularitySignal,
            'click_signal' => $this->clickSignal,
        ];
    }

    /** @return array<string, int|float|string|bool> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
