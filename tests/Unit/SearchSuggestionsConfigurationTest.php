<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchSuggestionConfigurationException;
use Zarbinco\PersianSearch\Search\SearchSuggestionPolicyFactory;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchSuggestionsConfigurationTest extends TestCase
{
    public function test_suggestions_configuration_scalar_is_rejected(): void
    {
        config()->set('persian-search.suggestions', 'invalid');

        $this->expectException(InvalidSearchSuggestionConfigurationException::class);
        app(SearchSuggestionPolicyFactory::class)->make();
    }

    public function test_suggestions_configuration_list_is_rejected(): void
    {
        config()->set('persian-search.suggestions', [true, true, 1, 2, 15000]);

        $this->expectException(InvalidSearchSuggestionConfigurationException::class);
        app(SearchSuggestionPolicyFactory::class)->make();
    }

    public function test_suggestions_configuration_empty_map_uses_defaults(): void
    {
        config()->set('persian-search.suggestions', []);

        $this->assertSame([
            'enabled' => true,
            'require_exact_window' => true,
            'minimum_results' => 1,
            'minimum_result_gain' => 2,
            'minimum_ratio_basis_points' => 15000,
        ], app(SearchSuggestionPolicyFactory::class)->make()->toArray());
    }

    public function test_suggestions_configuration_partial_map_uses_supplied_and_default_values(): void
    {
        config()->set('persian-search.suggestions', [
            'enabled' => false,
            'minimum_result_gain' => 3,
        ]);

        $this->assertSame([
            'enabled' => false,
            'require_exact_window' => true,
            'minimum_results' => 1,
            'minimum_result_gain' => 3,
            'minimum_ratio_basis_points' => 15000,
        ], app(SearchSuggestionPolicyFactory::class)->make()->toArray());
    }
}
