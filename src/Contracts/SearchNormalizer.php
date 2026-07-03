<?php

namespace Zarbinco\PersianSearch\Contracts;

interface SearchNormalizer
{
    public function normalize(string $value): string;

    /**
     * @return array<int, string>
     */
    public function tokens(string $value): array;
}
