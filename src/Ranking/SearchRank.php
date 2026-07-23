<?php

namespace Zarbinco\PersianSearch\Ranking;

use InvalidArgumentException;
use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Search\QueryVariant;

final readonly class SearchRank
{
    /** @var list<string> */
    public array $matchedTokens;

    /** @param array<int, mixed> $matchedTokens */
    public function __construct(
        public SearchRankTier $tier,
        public int $tierScore,
        public QueryVariant $variant,
        public SearchDocumentField $field,
        array $matchedTokens,
        public int $matchedTokenCount,
        public int $totalTokenCount,
        public int $coverageBasisPoints,
    ) {
        if ($this->tierScore < 1) {
            throw new InvalidArgumentException('Search rank tier score must be positive.');
        }

        $unique = [];

        foreach ($matchedTokens as $token) {
            if (! is_string($token) || $token === '') {
                throw new InvalidArgumentException('Search rank matched tokens must be non-empty strings.');
            }

            $unique[$token] = $token;
        }

        $this->matchedTokens = array_values($unique);

        if ($this->matchedTokenCount !== count($this->matchedTokens)
            || $this->matchedTokenCount < 0
            || $this->totalTokenCount < 0
            || $this->matchedTokenCount > $this->totalTokenCount) {
            throw new InvalidArgumentException('Search rank token counts are inconsistent.');
        }

        if ($this->coverageBasisPoints < 0 || $this->coverageBasisPoints > 10000) {
            throw new InvalidArgumentException('Search rank coverage must be between 0 and 10000 basis points.');
        }

        if ($this->field !== self::fieldFor($this->tier)) {
            throw new InvalidArgumentException('Search rank field does not correspond to its tier.');
        }
    }

    public function hasFullCoverage(): bool
    {
        return $this->coverageBasisPoints === 10000;
    }

    public static function compare(self $left, self $right): int
    {
        return $left->tier->precedence() <=> $right->tier->precedence()
            ?: $right->tierScore <=> $left->tierScore
            ?: $right->variant->priority <=> $left->variant->priority
            ?: $right->coverageBasisPoints <=> $left->coverageBasisPoints
            ?: $right->matchedTokenCount <=> $left->matchedTokenCount
            ?: strcmp($left->variant->fingerprint, $right->variant->fingerprint);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tier' => $this->tier->value,
            'tier_score' => $this->tierScore,
            'variant' => $this->variant->toArray(),
            'field' => $this->field->value,
            'matched_tokens' => $this->matchedTokens,
            'matched_token_count' => $this->matchedTokenCount,
            'total_token_count' => $this->totalTokenCount,
            'coverage_basis_points' => $this->coverageBasisPoints,
        ];
    }

    private static function fieldFor(SearchRankTier $tier): SearchDocumentField
    {
        return match ($tier) {
            SearchRankTier::ExactTitle,
            SearchRankTier::TitlePrefix,
            SearchRankTier::TitlePhrase,
            SearchRankTier::TitleAllTokens,
            SearchRankTier::TitleAnyToken => SearchDocumentField::Title,
            SearchRankTier::KeywordsPhrase,
            SearchRankTier::KeywordsAllTokens,
            SearchRankTier::KeywordsAnyToken => SearchDocumentField::Keywords,
            SearchRankTier::ExcerptPhrase,
            SearchRankTier::ExcerptAllTokens,
            SearchRankTier::ExcerptAnyToken => SearchDocumentField::Excerpt,
            SearchRankTier::ContentPhrase,
            SearchRankTier::ContentAllTokens,
            SearchRankTier::ContentAnyToken => SearchDocumentField::Content,
        };
    }
}
