<?php

namespace Zarbinco\PersianSearch\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Zarbinco\PersianSearch\Contracts\AdvancedQueryCorrector;
use Zarbinco\PersianSearch\Contracts\ContextualCorrectionEvaluator;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Contracts\SpellingCorrector;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchDocumentIdentity;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Indexing\SearchSourceIndexResult;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\PersianSearchManager;
use Zarbinco\PersianSearch\Providers\SearchDocumentSet;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Search\ProcessedSearchQuery;
use Zarbinco\PersianSearch\Search\SearchQueryBuilder;
use Zarbinco\PersianSearch\Search\SearchQueryProcessor;
use Zarbinco\PersianSearch\Text\PreparedSearchText;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

/**
 * @method static PreparedSearchText prepareText(mixed $value, ?string $locale = null)
 * @method static string normalize(string $value, ?string $locale = null)
 * @method static array<int, string> tokens(string $value, ?string $locale = null)
 * @method static SearchTextPipeline textPipeline()
 * @method static ProcessedSearchQuery processQuery(mixed $query, ?string $locale = null)
 * @method static SearchQueryProcessor queryProcessor()
 * @method static SearchDocument documentFor(Model $model)
 * @method static SearchDocumentSet documentsFor(mixed $source)
 * @method static SearchSourceIndexResult indexSource(mixed $source)
 * @method static SearchSourceIndexResult replaceDocumentSet(SearchDocumentSet $set)
 * @method static SearchDocumentProvider providerFor(mixed $source)
 * @method static SearchDocumentBuilder builder()
 * @method static SearchDocumentRecord index(Model $model)
 * @method static SearchDocumentRecord indexDocument(SearchDocument $document)
 * @method static int deleteFromIndex(Model $model)
 * @method static int deleteDocument(SearchDocumentIdentity $identity)
 * @method static int deleteSource(mixed $source)
 * @method static int deleteSourceReference(SearchSourceReference $reference)
 * @method static int deleteSourceKey(string $sourceKey, ?string $partition = null)
 * @method static int flushIndex(?string $sourceType = null, ?string $partition = null)
 * @method static SearchIndexManager indexManager()
 * @method static SearchQueryBuilder query(mixed $query)
 * @method static SearchQueryBuilder search(mixed $query)
 * @method static QueryExpander queryExpander()
 * @method static \Zarbinco\PersianSearch\Search\QueryVariantCollection expandQuery(\Zarbinco\PersianSearch\Search\ProcessedSearchQuery $query)
 * @method static SearchDriver driver()
 * @method static SpellingCorrector|null spellingCorrector()
 * @method static \Zarbinco\PersianSearch\Spelling\SpellingCorrectionCollection spellingCorrections(\Zarbinco\PersianSearch\Search\ProcessedSearchQuery $query)
 * @method static AdvancedQueryCorrector|null advancedCorrector()
 * @method static \Zarbinco\PersianSearch\Correction\AdvancedCorrectionCollection advancedCorrections(\Zarbinco\PersianSearch\Search\ProcessedSearchQuery $query)
 * @method static ContextualCorrectionEvaluator|null contextualCorrectionEvaluator()
 */
final class PersianSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PersianSearchManager::class;
    }
}
