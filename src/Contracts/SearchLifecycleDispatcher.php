<?php

namespace Zarbinco\PersianSearch\Contracts;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronization;

interface SearchLifecycleDispatcher
{
    public function prepareForModel(Model $model): ?SearchLifecycleSynchronization;

    public function dispatchForModel(Model $model): void;

    public function dispatchSynchronization(SearchLifecycleSynchronization $synchronization): void;

    public function execute(SearchLifecycleSynchronization $synchronization): void;
}
