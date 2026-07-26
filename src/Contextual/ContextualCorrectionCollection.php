<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, ContextualCorrection> */
final class ContextualCorrectionCollection implements Countable, IteratorAggregate
{
    /** @var list<ContextualCorrection> */
    private array $items = [];

    /** @param iterable<ContextualCorrection> $items */
    public function __construct(private readonly int $maximum, iterable $items = [])
    {
        if ($this->maximum < 1) {
            throw new InvalidArgumentException('Maximum contextual corrections must be positive.');
        }
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function add(ContextualCorrection $correction): void
    {
        if ($this->isFull()) {
            return;
        }
        foreach ($this->items as $existing) {
            if ($existing->fingerprint === $correction->fingerprint
                || ($existing->candidate->locale === $correction->candidate->locale
                    && $existing->candidate->correctedQuery === $correction->candidate->correctedQuery)) {
                return;
            }
        }
        $this->items[] = $correction;
    }

    /** @return list<ContextualCorrection> */
    public function all(): array
    {
        return $this->items;
    }

    public function isFull(): bool
    {
        return count($this->items) >= $this->maximum;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int, ContextualCorrection> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(
            static fn (ContextualCorrection $correction): array => $correction->toArray(),
            $this->items,
        );
    }
}
