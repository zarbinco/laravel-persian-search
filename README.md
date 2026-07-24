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

Each document may also store a safe Laravel source-connection name. The built-in
Eloquent provider captures the model's resolved connection at indexing time.
This name is semantic document data, but is not part of document or source
identity and contains no credentials or connection configuration. Custom and
virtual providers may leave it null.

```php
$set = PersianSearch::documentsFor($source); // Build and validate; no writes
$result = PersianSearch::indexSource($source); // Complete atomic replacement
$result = PersianSearch::replaceDocumentSet($set); // Replace an already validated set
$deleted = PersianSearch::deleteSource($source); // Every locale and partition
```

`indexSource()` resolves the provider once, fully builds and validates its document set before opening a database transaction, and then makes the persisted source snapshot exactly match that set. It creates missing identities, updates only semantically changed identities, leaves identical rows and timestamps untouched, and deletes omitted locales and partitions as stale. Empty output is a valid empty snapshot and deletes every existing document for that source. The returned `SearchSourceIndexResult` reports exact incoming, created, updated, unchanged, deleted, and final counts; `changed()` sums created, updated, and deleted rows, while `isNoOp()` is true when none of those rows changed.

`indexDocument()` is deliberately different: it is a transactionally race-tolerant single-document upsert and never deletes sibling locales or partitions. It locks and validates the source key, preserves hash-based no-op behavior, and recovers when a concurrent first writer creates the same identity. Race recovery applies only when the unique violation is attributable to the search-document insert on the configured connection; unique violations from model listeners or unrelated database work are rethrown. Every returned row is reloaded from the write connection and semantically verified. `documentsFor()` only constructs and validates a set. `deleteSourceReference()` explicitly deletes the complete identity named by a `SearchSourceReference`.

Each source replacement is transactionally all-or-nothing on the configured search-index connection. Existing rows for the source are locked in deterministic identity order during comparison and persistence, with the source-key lookup backed by a dedicated `source_key` index; database uniqueness continues protecting document identities. A create, update, or delete rejected by an Eloquent event is treated as a persistence failure. Before success, every row is reloaded and the complete semantic state—not only identity or `document_hash`—is compared with the validated document set. Observer mutations and hash-matching corrupted fields therefore roll back the transaction. This is not a distributed lock and does not claim stronger first-write serialization on databases where no existing source row can be locked. Transaction retries use the same prevalidated set; configure the bounded attempt count with `persian-search.index.transaction_attempts` (default `3`).

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

Automatic Eloquent synchronization is controlled by `index.sync_on_save`.
Creates, updates, and restores synchronize once through `saved`. Deletes prepare
their immutable locator and provider reference during `deleting`, while the
source row and any required relations are still available, and dispatch once
through `deleted` only after deletion succeeds. A canceled or failed delete
therefore dispatches nothing, and hard or force deletion never needs to
recompute provider identity from an absent row.
Explicit APIs such as `PersianSearch::indexSource()` and the trait helpers remain
immediate and are not routed through this lifecycle.

Lifecycle work is transaction-aware by default:

```php
'lifecycle' => [
    'after_commit' => true,
    'execution' => 'sync', // sync or queue
],
```

To dispatch after-commit work to a worker:

```php
'lifecycle' => [
    'after_commit' => true,
    'execution' => 'queue',
],
```

Inside a transaction, `after_commit` registers work on the model's exact source
connection. An outer rollback discards it, nested transactions wait for the
outermost commit, and transactions on unrelated connections do not delay it.
Outside a transaction, synchronous execution remains immediate. Setting
`after_commit` to `false` restores event-time behavior and can leave the search
index ahead of rolled-back source data when the two use different connections.
After-commit failures are deliberately not swallowed: synchronous exceptions
propagate from the commit callback, while queued failures use Laravel's normal
retry and failed-job handling.

Queued lifecycle jobs contain only an immutable Eloquent locator and the
event-time source reference—never a serialized model or prebuilt document set.
At execution they use the captured source connection, bypass global scopes only
for the exact primary key, read from the write connection, and index the latest
committed state. A missing or currently excluded soft-deleted row deletes the
captured source reference. Before pushing, the package acquires Laravel's actual
`UniqueLock` for the `ShouldBeUniqueUntilProcessing` job. Equivalent pending
work is suppressed; different model classes, source connections, key names, or
canonical key values use different locks. A queue-push exception releases the
lock, while a successful push leaves it for Laravel's worker to release
immediately before processing. Repeated execution remains idempotent even when
uniqueness is unavailable or has expired.

```php
'queue' => [
    'connection' => null, // Laravel default when null
    'queue' => null,      // Laravel default when null
    'tries' => 3,
    'backoff' => [10, 30, 60],
    'timeout' => 60,
    'unique_for' => 300,
],
```

Run a worker for the selected connection and queue whenever
`lifecycle.execution` is `queue`. Laravel's queue backend must support atomic
locks for uniqueness. Configuration is strictly typed and invalid execution,
routing, retry, timeout, uniqueness, or backoff values fail when the lifecycle
services are resolved. Explicit route names remain case-sensitive and unchanged,
but leading or trailing Unicode whitespace and Unicode control or formatting
characters are rejected.

Source-transaction timing is handled by the package dispatcher. After that
exact source-connection boundary is satisfied, the queue job is pushed with
`beforeCommit()`. A queue connection's global `after_commit` option therefore
cannot re-delay or discard the job because of an unrelated database transaction.

### Dependency-aware reindexing

Models whose data contributes to another searchable source can trigger bounded
source reindexing without using `HasPersianSearch` themselves. Register resolver
classes and return source locators through the provider-aware locator factory:

```php
use App\Models\Category;
use App\Models\Product;
use Zarbinco\PersianSearch\Contracts\SearchDependencyResolver;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyContext;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocatorFactory;

final class ProductCategoryDependencyResolver implements SearchDependencyResolver
{
    public function __construct(private SearchSourceLocatorFactory $locators) {}

    public function key(): string { return 'product-category'; }

    public function dependencyModel(): string { return Category::class; }

    public function resolve(SearchDependencyContext $context): iterable
    {
        foreach (Product::query()
            ->where('category_id', $context->dependency->getKey())
            ->orderBy('id')
            ->cursor() as $product) {
            yield $this->locators->forModel($product, 'eloquent');
        }
    }
}
```

```php
'dependencies' => [
    'enabled' => true,
    'maximum_sources_per_event' => 1000, // hard maximum: 20,000
    'resolvers' => [
        App\Search\ProductCategoryDependencyResolver::class,
    ],
],
```

Resolvers receive a detached, relation-free dependency snapshot with an exact
connection, typed operation/state, and sorted changed attributes for updates.
Each configured resolver receives its own fresh snapshot copy, including the
runtime table, key name, key type, incrementing mode, and raw key value. A
resolver may mutate its private copy without changing the live event model,
stored before/after snapshots, or the context observed by another resolver.
Resolver keys and dependency-model classes are stability-checked during
registration and then cached as immutable metadata; extension metadata methods
are not consulted again during event routing.
Creates and restores resolve after-state targets, deletes resolve before the row
is removed, and updates union before/after targets so foreign-key movement
reindexes both old and new owners. All matching resolvers complete before work
is dispatched; targets are deduplicated, deterministically ordered, and the
event fails without partial dispatch when its fanout limit is exceeded.
Soft deletes and force deletes each route once after a successful `deleted`
event; canceled or failed deletion routes nothing, and restore routes once from
the restored after-state.

Dependency transaction timing follows `lifecycle.after_commit`, but uses the
dependency model's exact connection. After that boundary, every immutable
source locator follows the same `sync` or unique `queue` router as ordinary
source lifecycle work. Execution reloads each source's current committed state,
so missing or excluded sources remove their captured document set and surviving
sources use atomic replacement. Resolver queries should be indexed and lazy;
choose a deliberately bounded maximum appropriate for the application's worst
legitimate fanout.

Queue uniqueness is keyed by the provider key plus the exact Eloquent source
locator. The same source/provider is suppressed while two providers for the
same source remain independent jobs. The event-time fallback reference is not
part of queue uniqueness. During target collection, however, two targets with
the same routing identity and different fallback references are rejected before
any work is scheduled rather than selecting one by resolver order.

The entire `dependencies` block is validated into one immutable policy snapshot
during package boot. Disabled dependencies and empty resolver lists register no
observers and do not instantiate application resolver classes; malformed
sections still fail validation instead of being skipped by dot-notation lookup.
Setting `'dependencies' => []` uses all documented defaults. The `resolvers`
value must be a proper sequential list—associative, sparse, and positional
top-level configurations are rejected. Valid configured resolver order is
preserved in policy diagnostics, while runtime registrations use explicit
locale-independent binary ordering by dependency model, resolver key, and
resolver class (`"10"` sorts before `"2"`).

The integration suite exercises queued dependency work through the complete
observer, exact dependency-connection commit callback, shared router,
provider-aware unique lock, queue payload, and current-state worker path. This
includes queue backends configured with `after_commit = true`; jobs remain
explicitly `beforeCommit()` after the package-owned dependency boundary.

This provides at-least-once-safe convergence, not exactly-once delivery. There
is no transactional outbox or atomic boundary between the committed source
transaction and a later queue-broker dispatch. A callback or broker failure
after source commit can therefore require explicit reindexing. The lifecycle
also does not provide provider-wide orphan cleanup, recursive dependency
chaining, cross-service transactions, or custom distributed locks.

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
    $result->rank->tier;
    $result->rank->tierScore;
}
```

Eloquent convenience searches still return model collections:

```php
$products = Product::persianSearch('كیك شکلاتي')->get();
```

`get()` and `first()` always use the same professional ranking path as
`results()`. `get()` returns successfully hydrated models in ranked order,
while `results()` additionally exposes structured rank metadata. Missing
Eloquent records do not invalidate document results.

The database driver searches `normalized_title`, `normalized_excerpt`, `normalized_keywords`, and `normalized_content`, ignores inactive documents, and applies source type and partition filters. Each query variant is searched using its own exact locale. A corrected English-layout query can therefore match a Persian document while presenting its exact English counterpart.

Each `SearchResult` exposes its winning structured `rank`. Its
`matchedVariant`, `candidateSource`, `matchedQuery`, and `matchedLocale`
properties are derived from the rank's winning variant, so provenance cannot
contradict ranking.

### Locale presentation bridge and suggestions

The processed query locale is the requested presentation locale. After ranking,
a matched document in another locale is replaced for display only when an
active document exists with the exact requested locale and the same partition,
source key, source type, and source ID. Locale matching is exact: `fa` does not
fall back to `fa_IR`, and bridging never crosses partitions. A missing or
inactive counterpart leaves the matched document visible. Counterpart titles
are not reranked.

The bound SQL query is only a counterpart candidate lookup. Every returned row
must also pass exact PHP `===` checks for locale, partition, and source key, so
case-insensitive database collations cannot change bridge identity. Duplicate
exact rows are treated as index corruption rather than selected by database
return order. Conflict diagnostics expose a deterministic source-key SHA-256
fingerprint and byte length instead of the raw source key.

Presented documents are deduplicated before totals, facets, pagination,
previews, grouping, and hydration. The better matched rank wins; an equal rank
keeps the first ranked occurrence. Locale facets therefore describe presented
documents. Only selected presented source models are hydrated.

```php
$page = PersianSearch::query('\\vjrhg')
    ->locale('en')
    ->paginate(
        perPage: 15,
        page: 1,
    );

if ($page->suggestion !== null) {
    echo $page->suggestion->query;
}

foreach ($page as $result) {
    echo $result->document->locale;
    echo $result->matchedLocale;
    echo $result->bridge->status->value;
}
```

Every result exposes bridge status plus requested, matched, and presented
locales. `document` and the backward-compatible `record` property both refer to
the presented search document, while `rank`, `matchedQuery`,
`candidateSource`, and `matchedLocale` retain matched-document provenance.

Suggestions are evidence-based and limited to direct keyboard-correction
families. Synonyms descended from a keyboard correction contribute evidence to
that family, but the visible suggestion remains the direct keyboard-corrected
query. Original-query synonyms belong to the original family; synonym-only
matches never create a suggestion. A correction is suggested only when the
original family has no results, the corrected family has a strictly better
semantic tier without fewer results, or configured integer result-gain and
ratio thresholds are met. By default, truncated candidate windows suppress
suggestions. The same suggestion and structured count/tier evidence are exposed
by results, pages, previews, and grouped results.

Malformed bridge configuration sections and out-of-range policy construction
are rejected. Public bridge, presented-candidate, result, and suggestion
evidence objects also reject status or reason combinations that cannot be
produced by a valid search execution.

Presented-result objects require actual persisted search-index records: an
assigned primary key alone is insufficient when Eloquent reports
`exists === false`. The matched document locale, winning variant locale, and
bridge matched locale must agree with exact binary equality. Suggestion reasons
also follow evaluator precedence—a strictly better tier uses
`better_semantic_tier`, not `material_result_gain`. Both bridge and suggestion
configuration sections must be associative maps; positional lists are rejected.

```php
'locale_bridge' => [
    'enabled' => true,
    'batch_size' => 200,
],

'suggestions' => [
    'enabled' => true,
    'require_exact_window' => true,
    'minimum_results' => 1,
    'minimum_result_gain' => 2,
    'minimum_ratio_basis_points' => 15000,
],
```

### Database candidate retrieval

Database retrieval is deliberately separate from ranking. Each retained query
variant becomes one bounded plan containing its complete normalized phrase
first, followed by unique searchable tokens. One SQL query is issued per plan,
and processing stops before later variants once the global candidate capacity is
full.

```php
'candidates' => [
    'maximum_terms_per_variant' => 10, // maximum 50
    'per_variant_limit' => 100,        // maximum 5,000
    'maximum_candidates' => 500,       // maximum 20,000
],
```

Only the fixed normalized title, keyword, excerpt, and content columns are
searched. Every query also requires an active document and its variant's exact
locale. Partition and source-type filters remain exact bound values.

Text uses parameterized `LIKE ? ESCAPE '!'` conditions. The escape character
`!` becomes `!!`, `%` becomes `!%`, and `_` becomes `!_`; backslashes and quotes
remain ordinary bound characters. For example, `%` in this query is literal:

```php
$results = PersianSearch::query('100% juice')
    ->locale('en')
    ->types(['product'])
    ->partition('public')
    ->results();
```

Rows returned by the database are checked again with exact PHP substring
matching. This removes collation-only false positives and defines final
candidate inclusion consistently. A persisted document matching several terms,
fields, or variants becomes one candidate, retains deterministic match evidence,
and preserves all matching variant evidence for ranking.

SQLite candidate execution is covered by the integration suite. MySQL and
PostgreSQL grammar tests verify their quoted columns, bound patterns,
`LIKE ? ESCAPE '!'` syntax, grouping, filters, and limits. Actual MySQL or
PostgreSQL service integration requires those services to be supplied by the
application test environment.

### Professional ranking

Ranking runs in PHP over the bounded candidate collection and never executes
candidate or source-model queries. For each candidate, every variant present in
its retrieval evidence is evaluated against the persisted normalized fields.
The fixed tier order is:

```text
exact title
title prefix → title phrase → title all tokens → title any token
keywords phrase → keywords all tokens → keywords any token
excerpt phrase → excerpt all tokens → excerpt any token
content phrase → content all tokens → content any token
```

Prefix and phrase matching compare complete token sequences. A query token such
as `گل` therefore does not prefix-match or phrase-match the token `گلدان`.
All-token matching requires every unique query token; any-token matching records
the matched count and deterministic integer coverage from 0 through 10,000
basis points.

A better semantic tier always wins before query-variant priority. This allows a
synonym exact-title match to beat an original content match, while the original
variant wins when both reach the same tier. Document priority is considered only
after semantic tier, winning variant priority, coverage, and matched-token
count.

Final ties use document priority descending, normalized-title Unicode length
ascending, then binary source key, partition, locale, and persisted document
primary key. Numeric primary keys are compared as arbitrary-size unsigned
decimal strings without integer or floating-point conversion; textually
distinct equal numeric forms still receive a binary identity tie-break.
Database collation, insertion order, timestamps, and current time do not
influence ranking.

```php
foreach (
    PersianSearch::query('orange juice')
        ->locale('en')
        ->results()
        ->items()
    as $result
) {
    echo $result->rank->tier->value;
    echo $result->rank->tierScore;
    echo $result->rank->variant->source->value;
    echo $result->rank->coverageBasisPoints;
}
```

Tier scores are validated positive, unique, and strictly descending diagnostics;
they cannot invert the fixed semantic order. Leading-wildcard substring
retrieval remains portable but is not generally accelerated by ordinary B-tree
indexes. Strict candidate limits bound the work without claiming fuzzy, BM25,
or full-text relevance.

### Result windows, pagination, and facets

Final limit and offset are applied only after the complete available candidate
window has been ranked. `knownTotal` is the size of that ranked window.
`totalIsExact` is true only when no candidate bound hid possible matches.
Otherwise `isTruncated` is true and `truncationReasons` identifies a
per-variant limit, the global candidate limit, or unexecuted later variants.
Facets and group counts inherit the same exactness; they never claim a
database-wide total for a truncated window.

```php
use Zarbinco\PersianSearch\Search\SearchFacetField;

$page = PersianSearch::query('orange juice')
    ->locale('en')
    ->types(['product', 'page'])
    ->facets([
        SearchFacetField::SourceType,
        SearchFacetField::Partition,
    ])
    ->paginate(
        perPage: 15,
        page: 1,
    );
```

`$page->metadata` contains the page, per-page size, returned count, known total,
total exactness, exact last page or `null`, previous/next flags, one-based
`from`/`to` positions, candidate limit, and truncation reasons. Explicit
`limit()` or `offset()` cannot be combined with `paginate()`. Asking for a page
at or beyond the end of a truncated known window throws instead of returning a
misleading empty page.

Supported facet fields are `source_type`, `partition`, and `locale`. Facets are
optional, counted in memory over the full filtered ranked window, and ordered
by count descending then binary value ascending. Their behavior is
conjunctive: existing type, partition, and exact-locale filters remain applied.
`$page->facets->sourceTypeCounts()` and `$results->typeCounts()` derive their
map from a requested source-type facet without another query.

Grouping by source type retains full-window known counts and global rank order
inside every bounded group:

```php
$groups = PersianSearch::query('orange juice')
    ->locale('en')
    ->groupBySourceType(perGroupLimit: 3);
```

A diverse preview first takes up to `perType` results from each type in global
rank order, then fills remaining slots from unselected ranked results. The
selected items retain their original relative order:

```php
$preview = PersianSearch::query('orange juice')
    ->locale('en')
    ->preview(
        limit: 8,
        perType: 2,
    );
```

Source models are hydrated only after slicing, grouping, or preview selection.
Selected IDs are batched by model class, persisted source connection, and model
key name. A stored connection is applied before the exact-key query, so equal
model classes and IDs from different databases cannot collide. A null
connection uses the model's normal default. Missing named connections fail
instead of silently falling back, and virtual results remain present with
`model === null`.

Non-searchable queries use one shared empty-result path for results, pages,
previews, groups, and model collections. They never call the expander, search
driver, ranker, hydrator, or database. Empty metadata is exact and reports the
configured global candidate limit; requested facets remain an empty collection.

Grouped results distinguish two independent dimensions:

- `countsAreExact` describes whether candidate-window bounds can hide group
  items.
- `groupsAreComplete` describes whether `maximum_groups` omitted source-type
  groups.

The group collection also exposes `knownGroupTotal`, `returnedGroups`,
`isTruncated`, and `maximumGroups`. Public result metadata validates counts,
limits, exactness flags, truncation reasons, item types, and uniqueness before
it can be serialized.

The fluent `query()` and existing `search()` alias process lazily at execution time, so the final effective locale is used regardless of builder call order. The query processor, expansion context, diagnostics, and database filter all receive that same resolved locale. Non-searchable model queries return an empty collection without accessing the search table.

Default text components can be replaced through Laravel's container by binding `SearchTextSanitizer`, `SearchTextNormalizer`, or `SearchTokenizer` before the pipeline is resolved.

## Commands

```bash
php artisan persian-search:reindex "App\Models\Product" --fresh
php artisan persian-search:flush "App\Models\Product"
php artisan persian-search:flush page --partition=public
php artisan persian-search:flush --force
```

Every current model is rebuilt through atomic source replacement. Command output reports processed sources plus incoming, created, updated, unchanged, and stale-deleted document totals. For the Eloquent fallback, `--fresh` first performs a global model-class flush, reports that count separately, and then rebuilds current models; this also removes fallback documents whose model rows no longer exist. A custom-provider `--fresh` does not pre-delete current sources, because replacement already removes their stale locales and partitions without rewriting unchanged rows. Custom-provider documents belonging to model rows no longer returned by the scoped model query cannot currently be enumerated; the command emits one warning per run and those orphaned sources require an explicit source-type flush.

## Architecture and boundaries

See [docs/architecture.md](docs/architecture.md) for the identity and storage design.

The default database implementation is intentionally portable and bounded.
Offset pagination is stable only while the index and ranking inputs remain
unchanged. Candidate limits can make totals and facets inexact, and increasing
them increases query and memory work. Leading-wildcard substring retrieval is
not full-text-index optimized. Cursor pagination, dependency reindexing,
cross-locale bridging, and typo-tolerant search are not provided.

## Testing

```bash
composer validate --strict
composer format -- --test
composer analyse
composer test
```
