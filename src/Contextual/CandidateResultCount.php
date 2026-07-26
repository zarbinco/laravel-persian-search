<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use InvalidArgumentException;
use JsonSerializable;

final readonly class CandidateResultCount implements JsonSerializable
{
    public function __construct(
        public int $count,
        public bool $isApproximate,
        public int $examinedCandidates,
        public bool $isAvailable = true,
    ) {
        if ($this->count < 0 || $this->examinedCandidates < $this->count) {
            throw new InvalidArgumentException('Contextual result-count evidence is inconsistent.');
        }
    }

    public static function unavailable(): self
    {
        return new self(0, false, 0, false);
    }

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'is_approximate' => $this->isApproximate,
            'examined_candidates' => $this->examinedCandidates,
            'is_available' => $this->isAvailable,
        ];
    }

    /** @return array<string, int|bool> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
