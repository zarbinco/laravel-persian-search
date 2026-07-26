<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use Zarbinco\PersianSearch\Contracts\QueryClickSignalProvider;

final readonly class NeutralQueryClickSignalProvider implements QueryClickSignalProvider
{
    public function clickConfidence(string $query, string $locale): float
    {
        return 0.0;
    }
}
