# Laravel Persian Search

Laravel Persian Search provides Persian-aware indexing, database search, relevance scoring, query expansion, configurable synonyms, and wrong-keyboard typing correction for Laravel applications. It is powered by `zarbinco/laravel-persian-core` for normalization and tokenization.

## Features

- Delegates Persian search normalization and tokenization to `laravel-persian-core`
- Searchable Eloquent model declarations
- In-memory normalized search documents
- Database-backed search index
- Manual indexing and deletion APIs
- Automatic indexing on save, delete, and restore
- Reindex and flush console commands
- Portable database search driver
- Relevance-ranked Eloquent model results
- Result objects with scores, matched tokens, candidate source, and matched query
- Query candidate expansion
- Configurable synonyms
- Wrong-keyboard typing correction for English-keyboard input intended as Persian

## Requirements

- PHP `^8.2`
- Laravel components `^11.0`, `^12.0`, or `^13.0`
- `zarbinco/laravel-persian-core`

## Installation

Install the package with Composer:

```bash
composer require zarbinco/laravel-persian-search
```

The package depends on `zarbinco/laravel-persian-core`, which Composer installs as a production dependency. Laravel package auto-discovery registers the service provider and optional facade alias.

## Configuration

Publish the configuration and migration files with the install command:

```bash
php artisan persian-search:install
php artisan migrate
```

You may also publish them manually:

```bash
php artisan vendor:publish --tag=persian-search-config
php artisan vendor:publish --tag=persian-search-migrations
php artisan migrate
```

The default driver is `database`. Search limits, candidate loading, indexing behavior, query expansion, synonyms, keyboard correction, and ranking weights are configured in `config/persian-search.php`.

## Defining Searchable Models

Models describe their searchable content by using `HasPersianSearch` and returning weighted searchable fields:

```php
use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;

final class Product extends Model
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

## Manual Indexing

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

## Automatic Indexing

When `persian-search.index.sync_on_save` is enabled, models using `HasPersianSearch` are indexed after save.

When `persian-search.index.delete_on_model_delete` is enabled, deleted models are removed from the index. Soft-deleted models are removed unless `include_soft_deleted` is enabled. Restored models are indexed again when automatic sync is enabled.

## Reindexing

Rebuild search documents for a model:

```bash
php artisan persian-search:reindex "App\Models\Product" --fresh
```

The `--fresh` option removes existing indexed documents for that model before reindexing.

## Flushing

Flush one searchable model type:

```bash
php artisan persian-search:flush "App\Models\Product"
```

Flush all search documents:

```bash
php artisan persian-search:flush --force
```

Without `--force`, flushing all documents asks for confirmation.

## Searching

Search indexed models through the facade:

```php
use Zarbinco\PersianSearch\Facades\PersianSearch;

$products = PersianSearch::search('كیك شکلاتي')
    ->for(App\Models\Product::class)
    ->get();
```

Search through the model convenience API:

```php
$products = App\Models\Product::persianSearch('كیك شکلاتي')->get();
```

Use locale filtering when indexed documents include locales:

```php
$products = PersianSearch::search('كیك')
    ->for(App\Models\Product::class)
    ->locale('fa')
    ->get();
```

Disable query expansion for a single search when exact normalized query behavior is needed:

```php
$products = App\Models\Product::persianSearch('كیك شکلاتي')
    ->withoutExpansion()
    ->get();
```

## Result Objects

Fetch result objects when you need scores and match metadata:

```php
$results = PersianSearch::search('كیك شکلاتي')
    ->for(App\Models\Product::class)
    ->results();

foreach ($results->items() as $result) {
    $result->model;
    $result->score;
    $result->matchedTokens;
    $result->candidateSource;
    $result->matchedQuery;
}
```

## Query Expansion

Search queries are expanded into candidates at search time. The original normalized query is always kept as the first candidate, and additional candidates can come from wrong-keyboard correction and configured synonyms.

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
$products = Product::persianSearch('موبایل سامسونگ')->get();
```

Synonym keys and values are normalized and tokenized through `SearchNormalizer`, which delegates to `laravel-persian-core`.

## Wrong-Keyboard Typing Correction

Wrong-keyboard correction handles English-keyboard input intended as Persian:

```php
$products = Product::persianSearch(';dt')->get();
```

The query above can match indexed content such as `کیف`.

This happens only at query time. It does not mutate stored index content, and it is not part of `laravel-persian-core` normalization. Wrong-keyboard typing correction belongs to `laravel-persian-search` as query candidate expansion.

Persian-to-English correction is not enabled by default.

## Relevance Scoring

The database ranker scores persisted documents using:

- Exact normalized phrase matches in title and content
- All query tokens present in the document
- Individual token matches
- Title boosts
- Stored field weights
- Query candidate boosts

The database driver scores each indexed document against query candidates and uses the best boosted score for ordering. The original query receives the strongest default boost, keyboard-corrected candidates receive a slightly lower boost, and synonym candidates receive configurable lower boosts.

## Console Commands

```bash
php artisan persian-search:install
php artisan persian-search:reindex "App\Models\Product" --fresh
php artisan persian-search:flush "App\Models\Product"
php artisan persian-search:flush --force
```

## Boundaries

This package uses portable database search by default. It is not a full-text engine.

`laravel-persian-core` owns Persian text normalization, digit conversion, punctuation cleanup, ZWNJ handling, and tokenization.

`laravel-persian-search` owns searchable model declarations, indexing, search execution, relevance scoring, and query intent features such as synonyms and wrong-keyboard correction.

This package does not currently provide Scout, Meilisearch, or Elasticsearch adapters. It does not perform fuzzy typo correction, stemming, or transliteration.

## Planned Capabilities

- Scout adapter
- Meilisearch and Elasticsearch adapters
- Search analytics
- Suggestion engine
- More advanced ranking strategies

## Release Notes

See [CHANGELOG.md](CHANGELOG.md).

## Testing

```bash
composer test
composer analyse
composer format -- --test
```
