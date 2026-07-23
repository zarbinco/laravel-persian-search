<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Search\SearchPageMetadata;
use Zarbinco\PersianSearch\Search\SearchResultTruncationReason;

final class SearchPageTest extends TestCase
{
    public function test_exact_page_metadata_has_a_last_page_and_positions(): void
    {
        $metadata = new SearchPageMetadata(2, 2, 2, 5, true, false, 500);

        $this->assertSame(3, $metadata->lastPage);
        $this->assertTrue($metadata->hasPreviousPage);
        $this->assertTrue($metadata->hasNextPage);
        $this->assertSame(3, $metadata->from);
        $this->assertSame(4, $metadata->to);
    }

    public function test_truncated_page_metadata_has_no_exact_last_page(): void
    {
        $metadata = new SearchPageMetadata(
            1,
            10,
            10,
            10,
            false,
            true,
            10,
            [SearchResultTruncationReason::GlobalCandidateLimit],
        );

        $this->assertNull($metadata->lastPage);
        $this->assertTrue($metadata->hasNextPage);
    }
}
