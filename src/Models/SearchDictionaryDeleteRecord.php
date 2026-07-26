<?php

namespace Zarbinco\PersianSearch\Models;

use Illuminate\Database\Eloquent\Model;

final class SearchDictionaryDeleteRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('persian-search.spelling.deletes_table', 'persian_search_dictionary_deletes');
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
}
