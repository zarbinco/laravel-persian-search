<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Zarbinco\PersianSearch\Contracts\SearchLifecycleDispatcher;

final readonly class DefaultSearchLifecycleDispatcher implements SearchLifecycleDispatcher
{
    public function __construct(
        private SearchLifecyclePolicy $policy,
        private SearchSourceLocatorFactory $locators,
        private DatabaseManager $database,
        private SearchLifecycleSynchronizationRouter $router,
    ) {}

    public function prepareForModel(Model $model): ?SearchLifecycleSynchronization
    {
        if (! $this->policy->automaticSync) {
            return null;
        }

        return $this->locators->forSource($model)->synchronization();
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
        $this->router->route($synchronization);
    }
}
