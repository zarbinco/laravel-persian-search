<?php

namespace Zarbinco\PersianSearch\Operations;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Throwable;
use Zarbinco\PersianSearch\Exceptions\SearchMaintenanceLockUnavailableException;

final readonly class SearchMaintenanceLockManager
{
    public function __construct(
        private CacheManager $cache,
        private SearchOperationsPolicy $policy,
        private SearchMaintenanceLockInspector $inspector,
    ) {}

    public function acquire(): SearchMaintenanceLock
    {
        try {
            $lock = $this->lock($this->policy->lockKey, $this->policy->lockSeconds);
            if (! $lock->get()) {
                throw new SearchMaintenanceLockUnavailableException;
            }

            return new SearchMaintenanceLock($lock);
        } catch (SearchMaintenanceLockUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new \RuntimeException('The configured Persian search maintenance lock store is unavailable.', 0, $exception);
        }
    }

    public function status(): SearchMaintenanceLockStatus
    {
        try {
            $lock = $this->lock($this->policy->lockKey, 1);

            return $this->inspector->inspect($lock);
        } catch (Throwable) {
            return SearchMaintenanceLockStatus::Unknown;
        }
    }

    public function testAtomicLock(): bool
    {
        $lock = $this->lock($this->policy->lockKey.':doctor:'.bin2hex(random_bytes(16)), 10);
        $acquired = false;
        try {
            $acquired = (bool) $lock->get();

            return $acquired;
        } finally {
            if ($acquired && $lock->release() === false) {
                throw new \RuntimeException('The atomic lock probe could not be released safely.');
            }
        }
    }

    private function lock(string $key, int $seconds): Lock
    {
        $repository = $this->cache->store($this->policy->lockStore);
        if (! $repository instanceof Repository) {
            throw new \RuntimeException('The configured cache repository is incompatible with atomic locks.');
        }
        $store = $repository->getStore();
        if (! $store instanceof LockProvider) {
            throw new \RuntimeException('The configured cache store does not support atomic locks.');
        }

        $lock = $store->lock($key, $seconds);
        if (! $lock instanceof Lock) {
            throw new \RuntimeException('The configured cache store returned an incompatible atomic lock.');
        }

        return $lock;
    }
}
