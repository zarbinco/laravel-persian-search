<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final readonly class SearchResult
{
    /** @param  list<string>  $matchedTokens */
    public function __construct(
        public SearchDocumentRecord $record,
        public ?Model $model,
        public int|float $score,
        public array $matchedTokens,
        public ?string $candidateSource = null,
        public ?string $matchedQuery = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'record' => $this->record,
            'model' => $this->model,
            'score' => $this->score,
            'matched_tokens' => $this->matchedTokens,
            'candidate_source' => $this->candidateSource,
            'matched_query' => $this->matchedQuery,
        ];
    }
}
