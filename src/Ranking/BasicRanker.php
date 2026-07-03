<?php

namespace Zarbinco\PersianSearch\Ranking;

use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\SearchQuery;

final class BasicRanker
{
    /**
     * @return array{score: float, matched_tokens: array<int, string>}
     */
    public function score(SearchDocumentRecord $record, SearchQuery $query): array
    {
        if ($query->isEmpty()) {
            return [
                'score' => 0.0,
                'matched_tokens' => [],
            ];
        }

        $score = 0.0;
        $matched = [];
        $title = (string) ($record->title ?? '');
        $content = (string) $record->content;
        $documentTokens = $this->stringList($record->tokens ?? []);
        $fields = is_array($record->fields) ? $record->fields : [];
        $exactPhrase = (float) config('persian-search.ranking.exact_phrase', 100);
        $allTokens = (float) config('persian-search.ranking.all_tokens', 70);
        $anyToken = (float) config('persian-search.ranking.any_token', 20);
        $titleBoost = (float) config('persian-search.ranking.title_boost', 2.0);
        $fieldWeightMultiplier = (float) config('persian-search.ranking.field_weight_multiplier', 1.0);

        if ($query->normalized !== '') {
            if ($this->contains($title, $query->normalized)) {
                $score += $exactPhrase * $titleBoost;
            }

            if ($this->contains($content, $query->normalized)) {
                $score += $exactPhrase;
            }
        }

        $presentTokens = [];

        foreach ($query->tokens as $token) {
            if ($token === '') {
                continue;
            }

            $tokenMatched = in_array($token, $documentTokens, true) || $this->contains($content, $token);

            if ($tokenMatched) {
                $score += $anyToken;
                $presentTokens[] = $token;
                $matched[$token] = $token;
            }

            if ($this->contains($title, $token)) {
                $score += $anyToken * max(0.0, $titleBoost);
                $presentTokens[] = $token;
                $matched[$token] = $token;
            }

            foreach ($fields as $field) {
                if ($this->fieldMatchesToken($field, $token)) {
                    $score += $anyToken * $this->fieldWeight($field) * $fieldWeightMultiplier;
                    $presentTokens[] = $token;
                    $matched[$token] = $token;
                }
            }
        }

        $queryTokens = array_values(array_filter(
            $query->tokens,
            static fn (string $token): bool => $token !== '',
        ));

        if ($queryTokens !== [] && count(array_unique($presentTokens)) === count(array_unique($queryTokens))) {
            $score += $allTokens;
        }

        return [
            'score' => $score,
            'matched_tokens' => array_values($matched),
        ];
    }

    private function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains($haystack, $needle);
    }

    /**
     * @param  array<mixed>  $field
     */
    private function fieldMatchesToken(array $field, string $token): bool
    {
        $value = $field['value'] ?? '';
        $tokens = $field['tokens'] ?? [];

        return (is_string($value) && $this->contains($value, $token))
            || in_array($token, $this->stringList(is_array($tokens) ? $tokens : []), true);
    }

    /**
     * @param  array<mixed>  $field
     */
    private function fieldWeight(array $field): float
    {
        $weight = $field['weight'] ?? 1;

        if (is_int($weight) || is_float($weight)) {
            return max(0.0, (float) $weight);
        }

        return 1.0;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<int, string>
     */
    private function stringList(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
