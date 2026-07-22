<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchTokenizerTest extends TestCase
{
    public function test_unicode_words_numbers_apostrophes_and_hyphens_are_tokenized(): void
    {
        $tokens = app(SearchTokenizer::class)->tokenize("آب‌میوه café don't l’été state-of-the-art 2.0", 'und');

        $this->assertSame(['آب', 'میوه', 'café', "don't", 'l’été', 'state', 'of', 'the', 'art', '2', '0'], $tokens);
    }

    public function test_tokens_are_deduplicated_in_first_seen_order(): void
    {
        $this->assertSame(
            ['one', 'two', 'سه'],
            app(SearchTokenizer::class)->tokenize('one two one سه two سه', 'und'),
        );
    }

    public function test_punctuation_is_not_a_token_and_stop_words_are_retained(): void
    {
        $this->assertSame(
            ['the', 'orange', 'و', 'آب', 'قیمت', '۱۲۵۰۰'],
            app(SearchTokenizer::class)->tokenize('the, orange! و آب؟ قیمت: ۱۲۵۰۰', 'und'),
        );
    }

    public function test_tokenization_is_deterministic(): void
    {
        $tokenizer = app(SearchTokenizer::class);
        $value = 'آبمیوه orange 100 محصول-جدید';

        $this->assertSame($tokenizer->tokenize($value, 'fa'), $tokenizer->tokenize($value, 'fa'));
    }

    public function test_one_letter_and_long_inputs_are_not_arbitrarily_filtered_or_truncated(): void
    {
        $words = array_merge(['a', 'ب'], array_map(static fn (int $number): string => 'word'.$number, range(1, 30)));

        $this->assertSame($words, app(SearchTokenizer::class)->tokenize(implode(' ', $words), 'und'));
    }
}
