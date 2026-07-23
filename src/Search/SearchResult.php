<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\SearchRank;

final readonly class SearchResult
{
    public QueryVariant $matchedVariant;

    public string $candidateSource;

    public string $matchedQuery;

    public string $matchedLocale;

    public function __construct(
        public SearchDocumentRecord $record,
        public ?Model $model,
        public SearchRank $rank,
    ) {
        $this->matchedVariant = $this->rank->variant;
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
            'rank' => $this->rank->toArray(),
            'matched_variant' => $this->matchedVariant->toArray(),
            'candidate_source' => $this->candidateSource,
            'matched_query' => $this->matchedQuery,
            'matched_locale' => $this->matchedLocale,
        ];
    }
}
