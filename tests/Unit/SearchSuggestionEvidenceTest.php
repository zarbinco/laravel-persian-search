<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Ranking\SearchRankTier;
use Zarbinco\PersianSearch\Search\SearchSuggestionEvidence;
use Zarbinco\PersianSearch\Search\SearchSuggestionReason;

final class SearchSuggestionEvidenceTest extends TestCase
{
    public function test_reason_semantics_and_serialization_are_enforced(): void
    {
        $valid = new SearchSuggestionEvidence(
            1,
            2,
            1,
            20000,
            SearchRankTier::ContentAnyToken,
            SearchRankTier::ContentAnyToken,
            true,
            SearchSuggestionReason::MaterialResultGain,
        );

        $this->assertSame('material_result_gain', $valid->toArray()['reason']);
        $this->assertSame(20000, $valid->toArray()['ratio_basis_points']);

        $this->expectException(InvalidArgumentException::class);
        new SearchSuggestionEvidence(
            1,
            1,
            0,
            10000,
            SearchRankTier::ExactTitle,
            SearchRankTier::ExactTitle,
            true,
            SearchSuggestionReason::BetterSemanticTier,
        );
    }

    public function test_material_result_gain_rejects_a_strictly_better_suggested_tier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchSuggestionEvidence(
            1,
            2,
            1,
            20000,
            SearchRankTier::ContentAnyToken,
            SearchRankTier::ExactTitle,
            true,
            SearchSuggestionReason::MaterialResultGain,
        );
    }

    public function test_material_result_gain_accepts_equal_and_weaker_tiers(): void
    {
        $equal = new SearchSuggestionEvidence(
            1,
            2,
            1,
            20000,
            SearchRankTier::TitlePhrase,
            SearchRankTier::TitlePhrase,
            true,
            SearchSuggestionReason::MaterialResultGain,
        );
        $weaker = new SearchSuggestionEvidence(
            1,
            2,
            1,
            20000,
            SearchRankTier::ExactTitle,
            SearchRankTier::ContentAnyToken,
            true,
            SearchSuggestionReason::MaterialResultGain,
        );
        $better = new SearchSuggestionEvidence(
            1,
            1,
            0,
            10000,
            SearchRankTier::ContentAnyToken,
            SearchRankTier::ExactTitle,
            true,
            SearchSuggestionReason::BetterSemanticTier,
        );

        $this->assertSame('material_result_gain', $equal->reason->value);
        $this->assertSame('material_result_gain', $weaker->reason->value);
        $this->assertSame('better_semantic_tier', $better->reason->value);
        $this->assertSame($equal->toArray(), $equal->jsonSerialize());
    }
}
