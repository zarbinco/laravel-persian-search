<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contracts;

interface QueryPopularityProvider
{
    public function popularity(string $query, string $locale): float;
}
