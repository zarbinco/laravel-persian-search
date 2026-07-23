<?php

namespace Zarbinco\PersianSearch\Drivers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Zarbinco\PersianSearch\Contracts\SearchCandidateDriver;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Contracts\SearchRanker;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidate;
use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Search\SearchResult;
use Zarbinco\PersianSearch\Search\SearchResults;

final readonly class DatabaseSearchDriver implements SearchDriver
{
    public function __construct(
        private SearchRanker $ranker,
        private SearchCandidateDriver $candidates,
    ) {}

    public function search(SearchQuery $query): SearchResults
    {
        if ($query->isEmpty()) {
            return new SearchResults($query, $query->processedQuery, [], 0);
        }

        $ranked = $this->ranker->rank($this->candidates->candidates($query));
        $models = $this->hydrateModels(array_map(
            static fn (SearchRankedCandidate $item): SearchDocumentRecord => $item->candidate->document,
            $ranked->all(),
        ));
        $items = [];

        foreach ($ranked as $item) {
            $record = $item->candidate->document;
            $items[] = new SearchResult(
                record: $record,
                model: $models[$record->source_type.'|'.$record->source_id] ?? null,
                rank: $item->rank,
            );
        }

        $total = count($items);

        return new SearchResults($query, $query->processedQuery, array_slice($items, $query->offset, $query->limit), $total);
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
