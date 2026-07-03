<?php

namespace Zarbinco\PersianSearch\Drivers;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\BasicRanker;
use Zarbinco\PersianSearch\Search\QueryCandidate;
use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Search\SearchResult;
use Zarbinco\PersianSearch\Search\SearchResults;

final readonly class DatabaseSearchDriver implements SearchDriver
{
    public function __construct(
        private BasicRanker $ranker,
    ) {}

    public function search(SearchQuery $query): SearchResults
    {
        if ($query->isEmpty()) {
            return new SearchResults($query, [], 0);
        }

        $candidates = $this->queryCandidates($query);
        $records = $this->candidateRecords($query, $candidates);
        $scored = [];

        foreach ($records as $record) {
            $score = $this->bestScore($record, $candidates);

            if ($score['score'] <= 0) {
                continue;
            }

            $scored[] = [
                'record' => $record,
                'score' => $score['score'],
                'matched_tokens' => $score['matched_tokens'],
                'candidate_source' => $score['candidate_source'],
                'matched_query' => $score['matched_query'],
            ];
        }

        usort(
            $scored,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score'],
        );

        $models = $this->hydrateModels(array_map(
            static fn (array $item): SearchDocumentRecord => $item['record'],
            $scored,
        ));

        $items = [];

        foreach ($scored as $item) {
            /** @var SearchDocumentRecord $record */
            $record = $item['record'];
            $modelKey = $record->searchable_type.'|'.$record->searchable_id;

            if (! isset($models[$modelKey])) {
                continue;
            }

            $items[] = new SearchResult(
                model: $models[$modelKey],
                record: $record,
                score: $item['score'],
                matchedTokens: $item['matched_tokens'],
                candidateSource: $item['candidate_source'],
                matchedQuery: $item['matched_query'],
            );
        }

        $total = count($items);
        $items = array_slice($items, $query->offset, $query->limit);

        return new SearchResults($query, $items, $total);
    }

    /**
     * @return list<QueryCandidate>
     */
    private function queryCandidates(SearchQuery $query): array
    {
        if ($query->hasCandidates()) {
            return $query->candidates();
        }

        if ($query->isEmpty()) {
            return [];
        }

        return [
            new QueryCandidate(
                source: 'original',
                original: $query->original,
                normalized: $query->normalized,
                tokens: $query->tokens,
                boost: 1.0,
            ),
        ];
    }

    /**
     * @param  list<QueryCandidate>  $candidates
     * @return array{
     *     score: float,
     *     matched_tokens: array<int, string>,
     *     candidate_source: string|null,
     *     matched_query: string|null
     * }
     */
    private function bestScore(SearchDocumentRecord $record, array $candidates): array
    {
        $best = [
            'score' => 0.0,
            'matched_tokens' => [],
            'candidate_source' => null,
            'matched_query' => null,
        ];

        foreach ($candidates as $candidate) {
            $score = $this->ranker->scoreCandidate($record, $candidate);

            if ($score['score'] > $best['score']) {
                $best = [
                    'score' => $score['score'],
                    'matched_tokens' => $score['matched_tokens'],
                    'candidate_source' => $score['candidate_source'],
                    'matched_query' => $score['matched_query'],
                ];
            }
        }

        return $best;
    }

    /**
     * @param  list<QueryCandidate>  $candidates
     * @return list<SearchDocumentRecord>
     */
    private function candidateRecords(SearchQuery $query, array $candidates): array
    {
        $builder = SearchDocumentRecord::query();

        if ($query->hasSearchableTypes()) {
            $builder->whereIn('searchable_type', $query->searchableTypes);
        }

        if ($query->locale !== null) {
            $builder->where('locale', SearchDocumentRecord::localeStorageKey($query->locale));
        }

        $terms = [];

        foreach ($candidates as $candidate) {
            $terms[] = $candidate->normalized;

            foreach ($candidate->tokens as $token) {
                $terms[] = $token;
            }
        }

        $terms = array_values(array_unique(array_filter(
            $terms,
            static fn (string $term): bool => $term !== '',
        )));

        if ($terms !== []) {
            $builder->where(function ($queryBuilder) use ($terms): void {
                foreach ($terms as $term) {
                    $pattern = '%'.$this->likeValue($term).'%';

                    $queryBuilder
                        ->orWhere('title', 'like', $pattern)
                        ->orWhere('content', 'like', $pattern);
                }
            });
        }

        $maxCandidates = max(1, (int) config('persian-search.database.max_candidates', 500));

        $records = [];

        foreach ($builder
            ->limit($maxCandidates)
            ->get()
            ->values() as $record) {
            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param  list<SearchDocumentRecord>  $records
     * @return array<string, Model>
     */
    private function hydrateModels(array $records): array
    {
        $idsByType = [];

        foreach ($records as $record) {
            $idsByType[$record->searchable_type][] = $record->searchable_id;
        }

        $models = [];

        foreach ($idsByType as $type => $ids) {
            if (! class_exists($type) || ! is_subclass_of($type, Model::class)) {
                continue;
            }

            /** @var class-string<Model> $type */
            $instance = new $type;
            $hydrated = $instance->newQuery()
                ->whereKey(array_values(array_unique($ids)))
                ->get()
                ->keyBy(static fn (Model $model): string => (string) $model->getKey());

            foreach ($hydrated as $id => $model) {
                $models[$type.'|'.$id] = $model;
            }
        }

        return $models;
    }

    private function likeValue(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
