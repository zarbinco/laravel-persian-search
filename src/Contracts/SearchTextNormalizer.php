<?php

namespace Zarbinco\PersianSearch\Contracts;

interface SearchTextNormalizer
{
    public function normalize(string $value, string $locale): string;
}
