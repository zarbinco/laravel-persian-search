<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final readonly class SearchResult
{
    /**
     * @param  array<int, string>  $matchedTokens
     */
    public function __construct(
        public Model $model,
        public SearchDocumentRecord $record,
        public int|float $score,
        public array $matchedTokens,
    ) {}

    /**
     * @return array{
     *     model: Model,
     *     record: SearchDocumentRecord,
     *     score: int|float,
     *     matched_tokens: array<int, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'record' => $this->record,
            'score' => $this->score,
            'matched_tokens' => $this->matchedTokens,
        ];
    }
}
