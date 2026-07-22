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
    normalizedKeywords: 'شرکت نمونه',
    normalizedContent: 'متن معرفی شرکت',
    payload: ['route_name' => 'about'],
    priority: 10,
    isActive: true,
);

PersianSearch::indexDocument($document);
```

Re-indexing the same `partition + source_key + locale` updates its row. A missing locale is stored as `und`. Raw `title` and `excerpt` remain suitable for display; normalized fields are searched.

## Search document providers

Application sources are converted to validated document sets by a provider registry. Custom providers configured in `persian-search.providers` are resolved through Laravel's container; the built-in `eloquent` provider is the fallback and does not need to be registered. Exactly one matching custom provider wins. Multiple matches throw an ambiguity exception, and unsupported sources throw a provider-not-found exception. Provider keys are stable canonical identifiers: they must be non-empty, may not contain leading or trailing Unicode whitespace, may not contain Unicode control or formatting characters, and must not change between calls. Lookup input may use surrounding ASCII or Unicode whitespace for convenience, but visible key content remains case-sensitive and is not otherwise normalized.

```php
'providers' => [
    App\Search\LocalizedEntryProvider::class,
    App\Search\StaticResourceProvider::class,
],
```

Every provider returns a stable `SearchSourceReference` containing a source key, source type, and canonical string or null source ID. It may then yield zero, one, or many documents across locales and partitions. The validated set rejects non-documents, source mismatches, and duplicate `partition + source_key + locale` identities before any index write.

```php
$set = PersianSearch::documentsFor($source); // Read-only
$records = PersianSearch::indexSource($source);
$deleted = PersianSearch::deleteSource($source); // Every locale and partition
```

`indexSource()` upserts the documents currently returned by the provider in their original order. It does not remove older documents omitted from the latest provider output, and an empty output performs no writes or deletions.

## Eloquent fallback provider

Models using `HasPersianSearch` are handled by the built-in fallback provider:

```php
use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;

final class CatalogEntry extends Model
{
    use HasPersianSearch;

    public function persianSearchableFields(): array
    {
        return ['name' => 10, 'group.name' => 5, 'description' => 1];
    }

    public function persianSearchableRelations(): array
    {
        return ['group'];
    }
}
```

The fallback provider calls `loadMissing()` for explicitly declared relation paths. The model reindex command eager-loads these relations only when the built-in Eloquent provider owns the rebuild, while retaining model global scopes and chunked iteration. Relation paths are never inferred from field names; invalid fallback declarations fail with a focused exception. Custom providers do not invoke or validate the fallback relation declaration and own any source-specific relation preparation needed by their `documents()` implementation. Declared fallback field values are aggregated into normalized content, while experimental field weights are accepted but are not persisted.

```php
PersianSearch::index($entry);
$entry->savePersianSearchDocument();

PersianSearch::deleteFromIndex($entry);
$entry->deletePersianSearchDocument();
```

Automatic save/delete/restore synchronization is controlled by the active index lifecycle configuration.
When `index.include_soft_deleted` is enabled, soft-deleted models keep their
documents, are included by model reindexing, and may be hydrated in search
results through the model's normal scoped query plus `withTrashed()`. Force
deletion still removes their documents.

Custom providers may override the Eloquent fallback, use an arbitrary source type, and produce multilingual or multi-partition documents. Providers also accept non-Eloquent value objects with null IDs. Such documents are fully searchable; results use `model === null` when the source type is not an Eloquent class. See [the architecture guide](docs/architecture.md#provider-examples) for complete custom Eloquent and virtual-source examples.

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

For the Eloquent fallback, `--fresh` globally removes documents using the model-class source type before rebuilding, including orphaned documents whose model rows no longer exist. For a custom provider, the command validates each current model's complete document set, deletes using the exact `SearchSourceReference` carried by that validated set, and then indexes the same set. The provider reference is not recomputed for deletion. This removes omitted locale or partition documents for current models only. Custom-provider documents belonging to model rows that are no longer returned by the scoped model query cannot yet be enumerated; the command emits one warning and those orphaned sources require an explicit source-type flush. Normal `indexSource()` behavior remains non-destructive and does not remove omitted documents.

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
