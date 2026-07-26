<?php

namespace Zarbinco\PersianSearch\Search;

use Zarbinco\PersianSearch\Candidates\SearchCandidatePolicy;
use Zarbinco\PersianSearch\Contextual\ContextualCorrectionPolicy;
use Zarbinco\PersianSearch\Contracts\CandidateResultCounter;
use Zarbinco\PersianSearch\Contracts\ContextualCorrectionEvaluator;
use Zarbinco\PersianSearch\Contracts\SearchCandidateDriver;
use Zarbinco\PersianSearch\Contracts\SearchRanker;
use Zarbinco\PersianSearch\Query\QueryVariantPolicy;

final readonly class SearchExecutionProcessor
{
    public function __construct(
        private SearchRanker $ranker,
        private SearchCandidateDriver $candidates,
        private SearchCandidatePolicy $candidatePolicy,
        private EffectiveSearchSuggestionEvaluator $suggestions,
        private SearchLocaleBridge $bridge,
        private ?ContextualCorrectionEvaluator $contextual = null,
        private ?CandidateResultCounter $resultCounter = null,
        private ?QueryVariantPolicy $variantPolicy = null,
        private ?ContextualCorrectionPolicy $contextualPolicy = null,
    ) {}

    public function process(SearchQuery $query, bool $preview = false): SearchExecutionContext
    {
        if ($query->isEmpty()) {
            return new SearchExecutionContext(
                new SearchResultWindow([], [], $this->candidatePolicy->maximumCandidates),
                null,
                $query,
            );
        }

        $this->resultCounter?->reset();
        $retrieval = $this->candidates->candidates($query);
        $ranked = $this->ranker->rank($retrieval->candidates);
        $exact = ! $retrieval->isTruncated();
        if ($this->contextual !== null
            && ($this->contextualPolicy === null || $this->contextualPolicy->enabled)
            && $this->resultCounter !== null
            && $this->variantPolicy !== null) {
            $direct = $this->resultCounter->directCount($query, $ranked, $exact);
            $corrections = $this->contextual->evaluate($query, $direct, $preview);
            $variants = $query->variants();
            $retained = array_fill_keys(
                array_map(static fn (QueryVariant $variant): string => $variant->fingerprint, $variants->all()),
                true,
            );
            $added = false;
            foreach ($corrections as $correction) {
                $candidate = $correction->candidate;
                if (! isset($retained[$candidate->parent->fingerprint])
                    || ! $variants->contains($candidate->parent->fingerprint)) {
                    continue;
                }
                $variant = new QueryVariant(
                    query: $candidate->correctedQuery,
                    locale: $candidate->locale,
                    tokens: $candidate->tokens,
                    source: QueryVariantSource::Contextual,
                    priority: $this->variantPolicy->priority(QueryVariantSource::Contextual),
                    fingerprint: $correction->fingerprint,
                    parentFingerprint: $candidate->parent->fingerprint,
                    keyboardCorrection: $candidate->parent->keyboardCorrection,
                    appliedSynonyms: $candidate->parent->appliedSynonyms,
                    spellingCorrection: $candidate->parent->spellingCorrection,
                    advancedCorrection: $candidate->parent->advancedCorrection,
                    contextualCorrection: $correction,
                );
                $variants = $variants->withPriorityReplacement($variant);
                if ($variants->contains($variant->fingerprint)) {
                    $retained[$variant->fingerprint] = true;
                    $added = true;
                }
            }
            if ($added) {
                $query = $query->withVariants($variants);
                $retrieval = $this->candidates->candidates($query);
                $ranked = $this->ranker->rank($retrieval->candidates);
                $exact = ! $retrieval->isTruncated();
            }
        }
        $suggestion = $this->suggestions->evaluate(
            $ranked,
            $query->variants(),
            $exact,
            $query->normalized,
        );
        $presented = $this->bridge->bridge($ranked, $query);

        return new SearchExecutionContext(
            new SearchResultWindow(
                $presented->all(),
                $retrieval->truncationReasons,
                $retrieval->candidateLimit,
            ),
            $suggestion,
            $query,
        );
    }
}
