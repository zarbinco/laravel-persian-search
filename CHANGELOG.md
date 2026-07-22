# Changelog

## Unreleased

- Added a contract-driven locale-aware text pipeline for safe value conversion, HTML sanitization, normalization, and Unicode tokenization.
- Added the immutable `PreparedSearchText` result and public `PersianSearch::prepareText()` utility.
- Routed Eloquent document fields and query-expansion candidates through the shared pipeline.
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
