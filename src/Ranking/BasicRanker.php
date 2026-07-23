<?php

namespace Zarbinco\PersianSearch\Ranking;

use LogicException;
use Zarbinco\PersianSearch\Candidates\SearchCandidate;
use Zarbinco\PersianSearch\Candidates\SearchCandidateCollection;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\SearchQuery;

final class BasicRanker
{
    /**
     * @return list<array{candidate: SearchCandidate, score: float, matched_tokens: list<string>}>
     */
    public function rank(SearchCandidateCollection $candidates): array
    {
        $ranked = [];

        foreach ($candidates as $candidate) {
            $score = $this->scoreVariant($candidate->document, $candidate->bestVariant);

            if ($score['score'] > 0) {
                $ranked[] = [
                    'candidate' => $candidate,
                    'score' => $score['score'],
                    'matched_tokens' => $score['matched_tokens'],
                ];
            }
        }

        usort($ranked, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];

            if ($score !== 0) {
                return $score;
            }

            $priority = $right['candidate']->document->priority <=> $left['candidate']->document->priority;

            if ($priority !== 0) {
                return $priority;
            }

            return [$left['candidate']->document->source_key, $left['candidate']->document->locale]
                <=> [$right['candidate']->document->source_key, $right['candidate']->document->locale];
        });

        return $ranked;
    }

    /** @return array{base_score: float, score: float, matched_tokens: list<string>} */
    public function score(SearchDocumentRecord $record, SearchQuery $query): array
    {
        $original = $query->variants()->original();

        if ($original === null) {
            throw new LogicException('A searchable query must contain an original variant.');
        }

        return $this->scoreVariant($record, $original);
    }

    /** @return array{base_score: float, score: float, matched_tokens: list<string>} */
    public function scoreVariant(SearchDocumentRecord $record, QueryVariant $variant): array
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

        if (str_contains($title, $variant->query)) {
            $score += $exactPhrase * $titleBoost;
        }

        if (str_contains($other, $variant->query)) {
            $score += $exactPhrase;
        }

        foreach ($variant->tokens as $token) {
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

        if ($variant->tokens !== [] && count($matched) === count(array_unique($variant->tokens))) {
            $score += $allTokens;
        }

        $score += max(0, $record->priority);

        return ['base_score' => $score, 'score' => $score, 'matched_tokens' => array_values($matched)];
    }
}
