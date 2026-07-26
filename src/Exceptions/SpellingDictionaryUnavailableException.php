<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;

final class SpellingDictionaryUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Persian search spelling is enabled but its dictionary tables are unavailable. Run the package migrations and build the dictionary.');
    }
}
