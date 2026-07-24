<?php

namespace Zarbinco\PersianSearch\Search;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SearchResultWindow implements JsonSerializable
{
    /** @var list<SearchPresentedCandidate> */
    public array $candidates;

    /** @var list<SearchResultTruncationReason> */
    public array $truncationReasons;

    public int $knownTotal;

    public bool $totalIsExact;

    public bool $isTruncated;

    /**
     * @param  array<int, mixed>  $candidates
     * @param  array<int, mixed>  $truncationReasons
     */
    public function __construct(array $candidates, array $truncationReasons, public int $candidateLimit)
    {
        if ($this->candidateLimit < 1) {
            throw new InvalidArgumentException('Search result candidate limit must be positive.');
        }

        $unique = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof SearchPresentedCandidate) {
                throw new InvalidArgumentException('Search result window candidates must be presented candidates.');
            }

            $identity = $candidate->identity();

            if (isset($unique[$identity])) {
                throw new InvalidArgumentException('Search result window candidate identities must be unique.');
            }

            $unique[$identity] = $candidate;
        }

        if (count($unique) > $this->candidateLimit) {
            throw new InvalidArgumentException('Search result window cannot exceed its candidate limit.');
        }

        $this->candidates = array_values($unique);
        $this->truncationReasons = SearchResultTruncationReason::normalize($truncationReasons);
        $this->knownTotal = count($this->candidates);
        $this->isTruncated = $this->truncationReasons !== [];
        $this->totalIsExact = ! $this->isTruncated;
    }

    public function knownTotal(): int
    {
        return $this->knownTotal;
    }

    public function isTruncated(): bool
    {
        return $this->isTruncated;
    }

    public function totalIsExact(): bool
    {
        return $this->totalIsExact;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'known_total' => $this->knownTotal(),
            'total_is_exact' => $this->totalIsExact(),
            'is_truncated' => $this->isTruncated(),
            'truncation_reasons' => array_map(
                static fn (SearchResultTruncationReason $reason): string => $reason->value,
                $this->truncationReasons,
            ),
            'candidate_limit' => $this->candidateLimit,
            'candidates' => array_map(
                static fn (SearchPresentedCandidate $candidate): array => $candidate->toArray(),
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
