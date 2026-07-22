<?php

namespace Zarbinco\PersianSearch\Contracts;

interface SearchTextSanitizer
{
    public function sanitize(string $value, string $locale): string;
}
