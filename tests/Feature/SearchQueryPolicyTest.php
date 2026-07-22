<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchQueryConfigurationException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Search\MaximumLengthPolicy;
use Zarbinco\PersianSearch\Search\SearchQueryPolicy;
use Zarbinco\PersianSearch\Search\SearchQueryProcessor;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchQueryPolicyTest extends TestCase
{
    public function test_default_policy_values_are_typed_and_stable(): void
    {
        $policy = SearchQueryPolicy::fromArray([]);

        $this->assertSame(2, $policy->minimumLength);
        $this->assertSame(200, $policy->maximumLength);
        $this->assertSame(1, $policy->minimumTokenLength);
        $this->assertSame(20, $policy->maximumTokens);
        $this->assertSame(MaximumLengthPolicy::Truncate, $policy->maximumLengthPolicy);
    }

    public function test_configured_policy_values_are_all_consumed(): void
    {
        $policy = SearchQueryPolicy::fromArray([
            'minimum_length' => 0,
            'maximum_length' => 50,
            'minimum_token_length' => 3,
            'maximum_tokens' => 4,
            'maximum_length_policy' => 'reject',
        ]);

        $this->assertSame(0, $policy->minimumLength);
        $this->assertSame(50, $policy->maximumLength);
        $this->assertSame(3, $policy->minimumTokenLength);
        $this->assertSame(4, $policy->maximumTokens);
        $this->assertSame(MaximumLengthPolicy::Reject, $policy->maximumLengthPolicy);
    }

    public function test_negative_minimum_length_is_rejected(): void
    {
        $this->expectException(InvalidSearchQueryConfigurationException::class);
        $this->expectExceptionMessage('minimum_length');

        new SearchQueryPolicy(minimumLength: -1);
    }

    public function test_zero_maximum_length_is_rejected(): void
    {
        $this->expectException(InvalidSearchQueryConfigurationException::class);
        $this->expectExceptionMessage('maximum_length');

        new SearchQueryPolicy(maximumLength: 0);
    }

    public function test_minimum_length_cannot_exceed_maximum_length(): void
    {
        $this->expectException(InvalidSearchQueryConfigurationException::class);
        $this->expectExceptionMessage('must not exceed maximum_length');

        new SearchQueryPolicy(minimumLength: 5, maximumLength: 4);
    }

    public function test_zero_minimum_token_length_is_rejected(): void
    {
        $this->expectException(InvalidSearchQueryConfigurationException::class);
        $this->expectExceptionMessage('minimum_token_length');

        new SearchQueryPolicy(minimumTokenLength: 0);
    }

    public function test_zero_maximum_tokens_is_rejected(): void
    {
        $this->expectException(InvalidSearchQueryConfigurationException::class);
        $this->expectExceptionMessage('maximum_tokens');

        new SearchQueryPolicy(maximumTokens: 0);
    }

    public function test_invalid_maximum_length_policy_is_rejected(): void
    {
        $this->expectException(InvalidSearchQueryConfigurationException::class);
        $this->expectExceptionMessage('maximum_length_policy');

        SearchQueryPolicy::fromArray(['maximum_length_policy' => 'silently-fallback']);
    }

    public function test_non_integer_configuration_is_rejected_without_coercion(): void
    {
        $this->expectException(InvalidSearchQueryConfigurationException::class);
        $this->expectExceptionMessage('maximum_tokens');

        SearchQueryPolicy::fromArray(['maximum_tokens' => '20']);
    }

    public function test_invalid_package_configuration_fails_when_processor_is_first_resolved(): void
    {
        config()->set('persian-search.query', 'invalid');

        $this->assertSame('text', PersianSearch::prepareText('TEXT', 'en')->normalized);

        $this->expectException(InvalidSearchQueryConfigurationException::class);
        $this->expectExceptionMessage('persian-search.query.configuration');

        app(SearchQueryProcessor::class);
    }
}
