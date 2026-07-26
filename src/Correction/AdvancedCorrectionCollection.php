<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, AdvancedCorrection> */
final class AdvancedCorrectionCollection implements Countable, IteratorAggregate
{
    /** @var list<AdvancedCorrection> */
    private array $items = [];

    /** @param iterable<AdvancedCorrection> $items */
    public function __construct(private readonly int $maximum, iterable $items = [])
    {
        if ($this->maximum < 1) {
            throw new InvalidArgumentException('Maximum advanced corrections must be greater than zero.');
        }

        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function add(AdvancedCorrection $correction): void
    {
        if (count($this->items) >= $this->maximum) {
            return;
        }

        foreach ($this->items as $existing) {
            if ($existing->fingerprint === $correction->fingerprint
                || ($existing->locale === $correction->locale && $existing->correctedQuery === $correction->correctedQuery)) {
                return;
            }
        }

        $this->items[] = $correction;
    }

    /** @return list<AdvancedCorrection> */
    public function all(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int, AdvancedCorrection> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (AdvancedCorrection $correction): array => $correction->toArray(), $this->items);
    }
}
