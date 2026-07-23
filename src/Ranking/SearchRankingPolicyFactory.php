<?php

namespace Zarbinco\PersianSearch\Ranking;

use Illuminate\Contracts\Config\Repository;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchRankingConfigurationException;

final readonly class SearchRankingPolicyFactory
{
    public function __construct(private Repository $config) {}

    public function make(): SearchRankingPolicy
    {
        $scores = $this->config->get('persian-search.ranking.tier_scores', self::defaults());

        if (! is_array($scores)) {
            throw InvalidSearchRankingConfigurationException::forValue(
                'persian-search.ranking.tier_scores',
                $scores,
                'must be an array',
            );
        }

        return new SearchRankingPolicy($scores);
    }

    /** @return array<string, int> */
    public static function defaults(): array
    {
        return [
            'exact_title' => 1400,
            'title_prefix' => 1300,
            'title_phrase' => 1200,
            'title_all_tokens' => 1100,
            'title_any_token' => 1000,
            'keywords_phrase' => 900,
            'keywords_all_tokens' => 850,
            'keywords_any_token' => 800,
            'excerpt_phrase' => 700,
            'excerpt_all_tokens' => 650,
            'excerpt_any_token' => 600,
            'content_phrase' => 500,
            'content_all_tokens' => 450,
            'content_any_token' => 400,
        ];
    }
}
