<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;

final class SearchRankTierTest extends TestCase
{
    public function test_search_rank_tier_has_fixed_order_and_stable_values(): void
    {
        $expected = [
            'exact_title',
            'title_prefix',
            'title_phrase',
            'title_all_tokens',
            'title_any_token',
            'keywords_phrase',
            'keywords_all_tokens',
            'keywords_any_token',
            'excerpt_phrase',
            'excerpt_all_tokens',
            'excerpt_any_token',
            'content_phrase',
            'content_all_tokens',
            'content_any_token',
        ];

        $this->assertSame($expected, array_map(
            static fn (SearchRankTier $tier): string => $tier->value,
            SearchRankTier::ordered(),
        ));
        $this->assertSame(range(0, 13), array_map(
            static fn (SearchRankTier $tier): int => $tier->precedence(),
            SearchRankTier::ordered(),
        ));
    }
}
