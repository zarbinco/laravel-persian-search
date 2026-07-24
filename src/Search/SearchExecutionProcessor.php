<?php

namespace Zarbinco\PersianSearch\Search;

use Zarbinco\PersianSearch\Candidates\SearchCandidatePolicy;
use Zarbinco\PersianSearch\Contracts\SearchCandidateDriver;
use Zarbinco\PersianSearch\Contracts\SearchRanker;

final readonly class SearchExecutionProcessor
{
    public function __construct(
        private SearchRanker $ranker,
        private SearchCandidateDriver $candidates,
        private SearchCandidatePolicy $candidatePolicy,
        private EffectiveSearchSuggestionEvaluator $suggestions,
        private SearchLocaleBridge $bridge,
    ) {}

    public function process(SearchQuery $query): SearchExecutionContext
    {
        if ($query->isEmpty()) {
            return new SearchExecutionContext(
                new SearchResultWindow([], [], $this->candidatePolicy->maximumCandidates),
                null,
            );
        }

        $retrieval = $this->candidates->candidates($query);
        $ranked = $this->ranker->rank($retrieval->candidates);
        $exact = ! $retrieval->isTruncated();
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
        );
    }
}
