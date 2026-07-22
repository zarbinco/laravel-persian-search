<?php

namespace Zarbinco\PersianSearch\Query;

final readonly class SynonymRule
{
    /**
     * @param  list<string>  $sourceTokens
     * @param  list<string>  $replacementTokens
     */
    public function __construct(
        public string $source,
        public array $sourceTokens,
        public string $replacement,
        public array $replacementTokens,
    ) {}
}
