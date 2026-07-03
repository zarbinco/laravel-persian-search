<?php

namespace Zarbinco\PersianSearch\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\PersianSearchManager;
use Zarbinco\PersianSearch\Search\SearchQueryBuilder;

/**
 * @method static string normalize(string $value)
 * @method static array<int, string> tokens(string $value)
 * @method static SearchDocument documentFor(Model $model)
 * @method static SearchDocumentBuilder builder()
 * @method static SearchDocumentRecord index(Model $model)
 * @method static int deleteFromIndex(Model $model)
 * @method static int flushIndex(?string $searchableType = null)
 * @method static SearchIndexManager indexManager()
 * @method static SearchQueryBuilder search(string $query)
 * @method static QueryExpander queryExpander()
 * @method static list<\Zarbinco\PersianSearch\Search\QueryCandidate> expand(string $query)
 * @method static SearchDriver driver()
 */
final class PersianSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PersianSearchManager::class;
    }
}
