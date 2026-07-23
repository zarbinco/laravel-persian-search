<?php

namespace Zarbinco\PersianSearch\Candidates;

final readonly class LiteralLikePattern
{
    private function __construct(public string $value) {}

    public static function contains(string $value): self
    {
        return new self('%'.str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $value,
        ).'%');
    }
}
