<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Tests\Unit;

use Zarbinco\PersianSearch\Contextual\CandidateResultCount;
use Zarbinco\PersianSearch\Contextual\ContextualCorrectionPolicy;
use Zarbinco\PersianSearch\Exceptions\InvalidSpellingConfigurationException;
use Zarbinco\PersianSearch\Tests\TestCase;

final class ContextualCorrectionPolicyTest extends TestCase
{
    public function test_published_defaults_are_disabled_bounded_and_config_cache_safe(): void
    {
        $config = config('persian-search.contextual');
        $this->assertIsArray($config);
        $policy = ContextualCorrectionPolicy::fromArray($config);

        $this->assertFalse($policy->enabled);
        $this->assertTrue($policy->ngramsEnabled);
        $this->assertTrue($policy->resultCountsEnabled);
        $this->assertFalse($policy->autoApplyRecommendationEnabled);
        $this->assertFalse($policy->evaluateOnPreview);
        $this->assertSame(3, $policy->maximumDirectResults);
        $this->assertSame(5, $policy->maximumCandidatesPerQuery);
        $this->assertSame(3, $policy->maximumResultCountCandidates);
        $this->assertSame(20, $policy->maximumContextLookups);
        $this->assertSame(3, $policy->maximumTransformationDepth);
        foreach ([
            $config['enabled'],
            $config['ngrams_enabled'],
            $config['result_counts_enabled'],
            $config['auto_apply_recommendation_enabled'],
        ] as $flag) {
            $this->assertIsBool($flag);
        }
    }

    public function test_auto_apply_threshold_cannot_be_weaker_than_suggestion_threshold(): void
    {
        $config = config('persian-search.contextual');
        $this->assertIsArray($config);
        $config['decision']['minimum_confidence_basis_points'] = 9000;
        $config['decision']['auto_apply_minimum_confidence_basis_points'] = 8999;

        $this->expectException(InvalidSpellingConfigurationException::class);
        ContextualCorrectionPolicy::fromArray($config);
    }

    public function test_result_count_candidate_limit_cannot_exceed_generation_limit(): void
    {
        $config = config('persian-search.contextual');
        $this->assertIsArray($config);
        $config['limits']['maximum_candidates_per_query'] = 2;
        $config['limits']['maximum_result_count_candidates'] = 3;

        $this->expectException(InvalidSpellingConfigurationException::class);
        ContextualCorrectionPolicy::fromArray($config);
    }

    public function test_contextual_tables_must_be_distinct(): void
    {
        $config = config('persian-search.contextual');
        $this->assertIsArray($config);
        $config['builds_table'] = $config['ngrams_table'];

        $this->expectException(InvalidSpellingConfigurationException::class);
        ContextualCorrectionPolicy::fromArray($config);
    }

    public function test_trigger_policy_uses_direct_count_and_preview_flags(): void
    {
        $config = config('persian-search.contextual');
        $this->assertIsArray($config);
        $config['enabled'] = true;
        $policy = ContextualCorrectionPolicy::fromArray($config);

        $this->assertTrue($policy->shouldEvaluate(new CandidateResultCount(0, false, 0), false));
        $this->assertTrue($policy->shouldEvaluate(new CandidateResultCount(3, false, 3), false));
        $this->assertFalse($policy->shouldEvaluate(new CandidateResultCount(4, false, 4), false));
        $this->assertFalse($policy->shouldEvaluate(new CandidateResultCount(0, false, 0), true));

        $config['result_counts_enabled'] = false;
        $withoutResultCounts = ContextualCorrectionPolicy::fromArray($config);
        $this->assertTrue($withoutResultCounts->shouldEvaluate(
            new CandidateResultCount(0, false, 0),
            false,
        ));
    }
}
