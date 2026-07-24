<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SearchResultSlice implements JsonSerializable
{
    /** @var list<SearchPresentedCandidate> */
    public array $candidates;

    public int $returned;

    /** @param array<int, SearchPresentedCandidate> $candidates */
    public function __construct(array $candidates, public int $offset, public int $limit)
    {
        if ($this->offset < 0 || $this->limit < 1) {
            throw new InvalidArgumentException('Search result slice requires a non-negative offset and positive limit.');
        }

        $this->candidates = array_values($candidates);
        $this->returned = count($this->candidates);
    }

    public function returned(): int
    {
        return $this->returned;
    }

    public static function fromWindow(SearchResultWindow $window, int $offset, int $limit): self
    {
        return new self(array_slice($window->candidates, $offset, $limit), $offset, $limit);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'offset' => $this->offset,
            'limit' => $this->limit,
            'returned' => $this->returned(),
            'candidate_ids' => array_map(
                static fn (SearchPresentedCandidate $candidate): string => $candidate->identity(),
                $this->candidates,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
