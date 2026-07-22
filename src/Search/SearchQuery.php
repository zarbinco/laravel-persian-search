<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;

final readonly class SearchQuery
{
    /**
     * @param  array<int, string>  $tokens
     * @param  list<string>  $sourceTypes
     * @param  list<QueryCandidate>  $candidates
     */
    public function __construct(
        public string $original,
        public string $normalized,
        public array $tokens,
        public array $sourceTypes,
        public ?string $locale,
        public string $textLocale,
        public string $partition,
        public int $limit,
        public int $offset,
        public bool $includeScores,
        private array $candidates = [],
    ) {}

    public function hasSourceTypes(): bool
    {
        return $this->sourceTypes !== [];
    }

    public function isEmpty(): bool
    {
        foreach ($this->candidates as $candidate) {
            if (! $candidate->isEmpty()) {
                return false;
            }
        }

        return $this->candidates !== [] || (trim($this->normalized) === '' && $this->tokens === []);
    }

    /** @return list<QueryCandidate> */
    public function candidates(): array
    {
        return $this->candidates;
    }

    public function hasCandidates(): bool
    {
        return $this->candidates !== [];
    }

    /** @param  array<int, mixed>  $candidates */
    public function withCandidates(array $candidates): self
    {
        foreach ($candidates as $candidate) {
            if (! $candidate instanceof QueryCandidate) {
                throw new InvalidArgumentException('Search query candidates must be QueryCandidate instances.');
            }
        }

        /** @var list<QueryCandidate> $candidates */
        return new self(
            original: $this->original,
            normalized: $this->normalized,
            tokens: $this->tokens,
            sourceTypes: $this->sourceTypes,
            locale: $this->locale,
            textLocale: $this->textLocale,
            partition: $this->partition,
            limit: $this->limit,
            offset: $this->offset,
            includeScores: $this->includeScores,
            candidates: $candidates,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'original' => $this->original,
            'normalized' => $this->normalized,
            'tokens' => $this->tokens,
            'source_types' => $this->sourceTypes,
            'locale' => $this->locale,
            'text_locale' => $this->textLocale,
            'partition' => $this->partition,
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
