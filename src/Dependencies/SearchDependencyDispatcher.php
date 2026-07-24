<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Illuminate\Database\DatabaseManager;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecyclePolicy;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronizationRouter;

final readonly class SearchDependencyDispatcher
{
    public function __construct(
        private DatabaseManager $database,
        private SearchLifecyclePolicy $policy,
        private SearchLifecycleSynchronizationRouter $router,
    ) {}

    public function dispatch(string $dependencyConnection, SearchDependencyTargetCollection $targets): void
    {
        $synchronizations = [];
        foreach ($targets as $target) {
            $synchronizations[] = $target->synchronization();
        }

        if ($synchronizations === []) {
            return;
        }

        $connection = $this->database->connection($dependencyConnection);
        if ($this->policy->afterCommit && $connection->transactionLevel() > 0) {
            $connection->afterCommit(static function () use ($synchronizations): void {
                $router = app(SearchLifecycleSynchronizationRouter::class);
                foreach ($synchronizations as $synchronization) {
                    $router->route($synchronization);
                }
            });

            return;
        }

        foreach ($synchronizations as $synchronization) {
            $this->router->route($synchronization);
        }
    }
}
