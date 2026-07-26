# Search architecture

Laravel 12 requires PHP 8.2 or later and Illuminate 12.61.1 or later within
Laravel 12. Laravel 13 requires PHP 8.3 or later and Illuminate 13.12.0 or later
within Laravel 13. Laravel 11 and earlier are not supported.

The index is document-first. A source produces one or more independently stored search documents; a document does not require an Eloquent model.

Future transliteration, mixed-language composition, global work budgets, and
database certification are design-only roadmap items. They are not part of the
current architecture or public API; see [the package roadmap](../ROADMAP.md).

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

The pipeline depends on the replaceable `SearchTextSanitizer`, `SearchTextNormalizer`, and `SearchTokenizer` contracts registered by the service provider. Document building and generated keyboard, spelling, and synonym variants use this preparation path; an original variant reuses its already-approved processed query without normalizing it again.

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

The complete tokenizer output remains in `tokens`. `searchableTokens` removes short tokens and applies the maximum token count without mutating the complete list. It does not apply stop words, stemming, synonyms, keyboard correction, or spelling correction.

Fluent query processing is lazy, so the final effective locale is authoritative and repeated execution has no stale processed state. A null builder locale uses the application locale; an explicitly empty or whitespace-only locale resolves to `und`; and a non-empty explicit locale is retained. Non-ready queries are converted directly to empty `SearchResults` by the builder: expansion, driver access, ranking, search-document SQL, and model hydration are skipped.

## Query variants

`QueryExpander` accepts only a ready `ProcessedSearchQuery` and returns a
bounded `QueryVariantCollection`. Generation order is original, keyboard,
edit-based spelling, advanced correction, then synonyms. Existing precedence
remains original (`1000`), keyboard (`800`), spelling (`700`), and
keyboard-spelling (`650`); phonetic/split/merge sources occupy deterministic
priorities above synonym (`600`), while keyboard-synonym remains `400`. Each
immutable variant carries normalized query text, ordered searchable tokens,
locale, source enum, priority, deterministic fingerprint, parent fingerprint,
and typed keyboard, spelling, advanced, or synonym provenance.

The collection deduplicates by fingerprint and by normalized query plus locale. Higher priority replaces lower provenance, equal priority keeps the first occurrence, different locales stay distinct, and the original can never be displaced by generated provenance. The original counts toward `maximum_variants`; generation stops at the bound without recursive synonym expansion or synonym Cartesian products. Synonym expansion returns a fresh lazy generator for each call. The expander does not invoke it when earlier variants already fill the collection and stops consuming it immediately after the final available slot, so the bound limits generated work as well as retained output.

### Spelling layer

The optional spelling layer is document-derived and multilingual. Active search
documents are tokenized per exact locale into a bounded dictionary. Each term
stores document and field frequencies; a second table stores deterministic
symmetric-delete keys up to the configured edit distance. Dictionary creation
is explicit operational work under the shared maintenance lock, while status is
read-only and reports table readiness, locale counts, last build time, and
staleness against the newest active document.

At query time an exact dictionary hit stops correction for that token. A miss
produces bounded delete keys, batches all inspected tokens into one indexed
locale lookup, verifies the small candidate window using weighted Unicode Damerau-Levenshtein distance, and
uses a deterministic beam to construct a limited number of whole-query
corrections. The core insertion/deletion/substitution/transposition algorithm is
locale-independent; optional adjacent-key maps only lower substitution cost for
known layouts. No full-table edit-distance query is executed.

Spelling is fail-soft by default when its tables are unavailable and can be
configured to fail closed for deployments that require the dictionary. It is
disabled by default so upgrading the package without publishing/building the
new dictionary cannot alter an existing application's search behavior.

### Advanced correction layer

`LanguageCorrectionProfile` owns locale-specific phonetic alternatives and
separator policy. The registry validates unique base locale metadata and
resolves exact-to-base fallback. Built-in Persian and English profiles remain
small independent classes; applications may register additional container-
resolvable profile classes through validated configuration.

`DatabaseAdvancedQueryCorrector` receives one parent variant and never scans
dictionary vocabulary. It bounds profile consumption, phonetic-change depth,
split positions, adjacent-pair inspection, unique lookup terms, candidate rows,
accepted options per token, changed tokens, retained states, and final variants.
Original tokens, phonetic outputs, all split segments, and merge outputs are
deduplicated into one parameterized term-table query per parent. There is no
query per token, proposal, or composed state. A split is valid only when both
segments exist in one locale; a phonetic or merge candidate requires its
complete term in that locale.

After that single lookup, accepted phonetic options are grouped by ordered token
index. A bounded beam starts with the unchanged token state and, for each
correctable index, retains both unchanged and accepted replacement branches.
Every state carries replacements, ordered proposals, weighted cost, combined
frequency, corrected-token count, and unresolved count. The beam rejects states
past `maximum_tokens_to_correct` or the remaining transformation depth,
deduplicates equivalent indexed replacements, sorts by unresolved count, cost,
frequency, and binary lexical key, and retains only a small multiple of the
configured output limit. Complete Cartesian expansion is never materialized.
Combined candidates must resolve all replacement terms in one locale from the
exact-to-base chain.

Merge proposal generation inspects up to `maximum_adjacent_pairs` safe
whitespace-separated positions without consuming the accepted merge limit.
Dictionary validation happens in the shared batch, and
`maximum_merges_per_query` applies to accepted correction states. The current
architecture emits single-merge states, so a value of one can retain multiple
alternative one-merge candidates but never combines two merges in one query.
Final ranking remains deterministic across unresolved count, weighted cost,
transformation kind, frequency, and corrected query.

The correction DTO contains an immutable transformation chain. Each
transformation records kind, token index, original/replacement tokens, cost,
profile, and rule. Query variants add the DTO as a trailing optional
constructor argument, preserving every existing positional call. Expansion
first retains original, keyboard, and edit-based spelling variants, then applies
advanced correction once to each distinct retained original, keyboard, or
spelling-derived semantic parent. Spelling-derived children retain their
`SpellingCorrection` while adding `AdvancedCorrection`; keyboard-spelling
children retain all three structured layers. `AdvancedCorrection::originalQuery`
is recovered from the earliest available provenance and therefore remains the
true user query rather than the intermediate spelling text.

Advanced output is never recursively expanded. Transformation depth is the
number of advanced `QueryTransformation` objects, including every token changed
inside a composed phonetic candidate; edit-based spelling metadata is a
separate provenance layer and does not increment that depth. Parent variants
are expanded only while retained, and no child is inserted with a fingerprint
that points to a parent absent from the bounded collection.

No new table is required: exact term/frequency rows already provide every
signal needed for bounded phonetic acceptance and two-segment split/merge
validation. When advanced features are enabled during dictionary rebuild,
short terms down to the configured phonetic or segment minimum are retained in
the existing term table; symmetric-delete rows remain limited to terms eligible
for edit-based spelling.

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
→ correction-family suggestion evaluation
→ batched exact-locale presentation bridging
→ presented-document deduplication
→ known-total and optional in-memory facets
→ final slice, page, groups, or two-pass preview selection
→ batched hydration of selected presented source IDs
→ immutable public result object
```

The processed query locale is also the requested presentation locale.
Counterpart resolution uses bound search-index queries in deterministic batches
over unique `partition + source_key` pairs. A counterpart must be active, use
the exact requested locale, remain in the same partition, and preserve source
key, source type, and canonical source ID. A type or ID mismatch is an identity
conflict. No source model is queried during bridging, and exact locale matching
does not negotiate locale families.

Pair-map keys use a collision-resistant hash of length-prefixed partition and
source-key values; the original values remain separate SQL bindings. SQL
equality only bounds possible counterpart rows. Returned locale, partition, and
source key are compared again with binary PHP `===`, making final identity
independent of database collation. A collation-only false positive is ignored.
Two persisted rows for one exact counterpart identity throw a corruption
exception rather than allowing query order to choose a winner. Identity
conflicts and duplicate diagnostics render a source-key fingerprint and byte
length, never the raw source key.

Same-locale candidates need no bridge lookup. With bridging disabled,
different-locale matches remain visible with `disabled` status. When no active
exact counterpart exists, the matched document remains visible with
`counterpart_missing` status. A successful replacement has `bridged` status;
same-locale presentation has `not_required` status. Rank and winning-variant
evidence always remain those of the matched document.

If several matches present the same persisted document, post-bridge
deduplication retains the better semantic rank and keeps the first occurrence
for an equal rank. Window totals, locale/type/partition facets, pagination,
preview diversity, group counts, and hydration all consume this deduplicated
presented window. Candidate-window exactness remains inherited from retrieval;
bridging and deduplication do not invent a truncation reason.

Suggestion evaluation runs once over ranked pre-bridge candidates and performs
no SQL or source hydration. The original variant and its synonym descendants
form the original family. Each direct keyboard, spelling, keyboard-spelling,
phonetic, split, merge, or keyboard-derived advanced variant starts a separate
correction family. Keyboard-synonym descendants contribute to their keyboard
family. Only a direct correction root can become the visible suggestion.

Universal eligibility requires suggestions to be enabled, the configured
minimum corrected-family result count, and—by default—an exact candidate
window. A correction family is effective when the original family has zero
results; or its best semantic tier is strictly better and its distinct result
count is not lower; or both the configured absolute gain and integer
basis-point ratio are met. Multiple eligible roots are resolved by rule
strength, tier, count, gain, variant priority, then fingerprint. The resulting
immutable evidence contains counts, gain, integer ratio, best tiers, exactness,
and reason, but no result content.

The complete variant parent graph is validated and family assignments are
cached before candidate scanning. Missing parents and cycles therefore fail
even when the malformed variant matched no document. Without a direct correction
root, evaluation returns before document tokenization. Within a candidate,
several evidence entries for one variant fingerprint trigger one suggestion
rank evaluation.

Policy constructors enforce the same bounds as their factories, and the
locale-bridge factory rejects a malformed non-map section. Immutable bridge
metadata, presented candidates, public results, and suggestion evidence enforce
status- and reason-specific semantic invariants at construction time.

Presented candidates require both matched and displayed
`SearchDocumentRecord` instances to have a primary key and Eloquent
`exists === true`; public results apply the same persistence requirement to the
displayed record without issuing an existence query. The matched record locale,
winning rank-variant locale, and bridge matched locale must be exactly equal.
Virtual documents still satisfy this boundary because their search-index row is
persisted even though no source model is hydrated.

Reason validation mirrors evaluator order. A strictly better corrected tier can
only carry `better_semantic_tier`; `material_result_gain` permits equal or
weaker corrected tiers when its generic positive count and integer-ratio
conditions hold. The suggestions configuration factory accepts empty or
partial associative maps but rejects scalar and list-shaped sections.

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

Every search result contains its presented `SearchDocumentRecord` through both
`document` and `record`, plus bridge metadata that distinguishes requested,
matched, and presented locales. Once selection is complete, presented source
IDs are batched by Eloquent model class, persisted source
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
Results, pages, previews, and group collections also expose the same optional
effective suggestion produced from the complete ranked candidate window.
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

Operational reindexing is driven only by explicitly configured source
enumerators. Enumerators return typed provider-aware locators; the existing
current-state synchronizer reloads each source, and the lifecycle router
performs synchronous or unique queued work. Commands never scan model classes
or build provider documents themselves.

Persisted documents carry the canonical provider key selected by the atomic
indexer. Provider ownership is semantic hashed data, but it does not change the
established `partition + source_key + locale` storage identity. Authoritative
enumerators additionally define current provider-owned references for safe
orphan pruning. See `docs/operations.md`.

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

The default relation list is empty. Paths must be non-empty strings, duplicates are removed in declaration order, and nested paths such as `group.organization` are supported. The fallback provider uses `loadMissing()`, so already-loaded relations are not queried again. Enumerator-driven reindexing validates and eager-loads these declarations only when `EloquentSearchDocumentProvider` owns synchronization. A custom provider neither invokes nor validates the fallback declaration and owns any relation or source preparation required by its `documents()` method. Reindexing does not infer relations from searchable field names or remove global scopes.

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

### Dependency reindexing pipeline

Configured `SearchDependencyResolver` implementations are registered once per
exact dependency model class. They run for dependency models independently of
the searchable-source trait. The observer builds detached raw-attribute
snapshots, clearing relations while preserving the concrete class, table,
primary-key behavior, existence, and resolved connection. Update snapshots use
`getRawOriginal()` before persistence and current raw attributes afterward.
Delete discovery occurs during `deleting`; create and restore discovery occurs
only after persistence.

Resolver results are materialized as provider-aware source locators, not
documents. A collection consumes lazy iterables, validates every yielded value,
deduplicates by stable locator fingerprint, enforces the configured fanout
ceiling, and sorts deterministically. Update before/after collections are
unioned under the same ceiling. Because every resolver and target is validated
before dispatch begins, exceptions cannot produce a partially routed event.
Per-model prepared update/delete state lives in an instance-scoped `WeakMap`;
there is no static request state.

Routing identity is the provider key plus the exact Eloquent locator. This same
fingerprint is authoritative for dependency deduplication and queue uniqueness,
so different providers for one source cannot suppress each other. Complete
synchronization identity additionally includes the fallback source reference.
If equal routing identities carry different complete identities, target
collection throws before dispatch; resolver order never chooses deletion
identity.

Registry initialization reads each resolver key and dependency-model class
twice to reject unstable metadata, validates the concrete exact model class,
then stores an immutable registration sorted by model, key, and resolver class.
Later observer registration and event filtering use only cached metadata.
Resolver execution copies the detached snapshot per registration, preserving
connection, table, raw attributes, runtime key name/type/incrementing behavior,
and existence while starting with no relations.

The dependency dispatcher schedules against the dependency connection. Its
after-commit callback captures only immutable synchronizations and resolves the
shared router when invoked. The router decides only synchronous versus unique
queued execution; it does not inspect transactions. Ordinary source lifecycle
and dependency lifecycle therefore share queue uniqueness and current-state
convergence while retaining their distinct transaction boundary.

Dependency configuration is parsed once into the policy used by both registrar
and registry. Boot always resolves this policy, so malformed top-level shapes
cannot evade validation. Disabled policies and empty resolver lists return
before application resolver construction or observer registration.
An empty dependency section means defaults. Resolver classes must remain a
sequential list in the policy; associative or sparse arrays are invalid even
under direct construction. Registration sorting compares model, key, and class
with binary `strcmp()` at each level, avoiding locale, natural-order, and
numeric-string coercion.

Queue integration tests traverse actual dependency model events through the
dependency connection's outer commit, shared routing, real provider-aware
unique locks, queue payloads, and current-state source synchronization. Separate
source, index, and unrelated connections verify that only the dependency
connection owns the scheduling boundary, including when the queue connection
itself enables after-commit dispatch.

The source commit, callback execution, queue broker, and search-index
transaction do not form one distributed transaction. In particular, this
design does not provide an outbox, exactly-once delivery, automatic recovery
from post-commit dispatch failure, provider-wide orphan cleanup, recursive
dependency chaining, or cross-service atomicity. A surfaced post-commit failure
may require explicit reindexing.

Custom Eloquent providers remain authoritative for both indexing and deletion.

Operational source identity has two deliberate forms. Lifecycle routing and
unique queued jobs use provider plus Eloquent locator, so multiple partition
claims for one source still synchronize once. Authoritative prune ownership
uses provider, exact partition, source key, source type, and null-aware source
ID, so one current partition cannot preserve an orphan in another partition.
The same hashed canonical ownership identity classifies both enumerated and
persisted references; locales are grouped below that identity.

Operational status never probes a lock by acquiring it. It reports
`available` or `held` only when the runtime provides a public non-mutating
inspection API, and otherwise reports `unknown`. Doctor uses random temporary
lock identities, validates queue connection/serialization/pre-commit behavior
and the actual unique-job cache without dispatch, and bounds deep ownership
sampling to the configured sample size. Disabled dependency policy prevents
resolver registry construction. Extension failures cross the command boundary
only as curated or fixed safe diagnostics.
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

## Real-word contextual correction

Contextual correction is a post-ranking, opt-in stage separate from the
non-word correction path. `SearchExecutionProcessor` retrieves and ranks existing
variants first. `DatabaseCandidateResultCounter` counts original matches with
full token coverage; above the trigger, no contextual dictionary or n-gram work
runs. Preview evaluation is independently disabled by default.

`DatabaseContextualCandidateGenerator` accepts only original, keyboard,
spelling, phonetic, split, and merge lineage parents—not synonym or contextual
variants—and only exact dictionary terms. It then reuses symmetric-delete
neighbours and bounded locale-profile phonetic alternatives. Term and
delete-key queries are batched across retained parents.
Weighted distance, same-locale-chain existence, protected terms, safe Unicode
words, token order, query size, state count, and transformation depth are
enforced before a candidate survives. A bounded cross-parent pool is globally
ordered by corpus gain, lexical cost, corrected-token count, parent priority,
binary query, and fingerprint before the configured final limit. Contextual
output never recursively feeds generation, and vocabulary is never scanned.

`DatabaseCorrectionEvidenceProvider` executes one hash-indexed bigram lookup
for all bounded candidates. Unigram evidence comes from the existing term
dictionary. Bigram evidence covers the preceding and following pairs touching
each corrected position. The final n-gram table indexes a SHA-256 identity
rather than oversized normalized text. Optional popularity and click contracts
default to zero and create no storage.

For each retained evidence candidate, `DatabaseCandidateResultCounter` creates
single-variant parent or contextual `SearchQuery` values, invokes the existing
candidate driver/ranker, requires full token coverage, preserves
locale/partition/type filters, caps and marks counts honestly, and memoizes
locale/query/partition/type duplicates during the request. Original results
control the global trigger; parent results control gain, threshold, confidence,
and zero-parent auto-apply checks. It does not hydrate models or fetch result
pages.

Confidence uses `0..10000`: lexical similarity contributes at most 2500,
corpus advantage 2500, bigram context 2000 (or neutral 1000 when unavailable),
eligible-result gain 3000, zero direct results 1000, and optional normalized
analytics 500, followed by a 10000 cap. Policy enforces corpus/context
advantage, candidate results, absolute/ratio gains, and minimum confidence.
When result counts are disabled, result evidence is explicitly unavailable,
gain thresholds are not fabricated, and only `suggest_only` is possible. When
n-grams are disabled, context is unavailable/neutral and no n-gram query or
minimum-context rule runs. The exact original remains retained and the package
never forces auto-application.

The additive migration owns final/private staging n-gram tables and a
per-locale build-generation table. A dictionary operation invalidates the
n-gram generation before term replacement, records the completed dictionary
generation after replacement, and only then stages n-grams. A successful staged n-gram
replacement finalizes only the matching generation; a failure preserves final
rows but readiness remains false. Generation equality plus a completed n-gram
timestamp defines readiness, including successful builds that legitimately
produce zero rows. Full rebuilds remove metadata for locales no longer present;
locale-scoped rebuilds leave other locale metadata untouched. Missing configured
metadata or n-gram tables degrade evidence to unavailable, while permissions,
missing columns, malformed SQL, and other query failures are rethrown. The marker write and term replacement cannot
be atomic when configured on different connections, so the implementation
fails conservatively and does not claim cross-connection atomicity.

When the query-variant collection is full, contextual insertion may replace
only a lower-priority leaf. Original, the contextual parent, all ancestors, and
every retained parent node are protected. Equal/higher-priority variants are
not displaced. A locale/query semantic duplicate is resolved before general
victim selection: only that exact duplicate may be replaced, and only when it
is a lower-priority unprotected leaf. The maximum remains exact, and
deterministic victim ordering prevents broken lineage.

```text
normalization → original/keyboard → non-word spelling → advanced correction
→ initial retrieve/rank → direct full-coverage count → contextual candidates
→ batched corpus/context evidence → bounded memoized candidate counts
→ confidence/decision → contextual variants → final retrieve/rank/suggestion
```
