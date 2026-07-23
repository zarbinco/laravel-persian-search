<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use JsonSerializable;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchResultGroup implements JsonSerializable
{
    /** @param array<int, mixed> $items */
    public function __construct(
        public string $sourceType,
        public int $knownCount,
        public bool $countIsExact,
        public array $items,
    ) {
        if (! array_is_list($this->items)) {
            throw new InvalidArgumentException('Search result group items must be a list.');
        }

        if (! CanonicalConfigurationName::isValid($this->sourceType)) {
            throw new InvalidArgumentException('Search result group source type must be a safe non-empty string.');
        }

        if ($this->knownCount < 0 || count($this->items) > $this->knownCount) {
            throw new InvalidArgumentException('Search result group counts are inconsistent.');
        }

        foreach ($this->items as $item) {
            if (! $item instanceof SearchResult) {
                throw new InvalidArgumentException('Search result group items must be search results.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'known_count' => $this->knownCount,
            'count_is_exact' => $this->countIsExact,
            'items' => array_map(static fn (SearchResult $result): array => $result->toArray(), $this->items),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
