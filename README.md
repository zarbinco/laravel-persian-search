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

Persian-family locales use Persian Core. English-family and unknown locales use conservative Unicode lowercase and whitespace normalization without Persian substitutions. With fluent queries, no explicit locale uses the application locale, an explicitly empty locale resolves to `und`, and a non-empty explicit locale is used as supplied. The final resolved locale controls both text processing and exact database locale filtering.

## Processing queries

Raw queries are processed before expansion or database access:

```php
$processed = PersianSearch::processQuery(
    query: '  كیك شکلاتي  ',
    locale: 'fa',
);

$processed->status->value;      // ready
$processed->normalizedQuery;    // کیک شکلاتی
$processed->tokens;             // Complete tokenizer output
$processed->searchableTokens;   // Tokens allowed by query policy
$processed->isSearchable();
```

Statuses are `empty`, `punctuation_only`, `too_short`, `too_long`, and `ready`. Only ready queries proceed to expansion and the search driver; other statuses return empty results without querying search documents or hydrating models.

Query policy defaults are configured in `config/persian-search.php`:

```php
'query' => [
    'minimum_length' => 2,
    'maximum_length' => 200,
    'minimum_token_length' => 1,
    'maximum_tokens' => 20,
    'maximum_length_policy' => 'truncate', // truncate or reject
],
```

Lengths count Unicode code points rather than bytes or visual grapheme clusters. Truncation occurs before sanitization and normalization. The complete `tokens` list remains diagnostic; `searchableTokens` applies minimum token length and then keeps the configured number of first eligible tokens. Invalid policy configuration throws when query processing is first resolved.

## Query variants

Ready processed queries expand deterministically into typed variants: original, English-to-Persian keyboard correction, synonyms from the original, then synonyms from the keyboard variant. The defaults assign priorities `1000`, `800`, `600`, and `400`, respectively, and keep at most 20 distinct query-locale pairs. Priority selects provenance when variants collide; it is not a document-score multiplier.

```php
$processed = PersianSearch::processQuery('\\vjrhg', 'en');
$variants = PersianSearch::expandQuery($processed);

$variants->all()[0]->query;  // \vjrhg, locale en, source original
$variants->all()[1]->query;  // پرتقال, locale fa, source keyboard

$sameView = PersianSearch::query('\\vjrhg')->locale('en')->variants();
```

Only `en_to_fa` keyboard correction is implemented. It follows the Windows Persian keyboard layout and handles base keys, uppercase Shift keys, and shifted punctuation as distinct physical inputs. Its authoritative map includes `\\ → پ`, `m → ئ`, `M → ء`, `c → ز`, and `C → ژ`; unmapped characters are retained before corrected text is normalized by the Persian text pipeline. Persian-to-English correction is intentionally not advertised.

Synonyms are disabled by default, exact-locale, token-boundary-aware, and one-way:

```php
'synonyms' => [
    'enabled' => true,
    'locales' => [
        'fa' => [
            'آبمیوه' => ['نوشیدنی میوه', 'جویس'],
            'پرتقال' => ['نارنج'],
        ],
        'en' => [
            'juice' => ['fruit drink'],
        ],
    ],
],
```

Single- and multi-token sources replace complete normalized token sequences, never substrings inside a word. Each generated variant applies one configured replacement, so expansion does not recurse or form a Cartesian product.

Synonym expansions are yielded lazily in configured rule, replacement, and token-position order. Duplicate candidate token sequences are skipped before normalization, and duplicate normalized query-locale outputs are yielded once with first-configured provenance. `variants.maximum_variants` bounds both retained variants and generated synonym work: expansion is not invoked when earlier variants fill the collection, and generator consumption stops as soon as the final slot is filled.

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
$results = PersianSearch::query('درباره')
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

The database driver searches `normalized_title`, `normalized_excerpt`, `normalized_keywords`, and `normalized_content`, ignores inactive documents, and applies source type and partition filters. Each query variant is searched using its own exact locale. A corrected English-layout query can therefore return a Persian document directly; localized counterpart resolution is not performed.

Each `SearchResult` exposes its typed `matchedVariant`. The convenience fields `candidateSource`, `matchedQuery`, and `matchedLocale` are derived from that object. If one stored document matches multiple variants, the driver keeps one result using variant priority first, score second, and stable variant order for ties.

The fluent `query()` and existing `search()` alias process lazily at execution time, so the final effective locale is used regardless of builder call order. The query processor, expansion context, diagnostics, and database filter all receive that same resolved locale. Non-searchable model queries return an empty collection without accessing the search table.

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
