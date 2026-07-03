<?php

namespace Zarbinco\PersianSearch\Core;

use Zarbinco\PersianCore\Facades\Persian;
use Zarbinco\PersianSearch\Contracts\SearchNormalizer;

final class CoreSearchNormalizer implements SearchNormalizer
{
    public function normalize(string $value): string
    {
        return Persian::search($value)->normalize();
    }

    /**
     * @return array<int, string>
     */
    public function tokens(string $value): array
    {
        return Persian::search($value)->tokens();
    }
}
