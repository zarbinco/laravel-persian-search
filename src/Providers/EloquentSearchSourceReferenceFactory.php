<?php

namespace Zarbinco\PersianSearch\Providers;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Exceptions\SearchableModelNotPersistedException;

final class EloquentSearchSourceReferenceFactory
{
    public function make(Model $model): SearchSourceReference
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw SearchableModelNotPersistedException::forIndexing();
        }

        return new SearchSourceReference(
            sourceKey: $model::class.':'.$key,
            sourceType: $model::class,
            sourceId: $key,
        );
    }
}
