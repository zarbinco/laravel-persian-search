<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Search\SearchResultTruncationReason;
use Zarbinco\PersianSearch\Search\SearchResultWindow;

final class SearchResultWindowTest extends TestCase
{
    public function test_exact_and_truncated_metadata_are_derived_from_the_ranked_candidates(): void
    {
        $exact = new SearchResultWindow([], [], 500);
        $truncated = new SearchResultWindow([], [
            SearchResultTruncationReason::UnexecutedVariants,
            SearchResultTruncationReason::PerVariantLimit,
        ], 10);

        $this->assertSame(0, $exact->knownTotal);
        $this->assertTrue($exact->totalIsExact);
        $this->assertFalse($exact->isTruncated);
        $this->assertFalse($truncated->totalIsExact);
        $this->assertTrue($truncated->isTruncated);
        $this->assertSame([
            SearchResultTruncationReason::PerVariantLimit,
            SearchResultTruncationReason::UnexecutedVariants,
        ], $truncated->truncationReasons);
    }

    public function test_candidate_limit_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SearchResultWindow([], [], 0);
    }
}
