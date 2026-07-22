<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Search\ProcessedSearchQuery;
use Zarbinco\PersianSearch\Search\SearchQueryStatus;
use Zarbinco\PersianSearch\Tests\TestCase;

final class ProcessedSearchQueryTest extends TestCase
{
    public function test_facade_process_query_returns_an_immutable_serializable_dto(): void
    {
        $processed = PersianSearch::processQuery('  ORANGE juice  ', 'en');

        $this->assertInstanceOf(ProcessedSearchQuery::class, $processed);
        $this->assertSame(SearchQueryStatus::Ready, $processed->status);
        $this->assertSame('  ORANGE juice  ', $processed->rawQuery);
        $this->assertSame('ORANGE juice', $processed->sanitizedQuery);
        $this->assertSame('orange juice', $processed->normalizedQuery);
        $this->assertSame(['orange', 'juice'], $processed->tokens);
        $this->assertSame(['orange', 'juice'], $processed->searchableTokens);
        $this->assertTrue($processed->isSearchable());
        $this->assertSame('ready', $processed->toArray()['status']);
        $this->assertSame($processed->toArray(), PersianSearch::processQuery('  ORANGE juice  ', 'en')->toArray());
    }
}
