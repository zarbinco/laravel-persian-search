# Laravel Persian Search

Laravel Persian Search provides a portable, document-first search index for Laravel applications. Persian normalization is delegated to `zarbinco/laravel-persian-core`.

## Requirements

- PHP `^8.2`
- Laravel components `^11.0`, `^12.0`, or `^13.0`
- `zarbinco/laravel-persian-core` `^0.2`

## Installation

```bash
composer require zarbinco/laravel-persian-search
php artisan persian-search:install
php artisan migrate
```

The index connection, table, default partition, undefined locale, lifecycle flags, query expansion, limits, and basic ranking settings are configured in `config/persian-search.php`.

## Preparing searchable text

The same locale-aware pipeline is available without an Eloquent model:

```php
$prepared = PersianSearch::prepareText(
    value: '<p>كیك شکلاتي</p>',
    locale: 'fa',
);

$prepared->raw;        // <p>كیك شکلاتي</p>
$prepared->sanitized;  // كیك شکلاتي
$prepared->normalized; // کیک شکلاتی
$prepared->tokens;     // ['کیک', 'شکلاتی']
```

Preparation converts supported scalar, backed-enum, `Stringable`, and nested array values; sanitizes HTML; cleans whitespace and invisible characters; normalizes for the locale; and creates ordered, unique Unicode tokens. Unsupported objects, resources, closures, and invalid UTF-8 fail with focused exceptions.

Persian-family locales use Persian Core. English-family and unknown locales use conservative Unicode lowercase and whitespace normalization without Persian substitutions. An explicit locale takes precedence; integration points otherwise use the application locale where appropriate, then the configured `und` fallback.

## Indexing documents

A document is independent of Eloquent and has a stable identity made from its partition, source key, and locale:

```php
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;

$document = new SearchDocument(
    partition: 'public',
    sourceKey: 'page:about',
    sourceType: 'page',
    sourceId: null,
    locale: 'fa',
    title: 'درباره ما',
    excerpt: 'درباره شرکت',
    normalizedTitle: 'درباره ما',
    normalizedExcerpt: 'درباره شرکت',
    normalizedKeywords: 'شرکت سن ایچ',
    normalizedContent: 'متن معرفی شرکت',
    payload: ['route_name' => 'about'],
    priority: 10,
    isActive: true,
);

PersianSearch::indexDocument($document);
```

Re-indexing the same `partition + source_key + locale` updates its row. A missing locale is stored as `und`. Raw `title` and `excerpt` remain suitable for display; normalized fields are searched.

## Eloquent convenience adapter

Models may use the current thin adapter:

```php
use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;

final class Product extends Model
{
    use HasPersianSearch;

    public function persianSearchableFields(): array
    {
        return ['name' => 10, 'brand.name' => 5, 'description' => 1];
    }
}
```

Loaded relation paths are supported and are never lazy-loaded by the document builder. Declared field values are temporarily aggregated into normalized content; experimental field weights are accepted by this adapter but are not persisted.

```php
PersianSearch::index($product);
$product->savePersianSearchDocument();

PersianSearch::deleteFromIndex($product);
$product->deletePersianSearchDocument();
```

Automatic save/delete/restore synchronization is controlled by the active index lifecycle configuration.
When `index.include_soft_deleted` is enabled, soft-deleted models keep their
documents, are included by model reindexing, and may be hydrated in search
results through the model's normal scoped query plus `withTrashed()`. Force
deletion still removes their documents.

## Searching

Document-first results include virtual records:

```php
$results = PersianSearch::search('درباره')
    ->types(['page', 'brand'])
    ->partition('public')
    ->locale('fa')
    ->results();

foreach ($results->items() as $result) {
    $result->record; // Always available
    $result->model;  // Eloquent model or null
    $result->score;
}
```

Eloquent convenience searches still return model collections:

```php
$products = Product::persianSearch('كیك شکلاتي')->get();
```

`SearchResults::models()` contains only successfully hydrated models. Missing Eloquent records do not invalidate document results.

The database driver searches `normalized_title`, `normalized_excerpt`, `normalized_keywords`, and `normalized_content`, ignores inactive documents, and applies source type, locale, and partition filters. Query-time synonym and wrong-keyboard candidate expansion remain configurable.

Default text components can be replaced through Laravel's container by binding `SearchTextSanitizer`, `SearchTextNormalizer`, or `SearchTokenizer` before the pipeline is resolved.

## Commands

```bash
php artisan persian-search:reindex "App\Models\Product" --fresh
php artisan persian-search:flush "App\Models\Product"
php artisan persian-search:flush page --partition=public
php artisan persian-search:flush --force
```

## Architecture and boundaries

See [docs/architecture.md](docs/architecture.md) for the identity and storage design.

The default database implementation is intentionally portable and simple. It does not provide pagination, facets, advanced ranking, dependency reindexing, queued indexing, cross-locale bridging, or typo-tolerant search.

## Testing

```bash
composer validate --strict
composer format -- --test
composer analyse
composer test
```
