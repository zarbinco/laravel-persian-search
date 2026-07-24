<?php

namespace Zarbinco\PersianSearch\Operations;

use Illuminate\Contracts\Cache\Lock;

final readonly class SearchMaintenanceLock
{
    public function __construct(private Lock $lock) {}

    public function release(): void
    {
        $this->lock->release();
    }
}
