<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;

final readonly class QueryCandidate
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function __construct(
        public string $source,
        public string $original,
        public string $normalized,
        public array $tokens,
        public float $boost,
    ) {
        if ($boost <= 0) {
            throw new InvalidArgumentException('Query candidate boost must be greater than zero.');
        }
    }

    public function isEmpty(): bool
    {
        return trim($this->normalized) === '' && $this->tokens === [];
    }

    /**
     * @return array{
     *     source: string,
     *     original: string,
     *     normalized: string,
     *     tokens: array<int, string>,
     *     boost: float
     * }
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'original' => $this->original,
            'normalized' => $this->normalized,
            'tokens' => $this->tokens,
            'boost' => $this->boost,
        ];
    }
}
