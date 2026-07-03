<?php

namespace Zarbinco\PersianSearch\Drivers;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\BasicRanker;
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

        $records = $this->candidateRecords($query);
        $scored = [];

        foreach ($records as $record) {
            $score = $this->ranker->score($record, $query);

            if ($score['score'] <= 0) {
                continue;
            }

            $scored[] = [
                'record' => $record,
                'score' => $score['score'],
                'matched_tokens' => $score['matched_tokens'],
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
            );
        }

        $total = count($items);
        $items = array_slice($items, $query->offset, $query->limit);

        return new SearchResults($query, $items, $total);
    }

    /**
     * @return list<SearchDocumentRecord>
     */
    private function candidateRecords(SearchQuery $query): array
    {
        $builder = SearchDocumentRecord::query();

        if ($query->hasSearchableTypes()) {
            $builder->whereIn('searchable_type', $query->searchableTypes);
        }

        if ($query->locale !== null) {
            $builder->where('locale', SearchDocumentRecord::localeStorageKey($query->locale));
        }

        $terms = array_values(array_unique(array_filter(
            array_merge([$query->normalized], $query->tokens),
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
