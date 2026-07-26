<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use Countable;

final class CorrectionEvidenceCollection implements Countable
{
    /** @var array<string, CorrectionEvidence> */
    private array $items = [];

    /** @param iterable<CorrectionEvidence> $items */
    public function __construct(iterable $items = [])
    {
        foreach ($items as $item) {
            $this->items[$item->candidateFingerprint] = $item;
        }
    }

    public function get(string $candidateFingerprint): ?CorrectionEvidence
    {
        return $this->items[$candidateFingerprint] ?? null;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(
            static fn (CorrectionEvidence $evidence): array => $evidence->toArray(),
            array_values($this->items),
        );
    }
}
