<?php

namespace Zarbinco\PersianSearch\Contracts;

interface SearchTokenizer
{
    /** @return list<string> */
    public function tokenize(string $normalizedText, string $locale): array;
}
