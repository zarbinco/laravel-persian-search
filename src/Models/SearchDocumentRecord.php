<?php

namespace Zarbinco\PersianSearch\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentHasher;
use Zarbinco\PersianSearch\Indexing\SearchDocumentIdentity;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

/**
 * @property string $id
 * @property string $partition
 * @property string $source_key
 * @property string $source_type
 * @property string|null $source_id
 * @property string|null $source_connection
 * @property string $locale
 * @property string|null $title
 * @property string|null $excerpt
 * @property string|null $normalized_title
 * @property string|null $normalized_excerpt
 * @property string|null $normalized_keywords
 * @property string|null $normalized_content
 * @property array<string|int, mixed>|null $payload
 * @property int $priority
 * @property bool $is_active
 * @property string $document_hash
 * @property Carbon|null $source_updated_at
 * @property Carbon|null $indexed_at
 */
final class SearchDocumentRecord extends Model
{
    protected $guarded = [];

    protected $keyType = 'string';

    public function getTable(): string
    {
        return (string) config('persian-search.index.table', 'persian_search_documents');
    }

    public function getConnectionName(): ?string
    {
        $connection = config('persian-search.index.connection');

        return is_string($connection) && trim($connection) !== ''
            ? $connection
            : parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'source_updated_at' => 'datetime',
            'indexed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forDocument(SearchDocument $document): array
    {
        return [
            ...$document->identity->toArray(),
            'source_type' => $document->sourceType,
            'source_id' => $document->sourceId,
            'source_connection' => $document->sourceConnection,
            'title' => $document->title,
            'excerpt' => $document->excerpt,
            'normalized_title' => $document->normalizedTitle,
            'normalized_excerpt' => $document->normalizedExcerpt,
            'normalized_keywords' => $document->normalizedKeywords,
            'normalized_content' => $document->normalizedContent,
            'payload' => self::jsonSafePayload($document->payload),
            'priority' => $document->priority,
            'is_active' => $document->isActive,
            'document_hash' => $document->documentHash,
            'source_updated_at' => $document->sourceUpdatedAt,
            'indexed_at' => now(),
        ];
    }

    /**
     * @return array{partition: string, source_key: string, locale: string}
     */
    public static function identityFor(SearchDocument $document): array
    {
        return $document->identity->toArray();
    }

    public static function localeStorageKey(?string $locale): string
    {
        return SearchDocumentIdentity::normalizeLocale($locale);
    }

    /**
     * @param  Builder<SearchDocumentRecord>  $query
     * @return Builder<SearchDocumentRecord>
     */
    public function scopeForSourceReference(Builder $query, SearchSourceReference $reference): Builder
    {
        $query
            ->where('source_key', $reference->sourceKey)
            ->where('source_type', $reference->sourceType);

        if ($reference->sourceId === null) {
            return $query->whereNull('source_id');
        }

        return $query->where('source_id', $reference->sourceId);
    }

    /**
     * @param  Builder<SearchDocumentRecord>  $query
     * @return Builder<SearchDocumentRecord>
     */
    public function scopeInIdentityOrder(Builder $query): Builder
    {
        return $query->orderBy('partition')->orderBy('locale')->orderBy($this->qualifyColumn('id'));
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    public static function jsonSafePayload(array $payload): array
    {
        $safe = SearchDocumentHasher::jsonSafeValue($payload);

        return is_array($safe) ? $safe : [];
    }
}
