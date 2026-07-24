<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchSuggestionConfigurationException;
use Zarbinco\PersianSearch\Search\SearchSuggestionPolicy;

final class SearchSuggestionPolicyTest extends TestCase
{
    public function test_direct_construction_uses_the_documented_bounds(): void
    {
        $policy = new SearchSuggestionPolicy(true, true, 1, 2, 15000);
        $this->assertSame(1, $policy->minimumResults);

        $this->expectException(InvalidSearchSuggestionConfigurationException::class);
        new SearchSuggestionPolicy(true, true, SearchSuggestionPolicy::MAXIMUM_RESULTS + 1, 2, 15000);
    }
}
