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
- Search-time query expansion with candidate boosting
- Configurable synonym expansion
- Wrong-keyboard typing correction for English-keyboard input intended as Persian
- Deterministic relevance scoring
- Result objects with scores, matched tokens, and matched query metadata
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

The default driver is `database`. Search limits, candidate limits, indexing behavior, query expansion, synonyms, keyboard correction, and ranking weights are configurable in `config/persian-search.php`.

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
    $candidateSource = $result->candidateSource;
    $matchedQuery = $result->matchedQuery;
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

Disable query expansion for a single search when you need exact normalized query behavior:

```php
$products = App\Models\Product::persianSearch('كیك شکلاتي')
    ->withoutExpansion()
    ->get();
```

## Query Expansion

Search queries are expanded into query candidates at search time. The original normalized query is always kept as the first candidate, and additional candidates can come from wrong-keyboard correction and configured synonyms.

Query candidates are not stored in the index. Indexed document titles, content, fields, and tokens remain normalized model data only.

Inspect candidates through the facade:

```php
$candidates = PersianSearch::expand(';dt');
```

Each candidate includes its source, original candidate text, normalized text, tokens, and boost.

## Synonyms

Synonym expansion is configurable and disabled by default:

```php
'synonyms' => [
    'enabled' => true,
    'bidirectional' => true,
    'max_candidates' => 20,
    'boost' => 0.85,
    'map' => [
        'گوشی' => ['موبایل', 'تلفن همراه'],
    ],
],
```

With that configuration, this query can match indexed content such as `گوشی سامسونگ`:

```php
$products = App\Models\Product::persianSearch('موبایل سامسونگ')->get();
```

Synonym keys and values are normalized and tokenized through `SearchNormalizer`, which delegates to `laravel-persian-core`.

## Wrong-Keyboard Typing Correction

Wrong-keyboard correction handles English-keyboard input intended as Persian. It is enabled by default for English-to-Persian query candidates:

```php
$products = App\Models\Product::persianSearch(';dt')->get();
```

The query above can match indexed content such as `کیف`. Keyboard correction only applies to user search input. It does not change model data, stored search documents, or Persian text normalization rules.

Persian-to-English correction is not enabled by default.

## Candidate Boosting

Each query candidate has a boost. The database driver scores each indexed document against candidates and uses the best boosted score for ordering. The original query receives the strongest default boost, keyboard-corrected candidates receive a slightly lower boost, and synonym candidates receive configurable lower boosts.

## Relevance

The database ranker scores persisted documents using:

- Exact normalized phrase matches in title and content
- All query tokens present in the document
- Individual token matches
- Title boosts
- Stored field weights
- Query candidate boosts

Search query normalization and tokenization always go through `SearchNormalizer`, which delegates to `laravel-persian-core`.

The scoring is deterministic and intentionally simple. It does not perform fuzzy matching, typo correction beyond wrong-keyboard layout correction, stemming, transliteration, or database-specific full-text search.

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
- Search-time query candidate expansion
- Wrong-keyboard typing correction for English-keyboard input intended as Persian
- Configurable synonym expansion
- Candidate boosting across original, keyboard, and synonym candidates
- Relevance-ranked Eloquent model results
- Result objects with scores, matched tokens, candidate source, and matched query
- Installation, reindexing, and flushing Artisan commands
- Publishable package configuration and migration

## Planned Capabilities

- Scout integration
- Meilisearch integration
- Elasticsearch integration
- Analytics and operational tooling
- Fuzzy typo correction and advanced suggestions
- Documentation and release-readiness improvements

Wrong-keyboard typing correction belongs to query candidate expansion in `laravel-persian-search`, not to `laravel-persian-core` as text normalization.

## Boundaries

`laravel-persian-core` owns Persian text normalization, digit conversion, punctuation cleanup, ZWNJ handling, and tokenization.

`laravel-persian-search` starts after that boundary. It must not duplicate Persian normalization logic, character maps, digit conversion, punctuation cleanup, ZWNJ handling, or tokenization rules already provided by `laravel-persian-core`.

Wrong-keyboard typing correction is intentionally scoped to query candidate expansion. Keyboard layout maps are used only to interpret search input typed with the wrong keyboard layout; they are not Persian normalization maps.

The current database driver searches persisted normalized documents. It does not implement fuzzy matching, stemming, transliteration, Scout, Meilisearch, or Elasticsearch.

## Testing

```bash
composer validate --strict
composer test
composer analyse
composer format -- --test
```
