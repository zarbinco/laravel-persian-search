<?php

namespace Zarbinco\PersianSearch\Ranking;

use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\QueryVariant;

final readonly class SearchRankMatcher
{
    public function __construct(
        private SearchTokenizer $tokenizer,
        private SearchRankingPolicy $policy,
    ) {}

    public function tokenize(SearchDocumentRecord $document): SearchDocumentTokenMap
    {
        return new SearchDocumentTokenMap(
            $this->fieldTokens($document->normalized_title, $document->locale),
            $this->fieldTokens($document->normalized_keywords, $document->locale),
            $this->fieldTokens($document->normalized_excerpt, $document->locale),
            $this->fieldTokens($document->normalized_content, $document->locale),
        );
    }

    public function match(
        SearchDocumentRecord $document,
        QueryVariant $variant,
        SearchDocumentTokenMap $fields,
    ): ?SearchRank {
        $queryTokens = $this->uniqueTokens($variant->tokens);
        $title = $document->normalized_title;

        if ($title !== null && $title === $variant->query) {
            return $this->rank(
                SearchRankTier::ExactTitle,
                $variant,
                SearchDocumentField::Title,
                $queryTokens,
                count($queryTokens),
                10000,
            );
        }

        if ($queryTokens === []) {
            return null;
        }

        foreach (SearchDocumentField::cases() as $field) {
            $fieldTokens = $fields->forField($field);

            if ($fieldTokens === []) {
                continue;
            }

            if ($field === SearchDocumentField::Title && $this->isPrefix($queryTokens, $fieldTokens)) {
                return $this->rank(SearchRankTier::TitlePrefix, $variant, $field, $queryTokens, count($queryTokens), 10000);
            }

            if ($this->containsPhrase($queryTokens, $fieldTokens)) {
                return $this->rank($this->phraseTier($field), $variant, $field, $queryTokens, count($queryTokens), 10000);
            }

            $matched = array_values(array_filter(
                $queryTokens,
                static fn (string $token): bool => in_array($token, $fieldTokens, true),
            ));

            if (count($matched) === count($queryTokens)) {
                return $this->rank($this->allTokensTier($field), $variant, $field, $matched, count($queryTokens), 10000);
            }

            if ($matched !== []) {
                $coverage = intdiv(count($matched) * 10000, count($queryTokens));

                return $this->rank($this->anyTokenTier($field), $variant, $field, $matched, count($queryTokens), $coverage);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function fieldTokens(?string $value, string $locale): array
    {
        return $value === null || $value === ''
            ? []
            : $this->tokenizer->tokenize($value, $locale);
    }

    /** @param list<string> $tokens
     * @return list<string>
     */
    private function uniqueTokens(array $tokens): array
    {
        $unique = [];

        foreach ($tokens as $token) {
            if ($token !== '' && ! isset($unique[$token])) {
                $unique[$token] = $token;
            }
        }

        return array_values($unique);
    }

    /** @param list<string> $query
     * @param  list<string>  $field
     */
    private function isPrefix(array $query, array $field): bool
    {
        return count($field) >= count($query)
            && array_slice($field, 0, count($query)) === $query;
    }

    /** @param list<string> $query
     * @param  list<string>  $field
     */
    private function containsPhrase(array $query, array $field): bool
    {
        $queryCount = count($query);

        if ($queryCount === 0 || count($field) < $queryCount) {
            return false;
        }

        $lastStart = count($field) - $queryCount;

        for ($offset = 0; $offset <= $lastStart; $offset++) {
            if (array_slice($field, $offset, $queryCount) === $query) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $matchedTokens */
    private function rank(
        SearchRankTier $tier,
        QueryVariant $variant,
        SearchDocumentField $field,
        array $matchedTokens,
        int $totalTokens,
        int $coverage,
    ): SearchRank {
        return new SearchRank(
            $tier,
            $this->policy->scoreFor($tier),
            $variant,
            $field,
            $matchedTokens,
            count($matchedTokens),
            $totalTokens,
            $coverage,
        );
    }

    private function phraseTier(SearchDocumentField $field): SearchRankTier
    {
        return match ($field) {
            SearchDocumentField::Title => SearchRankTier::TitlePhrase,
            SearchDocumentField::Keywords => SearchRankTier::KeywordsPhrase,
            SearchDocumentField::Excerpt => SearchRankTier::ExcerptPhrase,
            SearchDocumentField::Content => SearchRankTier::ContentPhrase,
        };
    }

    private function allTokensTier(SearchDocumentField $field): SearchRankTier
    {
        return match ($field) {
            SearchDocumentField::Title => SearchRankTier::TitleAllTokens,
            SearchDocumentField::Keywords => SearchRankTier::KeywordsAllTokens,
            SearchDocumentField::Excerpt => SearchRankTier::ExcerptAllTokens,
            SearchDocumentField::Content => SearchRankTier::ContentAllTokens,
        };
    }

    private function anyTokenTier(SearchDocumentField $field): SearchRankTier
    {
        return match ($field) {
            SearchDocumentField::Title => SearchRankTier::TitleAnyToken,
            SearchDocumentField::Keywords => SearchRankTier::KeywordsAnyToken,
            SearchDocumentField::Excerpt => SearchRankTier::ExcerptAnyToken,
            SearchDocumentField::Content => SearchRankTier::ContentAnyToken,
        };
    }
}
