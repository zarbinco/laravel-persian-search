<?php

namespace Zarbinco\PersianSearch\Indexing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use Zarbinco\PersianSearch\Exceptions\SearchIndexPersistenceException;
use Zarbinco\PersianSearch\Exceptions\SearchSourceIdentityConflictException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;
use Zarbinco\PersianSearch\Providers\SearchDocumentSet;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final readonly class SearchIndexManager
{
    public function __construct(
        private SearchDocumentProviderRegistry $providers,
        private SearchIndexingPolicy $policy,
        private SearchDocumentPersistenceVerifier $persistenceVerifier,
    ) {}

    public function documentsFor(mixed $source): SearchDocumentSet
    {
        return $this->providers->documentsFor($source);
    }

    public function indexSource(mixed $source): SearchSourceIndexResult
    {
        return $this->replaceDocumentSet($this->documentsFor($source));
    }

    public function indexSourceWithProvider(string $providerKey, mixed $source): SearchSourceIndexResult
    {
        return $this->replaceDocumentSet($this->providers->documentsForProvider($providerKey, $source));
    }

    public function replaceDocumentSet(SearchDocumentSet $set): SearchSourceIndexResult
    {
        $record = new SearchDocumentRecord;
        $connection = $record->getConnection();
        $connectionName = $connection->getName();

        return $connection->transaction(
            fn (): SearchSourceIndexResult => $this->replaceWithinTransaction($set, $connectionName),
            $this->policy->transactionAttempts,
        );
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
        $document = $document->withProviderKey('eloquent');
        $record = new SearchDocumentRecord;
        $connection = $record->getConnection();
        $connectionName = $connection->getName();

        return $connection->transaction(
            fn (): SearchDocumentRecord => $this->indexDocumentWithinTransaction($document, $connectionName),
            $this->policy->transactionAttempts,
        );
    }

    private function indexDocumentWithinTransaction(
        SearchDocument $document,
        ?string $connectionName,
    ): SearchDocumentRecord {
        $reference = new SearchSourceReference(
            $document->sourceKey(),
            $document->sourceType,
            $document->sourceId,
        );

        foreach ($this->lockedSourceKeyQuery($reference->sourceKey, $connectionName)->get() as $sourceRecord) {
            $this->ensureRecordMatchesReference($sourceRecord, $reference);
        }

        $record = $this->documentQuery($document, $connectionName)->lockForUpdate()->first();

        if ($record instanceof SearchDocumentRecord) {
            $this->ensureRecordMatchesReference($record, $reference);

            if (hash_equals($record->document_hash, $document->documentHash)) {
                return $this->reloadAndVerify($record, $document, $connectionName);
            }

            $record->fill(array_diff_key(
                SearchDocumentRecord::forDocument($document),
                SearchDocumentRecord::identityFor($document),
            ));
            $this->saveOrFail($record, $document->identity, 'update');

            return $this->reloadAndVerify($record, $document, $connectionName);
        }

        return $this->createDocumentOrRecoverRace($document, $reference, $connectionName);
    }

    public function index(Model $model): SearchDocumentRecord
    {
        $set = $this->documentsFor($model);
        $document = $set->all()[0] ?? null;

        if ($document === null) {
            throw new LogicException('The search document provider returned no documents for the model.');
        }

        $this->replaceDocumentSet($set);

        $record = SearchDocumentRecord::query()->where($document->identity->toArray())->first();

        if (! $record instanceof SearchDocumentRecord) {
            throw new LogicException('The indexed search document could not be loaded.');
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
        return $this->sourceQuery($reference, (new SearchDocumentRecord)->getConnectionName())->delete();
    }

    public function deleteSourceReferenceWithProvider(SearchSourceReference $reference, string $providerKey): int
    {
        return $this->sourceQuery($reference, (new SearchDocumentRecord)->getConnectionName())
            ->where('provider_key', $providerKey)
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

    private function replaceWithinTransaction(SearchDocumentSet $set, ?string $connectionName): SearchSourceIndexResult
    {
        $reference = $set->reference;
        $sourceKeyRows = $this->lockedSourceKeyQuery($reference->sourceKey, $connectionName)->get();

        foreach ($sourceKeyRows as $record) {
            $this->ensureRecordMatchesReference($record, $reference);
        }

        $existing = $this->sourceQuery($reference, $connectionName)
            ->inIdentityOrder()
            ->lockForUpdate()
            ->get();

        /** @var array<string, SearchDocumentRecord> $existingByIdentity */
        $existingByIdentity = [];

        foreach ($existing as $record) {
            $key = $this->recordIdentityKey($record);

            if (isset($existingByIdentity[$key])) {
                throw SearchSourceIdentityConflictException::duplicateIdentity(
                    $record->partition,
                    $record->source_key,
                    $record->locale,
                );
            }

            $existingByIdentity[$key] = $record;
        }

        $documents = array_map(
            static fn (SearchDocument $document): SearchDocument => $document->withProviderKey($set->providerKey),
            $set->all(),
        );
        usort($documents, static fn (SearchDocument $left, SearchDocument $right): int => [
            $left->partition(),
            $left->locale(),
            $left->sourceKey(),
        ] <=> [
            $right->partition(),
            $right->locale(),
            $right->sourceKey(),
        ]);

        /** @var list<SearchDocument> $creates */
        $creates = [];
        /** @var list<array{SearchDocumentRecord, SearchDocument}> $updates */
        $updates = [];
        $unchanged = 0;

        foreach ($documents as $document) {
            $key = $this->documentIdentityKey($document);
            $record = $existingByIdentity[$key] ?? null;

            if (! $record instanceof SearchDocumentRecord) {
                $creates[] = $document;

                continue;
            }

            unset($existingByIdentity[$key]);

            if (hash_equals($record->document_hash, $document->documentHash)) {
                $unchanged++;

                continue;
            }

            $updates[] = [$record, $document];
        }

        $stale = array_values($existingByIdentity);
        $created = 0;
        $updated = 0;
        $deleted = 0;

        foreach ($creates as $document) {
            $record = new SearchDocumentRecord;
            $record->setConnection($connectionName);
            $record->fill(SearchDocumentRecord::forDocument($document));
            $this->saveOrFail($record, $document->identity, 'create');
            $this->reloadAndVerify($record, $document, $connectionName);
            $created++;
        }

        foreach ($updates as [$record, $document]) {
            $record->fill(array_diff_key(
                SearchDocumentRecord::forDocument($document),
                SearchDocumentRecord::identityFor($document),
            ));
            $this->saveOrFail($record, $document->identity, 'update');
            $this->reloadAndVerify($record, $document, $connectionName);
            $updated++;
        }

        foreach ($stale as $record) {
            $this->deleteOrFail($record);
            $deleted++;
        }

        $this->verifyFinalSnapshot($set, $documents, $connectionName);

        return new SearchSourceIndexResult(
            reference: $reference,
            incoming: count($documents),
            created: $created,
            updated: $updated,
            unchanged: $unchanged,
            deleted: $deleted,
            final: count($documents),
        );
    }

    private function ensureRecordMatchesReference(SearchDocumentRecord $record, SearchSourceReference $reference): void
    {
        if ($record->source_type !== $reference->sourceType || $record->source_id !== $reference->sourceId) {
            throw SearchSourceIdentityConflictException::forReference($reference);
        }
    }

    private function createDocumentOrRecoverRace(
        SearchDocument $document,
        SearchSourceReference $reference,
        ?string $connectionName,
    ): SearchDocumentRecord {
        $record = new SearchDocumentRecord;
        $record->setConnection($connectionName);
        $record->fill(SearchDocumentRecord::forDocument($document));
        $query = $this->documentQuery($document, $connectionName);
        $create = function () use ($record, $document): void {
            $this->saveOrFail($record, $document->identity, 'create');
        };

        try {
            if ($record->getConnection()->getDriverName() === 'pgsql') {
                $query->withSavepointIfNeeded($create);
            } else {
                $create();
            }

            return $this->reloadAndVerify($record, $document, $connectionName);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isRecoverableIdentityInsertRace($exception, $record)) {
                throw $exception;
            }

            $concurrent = $this->documentQuery($document, $connectionName)
                ->useWritePdo()
                ->lockForUpdate()
                ->first();

            if (! $concurrent instanceof SearchDocumentRecord) {
                throw $exception;
            }

            $this->ensureRecordMatchesReference($concurrent, $reference);

            if (hash_equals($concurrent->document_hash, $document->documentHash)) {
                return $this->reloadAndVerify($concurrent, $document, $connectionName);
            }

            $concurrent->fill(array_diff_key(
                SearchDocumentRecord::forDocument($document),
                SearchDocumentRecord::identityFor($document),
            ));
            $this->saveOrFail($concurrent, $document->identity, 'update');

            return $this->reloadAndVerify($concurrent, $document, $connectionName);
        }
    }

    private function isRecoverableIdentityInsertRace(
        UniqueConstraintViolationException $exception,
        SearchDocumentRecord $record,
    ): bool {
        if ($record->exists || $record->getKey() !== null || $record->wasRecentlyCreated) {
            return false;
        }

        $connection = $record->getConnection();

        if ($exception->getConnectionName() !== $connection->getName()) {
            return false;
        }

        $table = $connection->getQueryGrammar()->wrapTable($record->getTable());

        return preg_match(
            '/^\s*insert\s+into\s+'.preg_quote($table, '/').'(?:\s|\()/i',
            $exception->getSql(),
        ) === 1;
    }

    private function reloadAndVerify(
        SearchDocumentRecord $record,
        SearchDocument $document,
        ?string $connectionName,
    ): SearchDocumentRecord {
        $key = $record->getKey();

        if ($key === null) {
            throw SearchIndexPersistenceException::persistedRowMissing($document->identity);
        }

        $persisted = $this->documentQuery($document, $connectionName)
            ->useWritePdo()
            ->whereKey($key)
            ->first();

        if (! $persisted instanceof SearchDocumentRecord) {
            throw SearchIndexPersistenceException::persistedRowMissing($document->identity);
        }

        $this->persistenceVerifier->verify($persisted, $document);

        return $persisted;
    }

    private function saveOrFail(
        SearchDocumentRecord $record,
        SearchDocumentIdentity $identity,
        string $operation,
    ): void {
        $saved = $record->save();
        $accepted = $saved === true && $record->exists;

        if ($operation === 'create') {
            $accepted = $accepted && $record->getKey() !== null;
        }

        if ($accepted) {
            return;
        }

        throw $operation === 'create'
            ? SearchIndexPersistenceException::createRejected($identity)
            : SearchIndexPersistenceException::updateRejected($identity);
    }

    private function deleteOrFail(SearchDocumentRecord $record): void
    {
        $identity = new SearchDocumentIdentity($record->partition, $record->source_key, $record->locale);

        if ($record->delete() !== true) {
            throw SearchIndexPersistenceException::deleteRejected($identity);
        }
    }

    /** @param list<SearchDocument> $documents */
    private function verifyFinalSnapshot(
        SearchDocumentSet $set,
        array $documents,
        ?string $connectionName,
    ): void {
        $persisted = $this->sourceQuery($set->reference, $connectionName)->inIdentityOrder()->get();
        /** @var array<string, SearchDocument> $incomingByIdentity */
        $incomingByIdentity = [];

        foreach ($documents as $document) {
            $incomingByIdentity[$this->documentIdentityKey($document)] = $document;
        }

        if (count($incomingByIdentity) !== $persisted->count()) {
            throw SearchIndexPersistenceException::snapshotMismatch(
                $set->reference,
                count($incomingByIdentity),
                $persisted->count(),
            );
        }

        foreach ($persisted as $record) {
            $document = $incomingByIdentity[$this->recordIdentityKey($record)] ?? null;

            if (! $document instanceof SearchDocument) {
                throw SearchIndexPersistenceException::snapshotMismatch(
                    $set->reference,
                    count($incomingByIdentity),
                    $persisted->count(),
                );
            }

            $this->persistenceVerifier->verify($record, $document);
        }
    }

    /** @return Builder<SearchDocumentRecord> */
    private function sourceQuery(SearchSourceReference $reference, ?string $connectionName): Builder
    {
        return $this->recordQuery($connectionName)->forSourceReference($reference);
    }

    /** @return Builder<SearchDocumentRecord> */
    private function lockedSourceKeyQuery(string $sourceKey, ?string $connectionName): Builder
    {
        return $this->recordQuery($connectionName)
            ->where('source_key', $sourceKey)
            ->inIdentityOrder()
            ->lockForUpdate();
    }

    /** @return Builder<SearchDocumentRecord> */
    private function documentQuery(SearchDocument $document, ?string $connectionName): Builder
    {
        return $this->recordQuery($connectionName)->where(SearchDocumentRecord::identityFor($document));
    }

    /** @return Builder<SearchDocumentRecord> */
    private function recordQuery(?string $connectionName): Builder
    {
        $record = new SearchDocumentRecord;
        $record->setConnection($connectionName);

        return $record->newQuery();
    }

    private function documentIdentityKey(SearchDocument $document): string
    {
        return hash('sha256', json_encode($document->identity->toArray(), JSON_THROW_ON_ERROR));
    }

    private function recordIdentityKey(SearchDocumentRecord $record): string
    {
        return hash('sha256', json_encode([
            'partition' => $record->partition,
            'source_key' => $record->source_key,
            'locale' => $record->locale,
        ], JSON_THROW_ON_ERROR));
    }
}
