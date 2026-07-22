<?php

namespace Zarbinco\PersianSearch\Drivers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\BasicRanker;
use Zarbinco\PersianSearch\Search\QueryCandidate;
use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Search\SearchResult;
use Zarbinco\PersianSearch\Search\SearchResults;

final readonly class DatabaseSearchDriver implements SearchDriver
{
    public function __construct(private BasicRanker $ranker) {}

    public function search(SearchQuery $query): SearchResults
    {
        if ($query->isEmpty()) {
            return new SearchResults($query, $query->processedQuery, [], 0);
        }

        $candidates = $this->queryCandidates($query);
        $scored = [];

        foreach ($this->candidateRecords($query, $candidates) as $record) {
            $score = $this->bestScore($record, $candidates);

            if ($score['score'] > 0) {
                $scored[] = ['record' => $record, ...$score];
            }
        }

        usort($scored, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];

            if ($score !== 0) {
                return $score;
            }

            /** @var SearchDocumentRecord $leftRecord */
            $leftRecord = $left['record'];
            /** @var SearchDocumentRecord $rightRecord */
            $rightRecord = $right['record'];

            if ($leftRecord->priority !== $rightRecord->priority) {
                return $rightRecord->priority <=> $leftRecord->priority;
            }

            return [$leftRecord->source_key, $leftRecord->locale]
                <=> [$rightRecord->source_key, $rightRecord->locale];
        });

        $models = $this->hydrateModels(array_map(
            static fn (array $item): SearchDocumentRecord => $item['record'],
            $scored,
        ));
        $items = [];

        foreach ($scored as $item) {
            /** @var SearchDocumentRecord $record */
            $record = $item['record'];
            $modelKey = $record->source_type.'|'.$record->source_id;
            $items[] = new SearchResult(
                record: $record,
                model: $models[$modelKey] ?? null,
                score: $item['score'],
                matchedTokens: $item['matched_tokens'],
                candidateSource: $item['candidate_source'],
                matchedQuery: $item['matched_query'],
            );
        }

        $total = count($items);

        return new SearchResults($query, $query->processedQuery, array_slice($items, $query->offset, $query->limit), $total);
    }

    /** @return list<QueryCandidate> */
    private function queryCandidates(SearchQuery $query): array
    {
        if ($query->hasCandidates()) {
            return $query->candidates();
        }

        return [new QueryCandidate(
            source: 'original',
            original: $query->original,
            normalized: $query->normalized,
            tokens: $query->tokens,
            boost: 1.0,
        )];
    }

    /**
     * @param  list<QueryCandidate>  $candidates
     * @return array{score: float, matched_tokens: list<string>, candidate_source: string|null, matched_query: string|null}
     */
    private function bestScore(SearchDocumentRecord $record, array $candidates): array
    {
        $best = ['score' => 0.0, 'matched_tokens' => [], 'candidate_source' => null, 'matched_query' => null];

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
        $builder = SearchDocumentRecord::query()
            ->where('partition', $query->partition)
            ->where('is_active', true);

        if ($query->hasSourceTypes()) {
            $builder->whereIn('source_type', $query->sourceTypes);
        }

        if ($query->locale !== null) {
            $builder->where('locale', SearchDocumentRecord::localeStorageKey($query->locale));
        }

        $terms = [];

        foreach ($candidates as $candidate) {
            $terms[] = $candidate->normalized;
            array_push($terms, ...$candidate->tokens);
        }

        $terms = array_values(array_unique(array_filter($terms, static fn (string $term): bool => $term !== '')));

        if ($terms !== []) {
            $builder->where(function ($nested) use ($terms): void {
                foreach ($terms as $term) {
                    $pattern = '%'.addcslashes($term, '%_\\').'%';

                    foreach (['normalized_title', 'normalized_excerpt', 'normalized_keywords', 'normalized_content'] as $column) {
                        $nested->orWhere($column, 'like', $pattern);
                    }
                }
            });
        }

        $records = [];

        foreach ($builder->limit(max(1, (int) config('persian-search.database.max_candidates', 500)))->get() as $record) {
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
            if ($record->source_id !== null && class_exists($record->source_type) && is_subclass_of($record->source_type, Model::class)) {
                $idsByType[$record->source_type][] = $record->source_id;
            }
        }

        $models = [];

        foreach ($idsByType as $type => $ids) {
            /** @var class-string<Model> $type */
            $instance = new $type;
            $builder = $instance->newQuery();

            if ((bool) config('persian-search.index.include_soft_deleted', false)
                && in_array(SoftDeletes::class, class_uses_recursive($instance), true)) {
                $withTrashed = [$builder, 'withTrashed'];

                if (! is_callable($withTrashed)) {
                    throw new LogicException('Soft-deleting model query does not support withTrashed().');
                }

                $withTrashed();
            }

            $hydrated = $builder->whereKey(array_values(array_unique($ids)))->get();

            foreach ($hydrated as $model) {
                $models[$type.'|'.$model->getKey()] = $model;
            }
        }

        return $models;
    }
}
