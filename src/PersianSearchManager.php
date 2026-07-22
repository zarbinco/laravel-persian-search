<?php

namespace Zarbinco\PersianSearch;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Throwable;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchDocumentIdentity;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\ProcessedSearchQuery;
use Zarbinco\PersianSearch\Search\QueryCandidate;
use Zarbinco\PersianSearch\Search\SearchQuery;
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
        if (! $model instanceof PersianSearchable) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] must implement [%s] to build a Persian search document.',
                $model::class,
                PersianSearchable::class,
            ));
        }

        return $this->builder->build($model);
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

    public function deleteSource(string $sourceKey, ?string $partition = null): int
    {
        return $this->indexManager->deleteSource($sourceKey, $partition);
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

    /**
     * @return list<QueryCandidate>
     */
    public function expand(mixed $query, ?string $locale = null): array
    {
        $processed = $this->processQuery($query, $locale);

        if (! $processed->isSearchable()) {
            return [];
        }

        $searchQuery = new SearchQuery(
            original: $processed->sanitizedQuery,
            normalized: $processed->normalizedQuery,
            tokens: $processed->searchableTokens,
            sourceTypes: [],
            locale: $processed->locale,
            textLocale: $processed->locale,
            partition: (string) config('persian-search.index.default_partition', 'default'),
            limit: (int) config('persian-search.search.default_limit', 20),
            offset: 0,
            includeScores: false,
            processedQuery: $processed,
        );

        return $this->expander->expand($searchQuery);
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
