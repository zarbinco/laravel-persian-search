<?php

namespace Zarbinco\PersianSearch\Indexing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;
use Zarbinco\PersianSearch\Providers\SearchDocumentSet;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final readonly class SearchIndexManager
{
    public function __construct(private SearchDocumentProviderRegistry $providers) {}

    public function documentsFor(mixed $source): SearchDocumentSet
    {
        return $this->providers->documentsFor($source);
    }

    /** @return Collection<int, SearchDocumentRecord> */
    public function indexSource(mixed $source): Collection
    {
        return $this->indexDocumentSet($this->documentsFor($source));
    }

    /** @return Collection<int, SearchDocumentRecord> */
    public function indexDocumentSet(SearchDocumentSet $set): Collection
    {
        $records = [];

        foreach ($set as $document) {
            $records[] = $this->indexDocument($document);
        }

        return collect($records);
    }

    public function documentFor(Model $model): SearchDocument
    {
        $document = $this->documentsFor($model)->all()[0] ?? null;

        if ($document === null) {
            throw new LogicException('The search document provider returned no documents for the model.');
        }

        return $document;
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
        $record = $this->indexSource($model)->first();

        if (! $record instanceof SearchDocumentRecord) {
            throw new LogicException('The search document provider returned no documents for the model.');
        }

        return $record;
    }

    public function deleteDocument(SearchDocumentIdentity $identity): int
    {
        return SearchDocumentRecord::query()->where($identity->toArray())->delete();
    }

    public function deleteSource(mixed $source): int
    {
        return $this->deleteSourceReference($this->providers->referenceFor($source));
    }

    public function deleteSourceReference(SearchSourceReference $reference): int
    {
        return SearchDocumentRecord::query()
            ->where('source_key', $reference->sourceKey)
            ->where('source_type', $reference->sourceType)
            ->where('source_id', $reference->sourceId)
            ->delete();
    }

    public function deleteSourceKey(string $sourceKey, ?string $partition = null): int
    {
        $sourceKey = trim($sourceKey);

        if ($sourceKey === '') {
            throw new LogicException('Search document source key must not be empty.');
        }

        $query = SearchDocumentRecord::query()->where('source_key', $sourceKey);

        if ($partition !== null) {
            $partition = trim($partition);

            if ($partition === '') {
                throw new LogicException('Search partition must not be empty.');
            }

            $query->where('partition', $partition);
        }

        return $query->delete();
    }

    public function delete(Model $model): int
    {
        return $this->deleteSource($model);
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
}
