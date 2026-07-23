<?php

namespace Zarbinco\PersianSearch\Ranking;

enum SearchRankTier: string
{
    case ExactTitle = 'exact_title';
    case TitlePrefix = 'title_prefix';
    case TitlePhrase = 'title_phrase';
    case TitleAllTokens = 'title_all_tokens';
    case TitleAnyToken = 'title_any_token';
    case KeywordsPhrase = 'keywords_phrase';
    case KeywordsAllTokens = 'keywords_all_tokens';
    case KeywordsAnyToken = 'keywords_any_token';
    case ExcerptPhrase = 'excerpt_phrase';
    case ExcerptAllTokens = 'excerpt_all_tokens';
    case ExcerptAnyToken = 'excerpt_any_token';
    case ContentPhrase = 'content_phrase';
    case ContentAllTokens = 'content_all_tokens';
    case ContentAnyToken = 'content_any_token';

    /** @return list<self> */
    public static function ordered(): array
    {
        return self::cases();
    }

    public function precedence(): int
    {
        foreach (self::ordered() as $position => $tier) {
            if ($tier === $this) {
                return $position;
            }
        }

        return PHP_INT_MAX;
    }
}
