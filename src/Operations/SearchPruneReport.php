<?php

namespace Zarbinco\PersianSearch\Operations;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SearchPruneReport implements JsonSerializable
{
    public function __construct(
        public bool $executed,
        public int $providers,
        public int $authoritativeEnumerators,
        public int $currentSourceReferences,
        public int $persistedSourceReferences,
        public int $currentDocuments,
        public int $orphanedSourceReferences,
        public int $orphanedDocuments,
        public int $deletedSourceReferences,
        public int $deletedDocuments,
        public int $failedSourceReferences = 0,
        public int $unprocessedSourceReferences = 0,
    ) {
        foreach (get_object_vars($this) as $value) {
            if (is_int($value) && $value < 0) {
                throw new InvalidArgumentException('Search prune report counts must not be negative.');
            }
        }
        if ($this->orphanedSourceReferences > $this->persistedSourceReferences
            || $this->deletedSourceReferences > $this->orphanedSourceReferences
            || $this->deletedDocuments > $this->orphanedDocuments
            || $this->failedSourceReferences > $this->orphanedSourceReferences
            || $this->deletedSourceReferences + $this->failedSourceReferences + $this->unprocessedSourceReferences > $this->orphanedSourceReferences
            || (! $this->executed && ($this->deletedSourceReferences + $this->deletedDocuments + $this->failedSourceReferences + $this->unprocessedSourceReferences) !== 0)
            || ($this->executed && $this->deletedSourceReferences + $this->failedSourceReferences + $this->unprocessedSourceReferences !== $this->orphanedSourceReferences)
            || ($this->unprocessedSourceReferences > 0 && $this->failedSourceReferences === 0)
            || $this->failedSourceReferences > 1) {
            throw new InvalidArgumentException('Search prune report counts are inconsistent.');
        }
    }

    public function status(): string
    {
        if ($this->failedSourceReferences === 0) {
            return 'success';
        }

        return $this->deletedSourceReferences > 0 ? 'partial_failure' : 'failed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status(),
            'executed' => $this->executed,
            'providers' => $this->providers,
            'authoritative_enumerators' => $this->authoritativeEnumerators,
            'current_source_references' => $this->currentSourceReferences,
            'persisted_source_references' => $this->persistedSourceReferences,
            'current_documents' => $this->currentDocuments,
            'orphaned_source_references' => $this->orphanedSourceReferences,
            'orphaned_documents' => $this->orphanedDocuments,
            'deleted_source_references' => $this->deletedSourceReferences,
            'deleted_documents' => $this->deletedDocuments,
            'failed_source_references' => $this->failedSourceReferences,
            'unprocessed_source_references' => $this->unprocessedSourceReferences,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
