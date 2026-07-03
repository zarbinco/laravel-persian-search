<?php

namespace Zarbinco\PersianSearch;

use Zarbinco\PersianSearch\Contracts\SearchNormalizer;

final class PersianSearchManager
{
    public function __construct(
        private readonly SearchNormalizer $normalizer,
    ) {}

    public function normalizer(): SearchNormalizer
    {
        return $this->normalizer;
    }

    public function normalize(string $value): string
    {
        return $this->normalizer->normalize($value);
    }

    /**
     * @return array<int, string>
     */
    public function tokens(string $value): array
    {
        return $this->normalizer->tokens($value);
    }
}
