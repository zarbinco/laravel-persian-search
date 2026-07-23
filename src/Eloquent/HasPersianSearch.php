<?php

namespace Zarbinco\PersianSearch\Eloquent;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Throwable;
use Zarbinco\PersianSearch\Contracts\SearchLifecycleDispatcher;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronization;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\PersianSearchManager;
use Zarbinco\PersianSearch\Search\SearchQueryBuilder;

/**
 * @mixin Model
 */
trait HasPersianSearch
{
    private ?SearchLifecycleSynchronization $persianSearchPendingDeletionSynchronization = null;

    protected static function bootHasPersianSearch(): void
    {
        static::saved(static function (self $model): void {
            app(SearchLifecycleDispatcher::class)->dispatchForModel($model);
        });

        static::deleting(static function (self $model): void {
            $model->persianSearchPendingDeletionSynchronization = app(SearchLifecycleDispatcher::class)
                ->prepareForModel($model);
        });

        static::deleted(static function (self $model): void {
            $synchronization = $model->persianSearchPendingDeletionSynchronization;
            $model->persianSearchPendingDeletionSynchronization = null;

            if ($synchronization !== null) {
                app(SearchLifecycleDispatcher::class)->dispatchSynchronization($synchronization);
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

    /** @return list<string> */
    public function persianSearchableRelations(): array
    {
        return [];
    }

    public function toPersianSearchDocument(): SearchDocument
    {
        $model = $this->persianSearchModel();

        return app(SearchIndexManager::class)->documentFor($model);
    }

    public function savePersianSearchDocument(): SearchDocumentRecord
    {
        return app(SearchIndexManager::class)->index($this->persianSearchModel());
    }

    public function deletePersianSearchDocument(): int
    {
        return app(SearchIndexManager::class)->deleteSource($this->persianSearchModel());
    }

    private function persianSearchModel(): Model
    {
        if (! $this instanceof Model) {
            throw new LogicException('The HasPersianSearch trait may only be used on Eloquent models.');
        }

        return $this;
    }
}
