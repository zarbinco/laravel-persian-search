<?php

namespace Zarbinco\PersianSearch\Search;

final class SearchLocaleCounterpartIdentity
{
    public static function key(string $partition, string $sourceKey): string
    {
        return hash(
            'sha256',
            strlen($partition).':'.$partition.'|'.strlen($sourceKey).':'.$sourceKey,
        );
    }

    public static function description(string $partition, string $sourceKey, string $locale): string
    {
        return sprintf(
            'partition [%s], locale [%s], source-key fingerprint [%s], length [%d]',
            $partition,
            $locale,
            hash('sha256', $sourceKey),
            strlen($sourceKey),
        );
    }
}
