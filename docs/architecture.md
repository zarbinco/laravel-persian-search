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

## Results and hydration

Every search result contains its `SearchDocumentRecord`. When `source_type` is an Eloquent model class and `source_id` resolves to a record, the result also contains that model. Virtual documents, arbitrary source types, and deleted model records remain valid results with a null model.

## Eloquent adapter

`HasPersianSearch` is a convenience adapter. It creates a stable key from the model class and persisted key, uses the configured default partition, preserves the raw model title, normalizes searchable field values into document content, stores model metadata as payload, and carries `updated_at` into `source_updated_at` when available.

Loaded dot-notation relations can be read, but the adapter never lazy-loads relations. Field weights from the convenience declaration are not persisted.
