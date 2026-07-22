<?php

namespace Zarbinco\PersianSearch\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchDocumentIdentity;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\PersianSearchManager;
use Zarbinco\PersianSearch\Search\SearchQueryBuilder;
use Zarbinco\PersianSearch\Text\PreparedSearchText;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

/**
 * @method static PreparedSearchText prepareText(mixed $value, ?string $locale = null)
 * @method static string normalize(string $value, ?string $locale = null)
 * @method static array<int, string> tokens(string $value, ?string $locale = null)
 * @method static SearchTextPipeline textPipeline()
 * @method static SearchDocument documentFor(Model $model)
 * @method static SearchDocumentBuilder builder()
 * @method static SearchDocumentRecord index(Model $model)
 * @method static SearchDocumentRecord indexDocument(SearchDocument $document)
 * @method static int deleteFromIndex(Model $model)
 * @method static int deleteDocument(SearchDocumentIdentity $identity)
 * @method static int deleteSource(string $sourceKey, ?string $partition = null)
 * @method static int flushIndex(?string $sourceType = null, ?string $partition = null)
 * @method static SearchIndexManager indexManager()
 * @method static SearchQueryBuilder search(string $query)
 * @method static QueryExpander queryExpander()
 * @method static list<\Zarbinco\PersianSearch\Search\QueryCandidate> expand(string $query, ?string $locale = null)
 * @method static SearchDriver driver()
 */
final class PersianSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PersianSearchManager::class;
    }
}
