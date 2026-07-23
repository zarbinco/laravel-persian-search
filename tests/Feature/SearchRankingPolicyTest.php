<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchRankingConfigurationException;
use Zarbinco\PersianSearch\Ranking\SearchRankingPolicy;
use Zarbinco\PersianSearch\Ranking\SearchRankingPolicyFactory;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchRankingPolicyTest extends TestCase
{
    public function test_search_ranking_policy_loads_every_default_score_deterministically(): void
    {
        $policy = app(SearchRankingPolicyFactory::class)->make();
        $defaults = SearchRankingPolicyFactory::defaults();

        $this->assertSame($defaults, $policy->tierScores);
        $this->assertSame(['tier_scores' => $defaults], $policy->toArray());

        foreach (SearchRankTier::ordered() as $tier) {
            $this->assertSame($defaults[$tier->value], $policy->scoreFor($tier));
        }
    }

    /** @param array<string, mixed>|int|string $scores */
    #[DataProvider('invalidScores')]
    public function test_search_ranking_policy_rejects_invalid_tier_scores(array|int|string $scores): void
    {
        config()->set('persian-search.ranking.tier_scores', $scores);
        $this->expectException(InvalidSearchRankingConfigurationException::class);
        app(SearchRankingPolicyFactory::class)->make();
    }

    /** @return array<string, array{array<string, mixed>|int|string}> */
    public static function invalidScores(): array
    {
        $defaults = SearchRankingPolicyFactory::defaults();
        $missing = $defaults;
        unset($missing['content_any_token']);
        $unknown = [...$defaults, 'unknown' => 1];
        $string = $defaults;
        $string['exact_title'] = '1400';
        $zero = $defaults;
        $zero['content_any_token'] = 0;
        $negative = $defaults;
        $negative['content_any_token'] = -1;
        $duplicate = $defaults;
        $duplicate['title_prefix'] = 1400;
        $ascending = $defaults;
        $ascending['title_prefix'] = 1500;

        return [
            'not an array' => ['invalid'],
            'integer is not an array' => [1],
            'missing tier' => [$missing],
            'unknown tier' => [$unknown],
            'non-integer' => [$string],
            'zero' => [$zero],
            'negative' => [$negative],
            'duplicate' => [$duplicate],
            'non-descending' => [$ascending],
        ];
    }

    public function test_invalid_configuration_exception_contains_keys_but_no_search_text(): void
    {
        $scores = SearchRankingPolicyFactory::defaults();
        $scores['exact_title'] = 0;

        try {
            new SearchRankingPolicy($scores);
            $this->fail('Invalid ranking configuration was accepted.');
        } catch (InvalidSearchRankingConfigurationException $exception) {
            $this->assertStringContainsString('exact_title', $exception->getMessage());
            $this->assertStringNotContainsString('secret query', $exception->getMessage());
            $this->assertStringNotContainsString('document body', $exception->getMessage());
        }
    }
}
