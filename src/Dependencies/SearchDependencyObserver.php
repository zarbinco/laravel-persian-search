<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Zarbinco\PersianSearch\Contracts\SearchDependencyPendingState;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchDependencyObserver
{
    public function __construct(
        private SearchDependencySnapshotFactory $snapshots,
        private SearchDependencyTargetResolver $targets,
        private SearchDependencyPendingState $pending,
        private SearchDependencyDispatcher $dispatcher,
        private SearchDependencyPolicy $policy,
    ) {}

    public function saving(Model $model): void
    {
        if (! $this->policy->enabled) {
            $this->pending->forget($model);

            return;
        }

        if (! $model->exists) {
            $this->pending->put($model, new SearchDependencyPreparedChange(
                SearchDependencyOperation::Created,
                $this->connectionName($model),
                new SearchDependencyTargetCollection([], $this->policy->maximumSourcesPerEvent),
            ));

            return;
        }

        if (! $model->isDirty() || $this->isRestoring($model)) {
            $this->pending->forget($model);

            return;
        }

        $changed = array_keys($model->getDirty());
        sort($changed, SORT_STRING);
        $before = $this->snapshots->beforeUpdate($model);
        $connection = $this->connectionName($before);
        $targets = $this->targets->resolve(
            $before,
            SearchDependencyOperation::Updated,
            SearchDependencyState::Before,
            $changed,
        );
        $this->pending->put($model, new SearchDependencyPreparedChange(
            SearchDependencyOperation::Updated,
            $connection,
            $targets,
            $changed,
        ));
    }

    public function saved(Model $model): void
    {
        $prepared = $this->pending->take($model);

        if (! $this->policy->enabled) {
            return;
        }

        if ($prepared?->operation === SearchDependencyOperation::Updated && $model->wasChanged()) {
            $after = $this->snapshots->current($model);
            $afterTargets = $this->targets->resolve(
                $after,
                SearchDependencyOperation::Updated,
                SearchDependencyState::After,
                $prepared->changedAttributes,
            );
            $this->dispatcher->dispatch(
                $prepared->connection,
                SearchDependencyTargetCollection::merge(
                    $prepared->beforeTargets,
                    $afterTargets,
                    $this->policy->maximumSourcesPerEvent,
                ),
            );

            return;
        }

        if ($prepared?->operation === SearchDependencyOperation::Created && $model->wasRecentlyCreated) {
            $after = $this->snapshots->current($model);
            $this->dispatcher->dispatch(
                $this->connectionName($after),
                $this->targets->resolve($after, SearchDependencyOperation::Created, SearchDependencyState::After),
            );
        }
    }

    public function deleting(Model $model): void
    {
        if (! $this->policy->enabled) {
            $this->pending->forget($model);

            return;
        }

        $before = $this->snapshots->current($model);
        $connection = $this->connectionName($before);
        $this->pending->put($model, new SearchDependencyPreparedChange(
            SearchDependencyOperation::Deleted,
            $connection,
            $this->targets->resolve($before, SearchDependencyOperation::Deleted, SearchDependencyState::Before),
        ));
    }

    public function deleted(Model $model): void
    {
        $prepared = $this->pending->take($model);

        if ($prepared?->operation === SearchDependencyOperation::Deleted) {
            $this->dispatcher->dispatch($prepared->connection, $prepared->beforeTargets);
        }
    }

    public function restored(Model $model): void
    {
        if (! $this->policy->enabled) {
            return;
        }

        $after = $this->snapshots->current($model);
        $this->dispatcher->dispatch(
            $this->connectionName($after),
            $this->targets->resolve($after, SearchDependencyOperation::Restored, SearchDependencyState::After),
        );
    }

    private function connectionName(Model $model): string
    {
        $connection = $model->getConnection()->getName();

        if (! is_string($connection) || ! CanonicalConfigurationName::isValid($connection)) {
            throw new \InvalidArgumentException('A search dependency model requires a canonical resolved connection name.');
        }

        return $connection;
    }

    private function isRestoring(Model $model): bool
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)
            || ! method_exists($model, 'getDeletedAtColumn')) {
            return false;
        }

        $column = $model->getDeletedAtColumn();

        return $model->isDirty($column)
            && $model->getAttribute($column) === null
            && $model->getRawOriginal($column) !== null;
    }
}
