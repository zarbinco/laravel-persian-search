<?php

namespace Zarbinco\PersianSearch\Indexing;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Exceptions\SearchableModelNotPersistedException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final readonly class SearchIndexManager
{
    public function __construct(
        private SearchDocumentBuilder $builder,
    ) {}

    public function documentFor(Model $model): SearchDocument
    {
        $this->ensureSearchable($model);

        return $this->builder->build($model);
    }

    public function index(Model $model): SearchDocumentRecord
    {
        $this->ensureSearchable($model);

        if ($model->getKey() === null) {
            throw SearchableModelNotPersistedException::forIndexing();
        }

        $document = $this->builder->build($model);
        $payload = SearchDocumentRecord::forDocument($document);
        $identity = [
            'searchable_type' => $payload['searchable_type'],
            'searchable_id' => $payload['searchable_id'],
            'locale' => $payload['locale'],
        ];

        return SearchDocumentRecord::query()->updateOrCreate(
            $identity,
            array_diff_key($payload, $identity),
        );
    }

    public function delete(Model $model): int
    {
        $this->ensureSearchable($model);

        if ($model->getKey() === null) {
            return 0;
        }

        return SearchDocumentRecord::query()
            ->where('searchable_type', $model::class)
            ->where('searchable_id', (string) $model->getKey())
            ->delete();
    }

    public function flush(?string $searchableType = null): int
    {
        $query = SearchDocumentRecord::query();

        if ($searchableType !== null) {
            $query->where('searchable_type', $searchableType);
        }

        return $query->delete();
    }

    private function ensureSearchable(Model $model): void
    {
        if (! $model instanceof PersianSearchable) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] must implement [%s] to use the Persian search index.',
                $model::class,
                PersianSearchable::class,
            ));
        }
    }
}
