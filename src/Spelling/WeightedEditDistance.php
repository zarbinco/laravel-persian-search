<?php

namespace Zarbinco\PersianSearch\Spelling;

use InvalidArgumentException;

final readonly class WeightedEditDistance
{
    public function __construct(
        public int $edits,
        public int $cost,
    ) {
        if ($this->edits < 0 || $this->cost < 0) {
            throw new InvalidArgumentException('Weighted edit-distance values must be zero or greater.');
        }
    }
}
