<?php

namespace Zarbinco\PersianSearch\Spelling;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SpellingTokenCorrection implements JsonSerializable
{
    public function __construct(
        public int $tokenIndex,
        public string $original,
        public string $corrected,
        public int $editDistance,
        public int $weightedCost,
        public int $documentFrequency,
    ) {
        if ($this->tokenIndex < 0 || $this->original === '' || $this->corrected === ''
            || $this->original === $this->corrected || $this->editDistance < 1
            || $this->weightedCost < 1 || $this->documentFrequency < 1) {
            throw new InvalidArgumentException('Spelling token correction metadata is invalid.');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'token_index' => $this->tokenIndex,
            'original' => $this->original,
            'corrected' => $this->corrected,
            'edit_distance' => $this->editDistance,
            'weighted_cost' => $this->weightedCost,
            'document_frequency' => $this->documentFrequency,
        ];
    }

    /** @return array<string, int|string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
