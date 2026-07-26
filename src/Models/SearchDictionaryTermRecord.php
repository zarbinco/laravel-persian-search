<?php

namespace Zarbinco\PersianSearch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $locale
 * @property string $term
 * @property string $normalized_term
 * @property int $document_frequency
 * @property int $title_frequency
 * @property int $keyword_frequency
 * @property int $excerpt_frequency
 * @property int $content_frequency
 * @property bool $is_protected
 */
final class SearchDictionaryTermRecord extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('persian-search.spelling.terms_table', 'persian_search_dictionary_terms');
    }

    public function getConnectionName(): ?string
    {
        $connection = config('persian-search.spelling.connection');
        if (! is_string($connection) || trim($connection) === '') {
            $connection = config('persian-search.index.connection');
        }

        return is_string($connection) && trim($connection) !== ''
            ? $connection
            : parent::getConnectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_frequency' => 'integer',
            'title_frequency' => 'integer',
            'keyword_frequency' => 'integer',
            'excerpt_frequency' => 'integer',
            'content_frequency' => 'integer',
            'is_protected' => 'boolean',
            'indexed_at' => 'datetime',
        ];
    }
}
