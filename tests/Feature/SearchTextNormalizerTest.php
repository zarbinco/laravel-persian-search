<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianCore\Facades\Persian;
use Zarbinco\PersianSearch\Contracts\SearchTextNormalizer;
use Zarbinco\PersianSearch\Tests\TestCase;
use Zarbinco\PersianSearch\Text\SearchLocaleResolver;

final class SearchTextNormalizerTest extends TestCase
{
    public function test_persian_and_persian_region_locales_delegate_to_persian_core(): void
    {
        $value = 'كیكِ شکلاتي ۱۲۳';
        $expected = Persian::search($value)->normalize();
        $normalizer = app(SearchTextNormalizer::class);

        $this->assertSame($expected, $normalizer->normalize($value, 'fa'));
        $this->assertSame($expected, $normalizer->normalize($value, 'FA-ir'));
    }

    public function test_english_and_unknown_locales_only_lowercase_and_normalize_whitespace(): void
    {
        $normalizer = app(SearchTextNormalizer::class);

        $this->assertSame('mixed case ۱۲۳ ك', $normalizer->normalize("  MIXED\nCase  ۱۲۳ ك ", 'en-US'));
        $this->assertSame('mixed case ۱۲۳ ك', $normalizer->normalize("  MIXED\nCase  ۱۲۳ ك ", 'und'));
    }

    public function test_persian_core_rules_cover_variants_digits_diacritics_and_tatweel_idempotently(): void
    {
        $value = 'ي ك ۱۲۳ سَلام کــیک';
        $normalizer = app(SearchTextNormalizer::class);
        $normalized = $normalizer->normalize($value, 'fa-IR');

        $this->assertSame(Persian::search($value)->normalize(), $normalized);
        $this->assertSame($normalized, $normalizer->normalize($normalized, 'fa-IR'));
    }

    public function test_mixed_scripts_and_accented_latin_letters_are_preserved(): void
    {
        $normalized = app(SearchTextNormalizer::class)->normalize('CAFÉ آب ORANGE 100', 'en');

        $this->assertSame('café آب orange 100', $normalized);
    }

    public function test_locale_resolution_is_case_insensitive_without_rewriting_the_stored_locale(): void
    {
        $locales = app(SearchLocaleResolver::class);

        $this->assertSame('FA_ir', $locales->resolve(' FA_ir '));
        $this->assertTrue($locales->isPersian('FA_ir'));
        $this->assertTrue($locales->isEnglish('en-GB'));
        $this->assertSame('und', $locales->resolve(null));
    }
}
