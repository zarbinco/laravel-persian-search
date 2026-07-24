<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;

final class SearchMaintenanceLockUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Persian search maintenance lock is unavailable.');
    }
}
