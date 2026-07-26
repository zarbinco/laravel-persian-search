<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ContextualCorrection implements JsonSerializable
{
    public CandidateResultCount $parentResults;

    public function __construct(
        public ContextualCandidate $candidate,
        public CorrectionEvidence $evidence,
        public CandidateResultCount $directResults,
        public CandidateResultCount $candidateResults,
        public int $confidenceBasisPoints,
        public ContextualConfidence $confidence,
        public ContextualDecision $decision,
        public string $fingerprint,
        ?CandidateResultCount $parentResults = null,
    ) {
        if ($this->candidate->fingerprint !== $this->evidence->candidateFingerprint
            || $this->confidenceBasisPoints < 0 || $this->confidenceBasisPoints > 10000
            || $this->confidence !== ContextualConfidence::fromBasisPoints($this->confidenceBasisPoints)
            || $this->decision === ContextualDecision::None || $this->fingerprint === '') {
            throw new InvalidArgumentException('Contextual correction metadata is invalid.');
        }
        $this->parentResults = $parentResults ?? $this->directResults;
    }

    public function resultGain(): int
    {
        return $this->candidateResults->count - $this->parentResults->count;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->candidate->toArray(),
            'evidence' => $this->evidence->toArray(),
            'original_results' => $this->directResults->toArray(),
            'parent_results' => $this->parentResults->toArray(),
            'direct_results' => $this->directResults->toArray(),
            'candidate_results' => $this->candidateResults->toArray(),
            'result_gain' => $this->resultGain(),
            'confidence_basis_points' => $this->confidenceBasisPoints,
            'confidence' => $this->confidence->value,
            'decision' => $this->decision->value,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
