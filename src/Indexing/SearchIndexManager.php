<?php

namespace Zarbinco\PersianSearch\Indexing;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Exceptions\SearchableModelNotPersistedException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final readonly class SearchIndexManager
{
    public function __construct(private SearchDocumentBuilder $builder) {}

    public function documentFor(Model $model): SearchDocument
    {
        $this->ensureSearchable($model);

        return $this->builder->build($model);
    }

    public function indexDocument(SearchDocument $document): SearchDocumentRecord
    {
        $payload = SearchDocumentRecord::forDocument($document);
        $identity = SearchDocumentRecord::identityFor($document);

        return SearchDocumentRecord::query()->updateOrCreate(
            $identity,
            array_diff_key($payload, $identity),
        );
    }

    public function index(Model $model): SearchDocumentRecord
    {
        $this->ensureSearchable($model);

        if ($model->getKey() === null) {
            throw SearchableModelNotPersistedException::forIndexing();
        }

        return $this->indexDocument($this->builder->build($model));
    }

    public function deleteDocument(SearchDocumentIdentity $identity): int
    {
        return SearchDocumentRecord::query()->where($identity->toArray())->delete();
    }

    public function deleteSource(string $sourceKey, ?string $partition = null): int
    {
        $sourceKey = trim($sourceKey);

        if ($sourceKey === '') {
            throw new InvalidArgumentException('Search document source key must not be empty.');
        }

        $query = SearchDocumentRecord::query()->where('source_key', $sourceKey);

        if ($partition !== null) {
            $partition = trim($partition);

            if ($partition === '') {
                throw new InvalidArgumentException('Search partition must not be empty.');
            }

            $query->where('partition', $partition);
        }

        return $query->delete();
    }

    public function delete(Model $model): int
    {
        $this->ensureSearchable($model);

        if ($model->getKey() === null) {
            return 0;
        }

        return SearchDocumentRecord::query()
            ->where('source_type', $model::class)
            ->where('source_id', (string) $model->getKey())
            ->delete();
    }

    public function flush(?string $sourceType = null, ?string $partition = null): int
    {
        $query = SearchDocumentRecord::query();

        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        if ($partition !== null) {
            $query->where('partition', $partition);
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
