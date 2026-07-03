<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;

final class SearchableModelNotPersistedException extends RuntimeException
{
    public static function forIndexing(): self
    {
        return new self('Cannot index an unsaved searchable model. Persist the model before indexing it.');
    }
}
