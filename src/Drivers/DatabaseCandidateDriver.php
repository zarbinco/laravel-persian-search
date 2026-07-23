<?php

namespace Zarbinco\PersianSearch\Drivers;

use Illuminate\Database\Eloquent\Builder;
use Zarbinco\PersianSearch\Candidates\LiteralLikeCondition;
use Zarbinco\PersianSearch\Candidates\SearchCandidate;
use Zarbinco\PersianSearch\Candidates\SearchCandidateCollection;
use Zarbinco\PersianSearch\Candidates\SearchCandidateMatcher;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePlan;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePlanBuilder;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePolicy;
use Zarbinco\PersianSearch\Contracts\SearchCandidateDriver;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\SearchQuery;

final readonly class DatabaseCandidateDriver implements SearchCandidateDriver
{
    public function __construct(
        private SearchCandidatePlanBuilder $plans,
        private SearchCandidateMatcher $matcher,
        private LiteralLikeCondition $literalLike,
        private SearchCandidatePolicy $policy,
    ) {}

    public function candidates(SearchQuery $query): SearchCandidateCollection
    {
        $candidates = new SearchCandidateCollection($this->policy->maximumCandidates);

        foreach ($this->plans->build($query) as $plan) {
            if ($candidates->isFull()) {
                break;
            }

            foreach ($this->records($plan) as $record) {
                $match = $this->matcher->match($record, $plan);

                if ($match === null) {
                    continue;
                }

                $candidate = SearchCandidate::fromMatch($record, $match);

                if ($candidates->isFull() && ! $candidates->contains($candidate->identity())) {
                    continue;
                }

                $candidates = $candidates->with($candidate);
            }
        }

        return $candidates;
    }

    /** @return list<SearchDocumentRecord> */
    private function records(SearchCandidatePlan $plan): array
    {
        $builder = SearchDocumentRecord::query()
            ->where('is_active', true)
            ->where('locale', SearchDocumentRecord::localeStorageKey($plan->variant->locale));

        if ($plan->partition !== null) {
            $builder->where('partition', $plan->partition);
        }

        if ($plan->sourceTypes !== []) {
            $builder->whereIn('source_type', $plan->sourceTypes);
        }

        $builder->where(function (Builder $textQuery) use ($plan): void {
            foreach ($plan->terms as $term) {
                foreach ($plan->fields as $field) {
                    $this->literalLike->orWhereContains($textQuery, $field, $term);
                }
            }
        });

        /** @var list<SearchDocumentRecord> $records */
        $records = $builder
            ->orderBy($builder->getModel()->qualifyColumn('id'))
            ->limit($plan->limit)
            ->get()
            ->all();

        return $records;
    }
}
