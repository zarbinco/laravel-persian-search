<?php

namespace Zarbinco\PersianSearch\Query;

use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Search\QueryCandidate;
use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final readonly class DefaultQueryExpander implements QueryExpander
{
    public function __construct(
        private SearchTextPipeline $pipeline,
        private KeyboardLayoutCorrector $keyboard,
        private SynonymExpander $synonyms,
    ) {}

    /**
     * @return list<QueryCandidate>
     */
    public function expand(SearchQuery $query): array
    {
        $maxCandidates = max(1, (int) config('persian-search.query_expansion.max_candidates', 25));
        $candidates = [];
        $seen = [];

        $original = $this->candidate(
            source: 'original',
            original: $query->original,
            normalized: $query->normalized,
            tokens: $query->tokens,
            boost: max(0.01, (float) config('persian-search.query_expansion.original_boost', 1.0)),
        );

        if ($original !== null) {
            $this->addCandidate($candidates, $seen, $original, $maxCandidates);
        }

        if (! (bool) config('persian-search.query_expansion.enabled', true)) {
            return $candidates;
        }

        $keyboard = null;
        $corrected = $this->keyboard->correct($query->original);

        if ($corrected !== null) {
            $keyboard = $this->candidateFromText(
                source: 'keyboard',
                text: $corrected,
                locale: 'fa',
                boost: max(0.01, (float) config('persian-search.query_expansion.keyboard_boost', 0.95)),
            );

            if ($keyboard !== null) {
                $this->addCandidate($candidates, $seen, $keyboard, $maxCandidates);
            }
        }

        if ($original !== null) {
            foreach ($this->synonyms->expand($original, $query->textLocale) as $candidate) {
                $this->addCandidate($candidates, $seen, $candidate, $maxCandidates);
            }
        }

        if ($keyboard !== null) {
            $boost = max(0.01, (float) config('persian-search.query_expansion.keyboard_synonym_boost', 0.80));

            foreach ($this->synonyms->expand($keyboard, 'fa', 'keyboard_synonym', $boost) as $candidate) {
                $this->addCandidate($candidates, $seen, $candidate, $maxCandidates);
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function candidate(string $source, string $original, string $normalized, array $tokens, float $boost): ?QueryCandidate
    {
        $candidate = new QueryCandidate(
            source: $source,
            original: $original,
            normalized: $normalized,
            tokens: $tokens,
            boost: $boost,
        );

        return $candidate->isEmpty() ? null : $candidate;
    }

    private function candidateFromText(string $source, string $text, string $locale, float $boost): ?QueryCandidate
    {
        $prepared = $this->pipeline->prepare($text, $locale);

        return $this->candidate(
            source: $source,
            original: $prepared->raw,
            normalized: $prepared->normalized,
            tokens: $prepared->tokens,
            boost: $boost,
        );
    }

    /**
     * @param  list<QueryCandidate>  $candidates
     * @param  array<string, bool>  $seen
     */
    private function addCandidate(array &$candidates, array &$seen, QueryCandidate $candidate, int $maxCandidates): void
    {
        if (count($candidates) >= $maxCandidates || $candidate->isEmpty()) {
            return;
        }

        if (isset($seen[$candidate->normalized])) {
            return;
        }

        $seen[$candidate->normalized] = true;
        $candidates[] = $candidate;
    }
}
