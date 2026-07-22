<?php

namespace Zarbinco\PersianSearch\Query;

use Zarbinco\PersianSearch\Search\QueryCandidate;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final readonly class SynonymExpander
{
    public function __construct(
        private SearchTextPipeline $pipeline,
    ) {}

    /**
     * @return list<QueryCandidate>
     */
    public function expand(QueryCandidate $candidate, string $locale, string $source = 'synonym', ?float $boost = null): array
    {
        if (! (bool) config('persian-search.synonyms.enabled', false)) {
            return [];
        }

        if ($candidate->isEmpty()) {
            return [];
        }

        $boost ??= max(0.01, (float) config('persian-search.synonyms.boost', 0.85));
        $maxCandidates = max(1, (int) config('persian-search.synonyms.max_candidates', 20));
        $expanded = [];
        $seen = [
            $candidate->normalized => true,
        ];

        foreach ($this->alternatives($locale) as [$needle, $replacement]) {
            if (count($expanded) >= $maxCandidates) {
                break;
            }

            if (! str_contains($candidate->normalized, $needle)) {
                continue;
            }

            $text = str_replace($needle, $replacement, $candidate->normalized);
            $prepared = $this->pipeline->prepare($text, $locale);
            $normalized = $prepared->normalized;

            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $expanded[] = new QueryCandidate(
                source: $source,
                original: $prepared->raw,
                normalized: $normalized,
                tokens: $prepared->tokens,
                boost: $boost,
            );
        }

        return $expanded;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function alternatives(string $locale): array
    {
        $map = config('persian-search.synonyms.map', []);

        if (! is_array($map)) {
            return [];
        }

        $bidirectional = (bool) config('persian-search.synonyms.bidirectional', true);
        $alternatives = [];
        $seen = [];

        foreach ($map as $key => $values) {
            if (! is_string($key)) {
                continue;
            }

            $normalizedKey = $this->pipeline->prepare($key, $locale)->normalized;

            if ($normalizedKey === '') {
                continue;
            }

            if (is_string($values)) {
                $values = [$values];
            }

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $normalizedValue = $this->pipeline->prepare($value, $locale)->normalized;

                if ($normalizedValue === '' || $normalizedValue === $normalizedKey) {
                    continue;
                }

                $this->addAlternative($alternatives, $seen, $normalizedKey, $normalizedValue);

                if ($bidirectional) {
                    $this->addAlternative($alternatives, $seen, $normalizedValue, $normalizedKey);
                }
            }
        }

        return $alternatives;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $alternatives
     * @param  array<string, bool>  $seen
     */
    private function addAlternative(array &$alternatives, array &$seen, string $needle, string $replacement): void
    {
        $key = $needle."\n".$replacement;

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $alternatives[] = [$needle, $replacement];
    }
}
