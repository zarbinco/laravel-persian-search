<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Stringable;
use Zarbinco\PersianCore\Facades\Persian;
use Zarbinco\PersianSearch\Exceptions\UnsupportedSearchQueryException;
use Zarbinco\PersianSearch\Search\MaximumLengthPolicy;
use Zarbinco\PersianSearch\Search\SearchQueryPolicy;
use Zarbinco\PersianSearch\Search\SearchQueryProcessor;
use Zarbinco\PersianSearch\Search\SearchQueryStatus;
use Zarbinco\PersianSearch\Tests\TestCase;
use Zarbinco\PersianSearch\Text\SearchLocaleResolver;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final class SearchQueryProcessorTest extends TestCase
{
    public function test_null_empty_ascii_unicode_and_html_only_queries_are_empty(): void
    {
        $processor = $this->processor();

        foreach ([null, '', '   ', "\u{00A0}\u{2028}\u{0085}", '<br><div></div>'] as $query) {
            $processed = $processor->process($query, 'und');
            $this->assertSame(SearchQueryStatus::Empty, $processed->status);
            $this->assertFalse($processed->isSearchable());
        }
    }

    public function test_punctuation_persian_punctuation_and_emoji_only_queries_are_punctuation_only(): void
    {
        $processor = $this->processor();

        foreach (['---', '... !!! ()[]{}', '،؛؟', '😀🔥'] as $query) {
            $processed = $processor->process($query, 'fa');
            $this->assertSame(SearchQueryStatus::PunctuationOnly, $processed->status);
            $this->assertFalse($processed->isSearchable());
        }
    }

    public function test_letters_and_numbers_are_meaningful_with_default_unicode_minimum_length(): void
    {
        $processor = $this->processor();

        $this->assertSame(SearchQueryStatus::TooShort, $processor->process('ک', 'fa')->status);
        $this->assertSame(SearchQueryStatus::TooShort, $processor->process('a', 'en')->status);
        $this->assertSame(SearchQueryStatus::TooShort, $processor->process('۱', 'fa')->status);
        $this->assertSame(SearchQueryStatus::Ready, $processor->process('کی', 'fa')->status);
        $this->assertSame(SearchQueryStatus::Ready, $processor->process('ab', 'en')->status);
        $this->assertSame(SearchQueryStatus::Ready, $processor->process('12', 'en')->status);
        $this->assertTrue($processor->process('کی', 'fa')->isSearchable());
    }

    public function test_normalized_length_is_unicode_safe_and_padding_does_not_inflate_it(): void
    {
        $processor = $this->processor(new SearchQueryPolicy(minimumLength: 2));

        $this->assertSame(SearchQueryStatus::TooShort, $processor->process('  ک  ', 'fa')->status);
        $this->assertSame(SearchQueryStatus::Ready, $processor->process('  کی  ', 'fa')->status);
        $this->assertSame(1, mb_strlen($processor->process('  ک  ', 'fa')->normalizedQuery, 'UTF-8'));
    }

    public function test_minimum_length_zero_disables_total_length_check(): void
    {
        $processed = $this->processor(new SearchQueryPolicy(minimumLength: 0))->process('a', 'en');

        $this->assertSame(SearchQueryStatus::Ready, $processed->status);
        $this->assertSame(['a'], $processed->searchableTokens);
    }

    public function test_meaningful_query_without_eligible_tokens_is_too_short(): void
    {
        $processed = $this->processor(new SearchQueryPolicy(minimumTokenLength: 3))->process('a b', 'en');

        $this->assertSame(['a', 'b'], $processed->tokens);
        $this->assertSame([], $processed->searchableTokens);
        $this->assertSame(SearchQueryStatus::TooShort, $processed->status);
    }

    public function test_token_filtering_preserves_complete_tokens_order_numbers_and_mixed_scripts(): void
    {
        $policy = new SearchQueryPolicy(minimumTokenLength: 2, maximumTokens: 3);
        $processed = $this->processor($policy)->process('a the و آب 12 orange run running', 'en');

        $this->assertSame(['a', 'the', 'و', 'آب', '12', 'orange', 'run', 'running'], $processed->tokens);
        $this->assertSame(['the', 'آب', '12'], $processed->searchableTokens);
        $this->assertSame(SearchQueryStatus::Ready, $processed->status);
    }

    public function test_maximum_token_count_does_not_truncate_complete_tokens(): void
    {
        $processed = $this->processor(new SearchQueryPolicy(maximumTokens: 2))->process('one two three four', 'en');

        $this->assertSame(['one', 'two', 'three', 'four'], $processed->tokens);
        $this->assertSame(['one', 'two'], $processed->searchableTokens);
    }

    public function test_maximum_length_truncates_english_persian_and_mixed_code_points_safely(): void
    {
        $processor = $this->processor(new SearchQueryPolicy(minimumLength: 0, maximumLength: 3));

        foreach (['abcdef' => 'abc', 'فارسی' => 'فار', 'aک😀b' => 'aک😀'] as $raw => $expected) {
            $processed = $processor->process($raw, 'und');
            $this->assertSame($expected, $processed->processedRawQuery);
            $this->assertTrue($processed->wasTruncated);
            $this->assertSame(mb_strlen($raw, 'UTF-8'), $processed->originalLength);
            $this->assertSame(3, $processed->processedLength);
            $this->assertSame(1, preg_match('//u', $processed->processedRawQuery));
        }
    }

    public function test_maximum_length_does_not_mark_input_under_limit_as_truncated(): void
    {
        $processed = $this->processor(new SearchQueryPolicy(maximumLength: 5))->process('four', 'en');

        $this->assertFalse($processed->wasTruncated);
        $this->assertSame(4, $processed->originalLength);
        $this->assertSame(4, $processed->processedLength);
    }

    public function test_maximum_length_counts_combining_characters_as_unicode_code_points(): void
    {
        $raw = "e\u{0301}x";
        $processed = $this->processor(new SearchQueryPolicy(minimumLength: 0, maximumLength: 2))->process($raw, 'en');

        $this->assertSame("e\u{0301}", $processed->processedRawQuery);
        $this->assertSame(3, $processed->originalLength);
        $this->assertSame(2, $processed->processedLength);
        $this->assertTrue($processed->wasTruncated);
    }

    public function test_maximum_length_reject_policy_returns_too_long_without_preparation(): void
    {
        $policy = new SearchQueryPolicy(maximumLength: 3, maximumLengthPolicy: MaximumLengthPolicy::Reject);
        $processed = $this->processor($policy)->process('<b>long</b>', 'en');

        $this->assertSame(SearchQueryStatus::TooLong, $processed->status);
        $this->assertFalse($processed->isSearchable());
        $this->assertFalse($processed->wasTruncated);
        $this->assertSame('', $processed->sanitizedQuery);
        $this->assertSame([], $processed->tokens);
    }

    public function test_stringable_queries_are_supported_and_other_types_are_rejected(): void
    {
        $query = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'orange';
            }
        };

        $processor = $this->processor();
        $this->assertSame('orange', $processor->process($query, 'en')->rawQuery);

        $resource = fopen('php://memory', 'r');

        try {
            foreach ([['orange'], new \stdClass, static fn (): string => 'orange', $resource] as $unsupported) {
                try {
                    $processor->process($unsupported, 'en');
                    $this->fail('Unsupported query input was accepted.');
                } catch (UnsupportedSearchQueryException $exception) {
                    $this->assertStringContainsString('not supported', $exception->getMessage());
                }
            }
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function test_locale_family_normalization_remains_delegated_and_generic_is_conservative(): void
    {
        $value = 'كیكِ شکلاتي';

        $this->assertSame(Persian::search($value)->normalize(), $this->processor()->process($value, 'fa-IR')->normalizedQuery);
        $this->assertSame('ك mixed', $this->processor()->process('ك MIXED', 'en-US')->normalizedQuery);
        $this->assertSame('fa_IR', $this->processor()->process('پرتقال', 'fa_IR')->locale);
    }

    public function test_undefined_locale_fallback_remains_und_without_application_context(): void
    {
        $this->assertSame('und', $this->processor()->process('orange', null)->locale);
    }

    private function processor(?SearchQueryPolicy $policy = null): SearchQueryProcessor
    {
        return new SearchQueryProcessor(
            app(SearchTextPipeline::class),
            app(SearchLocaleResolver::class),
            $policy ?? new SearchQueryPolicy,
        );
    }
}
