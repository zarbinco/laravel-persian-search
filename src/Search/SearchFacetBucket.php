<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use JsonSerializable;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchFacetBucket implements JsonSerializable
{
    public function __construct(public string $value, public int $count)
    {
        if (! CanonicalConfigurationName::isValid($this->value)) {
            throw new InvalidArgumentException('Search facet bucket value must be a safe non-empty string.');
        }

        if ($this->count < 1) {
            throw new InvalidArgumentException('Search facet bucket count must be positive.');
        }
    }

    /** @return array{value: string, count: int} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'count' => $this->count];
    }

    /** @return array{value: string, count: int} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
