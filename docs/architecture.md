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

The database driver executes each distinct variant against that variant's exact locale. Results are not bridged to localized counterparts. One stored record matching several variants is merged by variant priority, then score, with stable first occurrence as the final tie-break. Variant priority is provenance precedence, not ranking weight. `SearchResult::matchedVariant` is authoritative; `candidateSource`, `matchedQuery`, and `matchedLocale` are derived conveniences.

## Results and hydration

Every search result contains its `SearchDocumentRecord`. When `source_type` is an Eloquent model class and `source_id` resolves to a record, the result also contains that model. Virtual documents, arbitrary source types, and deleted model records remain valid results with a null model.

`SearchResults` also exposes its `ProcessedSearchQuery`, `status()`, and `isSearchableQuery()`. Non-searchable results have no items or models and a total of zero.

## Provider architecture

Indexing a source follows one path:

```text
application source → provider registry → resolved provider → source reference
                   → validated document set → index manager
```

A `SearchDocumentProvider` identifies itself with a stable key, determines whether it supports a source, returns the source's logical `SearchSourceReference`, and lazily yields search documents. Providers create documents only; they never write to or delete from the index.

Configured provider classes are read when the singleton registry is first resolved and instantiated through Laravel's container. One provider-key boundary validates and caches keys at that point. A registered key must be valid UTF-8, non-empty, contain no leading or trailing Unicode whitespace, contain no Unicode control or formatting characters, and return exactly the same value on repeated access. The fallback key `eloquent` is reserved. Lookup input trims surrounding ASCII and Unicode whitespace for convenience, then rejects remaining control or formatting characters. Registered keys are never silently normalized or lowercased. The same boundary safely describes keys in lookup, duplicate, and ambiguity exceptions without rendering unsafe invisible characters. Custom providers are checked before the built-in Eloquent fallback. Exactly one custom match is selected regardless of configuration order. Multiple custom matches are rejected as ambiguous, and a source with no matching provider is rejected. Empty or non-canonical keys, unstable keys, duplicate keys or classes, missing classes, and classes that do not implement the contract are also rejected. Provider constructor failures remain visible.

`SearchSourceReference` is independent of Eloquent. Its source key and type are non-empty, and its ID is a canonical string or null: integer IDs become exact decimal strings, strings such as `00123`, UUIDs, and ULIDs remain unchanged, and null remains null. Its deterministic fingerprint includes the key, type, and null-aware ID, but excludes locale, partition, and timestamps.

`SearchDocumentSet` consumes provider output once, preserves its order, and validates it before indexing. Every value must be a `SearchDocument` whose source key, type, and ID strictly match the reference. Duplicate storage identities (`partition + source_key + locale`) are rejected. Zero documents are valid.

The public source operations are:

```php
$documents = PersianSearch::documentsFor($source); // no writes
$records = PersianSearch::indexSource($source);    // ordered upserts
$deleted = PersianSearch::deleteSource($source);   // all locales and partitions
$provider = PersianSearch::providerFor($source);
```

Direct `indexDocument()` remains available. `indexSource()` only upserts documents returned by the current provider call. It does not remove previously indexed identities that are now omitted, and an empty document set does not remove existing rows.

The model-class reindex command has explicit provider-aware `--fresh` behavior. The Eloquent fallback retains its global model-class flush, which removes documents for missing model rows. A custom provider is handled per current source: the command first materializes and validates the complete `SearchDocumentSet`, then deletes all documents using the exact `SearchSourceReference` attached to that set, and finally indexes the same validated set without executing provider output or provider reference resolution again for deletion. This cleanup is command-only and has no transaction or replacement semantics. Because a model query cannot enumerate custom source identities for rows it no longer returns, orphaned custom-provider documents require an explicit source-type flush; one warning is emitted per command run.

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

Save, delete, force-delete, and restore lifecycle hooks resolve the provider for the actual model. A custom Eloquent provider therefore overrides the fallback for both indexing and source deletion. The existing automatic-sync and soft-delete settings continue to control when those operations occur.

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
