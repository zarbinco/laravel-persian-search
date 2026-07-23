<?php

namespace Zarbinco\PersianSearch\Candidates;

use Zarbinco\PersianSearch\Search\SearchQuery;

final readonly class SearchCandidatePlanBuilder
{
    public function __construct(private SearchCandidatePolicy $policy) {}

    /** @return list<SearchCandidatePlan> */
    public function build(SearchQuery $query): array
    {
        $plans = [];

        foreach ($query->variants() as $variant) {
            $terms = [];

            foreach ([$variant->query, ...$variant->tokens] as $term) {
                if ($term !== '') {
                    $terms[$term] = $term;
                }
            }

            $terms = array_slice(array_values($terms), 0, $this->policy->maximumTermsPerVariant);

            if ($terms === []) {
                continue;
            }

            $plans[] = new SearchCandidatePlan(
                variant: $variant,
                terms: $terms,
                fields: SearchDocumentField::cases(),
                partition: $query->partition,
                sourceTypes: $query->sourceTypes,
                limit: $this->policy->perVariantLimit,
            );
        }

        return $plans;
    }
}
