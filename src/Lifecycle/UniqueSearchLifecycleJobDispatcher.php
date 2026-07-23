<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Throwable;
use Zarbinco\PersianSearch\Jobs\SynchronizeEloquentSearchSourceJob;

final readonly class UniqueSearchLifecycleJobDispatcher
{
    public function __construct(
        private UniqueLock $uniqueLock,
        private BusDispatcher $bus,
    ) {}

    public function dispatch(SynchronizeEloquentSearchSourceJob $job): bool
    {
        $job->beforeCommit();

        if (! $this->uniqueLock->acquire($job)) {
            return false;
        }

        try {
            $this->bus->dispatch($job);
        } catch (Throwable $exception) {
            $this->uniqueLock->release($job);

            throw $exception;
        }

        return true;
    }
}
