<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contracts;

interface QueryClickSignalProvider
{
    public function clickConfidence(string $query, string $locale): float;
}
