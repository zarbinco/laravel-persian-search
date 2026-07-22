<?php

namespace Zarbinco\PersianSearch;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchDocumentIdentity;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;
use Zarbinco\PersianSearch\Providers\SearchDocumentSet;
use Zarbinco\PersianSearch\Search\ProcessedSearchQuery;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\SearchQueryBuilder;
use Zarbinco\PersianSearch\Search\SearchQueryProcessor;
use Zarbinco\PersianSearch\Text\PreparedSearchText;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final class PersianSearchManager
{
    private ?SearchQueryProcessor $resolvedQueryProcessor = null;

    /** @param Closure(): SearchQueryProcessor $queryProcessorResolver */
    public function __construct(
        private readonly SearchTextPipeline $pipeline,
        private readonly Closure $queryProcessorResolver,
        private readonly SearchDocumentBuilder $builder,
        private readonly SearchIndexManager $indexManager,
        private readonly SearchDriver $driver,
        private readonly QueryExpander $expander,
        private readonly SearchDocumentProviderRegistry $providers,
    ) {}

    public function textPipeline(): SearchTextPipeline
    {
        return $this->pipeline;
    }

    public function builder(): SearchDocumentBuilder
    {
        return $this->builder;
    }

    public function indexManager(): SearchIndexManager
    {
        return $this->indexManager;
    }

    public function driver(): SearchDriver
    {
        return $this->driver;
    }

    public function queryExpander(): QueryExpander
    {
        return $this->expander;
    }

    public function queryProcessor(): SearchQueryProcessor
    {
        return $this->resolvedQueryProcessor ??= ($this->queryProcessorResolver)();
    }

    public function prepareText(mixed $value, ?string $locale = null): PreparedSearchText
    {
        return $this->pipeline->prepare($value, $locale ?? $this->applicationLocale());
    }

    public function normalize(string $value, ?string $locale = null): string
    {
        return $this->prepareText($value, $locale)->normalized;
    }

    /**
     * @return array<int, string>
     */
    public function tokens(string $value, ?string $locale = null): array
    {
        return $this->prepareText($value, $locale)->tokens;
    }

    public function documentFor(Model $model): SearchDocument
    {
        return $this->indexManager->documentFor($model);
    }

    public function documentsFor(mixed $source): SearchDocumentSet
    {
        return $this->indexManager->documentsFor($source);
    }

    /** @return Collection<int, SearchDocumentRecord> */
    public function indexSource(mixed $source): Collection
    {
        return $this->indexManager->indexSource($source);
    }

    public function providerFor(mixed $source): SearchDocumentProvider
    {
        return $this->providers->resolve($source);
    }

    public function index(Model $model): SearchDocumentRecord
    {
        return $this->indexManager->index($model);
    }

    public function indexDocument(SearchDocument $document): SearchDocumentRecord
    {
        return $this->indexManager->indexDocument($document);
    }

    public function deleteFromIndex(Model $model): int
    {
        return $this->indexManager->delete($model);
    }

    public function deleteDocument(SearchDocumentIdentity $identity): int
    {
        return $this->indexManager->deleteDocument($identity);
    }

    public function deleteSource(mixed $source): int
    {
        return $this->indexManager->deleteSource($source);
    }

    public function deleteSourceKey(string $sourceKey, ?string $partition = null): int
    {
        return $this->indexManager->deleteSourceKey($sourceKey, $partition);
    }

    public function flushIndex(?string $sourceType = null, ?string $partition = null): int
    {
        return $this->indexManager->flush($sourceType, $partition);
    }

    public function processQuery(mixed $query, ?string $locale = null): ProcessedSearchQuery
    {
        return $this->queryProcessor()->process($query, $locale ?? $this->applicationLocale());
    }

    public function query(mixed $query): SearchQueryBuilder
    {
        return new SearchQueryBuilder($query, $this->queryProcessor(), $this->driver, $this->expander);
    }

    public function search(mixed $query): SearchQueryBuilder
    {
        return $this->query($query);
    }

    public function expandQuery(ProcessedSearchQuery $query): QueryVariantCollection
    {
        return $this->expander->expand($query);
    }

    private function applicationLocale(): ?string
    {
        try {
            return app()->getLocale();
        } catch (Throwable) {
            return null;
        }
    }
}
