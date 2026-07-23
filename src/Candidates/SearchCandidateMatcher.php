<?php

namespace Zarbinco\PersianSearch\Candidates;

use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final class SearchCandidateMatcher
{
    public function match(SearchDocumentRecord $record, SearchCandidatePlan $plan): ?SearchCandidateMatch
    {
        $matchedFields = [];
        $matchedTerms = [];

        foreach ($plan->fields as $field) {
            $value = $record->getAttribute($field->value);

            if (! is_string($value) || $value === '') {
                continue;
            }

            foreach ($plan->terms as $term) {
                if (str_contains($value, $term)) {
                    $matchedFields[$field->value] = $field;
                    $matchedTerms[$term] = $term;
                }
            }
        }

        if ($matchedFields === [] || $matchedTerms === []) {
            return null;
        }

        return new SearchCandidateMatch(
            $plan->variant,
            array_values($matchedFields),
            array_values($matchedTerms),
        );
    }
}
