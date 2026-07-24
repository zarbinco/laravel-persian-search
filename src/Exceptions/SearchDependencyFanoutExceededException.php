<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;

final class SearchDependencyFanoutExceededException extends RuntimeException
{
    public static function forLimit(int $maximum): self
    {
        return new self("Search dependency event exceeded its maximum of {$maximum} distinct source locators.");
    }
}
