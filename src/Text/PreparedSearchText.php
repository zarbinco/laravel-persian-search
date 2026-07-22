<?php

namespace Zarbinco\PersianSearch\Text;

final readonly class PreparedSearchText
{
    /** @param  list<string>  $tokens */
    public function __construct(
        public string $locale,
        public string $raw,
        public string $sanitized,
        public string $normalized,
        public array $tokens,
    ) {}

    public function isEmpty(): bool
    {
        return $this->normalized === '' && $this->tokens === [];
    }

    /**
     * @return array{locale: string, raw: string, sanitized: string, normalized: string, tokens: list<string>}
     */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'raw' => $this->raw,
            'sanitized' => $this->sanitized,
            'normalized' => $this->normalized,
            'tokens' => $this->tokens,
        ];
    }
}
