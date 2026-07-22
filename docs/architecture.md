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

Indexing and query candidates share one ordered pipeline:

```text
raw value → safe string conversion → HTML sanitization → invisible/whitespace cleanup
          → locale normalization → Unicode tokenization → prepared text
```

`PreparedSearchText` keeps the resolved locale plus raw, sanitized, normalized, and tokenized representations. It is an immutable runtime DTO and is not stored in the database. Raw title and excerpt values remain display data; only prepared normalized values enter searchable columns.

Supported raw values are null, strings, integers, floats, booleans, backed enums, `Stringable` objects, and recursively nested arrays of those values. Array keys are ignored and non-empty values are joined in input order. Other objects, closures, resources, and invalid UTF-8 are rejected rather than silently serialized.

The sanitizer decodes HTML entities, removes script, style, noscript, and template blocks with their content, converts common block tags to boundaries, and strips remaining markup. Meaningful Unicode whitespace is converted to ASCII space before non-whitespace controls, byte-order marks, and Unicode bidi formatting marks are removed. Malformed HTML is handled best-effort with PHP's tag stripping; text that resembles a syntactically valid tag may therefore be removed. ZWNJ, ZWJ, and zero-width space become normal separators before repeated whitespace is collapsed.

Persian-family locales (`fa`, including underscore and hyphen region forms) delegate letter, digit, diacritic, tatweel, and related normalization to Persian Core. English-family locales use Unicode lowercase and whitespace cleanup. Unknown locales use the same conservative generic behavior, preserving scripts and accented characters. Locale family matching is case-insensitive while the trimmed supplied locale remains the DTO/document value; an empty locale resolves to the configured undefined locale (`und` by default).

The tokenizer retains Unicode letters, combining marks, and numbers. It keeps apostrophes inside words, splits hyphenated words and decimals at punctuation, excludes punctuation as tokens, and removes duplicates while preserving first appearance. It applies no stop words, stemming, minimum token length, or token-count limit.

The pipeline depends on the replaceable `SearchTextSanitizer`, `SearchTextNormalizer`, and `SearchTokenizer` contracts registered by the service provider. Document building and original, keyboard-corrected, and synonym query candidates all use this same preparation path.

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

Fluent query processing is lazy, so the final effective locale is authoritative and repeated execution has no stale processed state. A null builder locale uses the application locale; an explicitly empty or whitespace-only locale resolves to `und`; and a non-empty explicit locale is retained. The resolved processed locale is used consistently for normalization, expansion context, query diagnostics, and exact database locale filtering. No locale-family fallback or all-locales mode is implied. Non-ready queries are converted directly to empty `SearchResults` by the builder: expansion, driver access, ranking, search-document SQL, and model hydration are skipped. Ready original candidates reuse the processed sanitized, normalized, and searchable-token values; generated keyboard and synonym candidates retain their existing preparation path.

## Results and hydration

Every search result contains its `SearchDocumentRecord`. When `source_type` is an Eloquent model class and `source_id` resolves to a record, the result also contains that model. Virtual documents, arbitrary source types, and deleted model records remain valid results with a null model.

`SearchResults` also exposes its `ProcessedSearchQuery`, `status()`, and `isSearchableQuery()`. Non-searchable results have no items or models and a total of zero.

## Eloquent adapter

`HasPersianSearch` is a convenience adapter. It creates a stable key from the model class and persisted key, uses the configured default partition, preserves the raw model title, prepares searchable field values into normalized document content, stores model metadata as payload, and carries `updated_at` into `source_updated_at` when available.

Loaded dot-notation relations can be read, but the adapter never lazy-loads relations. Field weights from the convenience declaration are not persisted.
