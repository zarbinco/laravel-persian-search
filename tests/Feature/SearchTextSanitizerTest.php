<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianSearch\Contracts\SearchTextSanitizer;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchTextException;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchTextSanitizerTest extends TestCase
{
    public function test_html_entities_tags_and_non_content_blocks_are_safely_sanitized(): void
    {
        $value = '<h1>Tea &amp; Cake</h1><script>alert("hidden")</script><p>Fresh&nbsp;daily</p>';

        $this->assertSame('Tea & Cake Fresh daily', app(SearchTextSanitizer::class)->sanitize($value, 'en'));
    }

    public function test_whitespace_invisible_characters_and_controls_are_cleaned(): void
    {
        $value = "  آب\u{200C}میوه\u{200B}\t تازه\u{200E}\n امروز\u{0007}  ";

        $this->assertSame('آب میوه تازه امروز', app(SearchTextSanitizer::class)->sanitize($value, 'fa'));
    }

    public function test_encoded_markup_is_not_allowed_to_restore_script_content(): void
    {
        $value = '&lt;script&gt;secret&lt;/script&gt;<div>visible</div>';

        $this->assertSame('visible', app(SearchTextSanitizer::class)->sanitize($value, 'en'));
    }

    public function test_all_non_content_blocks_and_common_boundaries_are_handled(): void
    {
        $value = '<style>hidden css</style><noscript>hidden fallback</noscript><template>hidden template</template>'
            .'<div>one<br>two</div><ul><li>three</li><li>four</li></ul><table><tr><td>five</td><td>six</td></tr></table>';

        $this->assertSame('one two three four five six', app(SearchTextSanitizer::class)->sanitize($value, 'en'));
    }

    public function test_malformed_html_does_not_crash_and_plain_comparison_text_survives(): void
    {
        $sanitizer = app(SearchTextSanitizer::class);

        $this->assertSame('before text', $sanitizer->sanitize('<div>before <strong>text', 'en'));
        $this->assertSame('price 5 < 10 and 10 > 5', $sanitizer->sanitize('price 5 < 10 and 10 > 5', 'en'));
    }

    public function test_directional_controls_and_bom_are_removed(): void
    {
        $marks = "\u{200E}\u{200F}\u{202A}\u{202B}\u{202C}\u{202D}\u{202E}\u{2066}\u{2067}\u{2068}\u{2069}\u{FEFF}";

        $this->assertSame('leftright', app(SearchTextSanitizer::class)->sanitize('left'.$marks.'right', 'und'));
    }

    public function test_each_zero_width_joining_character_becomes_a_separator(): void
    {
        $sanitizer = app(SearchTextSanitizer::class);

        $this->assertSame('می روم', $sanitizer->sanitize("می\u{200C}روم", 'fa'));
        $this->assertSame('می روم', $sanitizer->sanitize("می\u{200D}روم", 'fa'));
        $this->assertSame('می روم', $sanitizer->sanitize("می\u{200B}روم", 'fa'));
    }

    public function test_unicode_whitespace_vertical_tab_becomes_a_boundary(): void
    {
        $this->assertSame('a b', app(SearchTextSanitizer::class)->sanitize("a\u{000B}b", 'und'));
    }

    public function test_unicode_whitespace_form_feed_becomes_a_boundary(): void
    {
        $this->assertSame('a b', app(SearchTextSanitizer::class)->sanitize("a\u{000C}b", 'und'));
    }

    public function test_unicode_whitespace_next_line_becomes_a_boundary(): void
    {
        $this->assertSame('a b', app(SearchTextSanitizer::class)->sanitize("a\u{0085}b", 'und'));
    }

    public function test_unicode_whitespace_line_separator_becomes_a_boundary(): void
    {
        $this->assertSame('a b', app(SearchTextSanitizer::class)->sanitize("a\u{2028}b", 'und'));
    }

    public function test_unicode_whitespace_paragraph_separator_becomes_a_boundary(): void
    {
        $this->assertSame('a b', app(SearchTextSanitizer::class)->sanitize("a\u{2029}b", 'und'));
    }

    public function test_unicode_whitespace_mixture_collapses_to_one_space(): void
    {
        $whitespace = "\t\n\u{000B}\u{000C}\r\u{0085}\u{00A0}\u{2028}\u{2029}";

        $this->assertSame('one two', app(SearchTextSanitizer::class)->sanitize('one'.$whitespace.'two', 'und'));
    }

    public function test_non_whitespace_controls_are_still_removed_without_a_boundary(): void
    {
        $this->assertSame('ab', app(SearchTextSanitizer::class)->sanitize("a\u{0000}\u{0007}\u{007F}\u{0080}\u{200E}b", 'und'));
    }

    public function test_repeated_unicode_whitespace_is_still_collapsed(): void
    {
        $this->assertSame('one two', app(SearchTextSanitizer::class)->sanitize("one \u{000B} \u{0085} two", 'und'));
    }

    public function test_leading_and_trailing_unicode_whitespace_is_still_trimmed(): void
    {
        $this->assertSame('content', app(SearchTextSanitizer::class)->sanitize("\u{0085}\u{2028} content \u{000C}\u{2029}", 'und'));
    }

    public function test_unicode_whitespace_sanitization_is_idempotent(): void
    {
        $sanitizer = app(SearchTextSanitizer::class);
        $once = $sanitizer->sanitize("one\u{000B}\u{0085}two", 'und');

        $this->assertSame($once, $sanitizer->sanitize($once, 'und'));
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $this->expectException(InvalidSearchTextException::class);
        $this->expectExceptionMessage('valid UTF-8');

        app(SearchTextSanitizer::class)->sanitize("\xB1\x31", 'und');
    }
}
