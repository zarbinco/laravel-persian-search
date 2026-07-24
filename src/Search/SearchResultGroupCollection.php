<?php

namespace Zarbinco\PersianSearch\Search;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/** @implements IteratorAggregate<int, SearchResultGroup> */
final readonly class SearchResultGroupCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  array<int, mixed>  $groups
     */
    public function __construct(
        public array $groups,
        public bool $countsAreExact,
        public int $knownGroupTotal,
        public int $returnedGroups,
        public bool $groupsAreComplete,
        public bool $isTruncated,
        public int $maximumGroups,
        public ?SearchSuggestion $suggestion = null,
    ) {
        if (! array_is_list($this->groups)) {
            throw new InvalidArgumentException('Search result groups must be a list.');
        }

        $types = [];

        foreach ($this->groups as $group) {
            if (! $group instanceof SearchResultGroup) {
                throw new InvalidArgumentException('Search result groups must be typed group values.');
            }

            if (isset($types[$group->sourceType])) {
                throw new InvalidArgumentException('Search result group source types must be unique.');
            }

            if ($group->countIsExact !== $this->countsAreExact) {
                throw new InvalidArgumentException('Search result group count exactness must match its collection.');
            }

            $types[$group->sourceType] = true;
        }

        if ($this->maximumGroups < 1 || $this->returnedGroups !== count($this->groups)
            || $this->knownGroupTotal < $this->returnedGroups || $this->returnedGroups > $this->maximumGroups) {
            throw new InvalidArgumentException('Search result group collection counts are inconsistent.');
        }

        $complete = $this->returnedGroups === $this->knownGroupTotal;

        if ($this->groupsAreComplete !== $complete || $this->isTruncated === $complete) {
            throw new InvalidArgumentException('Search result group completeness flags are inconsistent.');
        }
    }

    public function count(): int
    {
        return count($this->groups);
    }

    /** @return Traversable<int, SearchResultGroup> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->groups);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'counts_are_exact' => $this->countsAreExact,
            'known_group_total' => $this->knownGroupTotal,
            'returned_groups' => $this->returnedGroups,
            'groups_are_complete' => $this->groupsAreComplete,
            'is_truncated' => $this->isTruncated,
            'maximum_groups' => $this->maximumGroups,
            'suggestion' => $this->suggestion?->toArray(),
            'groups' => array_map(static fn (SearchResultGroup $group): array => $group->toArray(), $this->groups),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
