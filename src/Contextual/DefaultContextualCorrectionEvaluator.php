<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use Zarbinco\PersianSearch\Contracts\CandidateResultCounter;
use Zarbinco\PersianSearch\Contracts\ContextualCorrectionEvaluator;
use Zarbinco\PersianSearch\Contracts\CorrectionEvidenceProvider;
use Zarbinco\PersianSearch\Contracts\QueryVariantResultCounter;
use Zarbinco\PersianSearch\Search\SearchQuery;

final readonly class DefaultContextualCorrectionEvaluator implements ContextualCorrectionEvaluator
{
    public function __construct(
        private ContextualCorrectionPolicy $policy,
        private DatabaseContextualCandidateGenerator $generator,
        private CorrectionEvidenceProvider $evidence,
        private CandidateResultCounter $results,
        private ?QueryVariantResultCounter $parentResults = null,
    ) {}

    public function evaluate(
        SearchQuery $query,
        CandidateResultCount $directResults,
        bool $preview = false,
    ): ContextualCorrectionCollection {
        $corrections = new ContextualCorrectionCollection($this->policy->maximumCandidatesPerQuery);
        if (! $this->policy->shouldEvaluate($directResults, $preview)) {
            return $corrections;
        }

        $candidates = $this->generator->generate($query->variants());
        $evidence = $this->evidence->evidenceFor($candidates);
        $ranked = [];
        foreach ($candidates as $candidate) {
            $candidateEvidence = $evidence->get($candidate->fingerprint);
            if ($candidateEvidence === null
                || $candidateEvidence->corpusGain() < $this->policy->minimumCorpusGain
                || ($candidateEvidence->contextApplicable
                    && $this->policy->ngramsEnabled
                    && (! $candidateEvidence->contextAvailable
                        || ! $candidateEvidence->ngramsReady
                        || $candidateEvidence->contextGain() <= $this->policy->minimumContextGain))) {
                continue;
            }
            $ranked[] = ['candidate' => $candidate, 'evidence' => $candidateEvidence];
        }
        usort($ranked, static fn (array $left, array $right): int => $right['evidence']->contextGain() <=> $left['evidence']->contextGain()
            ?: $right['evidence']->corpusGain() <=> $left['evidence']->corpusGain()
            ?: $left['candidate']->lexicalCost <=> $right['candidate']->lexicalCost
            ?: strcmp($left['candidate']->correctedQuery, $right['candidate']->correctedQuery));

        $maximumEvaluated = $this->policy->resultCountsEnabled
            ? $this->policy->maximumResultCountCandidates
            : $this->policy->maximumCandidatesPerQuery;
        foreach (array_slice($ranked, 0, $maximumEvaluated) as $entry) {
            $candidate = $entry['candidate'];
            $candidateEvidence = $entry['evidence'];
            $parentResults = CandidateResultCount::unavailable();
            $candidateResults = CandidateResultCount::unavailable();
            if ($this->policy->resultCountsEnabled) {
                $original = $query->variants()->original();
                $parentResults = $original !== null
                    && $candidate->parent->fingerprint === $original->fingerprint
                    ? $directResults
                    : ($this->parentResults?->countVariant($candidate->parent, $query)
                        ?? CandidateResultCount::unavailable());
                if (! $parentResults->isAvailable
                    || $parentResults->count > $this->policy->maximumDirectResults) {
                    continue;
                }
                $candidateResults = $this->results->countResults($candidate, $query);
                if (! $this->eligibleResultGain($parentResults, $candidateResults)) {
                    continue;
                }
            }
            $confidence = $this->confidence($candidate, $candidateEvidence, $parentResults, $candidateResults);
            if ($confidence < $this->policy->minimumConfidenceBasisPoints) {
                continue;
            }
            $decision = $this->autoApplyAllowed(
                $confidence,
                $directResults,
                $parentResults,
                $candidateResults,
            )
                ? ContextualDecision::AutoApplyAllowed
                : ContextualDecision::SuggestOnly;
            $fingerprint = hash('sha256', implode("\0", [
                'contextual-correction',
                $candidate->fingerprint,
                (string) $directResults->count,
                (string) $parentResults->count,
                (string) $candidateResults->count,
                $this->policy->resultCountsEnabled ? 'results-available' : 'results-disabled',
                (string) $confidence,
                $decision->value,
            ]));
            $corrections->add(new ContextualCorrection(
                candidate: $candidate,
                evidence: $candidateEvidence,
                directResults: $directResults,
                candidateResults: $candidateResults,
                confidenceBasisPoints: $confidence,
                confidence: ContextualConfidence::fromBasisPoints($confidence),
                decision: $decision,
                fingerprint: $fingerprint,
                parentResults: $parentResults,
            ));
        }

        return $corrections;
    }

    private function eligibleResultGain(
        CandidateResultCount $direct,
        CandidateResultCount $candidate,
    ): bool {
        if ($candidate->count === 0
            || $candidate->count - $direct->count < $this->policy->minimumAbsoluteResultGain) {
            return false;
        }
        if ($direct->count === 0) {
            return true;
        }
        $ratio = $candidate->count > intdiv(PHP_INT_MAX, 10000)
            ? PHP_INT_MAX
            : intdiv($candidate->count * 10000, $direct->count);

        return $ratio >= $this->policy->minimumResultGainRatioBasisPoints;
    }

    private function confidence(
        ContextualCandidate $candidate,
        CorrectionEvidence $evidence,
        CandidateResultCount $direct,
        CandidateResultCount $candidateResults,
    ): int {
        $lexical = max(0, 2500 - min(2000, intdiv($candidate->lexicalCost, 2)));
        $corpusTotal = max(1, $evidence->candidateUnigramScore + $evidence->originalUnigramScore);
        $corpus = $evidence->corpusGain() <= 0
            ? 0
            : min(2500, 1000 + intdiv($evidence->candidateUnigramScore * 1500, $corpusTotal));
        if (! $evidence->contextApplicable || ! $evidence->contextAvailable) {
            $context = 1000;
        } else {
            $contextTotal = max(1, $evidence->candidateContextScore + $evidence->originalContextScore);
            $context = $evidence->contextGain() <= 0
                ? 0
                : min(2000, 1000 + intdiv($evidence->candidateContextScore * 1000, $contextTotal));
        }
        if ($direct->isAvailable && $candidateResults->isAvailable) {
            $gain = $candidateResults->count - $direct->count;
            $result = min(3000, 1000 + ($gain * 500));
            $zeroDirect = $direct->count === 0 ? 1000 : 0;
        } else {
            $result = 1500;
            $zeroDirect = 0;
        }
        $analytics = (int) round(($evidence->popularitySignal + $evidence->clickSignal) * 250);

        return min(10000, $lexical + $corpus + $context + $result + $zeroDirect + $analytics);
    }

    private function autoApplyAllowed(
        int $confidence,
        CandidateResultCount $original,
        CandidateResultCount $parent,
        CandidateResultCount $candidate,
    ): bool {
        return $this->policy->resultCountsEnabled
            && $this->policy->autoApplyRecommendationEnabled
            && $confidence >= $this->policy->autoApplyMinimumConfidenceBasisPoints
            && $original->isAvailable
            && $parent->isAvailable
            && $candidate->isAvailable
            && ! $original->isApproximate
            && ! $parent->isApproximate
            && ! $candidate->isApproximate
            && $parent->count === 0
            && (! $this->policy->autoApplyRequiresZeroDirectResults || $original->count === 0);
    }
}
