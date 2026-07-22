<?php

namespace Zarbinco\PersianSearch\Ranking;

use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\QueryCandidate;
use Zarbinco\PersianSearch\Search\SearchQuery;

final class BasicRanker
{
    /** @return array<string, mixed> */
    public function score(SearchDocumentRecord $record, SearchQuery $query): array
    {
        return $this->scoreCandidate($record, new QueryCandidate(
            source: 'original',
            original: $query->original,
            normalized: $query->normalized,
            tokens: $query->tokens,
            boost: 1.0,
        ));
    }

    /**
     * @return array{base_score: float, score: float, matched_tokens: list<string>, candidate_source: string, matched_query: string}
     */
    public function scoreCandidate(SearchDocumentRecord $record, QueryCandidate $candidate): array
    {
        $score = 0.0;
        $matched = [];
        $title = (string) ($record->normalized_title ?? '');
        $other = implode(' ', array_filter([
            $record->normalized_excerpt,
            $record->normalized_keywords,
            $record->normalized_content,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));
        $exactPhrase = (float) config('persian-search.ranking.exact_phrase', 100);
        $allTokens = (float) config('persian-search.ranking.all_tokens', 70);
        $anyToken = (float) config('persian-search.ranking.any_token', 20);
        $titleBoost = max(0.0, (float) config('persian-search.ranking.title_boost', 2.0));

        if ($candidate->normalized !== '') {
            if (str_contains($title, $candidate->normalized)) {
                $score += $exactPhrase * $titleBoost;
            }

            if (str_contains($other, $candidate->normalized)) {
                $score += $exactPhrase;
            }
        }

        foreach ($candidate->tokens as $token) {
            if ($token === '') {
                continue;
            }

            $found = false;

            if (str_contains($other, $token)) {
                $score += $anyToken;
                $found = true;
            }

            if (str_contains($title, $token)) {
                $score += $anyToken * $titleBoost;
                $found = true;
            }

            if ($found) {
                $matched[$token] = $token;
            }
        }

        $queryTokens = array_values(array_unique(array_filter($candidate->tokens, static fn (string $token): bool => $token !== '')));

        if ($queryTokens !== [] && count($matched) === count($queryTokens)) {
            $score += $allTokens;
        }

        $score += max(0, $record->priority);

        return [
            'base_score' => $score,
            'score' => $score * $candidate->boost,
            'matched_tokens' => array_values($matched),
            'candidate_source' => $candidate->source,
            'matched_query' => $candidate->normalized,
        ];
    }
}
