<?php

namespace Zarbinco\PersianSearch\Eloquent;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Throwable;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;

/**
 * @mixin Model
 */
trait HasPersianSearch
{
    /**
     * @return array<int|string, string|int|float>
     */
    public function persianSearchableFields(): array
    {
        return [];
    }

    public function persianSearchTitle(): string
    {
        $model = $this->persianSearchModel();

        foreach (['title', 'name'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        $key = $model->getKey();
        $name = class_basename($model);

        if ($key === null || $key === '') {
            return $name;
        }

        return $name.' '.$key;
    }

    public function persianSearchLocale(): ?string
    {
        try {
            return app()->getLocale();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function persianSearchMetadata(): array
    {
        return [];
    }

    public function toPersianSearchDocument(): SearchDocument
    {
        $model = $this->persianSearchModel();

        return app(SearchDocumentBuilder::class)->build($model);
    }

    private function persianSearchModel(): Model
    {
        if (! $this instanceof Model) {
            throw new LogicException('The HasPersianSearch trait may only be used on Eloquent models.');
        }

        return $this;
    }
}
