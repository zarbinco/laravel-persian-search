<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;

final readonly class SearchQuery
{
    /**
     * @param  array<int, string>  $tokens
     * @param  list<class-string>  $searchableTypes
     * @param  list<QueryCandidate>  $candidates
     */
    public function __construct(
        public string $original,
        public string $normalized,
        public array $tokens,
        public array $searchableTypes,
        public ?string $locale,
        public int $limit,
        public int $offset,
        public bool $includeScores,
        private array $candidates = [],
    ) {}

    public function hasSearchableTypes(): bool
    {
        return $this->searchableTypes !== [];
    }

    public function isEmpty(): bool
    {
        if ($this->hasCandidates()) {
            foreach ($this->candidates as $candidate) {
                if (! $candidate->isEmpty()) {
                    return false;
                }
            }

            return true;
        }

        return trim($this->normalized) === '' && $this->tokens === [];
    }

    /**
     * @return list<QueryCandidate>
     */
    public function candidates(): array
    {
        return $this->candidates;
    }

    public function hasCandidates(): bool
    {
        return $this->candidates !== [];
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    public function withCandidates(array $candidates): self
    {
        $validated = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof QueryCandidate) {
                throw new InvalidArgumentException('Search query candidates must be QueryCandidate instances.');
            }

            $validated[] = $candidate;
        }

        return new self(
            original: $this->original,
            normalized: $this->normalized,
            tokens: $this->tokens,
            searchableTypes: $this->searchableTypes,
            locale: $this->locale,
            limit: $this->limit,
            offset: $this->offset,
            includeScores: $this->includeScores,
            candidates: $validated,
        );
    }

    /**
     * @return array{
     *     original: string,
     *     normalized: string,
     *     tokens: array<int, string>,
     *     searchable_types: list<class-string>,
     *     locale: string|null,
     *     limit: int,
     *     offset: int,
     *     include_scores: bool,
     *     candidates: list<array{
     *         source: string,
     *         original: string,
     *         normalized: string,
     *         tokens: array<int, string>,
     *         boost: float
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'original' => $this->original,
            'normalized' => $this->normalized,
            'tokens' => $this->tokens,
            'searchable_types' => $this->searchableTypes,
            'locale' => $this->locale,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'include_scores' => $this->includeScores,
            'candidates' => array_map(
                static fn (QueryCandidate $candidate): array => $candidate->toArray(),
                $this->candidates,
            ),
        ];
    }
}
