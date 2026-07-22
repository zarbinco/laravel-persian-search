<?php

namespace Zarbinco\PersianSearch\Indexing;

use Zarbinco\PersianSearch\Exceptions\SearchIndexPersistenceException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;

final class SearchDocumentPersistenceVerifier
{
    public function verify(SearchDocumentRecord $record, SearchDocument $document): void
    {
        $mismatches = [];
        $expected = [
            'partition' => $document->partition(),
            'source_key' => $document->sourceKey(),
            'source_type' => $document->sourceType,
            'source_id' => $document->sourceId,
            'locale' => $document->locale(),
            'title' => $document->title,
            'excerpt' => $document->excerpt,
            'normalized_title' => $document->normalizedTitle,
            'normalized_excerpt' => $document->normalizedExcerpt,
            'normalized_keywords' => $document->normalizedKeywords,
            'normalized_content' => $document->normalizedContent,
            'priority' => $document->priority,
            'is_active' => $document->isActive,
            'document_hash' => $document->documentHash,
        ];

        foreach ($expected as $field => $value) {
            if ($record->getAttribute($field) !== $value) {
                $mismatches[] = $field;
            }
        }

        $actualPayload = $record->payload;

        if (! is_array($actualPayload) || SearchDocumentHasher::canonicalizePayload($actualPayload) !==
            SearchDocumentHasher::canonicalizePayload(SearchDocumentRecord::jsonSafePayload($document->payload))) {
            $mismatches[] = 'payload';
        }

        $expectedTimestamp = new SearchDocumentRecord;
        $expectedTimestamp->setConnection($record->getConnectionName());
        $expectedTimestamp->setAttribute('source_updated_at', $document->sourceUpdatedAt);
        $expectedValue = $expectedTimestamp->source_updated_at;
        $actualValue = $record->source_updated_at;
        $dateFormat = $record->getDateFormat();

        if ($expectedValue?->format($dateFormat) !== $actualValue?->format($dateFormat)) {
            $mismatches[] = 'source_updated_at';
        }

        if ($mismatches !== []) {
            throw SearchIndexPersistenceException::semanticMismatch($document->identity, $mismatches);
        }
    }
}
