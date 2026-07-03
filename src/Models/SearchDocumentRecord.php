<?php

namespace Zarbinco\PersianSearch\Models;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Enumerable;
use Stringable;
use Traversable;
use UnitEnum;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchField;

/**
 * @property string $searchable_type
 * @property string $searchable_id
 * @property string $locale
 * @property string|null $title
 * @property string $content
 * @property array<int, string>|null $tokens
 * @property list<array{name: string, raw_value: mixed, value: string, tokens: array<int, string>, weight: int|float}>|null $fields
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $indexed_at
 */
final class SearchDocumentRecord extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('persian-search.index.table', 'persian_search_documents');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tokens' => 'array',
            'fields' => 'array',
            'metadata' => 'array',
            'indexed_at' => 'datetime',
        ];
    }

    /**
     * @return array{
     *     searchable_type: string,
     *     searchable_id: string,
     *     locale: string,
     *     title: string,
     *     content: string,
     *     tokens: array<int, string>,
     *     fields: list<array{name: string, raw_value: mixed, value: string, tokens: array<int, string>, weight: int|float}>,
     *     metadata: array<string, mixed>,
     *     indexed_at: Carbon
     * }
     */
    public static function forDocument(SearchDocument $document): array
    {
        return [
            'searchable_type' => $document->searchableType,
            'searchable_id' => (string) $document->searchableId,
            'locale' => self::localeStorageKey($document->locale),
            'title' => $document->title,
            'content' => $document->content,
            'tokens' => $document->tokens,
            'fields' => array_map(
                static fn (SearchField $field): array => self::fieldPayload($field),
                $document->fields,
            ),
            'metadata' => $document->metadata,
            'indexed_at' => now(),
        ];
    }

    public static function localeStorageKey(?string $locale): string
    {
        return $locale ?? '';
    }

    /**
     * @return array{name: string, raw_value: mixed, value: string, tokens: array<int, string>, weight: int|float}
     */
    private static function fieldPayload(SearchField $field): array
    {
        return [
            'name' => $field->name,
            'raw_value' => self::jsonSafeValue($field->rawValue),
            'value' => $field->value,
            'tokens' => $field->tokens,
            'weight' => $field->weight,
        ];
    }

    private static function jsonSafeValue(mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            $safe = [];

            foreach ($value as $key => $item) {
                $safe[$key] = self::jsonSafeValue($item);
            }

            return $safe;
        }

        if ($value instanceof Arrayable) {
            return self::jsonSafeValue($value->toArray());
        }

        if ($value instanceof Enumerable) {
            return self::jsonSafeValue($value->all());
        }

        if ($value instanceof Traversable) {
            return self::jsonSafeValue(iterator_to_array($value, preserve_keys: false));
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        if (is_resource($value)) {
            return get_debug_type($value);
        }

        return null;
    }
}
