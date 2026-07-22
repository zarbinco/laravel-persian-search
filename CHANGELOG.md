# Changelog

## Unreleased

- Verify reloaded persisted semantic fields for replacement and direct indexing, including hash-matching unchanged rows, canonical payloads, and storage-normalized source timestamps.
- Attribute direct first-create race recovery to the configured search-document insert and rethrow unrelated or post-insert listener uniqueness failures.
- Add focused semantic mismatch diagnostics that list field names without exposing stored or incoming values.
- Treat Eloquent-cancelled index creates, updates, and deletes as transactional persistence failures and verify the final source identity snapshot before commit.
- Add a dedicated leading `source_key` index for deterministic source conflict and locking lookups.
- Make direct single-document indexing transactional and tolerant of concurrent first-create identity races without deleting sibling documents.
- Harden persistence and source-conflict diagnostics against unsafe identity control and formatting characters.
- Made provider-based source indexing an atomic complete-snapshot replacement on the configured search-index connection.
- Added exact created, updated, unchanged, stale-deleted, incoming, and final source-index results.
- Added deterministic source-row locking, persisted source-identity conflict detection, and bounded transaction retries over prevalidated sets.
- Preserved true hash-based no-op writes and single-document sibling-safe upserts while cleaning omitted locales, partitions, and empty source snapshots.
- Updated model synchronization and reindex reporting to use the same atomic replacement path.
- Made provider-key validation and diagnostics Unicode-aware, rejecting control, formatting, and edge-whitespace characters.
- Isolated fallback searchable-relation declarations from custom-provider model rebuilds.
- Made custom fresh deletion use the exact source reference from the validated document set without resolving it again.
- Enforced canonical, stable provider keys and added key-specific registry lookup errors.
- Made model `--fresh` rebuilds validate, clean, and reindex each current custom-provider source while preserving global fallback cleanup.
- Added accurate custom-source deletion accounting and a warning for custom-provider sources that cannot be enumerated from the model query.
- Added container-resolved search document providers for custom Eloquent and non-Eloquent sources.
- Added immutable source references and validated, ordered multi-document sets across locales and partitions.
- Routed source indexing, source deletion, and model lifecycle synchronization through deterministic provider resolution.
- Added the built-in Eloquent fallback provider with explicit `loadMissing()` relation declarations and chunked reindex eager loading.
- Added `documentsFor()`, `indexSource()`, `deleteSource()`, and `providerFor()` source APIs.
- Made model reindexing report source and document counts separately when providers yield multiple documents.
- Made English-to-Persian correction faithful to Windows Persian base and Shift key states.
- Made synonym expansion lazy so the configured variant limit also bounds generated work.
- Deduplicated synonym token candidates before normalization and semantic query-locale outputs before yielding.
- Replaced loose query candidates with immutable, locale-aware query variants and bounded deterministic expansion.
- Added typed English-to-Persian keyboard correction metadata with a complete authoritative map, including backslash to `پ`.
- Added validated, exact-locale, one-way synonym dictionaries with token-boundary and phrase replacement.
- Added `PersianSearch::expandQuery()` diagnostics and per-variant locale database execution with typed result provenance.
- Added typed query policies, stable query statuses, and immutable processed-query diagnostics.
- Added Unicode-safe query length enforcement, token eligibility filtering, and configurable truncation or rejection.
- Added `PersianSearch::processQuery()` and the fluent `query()` API while retaining `search()` as an alias.
- Short-circuited non-searchable queries before expansion, driver access, ranking, SQL, or model hydration.
- Added a contract-driven locale-aware text pipeline for safe value conversion, HTML sanitization, normalization, and Unicode tokenization.
- Added the immutable `PreparedSearchText` result and public `PersianSearch::prepareText()` utility.
- Routed Eloquent document fields and generated query variants through the shared pipeline.
- Kept Persian normalization delegated to Persian Core while adding conservative English and generic locale behavior.
- Replaced the experimental model-first index with document-first identities and storage.
- Added virtual documents, partitions, localized identities, deterministic hashes, and optional Eloquent hydration.
- Separated raw display values from normalized searchable fields.
- Updated the Eloquent adapter, database search driver, result objects, and maintenance commands.

## 1.0.0

- Added Persian-aware Eloquent searchable models.
- Added database-backed search document indexing.
- Added database search driver.
- Added relevance-ranked results.
- Added query expansion.
- Added configurable synonyms.
- Added wrong-keyboard typing correction.
- Added indexing and maintenance console commands.
