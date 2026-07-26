<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

use InvalidArgumentException;

final readonly class PhoneticAlternative
{
    public function __construct(
        public string $token,
        public int $cost,
        public string $rule,
    ) {
        if ($this->token === '' || $this->cost < 1 || trim($this->rule) === '') {
            throw new InvalidArgumentException('Phonetic alternative metadata is invalid.');
        }
    }
}
