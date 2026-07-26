<?php

namespace Zarbinco\PersianSearch\Spelling;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, SpellingCorrection> */
final class SpellingCorrectionCollection implements Countable, IteratorAggregate
{
    /** @var list<SpellingCorrection> */
    private array $items = [];

    /** @param iterable<SpellingCorrection> $items */
    public function __construct(private readonly int $maximum, iterable $items = [])
    {
        if ($this->maximum < 1) {
            throw new InvalidArgumentException('Maximum spelling corrections must be greater than zero.');
        }

        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function add(SpellingCorrection $correction): void
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

    /** @return list<SpellingCorrection> */
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

    /** @return Traversable<int, SpellingCorrection> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (SpellingCorrection $correction): array => $correction->toArray(), $this->items);
    }
}
