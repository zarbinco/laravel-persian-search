<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Zarbinco\PersianSearch\Jobs\SynchronizeEloquentSearchSourceJob;

final readonly class SearchLifecycleSynchronizationRouter
{
    public function __construct(
        private SearchLifecyclePolicy $policy,
        private SearchQueuePolicy $queuePolicy,
        private EloquentSearchSourceSynchronizer $synchronizer,
        private UniqueSearchLifecycleJobDispatcher $jobs,
    ) {}

    public function route(SearchLifecycleSynchronization $synchronization): void
    {
        $this->routeUsing($synchronization, $this->policy->execution);
    }

    public function routeUsing(
        SearchLifecycleSynchronization $synchronization,
        SearchLifecycleExecutionMode $mode,
    ): bool {
        if ($mode === SearchLifecycleExecutionMode::Sync) {
            $this->synchronizer->synchronize($synchronization);

            return true;
        }

        return $this->jobs->dispatch(new SynchronizeEloquentSearchSourceJob($synchronization, $this->queuePolicy));
    }
}
