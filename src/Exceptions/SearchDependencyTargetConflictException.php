<?php

namespace Zarbinco\PersianSearch\Exceptions;

use LogicException;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocator;
use Zarbinco\PersianSearch\Providers\ProviderKey;

final class SearchDependencyTargetConflictException extends LogicException
{
    public static function forLocators(SearchSourceLocator $first, SearchSourceLocator $conflicting): self
    {
        return new self(
            'Conflicting search dependency targets share routing fingerprint ['.$first->fingerprint().
            '] for provider ['.ProviderKey::describe($first->providerKey).'] and source ['.
            $conflicting->source->description().'], but have different fallback references.',
        );
    }
}
