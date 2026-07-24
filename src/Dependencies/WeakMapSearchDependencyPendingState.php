<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Illuminate\Database\Eloquent\Model;
use WeakMap;
use Zarbinco\PersianSearch\Contracts\SearchDependencyPendingState;

final class WeakMapSearchDependencyPendingState implements SearchDependencyPendingState
{
    /** @var WeakMap<Model, SearchDependencyPreparedChange> */
    private WeakMap $changes;

    public function __construct()
    {
        $this->changes = new WeakMap;
    }

    public function put(Model $model, SearchDependencyPreparedChange $change): void
    {
        $this->changes[$model] = $change;
    }

    public function take(Model $model): ?SearchDependencyPreparedChange
    {
        $change = $this->changes[$model] ?? null;
        unset($this->changes[$model]);

        return $change;
    }

    public function forget(Model $model): void
    {
        unset($this->changes[$model]);
    }
}
