<?php

namespace Zarbinco\PersianSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Zarbinco\PersianSearch\Lifecycle\EloquentSearchSourceSynchronizer;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronization;
use Zarbinco\PersianSearch\Lifecycle\SearchQueuePolicy;

final class SynchronizeEloquentSearchSourceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public int $uniqueFor;

    /** @var list<int> */
    private array $backoff;

    public function __construct(
        public readonly SearchLifecycleSynchronization $synchronization,
        SearchQueuePolicy $policy,
    ) {
        $this->tries = $policy->tries;
        $this->timeout = $policy->timeout;
        $this->uniqueFor = $policy->uniqueFor;
        $this->backoff = $policy->backoff;

        if ($policy->connection !== null) {
            $this->onConnection($policy->connection);
        }

        if ($policy->queue !== null) {
            $this->onQueue($policy->queue);
        }

        $this->beforeCommit();
    }

    public function handle(EloquentSearchSourceSynchronizer $synchronizer): void
    {
        $synchronizer->synchronize($this->synchronization);
    }

    public function uniqueId(): string
    {
        return $this->synchronization->routingFingerprint();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return $this->backoff;
    }
}
