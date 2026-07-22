<?php

namespace Zarbinco\PersianSearch\Drivers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\BasicRanker;
use Zarbinco\PersianSearch\Search\QueryVariant;
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

        /** @var array<string, array{record: SearchDocumentRecord, score: float, matched_tokens: list<string>, variant: QueryVariant}> $scored */
        $scored = [];

        foreach ($query->variants() as $variant) {
            foreach ($this->candidateRecords($query, $variant) as $record) {
                $score = $this->ranker->scoreVariant($record, $variant);

                if ($score['score'] <= 0) {
                    continue;
                }

                $key = (string) $record->getKey();
                $existing = $scored[$key] ?? null;

                if ($existing === null
                    || $variant->priority > $existing['variant']->priority
                    || ($variant->priority === $existing['variant']->priority && $score['score'] > $existing['score'])) {
                    $scored[$key] = [
                        'record' => $record,
                        'score' => $score['score'],
                        'matched_tokens' => $score['matched_tokens'],
                        'variant' => $variant,
                    ];
                }
            }
        }

        $scored = array_values($scored);
        usort($scored, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];

            if ($score !== 0) {
                return $score;
            }

            $priority = $right['record']->priority <=> $left['record']->priority;

            if ($priority !== 0) {
                return $priority;
            }

            return [$left['record']->source_key, $left['record']->locale]
                <=> [$right['record']->source_key, $right['record']->locale];
        });

        $models = $this->hydrateModels(array_map(static fn (array $item): SearchDocumentRecord => $item['record'], $scored));
        $items = [];

        foreach ($scored as $item) {
            $record = $item['record'];
            $items[] = new SearchResult(
                record: $record,
                model: $models[$record->source_type.'|'.$record->source_id] ?? null,
                score: $item['score'],
                matchedTokens: $item['matched_tokens'],
                matchedVariant: $item['variant'],
            );
        }

        $total = count($items);

        return new SearchResults($query, $query->processedQuery, array_slice($items, $query->offset, $query->limit), $total);
    }

    /** @return list<SearchDocumentRecord> */
    private function candidateRecords(SearchQuery $query, QueryVariant $variant): array
    {
        $builder = SearchDocumentRecord::query()
            ->where('partition', $query->partition)
            ->where('is_active', true)
            ->where('locale', SearchDocumentRecord::localeStorageKey($variant->locale));

        if ($query->hasSourceTypes()) {
            $builder->whereIn('source_type', $query->sourceTypes);
        }

        $terms = array_values(array_unique([$variant->query, ...$variant->tokens]));
        $builder->where(function ($nested) use ($terms): void {
            foreach ($terms as $term) {
                $pattern = '%'.addcslashes($term, '%_\\').'%';

                foreach (['normalized_title', 'normalized_excerpt', 'normalized_keywords', 'normalized_content'] as $column) {
                    $nested->orWhere($column, 'like', $pattern);
                }
            }
        });

        /** @var list<SearchDocumentRecord> $records */
        $records = $builder->limit(max(1, (int) config('persian-search.database.max_candidates', 500)))->get()->all();

        return $records;
    }

    /** @param list<SearchDocumentRecord> $records
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

            foreach ($builder->whereKey(array_values(array_unique($ids)))->get() as $model) {
                $models[$type.'|'.$model->getKey()] = $model;
            }
        }

        return $models;
    }
}
