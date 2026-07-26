<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use Zarbinco\PersianSearch\Contracts\QueryPopularityProvider;

final readonly class NeutralQueryPopularityProvider implements QueryPopularityProvider
{
    public function popularity(string $query, string $locale): float
    {
        return 0.0;
    }
}
