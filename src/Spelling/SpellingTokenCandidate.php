<?php

namespace Zarbinco\PersianSearch\Spelling;

use InvalidArgumentException;

final readonly class SpellingTokenCandidate
{
    public function __construct(
        public string $term,
        public string $locale,
        public int $editDistance,
        public int $weightedCost,
        public int $documentFrequency,
        public int $titleFrequency,
    ) {
        if ($this->term === '' || trim($this->locale) === '' || $this->editDistance < 1
            || $this->weightedCost < 1 || $this->documentFrequency < 1 || $this->titleFrequency < 0) {
            throw new InvalidArgumentException('Spelling token candidate metadata is invalid.');
        }
    }
}
