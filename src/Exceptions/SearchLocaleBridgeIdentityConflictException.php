<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;
use Zarbinco\PersianSearch\Search\SearchLocaleCounterpartIdentity;

final class SearchLocaleBridgeIdentityConflictException extends RuntimeException
{
    public static function forIdentity(string $partition, string $sourceKey, string $locale): self
    {
        return new self(
            'Search locale counterpart identity conflicts for '.
            SearchLocaleCounterpartIdentity::description($partition, $sourceKey, $locale).'.',
        );
    }
}
