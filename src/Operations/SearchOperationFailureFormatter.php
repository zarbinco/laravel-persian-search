<?php

namespace Zarbinco\PersianSearch\Operations;

use Throwable;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchSourceEnumeratorException;
use Zarbinco\PersianSearch\Exceptions\SearchMaintenanceLockUnavailableException;
use Zarbinco\PersianSearch\Exceptions\SearchOperationSourceLimitExceededException;

final class SearchOperationFailureFormatter
{
    public function format(Throwable $exception, string $operation): string
    {
        if ($exception instanceof SearchMaintenanceLockUnavailableException
            || $exception instanceof SearchOperationSourceLimitExceededException) {
            return $exception->getMessage();
        }
        if ($exception instanceof InvalidSearchSourceEnumeratorException) {
            return 'Search source enumeration failed safely.';
        }

        return match ($operation) {
            'reindex' => 'Search reindex execution failed safely.',
            'prune' => 'Search prune execution failed safely.',
            default => 'Search operation failed safely.',
        };
    }
}
