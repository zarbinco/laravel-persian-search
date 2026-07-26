<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Contracts\LanguageCorrectionProfile;
use Zarbinco\PersianSearch\Correction\AdvancedCorrectionPolicy;
use Zarbinco\PersianSearch\Correction\EnglishLanguageCorrectionProfile;
use Zarbinco\PersianSearch\Correction\LanguageCorrectionProfileRegistry;
use Zarbinco\PersianSearch\Correction\PersianLanguageCorrectionProfile;
use Zarbinco\PersianSearch\Correction\PhoneticAlternative;
use Zarbinco\PersianSearch\Exceptions\InvalidSpellingConfigurationException;
use Zarbinco\PersianSearch\Search\QueryVariantSource;

final class AdvancedCorrectionPolicyTest extends TestCase
{
    public function test_defaults_are_disabled_bounded_and_include_built_in_profiles(): void
    {
        $policy = AdvancedCorrectionPolicy::fromArray([]);

        $this->assertFalse($policy->enabled());
        $this->assertSame(2, $policy->maximumTransformationDepth);
        $this->assertSame(32, $policy->maximumAlternativesPerToken);
        $this->assertSame(256, $policy->maximumLookupTerms);
        $this->assertSame([
            PersianLanguageCorrectionProfile::class,
            EnglishLanguageCorrectionProfile::class,
        ], $policy->profileClasses);
    }

    public function test_invalid_limits_profiles_and_unsupported_segment_counts_fail_closed(): void
    {
        foreach ([
            ['phonetic' => ['maximum_alternatives_per_token' => 0]],
            ['phonetic' => ['profiles' => [self::class]]],
            ['segmentation' => ['maximum_segments' => 3]],
            ['segmentation' => ['minimum_segment_length' => 0]],
            ['phonetic' => ['maximum_tokens_to_inspect' => 1, 'maximum_tokens_to_correct' => 2]],
            ['segmentation' => ['minimum_token_length' => 4, 'minimum_segment_length' => 3]],
            ['segmentation' => ['maximum_adjacent_pairs' => 1, 'maximum_merges_per_query' => 2]],
        ] as $config) {
            try {
                AdvancedCorrectionPolicy::fromArray($config);
                $this->fail('Invalid advanced correction configuration was accepted.');
            } catch (InvalidSpellingConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_registry_resolves_regional_locales_and_rejects_duplicates(): void
    {
        $persian = new PersianLanguageCorrectionProfile;
        $english = new EnglishLanguageCorrectionProfile;
        $registry = new LanguageCorrectionProfileRegistry([$persian, $english]);

        $this->assertSame($persian, $registry->forLocale('fa-IR'));
        $this->assertSame($english, $registry->forLocale('en-US'));
        $this->assertNull($registry->forLocale('fr'));
        $this->assertSame(['en', 'fa'], $registry->locales());

        $this->expectException(InvalidArgumentException::class);
        new LanguageCorrectionProfileRegistry([$english, new EnglishLanguageCorrectionProfile]);
    }

    public function test_built_in_profiles_expose_only_documented_conservative_rules(): void
    {
        $persian = iterator_to_array((new PersianLanguageCorrectionProfile)->phoneticAlternatives('قذا'));
        $english = iterator_to_array((new EnglishLanguageCorrectionProfile)->phoneticAlternatives('fone'));

        $this->assertContains('غذا', array_map(
            static fn (PhoneticAlternative $alternative): string => $alternative->token,
            $persian,
        ));
        $this->assertContains('phone', array_map(
            static fn (PhoneticAlternative $alternative): string => $alternative->token,
            $english,
        ));
        $this->assertNotContains('food', array_map(
            static fn (PhoneticAlternative $alternative): string => $alternative->token,
            $english,
        ));
    }

    public function test_registry_rejects_invalid_profile_metadata(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LanguageCorrectionProfileRegistry([new InvalidSeparatorProfile]);
    }

    public function test_existing_query_variant_source_values_are_unchanged(): void
    {
        $this->assertSame('original', QueryVariantSource::Original->value);
        $this->assertSame('keyboard', QueryVariantSource::Keyboard->value);
        $this->assertSame('spelling', QueryVariantSource::Spelling->value);
        $this->assertSame('keyboard_spelling', QueryVariantSource::KeyboardSpelling->value);
        $this->assertSame('synonym', QueryVariantSource::Synonym->value);
        $this->assertSame('keyboard_synonym', QueryVariantSource::KeyboardSynonym->value);
    }
}

final class InvalidSeparatorProfile implements LanguageCorrectionProfile
{
    public function locale(): string
    {
        return 'invalid';
    }

    public function phoneticAlternatives(string $token): iterable
    {
        return [];
    }

    public function separators(): array
    {
        return ['too-long'];
    }
}
