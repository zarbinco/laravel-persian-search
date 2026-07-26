<?php

namespace Zarbinco\PersianSearch;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use Zarbinco\PersianSearch\Contracts\AdvancedQueryCorrector;
use Zarbinco\PersianSearch\Contracts\ContextualCorrectionEvaluator;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Contracts\SpellingCorrector;
use Zarbinco\PersianSearch\Correction\AdvancedCorrectionCollection;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchDocumentIdentity;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Indexing\SearchSourceIndexResult;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;
use Zarbinco\PersianSearch\Providers\SearchDocumentSet;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Search\EmptySearchResultFactory;
use Zarbinco\PersianSearch\Search\ProcessedSearchQuery;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\SearchQueryBuilder;
use Zarbinco\PersianSearch\Search\SearchQueryProcessor;
use Zarbinco\PersianSearch\Search\SearchResultPolicy;
use Zarbinco\PersianSearch\Spelling\SpellingCorrectionCollection;
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
        private readonly SearchResultPolicy $resultPolicy,
        private readonly EmptySearchResultFactory $emptyResults,
        private readonly ?SpellingCorrector $spelling = null,
        private readonly ?AdvancedQueryCorrector $advanced = null,
        private readonly ?ContextualCorrectionEvaluator $contextual = null,
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

    public function spellingCorrector(): ?SpellingCorrector
    {
        return $this->spelling;
    }

    public function spellingCorrections(ProcessedSearchQuery $query): SpellingCorrectionCollection
    {
        $original = $this->expander->original($query)->original();

        return $original === null || $this->spelling === null
            ? new SpellingCorrectionCollection(1)
            : $this->spelling->correct($original);
    }

    public function advancedCorrector(): ?AdvancedQueryCorrector
    {
        return $this->advanced;
    }

    public function advancedCorrections(ProcessedSearchQuery $query): AdvancedCorrectionCollection
    {
        $original = $this->expander->original($query)->original();

        return $original === null || $this->advanced === null
            ? new AdvancedCorrectionCollection(1)
            : $this->advanced->correct($original);
    }

    public function contextualCorrectionEvaluator(): ?ContextualCorrectionEvaluator
    {
        return $this->contextual;
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

    public function indexSource(mixed $source): SearchSourceIndexResult
    {
        return $this->indexManager->indexSource($source);
    }

    public function replaceDocumentSet(SearchDocumentSet $set): SearchSourceIndexResult
    {
        return $this->indexManager->replaceDocumentSet($set);
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

    public function deleteSourceReference(SearchSourceReference $reference): int
    {
        return $this->indexManager->deleteSourceReference($reference);
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
        return new SearchQueryBuilder(
            $query,
            $this->queryProcessor(),
            $this->driver,
            $this->expander,
            $this->resultPolicy,
            $this->emptyResults,
        );
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
