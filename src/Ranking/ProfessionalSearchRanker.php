<?php

namespace Zarbinco\PersianSearch\Ranking;

use Zarbinco\PersianSearch\Candidates\SearchCandidateCollection;
use Zarbinco\PersianSearch\Contracts\SearchRanker;

final readonly class ProfessionalSearchRanker implements SearchRanker
{
    public function __construct(private SearchRankMatcher $matcher) {}

    public function rank(SearchCandidateCollection $candidates): SearchRankedCandidateCollection
    {
        $ranked = [];

        foreach ($candidates as $candidate) {
            $fields = $this->matcher->tokenize($candidate->document);
            $variants = [];

            foreach ($candidate->matches as $match) {
                $variants[$match->variant->fingerprint] ??= $match->variant;
            }

            $best = null;

            foreach ($variants as $variant) {
                $rank = $this->matcher->match($candidate->document, $variant, $fields);

                if ($rank !== null && ($best === null || SearchRank::compare($rank, $best) < 0)) {
                    $best = $rank;
                }
            }

            if ($best !== null) {
                $ranked[] = new SearchRankedCandidate($candidate, $best);
            }
        }

        return new SearchRankedCandidateCollection($ranked);
    }
}
