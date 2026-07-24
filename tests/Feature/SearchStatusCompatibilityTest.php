<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Zarbinco\PersianSearch\Operations\SearchMaintenanceLockManager;
use Zarbinco\PersianSearch\Operations\SearchMaintenanceLockStatus;
use Zarbinco\PersianSearch\Operations\SearchOperationsPolicy;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchStatusCompatibilityTest extends TestCase
{
    public function test_actual_lock_capability_determines_the_status(): void
    {
        $policy = app(SearchOperationsPolicy::class);
        $repository = app(CacheManager::class)->store($policy->lockStore);
        $this->assertInstanceOf(Repository::class, $repository);
        $store = $repository->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $rawLock = $store->lock($policy->lockKey, 1);
        $expected = method_exists($rawLock, 'isLocked')
            ? SearchMaintenanceLockStatus::Available
            : SearchMaintenanceLockStatus::Unknown;

        $this->assertSame($expected, app(SearchMaintenanceLockManager::class)->status());
    }
}
