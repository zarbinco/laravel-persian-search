<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Ranking\SearchRankingPolicy;
use Zarbinco\PersianSearch\Ranking\SearchRankingPolicyFactory;
use Zarbinco\PersianSearch\Ranking\SearchRankMatcher;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Text\UnicodeSearchTokenizer;

final class SearchRankMatcherTest extends TestCase
{
    /**
     * @param  list<string>  $queryTokens
     * @param  array<string, string|null>  $fields
     */
    #[DataProvider('semanticTiers')]
    public function test_search_rank_matcher_selects_the_first_semantic_tier(
        string $query,
        array $queryTokens,
        array $fields,
        SearchRankTier $expected,
    ): void {
        $matcher = $this->matcher();
        $record = $this->record($fields);
        $rank = $matcher->match($record, $this->variant($query, $queryTokens), $matcher->tokenize($record));

        $this->assertNotNull($rank);
        $this->assertSame($expected, $rank->tier);
        $this->assertSame(SearchRankingPolicyFactory::defaults()[$expected->value], $rank->tierScore);
    }

    /** @return array<string, array{string, list<string>, array<string, string|null>, SearchRankTier}> */
    public static function semanticTiers(): array
    {
        return [
            'exact_title' => ['orange juice', ['orange', 'juice'], ['normalized_title' => 'orange juice'], SearchRankTier::ExactTitle],
            'title_prefix' => ['orange juice', ['orange', 'juice'], ['normalized_title' => 'orange juice drink'], SearchRankTier::TitlePrefix],
            'title_phrase' => ['orange juice', ['orange', 'juice'], ['normalized_title' => 'fresh orange juice drink'], SearchRankTier::TitlePhrase],
            'title_all_tokens' => ['orange juice', ['orange', 'juice'], ['normalized_title' => 'juice from orange'], SearchRankTier::TitleAllTokens],
            'title_any_token' => ['orange juice', ['orange', 'juice'], ['normalized_title' => 'fresh orange'], SearchRankTier::TitleAnyToken],
            'keywords_phrase' => ['orange juice', ['orange', 'juice'], ['normalized_keywords' => 'fresh orange juice drink'], SearchRankTier::KeywordsPhrase],
            'keywords_all_tokens' => ['orange juice', ['orange', 'juice'], ['normalized_keywords' => 'juice from orange'], SearchRankTier::KeywordsAllTokens],
            'keywords_any_token' => ['orange juice', ['orange', 'juice'], ['normalized_keywords' => 'fresh orange'], SearchRankTier::KeywordsAnyToken],
            'excerpt_phrase' => ['orange juice', ['orange', 'juice'], ['normalized_excerpt' => 'fresh orange juice drink'], SearchRankTier::ExcerptPhrase],
            'excerpt_all_tokens' => ['orange juice', ['orange', 'juice'], ['normalized_excerpt' => 'juice from orange'], SearchRankTier::ExcerptAllTokens],
            'excerpt_any_token' => ['orange juice', ['orange', 'juice'], ['normalized_excerpt' => 'fresh orange'], SearchRankTier::ExcerptAnyToken],
            'content_phrase' => ['orange juice', ['orange', 'juice'], ['normalized_content' => 'fresh orange juice drink'], SearchRankTier::ContentPhrase],
            'content_all_tokens' => ['orange juice', ['orange', 'juice'], ['normalized_content' => 'juice from orange'], SearchRankTier::ContentAllTokens],
            'content_any_token' => ['orange juice', ['orange', 'juice'], ['normalized_content' => 'fresh orange'], SearchRankTier::ContentAnyToken],
            'Persian phrase' => ['آب پرتقال', ['آب', 'پرتقال'], ['normalized_content' => 'نوشیدنی آب پرتقال تازه'], SearchRankTier::ContentPhrase],
            'mixed phrase' => ['پرتقال juice', ['پرتقال', 'juice'], ['normalized_excerpt' => 'fresh پرتقال juice'], SearchRankTier::ExcerptPhrase],
            'numeric phrase' => ['orange 100', ['orange', '100'], ['normalized_keywords' => 'fresh orange 100 percent'], SearchRankTier::KeywordsPhrase],
        ];
    }

    public function test_title_prefix_and_phrase_are_token_aware_not_substring_based(): void
    {
        $matcher = $this->matcher();
        $record = $this->record(['normalized_title' => 'گلدان زیبا', 'normalized_content' => 'یک گل زیبا']);
        $rank = $matcher->match($record, $this->variant('گل', ['گل']), $matcher->tokenize($record));

        $this->assertNotNull($rank);
        $this->assertSame(SearchRankTier::ContentPhrase, $rank->tier);

        $wrongOrder = $this->record(['normalized_title' => 'juice orange drink']);
        $wrongRank = $matcher->match(
            $wrongOrder,
            $this->variant('orange juice', ['orange', 'juice']),
            $matcher->tokenize($wrongOrder),
        );
        $this->assertNotNull($wrongRank);
        $this->assertSame(SearchRankTier::TitleAllTokens, $wrongRank->tier);

        $nonContiguous = $this->record(['normalized_title' => 'fresh orange natural juice']);
        $nonContiguousRank = $matcher->match(
            $nonContiguous,
            $this->variant('orange juice', ['orange', 'juice']),
            $matcher->tokenize($nonContiguous),
        );
        $this->assertNotNull($nonContiguousRank);
        $this->assertSame(SearchRankTier::TitleAllTokens, $nonContiguousRank->tier);
    }

    public function test_any_token_coverage_uses_unique_tokens_and_integer_basis_points(): void
    {
        $matcher = $this->matcher();
        $record = $this->record(['normalized_content' => 'orange orange fruit']);
        $rank = $matcher->match(
            $record,
            $this->variant('orange fruit juice', ['orange', 'orange', 'fruit', 'juice']),
            $matcher->tokenize($record),
        );

        $this->assertNotNull($rank);
        $this->assertSame(SearchRankTier::ContentAnyToken, $rank->tier);
        $this->assertSame(['orange', 'fruit'], $rank->matchedTokens);
        $this->assertSame(2, $rank->matchedTokenCount);
        $this->assertSame(3, $rank->totalTokenCount);
        $this->assertSame(6666, $rank->coverageBasisPoints);
        $this->assertFalse($rank->hasFullCoverage());
        $this->assertSame(6666, $rank->toArray()['coverage_basis_points']);
    }

    public function test_zero_token_matches_produce_no_rank_and_null_fields_are_safe(): void
    {
        $matcher = $this->matcher();
        $record = $this->record([
            'normalized_title' => null,
            'normalized_keywords' => null,
            'normalized_excerpt' => '',
            'normalized_content' => 'orange',
        ]);

        $this->assertNull($matcher->match(
            $record,
            $this->variant('ran', ['ran']),
            $matcher->tokenize($record),
        ));
    }

    private function matcher(): SearchRankMatcher
    {
        return new SearchRankMatcher(
            new UnicodeSearchTokenizer,
            new SearchRankingPolicy(SearchRankingPolicyFactory::defaults()),
        );
    }

    /** @param array<string, string|null> $fields */
    private function record(array $fields): SearchDocumentRecord
    {
        $record = new SearchDocumentRecord;
        $record->setRawAttributes([
            'id' => 1,
            'source_key' => 'page:1',
            'partition' => 'public',
            'locale' => 'en',
            'priority' => 0,
            'normalized_title' => null,
            'normalized_keywords' => null,
            'normalized_excerpt' => null,
            'normalized_content' => null,
            ...$fields,
        ], true);

        return $record;
    }

    /** @param list<string> $tokens */
    private function variant(string $query, array $tokens): QueryVariant
    {
        return new QueryVariant($query, 'en', $tokens, QueryVariantSource::Original, 1000, hash('sha256', $query));
    }
}
