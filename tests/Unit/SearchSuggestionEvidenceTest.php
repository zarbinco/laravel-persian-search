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
}
