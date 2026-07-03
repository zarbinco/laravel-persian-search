<?php

namespace Zarbinco\PersianSearch\Indexing;

final readonly class SearchField
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function __construct(
        public string $name,
        public mixed $rawValue,
        public string $value,
        public array $tokens,
        public int|float $weight,
    ) {}

    /**
     * @return array{name: string, raw_value: mixed, value: string, tokens: array<int, string>, weight: int|float}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'raw_value' => $this->rawValue,
            'value' => $this->value,
            'tokens' => $this->tokens,
            'weight' => $this->weight,
        ];
    }
}
