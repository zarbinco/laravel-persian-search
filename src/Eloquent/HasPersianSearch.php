<?php

namespace Zarbinco\PersianSearch\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Throwable;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\PersianSearchManager;
use Zarbinco\PersianSearch\Search\SearchQueryBuilder;

/**
 * @mixin Model
 */
trait HasPersianSearch
{
    protected static function bootHasPersianSearch(): void
    {
        static::saved(static function (Model $model): void {
            if ((bool) config('persian-search.index.sync_on_save', true)) {
                app(SearchIndexManager::class)->index($model);
            }
        });

        static::deleted(static function (Model $model): void {
            if (! (bool) config('persian-search.index.delete_on_model_delete', true)) {
                return;
            }

            if (self::persianSearchUsesSoftDeletes($model) && ! self::persianSearchIsForceDeleting($model)) {
                if (! (bool) config('persian-search.index.include_soft_deleted', false)) {
                    app(SearchIndexManager::class)->delete($model);
                }

                return;
            }

            app(SearchIndexManager::class)->delete($model);
        });

        static::registerModelEvent('restored', static function (Model $model): void {
            if ((bool) config('persian-search.index.sync_on_save', true)) {
                app(SearchIndexManager::class)->index($model);
            }
        });
    }

    public static function persianSearch(mixed $query): SearchQueryBuilder
    {
        $manager = app(PersianSearchManager::class);

        return $manager->search($query)->for(static::class);
    }

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

    public function savePersianSearchDocument(): SearchDocumentRecord
    {
        return app(SearchIndexManager::class)->index($this->persianSearchModel());
    }

    public function deletePersianSearchDocument(): int
    {
        return app(SearchIndexManager::class)->delete($this->persianSearchModel());
    }

    private function persianSearchModel(): Model
    {
        if (! $this instanceof Model) {
            throw new LogicException('The HasPersianSearch trait may only be used on Eloquent models.');
        }

        return $this;
    }

    private static function persianSearchUsesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    private static function persianSearchIsForceDeleting(Model $model): bool
    {
        return method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
    }
}
