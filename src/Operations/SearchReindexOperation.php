<?php

namespace Zarbinco\PersianSearch\Operations;

use Throwable;
use Zarbinco\PersianSearch\Exceptions\SearchOperationExecutionException;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleExecutionMode;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecyclePolicy;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronizationRouter;

final readonly class SearchReindexOperation
{
    public function __construct(
        private SearchSourceEnumeratorRegistry $enumerators,
        private SearchOperationsPolicy $policy,
        private SearchLifecyclePolicy $lifecycle,
        private SearchLifecycleSynchronizationRouter $router,
        private SearchMaintenanceLockManager $locks,
    ) {}

    public function run(SearchReindexRequest $request): SearchReindexReport
    {
        $selected = $this->enumerators->selected($request->enumeratorKeys, $request->providerKeys);
        $mode = $request->executionMode === null
            ? $this->lifecycle->execution
            : SearchLifecycleExecutionMode::from($request->executionMode);
        $context = new SearchSourceEnumerationContext(
            $this->policy->chunkSize,
            $request->limit,
            $request->enumeratorKeys,
            $request->providerKeys,
            $request->dryRun,
        );
        $lock = $request->dryRun ? null : $this->locks->acquire();

        try {
            $collection = new SearchSourceCollection($this->policy->maximumSourcesPerRun);
            $limitReached = false;
            foreach ($selected as $registration) {
                foreach ($registration->enumerator->enumerate($context) as $locator) {
                    $collection->add($locator, $registration->providerKey);
                    if ($request->limit !== null && $collection->count() >= $request->limit) {
                        $limitReached = true;
                        break;
                    }
                }
                if ($limitReached) {
                    break;
                }
            }

            $synchronized = 0;
            $queued = 0;
            $suppressed = 0;
            if (! $request->dryRun) {
                foreach ($collection->all() as $locator) {
                    try {
                        $accepted = $this->router->routeUsing($locator->synchronization(), $mode);
                    } catch (Throwable $exception) {
                        $completed = $synchronized + $queued + $suppressed;
                        throw new SearchOperationExecutionException(
                            new SearchReindexReport(
                                $mode->value,
                                false,
                                count($selected),
                                $collection->enumerated(),
                                $collection->count(),
                                $collection->duplicates(),
                                $synchronized,
                                $queued,
                                $suppressed,
                                1,
                                $collection->count() - $completed - 1,
                            ),
                            'source_routing',
                            'Search reindex execution failed safely.',
                            $exception,
                        );
                    }
                    if ($mode === SearchLifecycleExecutionMode::Sync) {
                        $synchronized++;
                    } elseif ($accepted) {
                        $queued++;
                    } else {
                        $suppressed++;
                    }
                }
            }

            return new SearchReindexReport(
                $mode->value,
                $request->dryRun,
                count($selected),
                $collection->enumerated(),
                $collection->count(),
                $collection->duplicates(),
                $synchronized,
                $queued,
                $suppressed,
                0,
                0,
            );
        } finally {
            $lock?->release();
        }
    }
}
