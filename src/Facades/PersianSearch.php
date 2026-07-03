<?php

namespace Zarbinco\PersianSearch\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\PersianSearchManager;

/**
 * @method static string normalize(string $value)
 * @method static array<int, string> tokens(string $value)
 * @method static SearchDocument documentFor(Model $model)
 * @method static SearchDocumentBuilder builder()
 */
final class PersianSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PersianSearchManager::class;
    }
}
