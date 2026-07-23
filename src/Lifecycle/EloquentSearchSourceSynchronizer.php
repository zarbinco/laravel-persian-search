<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Zarbinco\PersianSearch\Exceptions\InvalidEloquentSearchSourceLocatorException;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Indexing\SearchSourceIndexResult;

final readonly class EloquentSearchSourceSynchronizer
{
    public function __construct(
        private SearchIndexManager $index,
        private SearchLifecyclePolicy $policy,
    ) {}

    public function synchronize(SearchLifecycleSynchronization $synchronization): ?SearchSourceIndexResult
    {
        $locator = $synchronization->locator;
        $class = $locator->modelClass;
        $prototype = new $class;
        $prototype->setConnection($locator->connection);

        if ($prototype->getKeyName() !== $locator->keyName) {
            throw InvalidEloquentSearchSourceLocatorException::keyNameMismatch(
                $locator->keyName,
                $prototype->getKeyName(),
            );
        }

        $model = $prototype->newQueryWithoutScopes()
            ->useWritePdo()
            ->where($locator->keyName, $locator->keyValue)
            ->first();

        if (! $model instanceof Model) {
            $this->index->deleteSourceReference($synchronization->fallbackReference);

            return null;
        }

        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
        $isTrashed = $usesSoftDeletes && method_exists($model, 'trashed') && $model->trashed();

        if ($isTrashed && ! $this->policy->includeSoftDeleted) {
            $this->index->deleteSourceReference($synchronization->fallbackReference);

            return null;
        }

        return $this->index->indexSource($model);
    }
}
