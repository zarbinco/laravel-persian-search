<?php

namespace Zarbinco\PersianSearch\Candidates;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\QueryVariant;

final readonly class SearchCandidate
{
    /** @var list<SearchCandidateMatch> */
    public array $matches;

    /** @param array<int, mixed> $matches */
    public function __construct(
        public SearchDocumentRecord $document,
        public QueryVariant $bestVariant,
        array $matches,
    ) {
        if ($this->document->getKey() === null) {
            throw new InvalidArgumentException('Search candidate document must be persisted.');
        }

        $unique = [];

        foreach ($matches as $match) {
            if (! $match instanceof SearchCandidateMatch) {
                throw new InvalidArgumentException('Search candidate matches must be SearchCandidateMatch values.');
            }

            $unique[$match->fingerprint()] = $match;
        }

        if ($unique === []) {
            throw new InvalidArgumentException('Search candidate must contain match evidence.');
        }

        $this->matches = array_values($unique);
    }

    public static function fromMatch(SearchDocumentRecord $document, SearchCandidateMatch $match): self
    {
        return new self($document, $match->variant, [$match]);
    }

    public function identity(): string
    {
        return (string) $this->document->getKey();
    }

    public function withMatch(SearchCandidateMatch $match): self
    {
        $best = $match->variant->priority > $this->bestVariant->priority
            ? $match->variant
            : $this->bestVariant;

        return new self($this->document, $best, [...$this->matches, $match]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'document_id' => $this->identity(),
            'best_variant' => $this->bestVariant->toArray(),
            'matches' => array_map(static fn (SearchCandidateMatch $match): array => $match->toArray(), $this->matches),
        ];
    }
}
