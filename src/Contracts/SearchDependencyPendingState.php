<?php

namespace Zarbinco\PersianSearch\Contracts;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyPreparedChange;

interface SearchDependencyPendingState
{
    public function put(Model $model, SearchDependencyPreparedChange $change): void;

    public function take(Model $model): ?SearchDependencyPreparedChange;

    public function forget(Model $model): void;
}
