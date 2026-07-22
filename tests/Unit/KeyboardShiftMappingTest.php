<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Query\KeyboardLayoutCorrector;
use Zarbinco\PersianSearch\Query\WindowsPersianKeyboardMap;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Tests\TestCase;

final class KeyboardShiftMappingTest extends TestCase
{
    public function test_base_and_shift_states_are_exact_and_case_sensitive(): void
    {
        $map = app(WindowsPersianKeyboardMap::class)->map();

        foreach ([
            'c' => 'ز', 'C' => 'ژ',
            'h' => 'ا', 'H' => 'آ',
            'm' => 'ئ', 'M' => 'ء',
            '?' => '؟', '\\' => 'پ',
            '{' => '}', '}' => '{', '`' => '÷', '~' => '×',
        ] as $key => $expected) {
            $this->assertSame($expected, $map[$key]);
        }
    }

    public function test_all_required_printable_letter_shift_mappings_are_present(): void
    {
        $map = app(WindowsPersianKeyboardMap::class)->map();
        $expected = [
            'A' => 'َ', 'B' => 'إ', 'C' => 'ژ', 'D' => 'ِ', 'E' => 'ٍ',
            'F' => 'ّ', 'G' => 'ۀ', 'H' => 'آ', 'I' => ']', 'J' => 'ـ',
            'K' => '«', 'L' => '»', 'M' => 'ء', 'N' => 'أ', 'O' => '[',
            'P' => '\\', 'Q' => 'ً', 'R' => 'ریال', 'S' => 'ُ', 'T' => '،',
            'U' => ',', 'V' => 'ؤ', 'W' => 'ٌ', 'X' => 'ي', 'Y' => '؛', 'Z' => 'ة',
        ];

        $this->assertSame($expected, array_intersect_key($map, $expected));
    }

    public function test_mixed_shift_output_multi_character_mapping_and_fingerprints_are_deterministic(): void
    {
        $first = PersianSearch::expandQuery(PersianSearch::processQuery('cCRh', 'en'))->all()[1];
        $second = PersianSearch::expandQuery(PersianSearch::processQuery('cCRh', 'en'))->all()[1];

        $this->assertSame('زژریالا', $first->query);
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame('fa', $first->locale);
    }

    public function test_shift_output_that_normalizes_to_no_searchable_text_is_rejected(): void
    {
        $variant = new QueryVariant('AA', 'en', ['AA'], QueryVariantSource::Original, 1000, 'original');

        $this->assertNull(app(KeyboardLayoutCorrector::class)->correct($variant));
    }
}
