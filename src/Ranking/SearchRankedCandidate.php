<?php

namespace Zarbinco\PersianSearch\Ranking;

use RuntimeException;
use Zarbinco\PersianSearch\Candidates\SearchCandidate;

final readonly class SearchRankedCandidate
{
    public int $normalizedTitleLength;

    public function __construct(
        public SearchCandidate $candidate,
        public SearchRank $rank,
    ) {
        $title = $this->candidate->document->normalized_title;

        if ($title === null || $title === '') {
            $this->normalizedTitleLength = PHP_INT_MAX;

            return;
        }

        $count = preg_match_all('/./us', $title);

        if ($count === false) {
            throw new RuntimeException('Normalized search-document title must be valid UTF-8.');
        }

        $this->normalizedTitleLength = $count;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'candidate' => $this->candidate->toArray(),
            'rank' => $this->rank->toArray(),
            'normalized_title_length' => $this->normalizedTitleLength,
        ];
    }
}
