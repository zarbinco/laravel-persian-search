<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use Zarbinco\PersianSearch\Contracts\SearchTextNormalizer;
use Zarbinco\PersianSearch\Contracts\SearchTextSanitizer;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Query\SynonymDictionary;
use Zarbinco\PersianSearch\Query\SynonymRule;
use Zarbinco\PersianSearch\Query\TokenAwareSynonymExpander;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Tests\TestCase;
use Zarbinco\PersianSearch\Text\SearchLocaleResolver;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;
use Zarbinco\PersianSearch\Text\SearchTextValueConverter;

final class SemanticSynonymDeduplicationTest extends TestCase
{
    public function test_duplicate_candidate_token_sequences_are_yielded_once_with_first_provenance(): void
    {
        $expander = new TokenAwareSynonymExpander(
            new SynonymDictionary(true, ['en' => [
                new SynonymRule('a', ['a'], 'x', ['x']),
                new SynonymRule('a b', ['a', 'b'], 'x b', ['x', 'b']),
                new SynonymRule('a b c', ['a', 'b', 'c'], 'x b c', ['x', 'b', 'c']),
            ]]),
            app(SearchTextPipeline::class),
        );
        $variant = new QueryVariant('a b c', 'en', ['a', 'b', 'c'], QueryVariantSource::Original, 1000, 'original');
        $expansions = iterator_to_array($expander->expand($variant));

        $this->assertCount(1, $expansions);
        $this->assertSame('x b c', $expansions[0]->query);
        $this->assertSame('a', $expansions[0]->sourceTerm);
        $this->assertSame('x', $expansions[0]->replacementTerm);
    }

    public function test_distinct_candidate_tokens_normalizing_to_same_semantic_query_are_yielded_once(): void
    {
        $expander = $this->expander([
            new SynonymRule('a', ['a'], 'x', ['x']),
            new SynonymRule('a', ['a'], 'X', ['X']),
        ]);
        $expansions = iterator_to_array($expander->expand($this->variant('a b', 'en', ['a', 'b'])));

        $this->assertCount(1, $expansions);
        $this->assertSame('x b', $expansions[0]->query);
        $this->assertSame('x', $expansions[0]->replacementTerm);
    }

    public function test_duplicate_candidates_skip_pipeline_while_unique_candidates_invoke_it_once(): void
    {
        $recorder = new SynonymPipelineRecorder;
        $expander = new TokenAwareSynonymExpander(
            new SynonymDictionary(true, ['en' => [
                new SynonymRule('a', ['a'], 'x', ['x']),
                new SynonymRule('a b', ['a', 'b'], 'x b', ['x', 'b']),
                new SynonymRule('a b c', ['a', 'b', 'c'], 'x b c', ['x', 'b', 'c']),
                new SynonymRule('b', ['b'], 'y', ['y']),
            ]]),
            $this->recordingPipeline($recorder),
        );
        $expansions = iterator_to_array($expander->expand($this->variant('a b c', 'en', ['a', 'b', 'c'])));

        $this->assertCount(2, $expansions);
        $this->assertSame(['x b c', 'a y c'], array_map(static fn ($expansion): string => $expansion->query, $expansions));
        $this->assertSame(2, $recorder->preparations);
    }

    public function test_repeated_calls_are_independent_deterministic_and_locale_scoped(): void
    {
        $rules = [new SynonymRule('a', ['a'], 'x', ['x'])];
        $expander = new TokenAwareSynonymExpander(
            new SynonymDictionary(true, ['en' => $rules, 'fa' => $rules]),
            app(SearchTextPipeline::class),
        );
        $english = $this->variant('a b', 'en', ['a', 'b']);
        $persianLocale = $this->variant('a b', 'fa', ['a', 'b']);
        $first = iterator_to_array($expander->expand($english));
        $second = iterator_to_array($expander->expand($english));
        $otherLocale = iterator_to_array($expander->expand($persianLocale));

        $this->assertSame(array_map(static fn ($item): array => $item->toArray(), $first), array_map(static fn ($item): array => $item->toArray(), $second));
        $this->assertCount(1, $otherLocale);
        $this->assertSame('fa', $otherLocale[0]->locale);
        $this->assertNotSame($first[0]->fingerprint, $otherLocale[0]->fingerprint);
    }

    /** @param list<SynonymRule> $rules */
    private function expander(array $rules): TokenAwareSynonymExpander
    {
        return new TokenAwareSynonymExpander(new SynonymDictionary(true, ['en' => $rules]), app(SearchTextPipeline::class));
    }

    /** @param list<string> $tokens */
    private function variant(string $query, string $locale, array $tokens): QueryVariant
    {
        return new QueryVariant($query, $locale, $tokens, QueryVariantSource::Original, 1000, hash('sha256', $locale."\0".$query));
    }

    private function recordingPipeline(SynonymPipelineRecorder $recorder): SearchTextPipeline
    {
        return new SearchTextPipeline(
            new SearchTextValueConverter,
            new class($recorder) implements SearchTextSanitizer
            {
                public function __construct(private SynonymPipelineRecorder $recorder) {}

                public function sanitize(string $value, string $locale): string
                {
                    $this->recorder->preparations++;

                    return $value;
                }
            },
            new class implements SearchTextNormalizer
            {
                public function normalize(string $value, string $locale): string
                {
                    return strtolower($value);
                }
            },
            new class implements SearchTokenizer
            {
                public function tokenize(string $normalizedText, string $locale): array
                {
                    $tokens = preg_split('/ +/', trim($normalizedText), -1, PREG_SPLIT_NO_EMPTY);

                    return is_array($tokens) ? array_values(array_unique($tokens)) : [];
                }
            },
            new SearchLocaleResolver,
        );
    }
}

final class SynonymPipelineRecorder
{
    public int $preparations = 0;
}
