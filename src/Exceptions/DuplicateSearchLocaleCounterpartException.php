<?php

namespace Zarbinco\PersianSearch\Exceptions;

use RuntimeException;
use Zarbinco\PersianSearch\Search\SearchLocaleCounterpartIdentity;

final class DuplicateSearchLocaleCounterpartException extends RuntimeException
{
    public static function forIdentity(string $partition, string $sourceKey, string $locale): self
    {
        return new self(
            'Duplicate exact search locale counterparts exist for '.
            SearchLocaleCounterpartIdentity::description($partition, $sourceKey, $locale).'.',
        );
    }
}
