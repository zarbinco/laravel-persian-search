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
    ) {
        foreach (get_object_vars($this) as $value) {
            if (is_int($value) && $value < 0) {
                throw new InvalidArgumentException('Search prune report counts must not be negative.');
            }
        }
        if (! $this->executed && ($this->deletedSourceReferences !== 0 || $this->deletedDocuments !== 0)) {
            throw new InvalidArgumentException('A prune dry-run cannot report deletions.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => 'success',
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
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
