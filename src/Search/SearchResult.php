<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final readonly class SearchResult
{
    public string $candidateSource;

    public string $matchedQuery;

    public string $matchedLocale;

    /** @param list<string> $matchedTokens */
    public function __construct(
        public SearchDocumentRecord $record,
        public ?Model $model,
        public int|float $score,
        public array $matchedTokens,
        public QueryVariant $matchedVariant,
    ) {
        $this->candidateSource = $this->matchedVariant->source->value;
        $this->matchedQuery = $this->matchedVariant->query;
        $this->matchedLocale = $this->matchedVariant->locale;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'record' => $this->record,
            'model' => $this->model,
            'score' => $this->score,
            'matched_tokens' => $this->matchedTokens,
            'matched_variant' => $this->matchedVariant->toArray(),
            'candidate_source' => $this->candidateSource,
            'matched_query' => $this->matchedQuery,
            'matched_locale' => $this->matchedLocale,
        ];
    }
}
