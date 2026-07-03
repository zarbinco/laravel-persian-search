# laravel-persian-search

`zarbinco/laravel-persian-search` provides Persian-aware indexing and database search utilities for Laravel applications, powered by `zarbinco/laravel-persian-core`.

## Overview

The package currently supports:

- Search normalization and tokenization delegated to `laravel-persian-core`
- Searchable model declarations through `PersianSearchable`
- Eloquent helpers through `HasPersianSearch`
- In-memory `SearchDocument` creation from Eloquent models
- Database-backed persistence for normalized search documents
- Manual indexing, deletion, and flushing APIs
- Optional automatic indexing on model save/delete/restore
- Portable database search over persisted search documents
- Deterministic relevance scoring
- Result objects with scores and matched tokens
- Artisan commands for installation, reindexing, and flushing

The database driver uses normalized document data stored in `persian_search_documents`. It is portable database search, not database-specific full-text search.

## Installation

Install the package with Composer:

```bash
composer require zarbinco/laravel-persian-search
```

The package depends on `zarbinco/laravel-persian-core` and uses Laravel package auto-discovery.

## Configuration

Publish the configuration and migration files:

```bash
php artisan vendor:publish --tag=persian-search-config
php artisan vendor:publish --tag=persian-search-migrations
php artisan migrate
```

Or use the install command:

```bash
php artisan persian-search:install
php artisan migrate
```

The default driver is `database`. Search limits, candidate limits, indexing behavior, and ranking weights are configurable in `config/persian-search.php`.

## Defining Searchable Models

Models describe their Persian-searchable content by implementing `PersianSearchable` and using `HasPersianSearch`:

```php
use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;

final class Product extends Model implements PersianSearchable
{
    use HasPersianSearch;

    public function persianSearchableFields(): array
    {
        return [
            'name' => 10,
            'brand.name' => 5,
            'description' => 1,
        ];
    }
}
```

Field values may come from model attributes or loaded relation paths such as `brand.name`. Field weights are stored and used by the database ranker.

## Indexing

Persist a normalized search document manually:

```php
use Zarbinco\PersianSearch\Facades\PersianSearch;

PersianSearch::index($product);

$product->savePersianSearchDocument();
```

Remove a model from the index:

```php
PersianSearch::deleteFromIndex($product);

$product->deletePersianSearchDocument();
```

When `persian-search.index.sync_on_save` is enabled, models using `HasPersianSearch` are indexed after save. When `delete_on_model_delete` is enabled, deleted models are removed from the index. Soft-deleted models are removed unless `include_soft_deleted` is enabled, and restored models are indexed again when automatic sync is enabled.

Reindex from the command line:

```bash
php artisan persian-search:reindex "App\Models\Product" --fresh
```

Flush indexed documents:

```bash
php artisan persian-search:flush "App\Models\Product"
php artisan persian-search:flush --force
```

## Searching

Search indexed models through the facade:

```php
use Zarbinco\PersianSearch\Facades\PersianSearch;

$products = PersianSearch::search('كیك شکلاتي')
    ->for(App\Models\Product::class)
    ->limit(10)
    ->get();
```

Search through the model convenience API:

```php
$products = App\Models\Product::persianSearch('كیك شکلاتي')->get();
```

Fetch result objects with scores:

```php
$results = PersianSearch::search('كیك شکلاتي')
    ->for(App\Models\Product::class)
    ->results();

foreach ($results->items() as $result) {
    $model = $result->model;
    $score = $result->score;
    $matchedTokens = $result->matchedTokens;
}
```

Use locale filtering when your indexed documents include locales:

```php
$products = PersianSearch::search('كیك')
    ->for(App\Models\Product::class)
    ->locale('fa')
    ->get();
```

If no locale is provided, results are not filtered by locale.

## Relevance

The database ranker scores persisted documents using:

- Exact normalized phrase matches in title and content
- All query tokens present in the document
- Individual token matches
- Title boosts
- Stored field weights

Search query normalization and tokenization always go through `SearchNormalizer`, which delegates to `laravel-persian-core`.

The scoring is deterministic and intentionally simple. It does not perform synonym expansion, typo correction, fuzzy matching, or wrong-keyboard typing correction.

## Current Features

- Persian search normalization through `laravel-persian-core`
- Persian search token generation through `laravel-persian-core`
- Searchable model declaration through `PersianSearchable`
- Eloquent defaults and convenience helpers through `HasPersianSearch`
- In-memory normalized `SearchDocument` creation from Eloquent models
- Database-backed storage for normalized search documents
- Manual indexing, deletion, and flushing APIs
- Automatic save/delete/restore indexing hooks
- Database search driver over persisted documents
- Relevance-ranked Eloquent model results
- Result objects with scores and matched tokens
- Installation, reindexing, and flushing Artisan commands
- Publishable package configuration and migration

## Planned Capabilities

- Query expansion
- Synonyms
- Wrong-keyboard typing correction
- Scout integration
- Meilisearch integration
- Elasticsearch integration
- Analytics and operational tooling
- Documentation and release-readiness improvements

Wrong-keyboard typing correction is a search-layer feature planned for this package. It belongs to query candidate expansion in `laravel-persian-search`, not to `laravel-persian-core` as text normalization.

## Boundaries

`laravel-persian-core` owns Persian text normalization, digit conversion, punctuation cleanup, ZWNJ handling, and tokenization.

`laravel-persian-search` starts after that boundary. It must not duplicate Persian normalization logic, character maps, digit conversion, punctuation cleanup, ZWNJ handling, or tokenization rules already provided by `laravel-persian-core`.

The current database driver searches persisted normalized documents. It does not implement synonyms, wrong-keyboard typing correction, query expansion, fuzzy matching, Scout, Meilisearch, or Elasticsearch.

## Testing

```bash
composer validate --strict
composer test
composer analyse
composer format -- --test
```
