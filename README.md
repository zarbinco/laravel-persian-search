# laravel-persian-search

`zarbinco/laravel-persian-search` provides Laravel-facing search indexing utilities for Persian applications, powered by `zarbinco/laravel-persian-core`.

## Overview

The package currently supports Persian search preparation and indexing primitives for Eloquent models:

- Search normalization and tokenization delegated to `laravel-persian-core`
- Searchable model declarations through `PersianSearchable`
- Eloquent helpers through `HasPersianSearch`
- In-memory `SearchDocument` creation from Eloquent models
- Database-backed persistence for normalized search documents
- Manual indexing, deletion, and flushing APIs
- Optional automatic indexing on model save/delete
- Artisan commands for installation, reindexing, and flushing

It does not currently execute search queries, provide relevance-ranked results, integrate with Scout, or connect to external search engines.

## Installation

Install the package with Composer:

```bash
composer require zarbinco/laravel-persian-search
```

The package depends on `zarbinco/laravel-persian-core` and uses Laravel package auto-discovery.

## Publishing Configuration And Migrations

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

The index table name is controlled by `persian-search.index.table` and defaults to `persian_search_documents`.

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

Field values may come from model attributes or loaded relation paths such as `brand.name`. Field weights are stored with the document for future ranking features.

## Building In-Memory Documents

```php
use Zarbinco\PersianSearch\Facades\PersianSearch;

$document = PersianSearch::documentFor($product);
```

The document builder resolves declared fields, converts supported values into searchable strings, delegates normalization and tokenization to `laravel-persian-core`, and returns a `SearchDocument` value object.

## Persisting Search Documents

Persist a normalized document manually:

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

Persisted records are stored as normalized documents only. The package does not use them to execute search queries yet.

## Automatic Indexing On Save And Delete

Automatic indexing is controlled by configuration:

```php
'index' => [
    'sync_on_save' => true,
    'delete_on_model_delete' => true,
    'include_soft_deleted' => false,
],
```

When enabled, models using `HasPersianSearch` are indexed after save and removed from the index after delete. Soft-deleted models are removed unless `include_soft_deleted` is enabled, and restored models are indexed again when automatic sync is enabled.

Queue-backed indexing is reserved for a future implementation.

## Reindexing From The Command Line

```bash
php artisan persian-search:reindex "App\Models\Product" --fresh
```

The `--fresh` option removes existing documents for the model class before rebuilding them. Use `--chunk=100` to control chunk size.

## Flushing Indexed Documents

Flush one model class:

```bash
php artisan persian-search:flush "App\Models\Product"
```

Flush all indexed documents:

```bash
php artisan persian-search:flush --force
```

## Current Features

- Search normalization through `laravel-persian-core`
- Search token generation through `laravel-persian-core`
- Container binding for `Zarbinco\PersianSearch\Contracts\SearchNormalizer`
- Searchable model declaration through `Zarbinco\PersianSearch\Contracts\PersianSearchable`
- Eloquent defaults and convenience helpers through `HasPersianSearch`
- In-memory normalized `SearchDocument` creation from Eloquent models
- Database-backed storage for normalized search documents
- Manual indexing, deletion, and flushing APIs
- Automatic save/delete indexing hooks
- Installation, reindexing, and flushing Artisan commands
- Publishable package configuration and migration

## Planned Capabilities

- Database search driver
- Relevance-ranked search results
- Query expansion
- Synonyms
- Wrong-keyboard typing correction
- Scout integration
- External search-engine integrations
- Documentation and release-readiness improvements

Wrong-keyboard typing correction is a search-layer feature planned for this package. It belongs to query candidate expansion in `laravel-persian-search`, not to `laravel-persian-core` as text normalization.

## Boundaries

`laravel-persian-core` owns Persian text normalization, digit conversion, punctuation cleanup, ZWNJ handling, and tokenization.

`laravel-persian-search` starts after that boundary. It must not duplicate Persian normalization logic, character maps, digit conversion, punctuation cleanup, ZWNJ handling, or tokenization rules already provided by `laravel-persian-core`.

The current database index stores normalized documents only. It does not execute search queries or provide ranked search results.

## Testing

```bash
composer validate --strict
composer test
composer analyse
composer format -- --test
```
