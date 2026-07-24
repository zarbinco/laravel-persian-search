<?php

namespace Zarbinco\PersianSearch\Operations;

use Throwable;

final class SearchMaintenanceLockInspector
{
    public function inspect(object $lock): SearchMaintenanceLockStatus
    {
        try {
            if (! method_exists($lock, 'isLocked')) {
                return SearchMaintenanceLockStatus::Unknown;
            }
            $method = new \ReflectionMethod($lock, 'isLocked');
            if (! $method->isPublic()) {
                return SearchMaintenanceLockStatus::Unknown;
            }

            return $method->invoke($lock)
                ? SearchMaintenanceLockStatus::Held
                : SearchMaintenanceLockStatus::Available;
        } catch (Throwable) {
            return SearchMaintenanceLockStatus::Unknown;
        }
    }
}
