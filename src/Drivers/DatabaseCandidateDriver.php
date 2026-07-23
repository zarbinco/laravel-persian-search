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
use Zarbinco\PersianSearch\Candidates\SearchCandidateRetrieval;
use Zarbinco\PersianSearch\Contracts\SearchCandidateDriver;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Search\SearchResultTruncationReason;

final readonly class DatabaseCandidateDriver implements SearchCandidateDriver
{
    public function __construct(
        private SearchCandidatePlanBuilder $plans,
        private SearchCandidateMatcher $matcher,
        private LiteralLikeCondition $literalLike,
        private SearchCandidatePolicy $policy,
    ) {}

    public function candidates(SearchQuery $query): SearchCandidateRetrieval
    {
        $candidates = new SearchCandidateCollection($this->policy->maximumCandidates);
        $reasons = [];
        $plans = $this->plans->build($query);

        foreach ($plans as $index => $plan) {
            if ($candidates->isFull()) {
                $reasons[] = SearchResultTruncationReason::GlobalCandidateLimit;
                $reasons[] = SearchResultTruncationReason::UnexecutedVariants;

                break;
            }

            $records = $this->records($plan);

            if ($records['truncated']) {
                $reasons[] = SearchResultTruncationReason::PerVariantLimit;
            }

            foreach ($records['records'] as $record) {
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

            if ($candidates->isFull()) {
                $reasons[] = SearchResultTruncationReason::GlobalCandidateLimit;

                if ($index < count($plans) - 1) {
                    $reasons[] = SearchResultTruncationReason::UnexecutedVariants;
                }
            }
        }

        return new SearchCandidateRetrieval($candidates, $reasons, $this->policy->maximumCandidates);
    }

    /** @return array{records: list<SearchDocumentRecord>, truncated: bool} */
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
            ->limit($this->detectionLimit($plan->limit))
            ->get()
            ->all();

        return [
            'records' => array_slice($records, 0, $plan->limit),
            'truncated' => count($records) > $plan->limit,
        ];
    }

    private function detectionLimit(int $limit): int
    {
        if ($limit === PHP_INT_MAX) {
            throw new \LogicException('Candidate per-variant limit cannot be incremented safely.');
        }

        return $limit + 1;
    }
}
