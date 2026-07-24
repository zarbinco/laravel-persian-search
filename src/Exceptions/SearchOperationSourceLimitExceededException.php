<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;

final class SearchOperationSourceLimitExceededException extends RuntimeException
{
    public function __construct(public readonly int $maximum)
    {
        parent::__construct("Search operation source limit of {$maximum} unique sources was exceeded.");
    }
}
