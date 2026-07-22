<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianSearch\Contracts\SearchTextNormalizer;
use Zarbinco\PersianSearch\Contracts\SearchTextSanitizer;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Tests\TestCase;
use Zarbinco\PersianSearch\Text\PreparedSearchText;
use Zarbinco\PersianSearch\Text\SearchLocaleResolver;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;
use Zarbinco\PersianSearch\Text\SearchTextValueConverter;

final class SearchTextPipelineTest extends TestCase
{
    public function test_public_api_returns_an_immutable_complete_preparation_result(): void
    {
        $prepared = PersianSearch::prepareText(['<b>HELLO</b>', 'World', 'HELLO'], 'en-US');

        $this->assertInstanceOf(PreparedSearchText::class, $prepared);
        $this->assertSame('en-US', $prepared->locale);
        $this->assertSame('<b>HELLO</b> World HELLO', $prepared->raw);
        $this->assertSame('HELLO World HELLO', $prepared->sanitized);
        $this->assertSame('hello world hello', $prepared->normalized);
        $this->assertSame(['hello', 'world'], $prepared->tokens);
        $this->assertSame($prepared->toArray(), PersianSearch::prepareText(['<b>HELLO</b>', 'World', 'HELLO'], 'en-US')->toArray());
    }

    public function test_pipeline_executes_sanitization_normalization_and_tokenization_in_order(): void
    {
        $recorder = new PipelineCallRecorder;
        $sanitizer = new class($recorder) implements SearchTextSanitizer
        {
            public function __construct(private PipelineCallRecorder $recorder) {}

            public function sanitize(string $value, string $locale): string
            {
                $this->recorder->add('sanitize:'.$value.':'.$locale);

                return 'sanitized';
            }
        };
        $normalizer = new class($recorder) implements SearchTextNormalizer
        {
            public function __construct(private PipelineCallRecorder $recorder) {}

            public function normalize(string $value, string $locale): string
            {
                $this->recorder->add('normalize:'.$value.':'.$locale);

                return 'normalized';
            }
        };
        $tokenizer = new class($recorder) implements SearchTokenizer
        {
            public function __construct(private PipelineCallRecorder $recorder) {}

            public function tokenize(string $normalizedText, string $locale): array
            {
                $this->recorder->add('tokenize:'.$normalizedText.':'.$locale);

                return ['token'];
            }
        };
        $pipeline = new SearchTextPipeline(
            new SearchTextValueConverter,
            $sanitizer,
            $normalizer,
            $tokenizer,
            new SearchLocaleResolver,
        );

        $this->assertSame(['token'], $pipeline->prepare('raw', 'en')->tokens);
        $this->assertSame([
            'sanitize:raw:en',
            'normalize:sanitized:en',
            'tokenize:normalized:en',
        ], $recorder->calls);
    }

    public function test_custom_contract_bindings_are_honored_by_the_pipeline(): void
    {
        app()->singleton(SearchTextSanitizer::class, fn (): SearchTextSanitizer => new class implements SearchTextSanitizer
        {
            public function sanitize(string $value, string $locale): string
            {
                return 'custom sanitized';
            }
        });
        app()->singleton(SearchTokenizer::class, fn (): SearchTokenizer => new class implements SearchTokenizer
        {
            public function tokenize(string $normalizedText, string $locale): array
            {
                return ['custom'];
            }
        });
        app()->forgetInstance(SearchTextPipeline::class);

        $prepared = app(SearchTextPipeline::class)->prepare('ignored', 'en');

        $this->assertSame('custom sanitized', $prepared->sanitized);
        $this->assertSame(['custom'], $prepared->tokens);
    }

    public function test_empty_values_have_a_stable_empty_representation(): void
    {
        $prepared = app(SearchTextPipeline::class)->prepare([null, '', []], null);

        $this->assertSame('und', $prepared->locale);
        $this->assertTrue($prepared->isEmpty());
        $this->assertSame('', $prepared->raw);
        $this->assertSame([], $prepared->tokens);
    }

    public function test_preparation_is_idempotent_for_searchable_output(): void
    {
        $first = PersianSearch::prepareText('<p> كیكِ&nbsp;TEST </p>', 'fa_IR');
        $second = PersianSearch::prepareText($first->normalized, 'fa_IR');

        $this->assertSame($first->normalized, $second->normalized);
        $this->assertSame($first->tokens, $second->tokens);
    }

    public function test_explicit_locale_overrides_the_application_locale(): void
    {
        app()->setLocale('fa');

        $prepared = PersianSearch::prepareText('ك TEST', 'en_US');

        $this->assertSame('en_US', $prepared->locale);
        $this->assertSame('ك test', $prepared->normalized);
    }

    public function test_unicode_whitespace_boundary_survives_through_tokenization(): void
    {
        $prepared = app(SearchTextPipeline::class)->prepare("orange\u{0085}juice", 'en');

        $this->assertSame('orange juice', $prepared->sanitized);
        $this->assertSame(['orange', 'juice'], $prepared->tokens);
    }
}

final class PipelineCallRecorder
{
    /** @var list<string> */
    public array $calls = [];

    public function add(string $call): void
    {
        $this->calls[] = $call;
    }
}
