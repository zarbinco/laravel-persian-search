<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Exceptions\InvalidSpellingConfigurationException;
use Zarbinco\PersianSearch\Query\QueryVariantPolicy;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Spelling\SpellingPolicy;

final class SpellingPolicyTest extends TestCase
{
    public function test_defaults_are_bounded_and_locale_fallback_is_deterministic(): void
    {
        $policy = SpellingPolicy::fromArray([]);

        $this->assertFalse($policy->enabled);
        $this->assertSame(0, $policy->editDistanceFor('abc'));
        $this->assertSame(1, $policy->editDistanceFor('orange'));
        $this->assertSame(2, $policy->editDistanceFor('chocolate'));
        $this->assertSame(['en-GB', 'en'], $policy->localeChain('en-GB'));
        $this->assertSame(['fa'], $policy->localeChain('fa'));
    }

    public function test_old_variant_configuration_can_omit_new_spelling_priorities(): void
    {
        $policy = QueryVariantPolicy::fromArray(20, [
            'original' => 1000,
            'keyboard' => 800,
            'synonym' => 600,
            'keyboard_synonym' => 400,
        ]);

        $this->assertSame(700, $policy->priority(QueryVariantSource::Spelling));
        $this->assertSame(650, $policy->priority(QueryVariantSource::KeyboardSpelling));
    }

    public function test_unsafe_dictionary_table_identifier_is_rejected(): void
    {
        $this->expectException(InvalidSpellingConfigurationException::class);
        SpellingPolicy::fromArray([
            'terms_table' => 'terms as injected',
        ]);
    }

    public function test_legacy_custom_priority_scale_remains_compatible_without_new_keys(): void
    {
        $policy = QueryVariantPolicy::fromArray(20, [
            'original' => 10,
            'keyboard' => 9,
            'synonym' => 8,
            'keyboard_synonym' => 7,
        ]);

        $this->assertSame(9, $policy->priority(QueryVariantSource::Spelling));
        $this->assertSame(8, $policy->priority(QueryVariantSource::KeyboardSpelling));
    }

    public function test_invalid_unbounded_configuration_is_rejected(): void
    {
        $this->expectException(InvalidSpellingConfigurationException::class);
        SpellingPolicy::fromArray([
            'correction' => ['maximum_candidates_per_token' => 1000000],
        ]);
    }
}
