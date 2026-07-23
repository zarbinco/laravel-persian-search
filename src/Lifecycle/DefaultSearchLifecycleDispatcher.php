<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Contracts\SearchLifecycleDispatcher;
use Zarbinco\PersianSearch\Jobs\SynchronizeEloquentSearchSourceJob;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;

final readonly class DefaultSearchLifecycleDispatcher implements SearchLifecycleDispatcher
{
    public function __construct(
        private SearchLifecyclePolicy $policy,
        private SearchQueuePolicy $queuePolicy,
        private SearchDocumentProviderRegistry $providers,
        private EloquentSearchSourceSynchronizer $synchronizer,
        private UniqueSearchLifecycleJobDispatcher $jobs,
        private DatabaseManager $database,
    ) {}

    public function prepareForModel(Model $model): ?SearchLifecycleSynchronization
    {
        if (! $this->policy->automaticSync) {
            return null;
        }

        return new SearchLifecycleSynchronization(
            EloquentSearchSourceLocator::fromModel($model),
            $this->providers->referenceFor($model),
        );
    }

    public function dispatchForModel(Model $model): void
    {
        $synchronization = $this->prepareForModel($model);

        if ($synchronization !== null) {
            $this->dispatchSynchronization($synchronization);
        }
    }

    public function dispatchSynchronization(SearchLifecycleSynchronization $synchronization): void
    {
        $sourceConnection = $this->database->connection($synchronization->locator->connection);

        if ($this->policy->afterCommit && $sourceConnection->transactionLevel() > 0) {
            $sourceConnection->afterCommit(static function () use ($synchronization): void {
                app(SearchLifecycleDispatcher::class)->execute($synchronization);
            });

            return;
        }

        $this->execute($synchronization);
    }

    public function execute(SearchLifecycleSynchronization $synchronization): void
    {
        if ($this->policy->execution === SearchLifecycleExecutionMode::Sync) {
            $this->synchronizer->synchronize($synchronization);

            return;
        }

        $this->jobs->dispatch(new SynchronizeEloquentSearchSourceJob($synchronization, $this->queuePolicy));
    }
}
