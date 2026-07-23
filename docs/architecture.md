# Search architecture

The index is document-first. A source produces one or more independently stored search documents; a document does not require an Eloquent model.

## Identity

The unique storage identity is `partition + source_key + locale`.

- `source_key` is the stable identity of a logical source.
- `partition` isolates contexts such as public and administrative search.
- `locale` distinguishes localized documents. Undefined locale is stored as `und`.
- `source_type` describes the domain source and may be an Eloquent class or a value such as `page`, `brand`, or `product`.
- `source_id` is optional and stored as a string, allowing integer, UUID, ULID, and domain-specific identifiers.

Re-indexing an existing identity updates one row. The same source key may independently exist in multiple partitions and locales.

## Display and search data

`title` and `excerpt` contain unmodified display values. Search operates on the separate `normalized_title`, `normalized_excerpt`, `normalized_keywords`, and `normalized_content` fields. Payload contains JSON-safe display or routing data that does not belong in normalized text.

Each document has a deterministic SHA-256 hash over meaningful document data. Payload maps are recursively key-sorted before hashing, so associative key order does not affect the hash. Index and database timestamps are excluded.

## Text preparation

Indexing and generated query variants share one ordered pipeline:

```text
raw value → safe string conversion → HTML sanitization → invisible/whitespace cleanup
          → locale normalization → Unicode tokenization → prepared text
```

`PreparedSearchText` keeps the resolved locale plus raw, sanitized, normalized, and tokenized representations. It is an immutable runtime DTO and is not stored in the database. Raw title and excerpt values remain display data; only prepared normalized values enter searchable columns.

Supported raw values are null, strings, integers, floats, booleans, backed enums, `Stringable` objects, and recursively nested arrays of those values. Array keys are ignored and non-empty values are joined in input order. Other objects, closures, resources, and invalid UTF-8 are rejected rather than silently serialized.

The sanitizer decodes HTML entities, removes script, style, noscript, and template blocks with their content, converts common block tags to boundaries, and strips remaining markup. Meaningful Unicode whitespace is converted to ASCII space before non-whitespace controls, byte-order marks, and Unicode bidi formatting marks are removed. Malformed HTML is handled best-effort with PHP's tag stripping; text that resembles a syntactically valid tag may therefore be removed. ZWNJ, ZWJ, and zero-width space become normal separators before repeated whitespace is collapsed.

Persian-family locales (`fa`, including underscore and hyphen region forms) delegate letter, digit, diacritic, tatweel, and related normalization to Persian Core. English-family locales use Unicode lowercase and whitespace cleanup. Unknown locales use the same conservative generic behavior, preserving scripts and accented characters. Locale family matching is case-insensitive while the trimmed supplied locale remains the DTO/document value; an empty locale resolves to the configured undefined locale (`und` by default).

The tokenizer retains Unicode letters, combining marks, and numbers. It keeps apostrophes inside words, splits hyphenated words and decimals at punctuation, excludes punctuation as tokens, and removes duplicates while preserving first appearance. It applies no stop words, stemming, minimum token length, or token-count limit.

The pipeline depends on the replaceable `SearchTextSanitizer`, `SearchTextNormalizer`, and `SearchTokenizer` contracts registered by the service provider. Document building and generated keyboard and synonym variants use this preparation path; an original variant reuses its already-approved processed query without normalizing it again.

## Query processing

User queries pass through `SearchQueryProcessor` before expansion, ranking, or driver access:

```text
raw query → strict string conversion → maximum-length policy → text pipeline
          → status detection → token filtering/limit → processed query
```

`ProcessedSearchQuery` is an immutable diagnostic DTO containing the original and processed raw query, resolved locale, sanitized and normalized values, complete tokens, searchable tokens, status, truncation flag, and Unicode lengths. Query input accepts strings, `Stringable` objects, and null; unsupported types are rejected without serialization or logging.

`SearchQueryStatus` has five stable values:

- `empty`: sanitization leaves no content.
- `punctuation_only`: content remains but its normalized form has no Unicode letter or number. This includes emoji-only input.
- `too_short`: normalized content fails the total minimum or all tokens fail the token minimum.
- `too_long`: the raw query exceeds the maximum while the configured policy is `reject`.
- `ready`: the query may proceed to expansion and search.

The default policy requires two normalized Unicode code points, accepts at most 200 raw code points, permits tokens of one or more code points, keeps the first 20 eligible tokens, and truncates excessive input. The alternative maximum policy rejects excessive input. Lengths are Unicode code-point counts, not byte or grapheme-cluster counts. All policy values are typed and validated when query processing is first resolved.

The complete tokenizer output remains in `tokens`. `searchableTokens` removes short tokens and applies the maximum token count without mutating the complete list. It does not apply stop words, stemming, synonyms, or keyboard correction.

Fluent query processing is lazy, so the final effective locale is authoritative and repeated execution has no stale processed state. A null builder locale uses the application locale; an explicitly empty or whitespace-only locale resolves to `und`; and a non-empty explicit locale is retained. Non-ready queries are converted directly to empty `SearchResults` by the builder: expansion, driver access, ranking, search-document SQL, and model hydration are skipped.

## Query variants

`QueryExpander` accepts only a ready `ProcessedSearchQuery` and returns a bounded `QueryVariantCollection`. Generation order and default precedence are original (`1000`), keyboard (`800`), synonym (`600`), and keyboard-synonym (`400`). Each immutable variant carries normalized query text, ordered unique searchable tokens, locale, source enum, priority, deterministic fingerprint, parent fingerprint, and typed correction or synonym provenance.

The collection deduplicates by fingerprint and by normalized query plus locale. Higher priority replaces lower provenance, equal priority keeps the first occurrence, different locales stay distinct, and the original can never be displaced by generated provenance. The original counts toward `maximum_variants`; generation stops at the bound without recursive synonym expansion or synonym Cartesian products. Synonym expansion returns a fresh lazy generator for each call. The expander does not invoke it when earlier variants already fill the collection and stops consuming it immediately after the final available slot, so the bound limits generated work as well as retained output.

English-to-Persian is the only keyboard direction. English-family input uses one authoritative Windows Persian keyboard map, including backslash to `پ`. Base and Shift states are mapped case-sensitively, including uppercase letters, shifted punctuation, and multi-character output such as `R → ریال`. The already-sanitized physical input is retained for correction before English normalization can collapse Shift state. A generic configured `en` source accepts English region locales; a configured region locale is exact. Corrected output is prepared with the configured Persian target locale. No reverse layout correction or transliteration is claimed.

Synonym dictionaries are normalized once by a typed factory. Dictionaries are exact-locale and one-way. Rules match complete token sequences, preserve configured rule/replacement order and token position order, and create one replacement per variant. Each generator invocation maintains isolated deduplication state: repeated candidate token sequences are skipped before text preparation, then repeated normalized query-locale outputs are skipped before fingerprint and DTO creation. The first valid configured provenance wins. Empty terms, non-string replacements, malformed nesting, and normalized self-replacements fail with a focused configuration exception.

Candidate retrieval is a separate boundary before ranking. A typed policy
limits terms per variant, rows per variant, and globally retained unique
candidates. `SearchCandidatePlanBuilder` preserves variant order and creates one
plan per distinct variant. Each plan starts with the complete normalized query,
then adds unique searchable tokens in their original order. Its fields are the
closed `SearchDocumentField` enum: normalized title, keywords, excerpt, and
content.

`DatabaseCandidateDriver` executes at most one query per plan on the configured
search-document connection. It first binds the active, exact variant locale,
optional partition, and optional source-type filters. All term/field conditions
are contained in one grouped expression. Columns are selected only from the
enum and quoted by the active SQLite, MySQL, or PostgreSQL grammar. Text is
always a binding for `LIKE ? ESCAPE '!'`; `!`, `%`, and `_` are escaped as
`!!`, `!%`, and `!_`, while backslash has no special package meaning.
Candidate rows use stable primary-key ordering and the per-variant SQL limit.

The database query is only a possible-match filter because collation behavior
differs. `SearchCandidateMatcher` performs exact `str_contains()` checks over
the same normalized fields without another normalization pass. Null fields are
ignored. Its evidence records only deterministic fields, terms, and variant
provenance—never field values. This PHP check is authoritative for candidate
inclusion.

`SearchCandidateCollection` deduplicates by persisted document primary key,
merges unique evidence across terms, fields, and variants, and retains the
highest-priority retrieval variant without treating it as the final ranking
winner. Different persisted locale or partition rows remain distinct. The
global limit counts only new document identities; later plan queries are
skipped once it is full. Candidate retrieval never hydrates source models.

`ProfessionalSearchRanker` consumes only that bounded collection. It tokenizes
each non-empty normalized title, keywords, excerpt, and content field once per
candidate with the package tokenizer, without another normalization pass. Every
distinct variant in the candidate's match evidence is evaluated. Prefix and
phrase checks compare exact token sequences; all-token and any-token checks use
unique query tokens, with any-token coverage represented as integer basis
points.

The fixed semantic precedence is exact title; title prefix, phrase, all tokens,
and any token; then the same phrase, all-token, and any-token progression for
keywords, excerpt, and content. A better tier always precedes variant priority.
Equal-tier variants compare their validated tier score, priority, coverage,
matched-token count, and deterministic fingerprint. Candidates that cannot
produce a token-aware rank are omitted as an internal integrity safeguard
rather than receiving a fabricated fallback tier.

`SearchRankedCandidateCollection` sorts winning ranks by tier, winning variant
priority, coverage, matched-token count, persisted document priority, normalized
title Unicode length, binary source key, partition, locale, and persisted
primary key. Digit-only primary keys are retained as strings and compared by
normalized decimal length, then lexical numeric value, then their original
binary identity. This remains overflow-safe for arbitrary-size unsigned
identifiers and guarantees that distinct identity strings never compare equal.
Non-digit identities use binary comparison. Ranking does not inspect raw
display fields, payload, database collation, timestamps, or current time. It
performs no database query or source hydration. Optional batch Eloquent
hydration remains in the result stage after ranking.

Each `SearchResult` owns one immutable `SearchRank`. Its tier, validated score,
field, matched tokens, integer coverage, and winning variant are safe structured
diagnostics. Convenience provenance properties derive from that same variant.
All candidate retrieval evidence remains on the ranked candidate during the
ranking boundary. Virtual documents and missing source models remain valid.
`results()`, `get()`, and `first()` share one query-construction and
professional-ranking path; model-only retrieval cannot disable ranking and
preserves ranked order.

The existing equality indexes already cover stable partition, type, locale, and
active-filter prefixes, so no schema index was added. Leading-wildcard
substring `LIKE` is not generally B-tree accelerated. SQLite integration tests
execute the complete flow; MySQL and PostgreSQL grammar tests verify quotation,
bindings, escape syntax, grouping, and limits without claiming service-backed
integration when those services are unavailable.

## Results and hydration

Result processing starts from one immutable ranked window:

```text
processed query and variants
→ bounded candidate retrieval with truncation evidence
→ professional ranking once
→ known-total and optional in-memory facets
→ final slice, page, groups, or two-pass preview selection
→ batched hydration of selected source IDs
→ immutable public result object
```

`SearchResultWindow::knownTotal` is always the number of available rankable
candidates. It is exact only when per-variant capacity was not exceeded, global
candidate capacity was not reached, and no later variant was skipped.
Truncated windows expose ordered typed reasons and never provide an exact last
page. A page whose offset is at or beyond a truncated known window is rejected
because the requested results may exist outside the available candidates.

Plain results apply offset and limit after ranking. Page pagination owns the
final slice and therefore rejects an explicit builder limit or offset. Exact
page metadata contains the last page; inexact metadata uses `null`.
`hasNextPage` remains true while more known items exist or truncation means
additional items may exist. Offset pagination assumes the index and all ranking
inputs stay unchanged between requests; cursor pagination is not implemented.

Optional `source_type`, `partition`, and `locale` facets are counted in one
in-memory pass over the full already-filtered ranked window, never page items.
They are conjunctive, perform no aggregation query or hydration, and inherit
window exactness. Source-type convenience counts are derived from that facet
rather than stored twice.

Source-type grouping performs one full-window grouping pass, retains the global
rank order of selected group items, and orders groups by first ranked item,
known count descending, then binary type. Preview performs a diversity-capped
pass followed by a fill pass and finally restores selected candidates to global
relative order. Neither path randomizes or reranks.

Group-list completeness is separate from candidate-window exactness.
`countsAreExact` follows the candidate window, while `knownGroupTotal`,
`returnedGroups`, `groupsAreComplete`, `isTruncated`, and `maximumGroups`
describe whether the configured output cap omitted complete source-type groups.
Omitted groups are never hydrated.

Every search result contains its `SearchDocumentRecord`. Once selection is
complete, source IDs are batched by Eloquent model class, persisted source
connection, and key name. `source_connection` stores only a validated Laravel
connection name and participates in semantic hashing and persistence
verification; it is not part of storage identity or `SearchSourceReference`.
The built-in provider captures `$model->getConnection()->getName()` at indexing
time. Hydration applies a non-null stored connection before constructing the
exact-key query and includes it in model-map identity, preventing same-class,
same-key collisions across databases. Null continues to use the model default,
and an unavailable named connection is surfaced without fallback.

Soft-deleting models use `withTrashed()` only when configured. Virtual
documents, arbitrary source types, missing records, and null source IDs remain
valid results with a null model. Relations are removed from serialized hydrated
models.

`SearchResults` exposes its processed query, winning variants, ranked items,
known total, total exactness, truncation reasons, facets, and applied slice.
Non-searchable queries return an exact empty result without expansion, driver
access, ranking, SQL, or hydration. One empty-result factory creates results,
pages, previews, and groups with the configured candidate and result limits,
empty facets, exact zero totals, and consistent serialization.

Public result constructors reject contradictory states: invalid item types,
duplicate identities or buckets/groups, impossible counts, invalid limits,
inconsistent exactness/truncation flags, mismatched truncation reasons, unsafe
facet identifiers, and overflowing page positions cannot enter serialized
output.

Candidate capacity bounds memory and query work, but may make totals, facets,
and group counts inexact. Increasing those limits increases work.
Leading-wildcard substring retrieval remains bounded but is not full-text-index
optimized.

## Provider architecture

Indexing a source follows one path:

```text
application source → provider registry → resolved provider → source reference
                   → fully consumed and validated document set
                   → search-connection transaction → locked existing source rows
                   → complete diff → create → update → stale delete → result
```

A `SearchDocumentProvider` identifies itself with a stable key, determines whether it supports a source, returns the source's logical `SearchSourceReference`, and lazily yields search documents. Providers create documents only; they never write to or delete from the index.

Configured provider classes are read when the singleton registry is first resolved and instantiated through Laravel's container. One provider-key boundary validates and caches keys at that point. A registered key must be valid UTF-8, non-empty, contain no leading or trailing Unicode whitespace, contain no Unicode control or formatting characters, and return exactly the same value on repeated access. The fallback key `eloquent` is reserved. Lookup input trims surrounding ASCII and Unicode whitespace for convenience, then rejects remaining control or formatting characters. Registered keys are never silently normalized or lowercased. The same boundary safely describes keys in lookup, duplicate, and ambiguity exceptions without rendering unsafe invisible characters. Custom providers are checked before the built-in Eloquent fallback. Exactly one custom match is selected regardless of configuration order. Multiple custom matches are rejected as ambiguous, and a source with no matching provider is rejected. Empty or non-canonical keys, unstable keys, duplicate keys or classes, missing classes, and classes that do not implement the contract are also rejected. Provider constructor failures remain visible.

`SearchSourceReference` is independent of Eloquent. Its source key and type are non-empty, and its ID is a canonical string or null: integer IDs become exact decimal strings, strings such as `00123`, UUIDs, and ULIDs remain unchanged, and null remains null. Its deterministic fingerprint includes the key, type, and null-aware ID, but excludes locale, partition, and timestamps.

`SearchDocumentSet` consumes provider output once, preserves its order, and validates it before indexing. Every value must be a `SearchDocument` whose source key, type, and ID strictly match the reference. Duplicate storage identities (`partition + source_key + locale`) are rejected. Zero documents are valid.

The public source operations are:

```php
$documents = PersianSearch::documentsFor($source);       // no writes
$result = PersianSearch::indexSource($source);            // complete replacement
$result = PersianSearch::replaceDocumentSet($documents);  // validated set replacement
$deleted = PersianSearch::deleteSource($source);           // all locales and partitions
$provider = PersianSearch::providerFor($source);
```

`indexSource()` resolves the provider and materializes its validated set before starting persistence. `replaceDocumentSet()` then uses the configured connection of `SearchDocumentRecord` for one bounded-retry transaction. It locks rows sharing the logical source key in stable partition, locale, and primary-key order; the lookup is backed by the dedicated `ps_docs_source_key` index. It rejects conflicting persisted source type or canonical/null ID; maps rows by `partition + source_key + locale`; computes the full diff; creates missing rows; updates changed rows; and finally deletes stale rows. The authoritative `forSourceReference()` scope applies exact key, type, and canonical string ID conditions, using `whereNull` for a null ID, and is shared by replacement and explicit reference deletion.

Every required `save()` and `delete()` outcome is checked. Eloquent event cancellation is a transaction failure rather than a successful partial result. Creates and changed updates are then reloaded by primary key plus exact identity from the write connection and semantically verified before their counters advance. Final verification reads the complete ordered source through the same reference scope, pairs every persisted row with its incoming document, and verifies identity, source metadata, display and normalized text, canonical decoded payload, typed priority and active state, document hash, and storage-normalized source timestamp. Database-managed IDs and timestamps plus operational `indexed_at` are excluded. Matching `document_hash` never bypasses field verification, so observer mutations and previously corrupted unchanged rows roll back rather than being accepted.

Document hashes cover every persisted semantic field while excluding Laravel-managed timestamps. Associative payload keys are recursively canonicalized, list order and scalar types remain significant, and JSON failures are exceptions. Equal hashes are true no-ops: no `save()` or update query runs, the primary key and timestamps remain unchanged, and the row is counted as unchanged. An empty set is a complete empty snapshot, so it deletes all matching source rows; empty-to-empty is a no-op. `SearchSourceIndexResult` enforces `incoming = created + updated + unchanged` and `final = incoming`, with `changed()` equal to created plus updated plus deleted.

Direct `indexDocument()` remains a low-level, hash-aware single-identity upsert and never removes sibling locales or partitions. It runs in a bounded-retry transaction on the configured search connection, uses the same locked source-key conflict validation, checks rejected create/update outcomes, and returns a freshly reloaded, semantically verified record. If a concurrent first writer wins the unique identity race, the operation reloads and validates that exact row, returns an identical semantic row unchanged, or updates and verifies it. Recovery is considered only when the model insert has not succeeded and the structured exception connection and SQL identify an insert into the configured search-document table. Post-insert, listener-table, different-connection, update, delete, and uncertain unique violations are rethrown unchanged. `documentsFor()` is construction and validation only. `deleteSourceReference()` is an explicit complete-source deletion operation.

Each replacement is transactionally all-or-nothing. Existing rows for the source are locked during diff and persistence, and database uniqueness continues protecting document identities. This is not a cache, advisory, or distributed lock; in particular, the package does not claim stronger cross-database first-write serialization when no existing row is available to lock. Transaction retries rerun persistence against the same prevalidated document set and never rerun provider business logic.

The model-class reindex command sends every current model through the same atomic `indexSource()` path and reports processed sources plus incoming, created, updated, unchanged, and stale-deleted totals. Fallback `--fresh` retains its separate global model-class flush so missing model rows are cleaned. Custom-provider `--fresh` does not pre-delete current sources and emits one warning because source identities belonging to model rows no longer returned by the scoped query cannot currently be enumerated; those orphaned documents require an explicit source-type flush.

## Built-in Eloquent fallback

`HasPersianSearch` is the explicit searchable-model convention. The fallback provider accepts only Eloquent models implementing that convention and rejects models without a persisted integer or string key. It creates a reference using the model class and canonical key, preserving the existing `ModelClass:key` source key and model class source type. Its focused builder produces one document through the shared locale-aware text pipeline, preserves the raw display title and metadata payload, and carries `updated_at` into `source_updated_at` when available.

Models may explicitly declare relations needed by dot-notation fields:

```php
final class CatalogEntry extends Model
{
    use HasPersianSearch;

    public function persianSearchableFields(): array
    {
        return ['name', 'group.name'];
    }

    public function persianSearchableRelations(): array
    {
        return ['group'];
    }
}
```

The default relation list is empty. Paths must be non-empty strings, duplicates are removed in declaration order, and nested paths such as `group.organization` are supported. The fallback provider uses `loadMissing()`, so already-loaded relations are not queried again. The model reindex command validates and eager-loads these declarations only when `EloquentSearchDocumentProvider` owns the rebuild. A custom provider neither invokes nor validates the fallback declaration and owns any relation or source preparation required by its `documents()` method. The command does not infer relations from searchable field names or remove global scopes.

The Eloquent lifecycle uses `saved` for create, update, and restore. Deletion
uses `deleting` only to prepare an immutable synchronization and `deleted` only
to dispatch it after the delete succeeds. Preparation resolves the locator and
provider reference while a hard-deleted row and its relations are still
available. The value is stored in a declared instance-local trait property,
replaced on each attempt, and cleared after `deleted`; no dynamic property,
static map, or mutable model enters a callback or job. A canceled or failed
delete never reaches dispatch.

The locator contains model class, exact source connection, primary-key name,
and canonical string key, plus the provider's event-time
`SearchSourceReference`. Its length-framed fingerprint includes every locator
field without retaining model attributes.

When automatic synchronization and `lifecycle.after_commit` are enabled, an
event inside a transaction registers a callback on the model's source
connection. Laravel releases it only after the outermost commit and discards it
on rollback. The callback captures only immutable synchronization data and
resolves the dispatcher when it runs. Work outside a transaction executes or
dispatches immediately. This is intentionally local transaction coordination,
not an outbox or distributed atomicity guarantee; disabling `after_commit` can
produce rollback leakage when source and index connections differ.

Both synchronous and queued modes converge through
`EloquentSearchSourceSynchronizer`. It creates a fresh model prototype on the
captured connection, verifies the primary-key name, performs one exact
`newQueryWithoutScopes()->useWritePdo()` lookup, and then evaluates the current
committed row. A present eligible row is sent through atomic `indexSource()`.
A missing row, or a soft-deleted row excluded by policy, deletes the captured
fallback reference. Bypassing global scopes is deliberately limited to the exact
locator key and is required to distinguish deletion from scope visibility.

Queue jobs carry the synchronization value object and scalar execution settings,
not a serialized model, relation graph, or document set. A dedicated dispatcher
acquires Laravel's `UniqueLock` using the framework's own unique-job key before
calling the bus. Duplicate acquisition returns false without pushing; dispatch
failure releases the lock; successful dispatch leaves it for the worker to
release immediately before processing as required by
`ShouldBeUniqueUntilProcessing`. Configured `unique_for` supplies the lock
duration. This requires an atomic-lock-capable default cache backend.

The job explicitly calls `beforeCommit()`. Source-transaction timing is handled
by the package dispatcher against the connection stored in the locator, so a
queue connection's global `after_commit` option cannot introduce a second,
unrelated transaction boundary. Connection and queue route names are
case-sensitive and otherwise unchanged, but must be valid UTF-8, contain no
Unicode control or formatting characters, and have no Unicode whitespace at
either edge.

Jobs reload current state on every attempt, so save-then-delete,
delete-then-restore, repeated delivery, lock expiry, and multiple pending events
converge without assuming event order. Exceptions are not caught: synchronous
after-commit failures propagate from commit, queue-push failures surface after
releasing the unique lock, and workers retain Laravel's retry and failed-job
semantics.

The source commit, callback execution, queue broker, and search-index
transaction do not form one distributed transaction. In particular, this
design does not provide an outbox, exactly-once delivery, automatic recovery
from post-commit dispatch failure, provider-wide orphan cleanup, dependency
propagation, or cross-service atomicity. A surfaced post-commit failure may
require explicit reindexing.

Custom Eloquent providers remain authoritative for both indexing and deletion.
Their event-time reference provides deletion identity when the source no longer
exists, while a surviving model is resolved again through the registry before
atomic indexing. `index.sync_on_save` is the sole automatic lifecycle switch;
`index.include_soft_deleted` governs soft-delete eligibility. Explicit indexing
and deletion APIs remain immediate and bypass lifecycle scheduling.

## Provider examples

This custom Eloquent provider produces two locales for one model. It can be registered in `persian-search.providers` and its constructor dependencies are supplied by Laravel's container.

```php
namespace App\Search;

use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final class LocalizedEntryProvider implements SearchDocumentProvider
{
    public function __construct(private SearchTextPipeline $text) {}

    public function key(): string
    {
        return 'localized-entries';
    }

    public function supports(mixed $source): bool
    {
        return $source instanceof \App\Models\CatalogEntry;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        return new SearchSourceReference(
            sourceKey: 'entry:'.$source->getKey(),
            sourceType: 'catalog-entry',
            sourceId: $source->getKey(),
        );
    }

    public function documents(mixed $source): iterable
    {
        $reference = $this->reference($source);

        foreach (['fa' => $source->title_fa, 'en' => $source->title_en] as $locale => $title) {
            $prepared = $this->text->prepare($title, $locale);

            yield new SearchDocument(
                partition: 'public',
                sourceKey: $reference->sourceKey,
                sourceType: $reference->sourceType,
                sourceId: $reference->sourceId,
                locale: $locale,
                title: $title,
                excerpt: null,
                normalizedTitle: $prepared->normalized,
                normalizedExcerpt: null,
                normalizedKeywords: null,
                normalizedContent: $prepared->normalized,
            );
        }
    }
}
```

A virtual source needs no Eloquent APIs and may have a null source ID:

```php
final readonly class StaticResource
{
    public function __construct(public string $key, public string $title) {}
}

final class StaticResourceProvider implements SearchDocumentProvider
{
    public function __construct(private SearchTextPipeline $text) {}

    public function key(): string { return 'static-resources'; }

    public function supports(mixed $source): bool
    {
        return $source instanceof StaticResource;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        return new SearchSourceReference('resource:'.$source->key, 'resource', null);
    }

    public function documents(mixed $source): iterable
    {
        $reference = $this->reference($source);
        $prepared = $this->text->prepare($source->title, 'fa');

        yield new SearchDocument(
            partition: 'public',
            sourceKey: $reference->sourceKey,
            sourceType: $reference->sourceType,
            sourceId: null,
            locale: 'fa',
            title: $source->title,
            excerpt: null,
            normalizedTitle: $prepared->normalized,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $prepared->normalized,
            payload: ['resource_key' => $source->key],
        );
    }
}
```

Because `resource` is not an Eloquent model class, matching results remain complete document results with `model === null`.
