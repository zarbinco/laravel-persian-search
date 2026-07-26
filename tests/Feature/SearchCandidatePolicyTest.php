<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePlanBuilder;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePolicy;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePolicyFactory;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchCandidateConfigurationException;
use Zarbinco\PersianSearch\Search\ProcessedSearchQuery;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Search\SearchQueryStatus;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchCandidatePolicyTest extends TestCase
{
    public function test_defaults_and_explicit_values_are_loaded(): void
    {
        $defaults = app(SearchCandidatePolicyFactory::class)->make();
        $this->assertSame(10, $defaults->maximumTermsPerVariant);
        $this->assertSame(100, $defaults->perVariantLimit);
        $this->assertSame(500, $defaults->maximumCandidates);

        config()->set('persian-search.candidates', [
            'maximum_terms_per_variant' => 4,
            'per_variant_limit' => 25,
            'maximum_candidates' => 75,
        ]);
        $configured = app(SearchCandidatePolicyFactory::class)->make();
        $this->assertSame([4, 25, 75], [
            $configured->maximumTermsPerVariant,
            $configured->perVariantLimit,
            $configured->maximumCandidates,
        ]);
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_candidate_configuration_is_rejected(string $key, mixed $value): void
    {
        config()->set($key, $value);
        $this->expectException(InvalidSearchCandidateConfigurationException::class);
        app(SearchCandidatePolicyFactory::class)->make();
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidConfiguration(): array
    {
        return [
            'terms zero' => ['persian-search.candidates.maximum_terms_per_variant', 0],
            'terms negative' => ['persian-search.candidates.maximum_terms_per_variant', -1],
            'terms string' => ['persian-search.candidates.maximum_terms_per_variant', '10'],
            'terms excessive' => ['persian-search.candidates.maximum_terms_per_variant', 51],
            'variant zero' => ['persian-search.candidates.per_variant_limit', 0],
            'variant excessive' => ['persian-search.candidates.per_variant_limit', 5001],
            'candidate zero' => ['persian-search.candidates.maximum_candidates', 0],
            'candidate excessive' => ['persian-search.candidates.maximum_candidates', 20001],
        ];
    }

    public function test_plan_builder_retains_phrase_then_unique_tokens_and_enforces_term_limit(): void
    {
        $variant = new QueryVariant(
            'orange fruit juice',
            'en',
            ['orange', 'fruit', 'juice', 'orange'],
            QueryVariantSource::Original,
            1000,
            'original',
        );
        $query = $this->makeSearchQuery(new QueryVariantCollection(2, [$variant]));
        $builder = new SearchCandidatePlanBuilder(new SearchCandidatePolicy(3, 25, 75));

        $first = $builder->build($query);
        $second = $builder->build($query);

        $this->assertSame(['orange fruit juice', 'orange', 'fruit'], $first[0]->terms);
        $this->assertSame(25, $first[0]->limit);
        $this->assertSame($first[0]->toArray(), $second[0]->toArray());
    }

    public function test_complete_single_token_phrase_is_retained_once_and_variant_order_is_preserved(): void
    {
        $original = new QueryVariant('orange', 'en', ['orange'], QueryVariantSource::Original, 1000, 'original');
        $synonym = new QueryVariant('citrus', 'en', ['citrus'], QueryVariantSource::Synonym, 600, 'synonym');
        $plans = (new SearchCandidatePlanBuilder(new SearchCandidatePolicy(10, 100, 500)))
            ->build($this->makeSearchQuery(new QueryVariantCollection(2, [$original, $synonym])));

        $this->assertSame(['orange'], $plans[0]->terms);
        $this->assertSame(QueryVariantSource::Original, $plans[0]->variant->source);
        $this->assertSame(QueryVariantSource::Synonym, $plans[1]->variant->source);
    }

    public function test_plan_execution_orders_contextual_variants_before_lower_priority_variants(): void
    {
        $original = new QueryVariant('original', 'en', ['original'], QueryVariantSource::Original, 1000, 'original');
        $low = new QueryVariant(
            'low',
            'en',
            ['low'],
            QueryVariantSource::Synonym,
            450,
            'low',
            'original',
        );
        $victim = new QueryVariant(
            'victim',
            'en',
            ['victim'],
            QueryVariantSource::KeyboardSynonym,
            400,
            'victim',
            'original',
        );
        $contextual = new QueryVariant(
            'corrected',
            'en',
            ['corrected'],
            QueryVariantSource::Contextual,
            500,
            'contextual',
            'original',
        );
        $variants = (new QueryVariantCollection(3, [$original, $low, $victim]))
            ->withPriorityReplacement($contextual);
        $plans = (new SearchCandidatePlanBuilder(new SearchCandidatePolicy(10, 100, 500)))
            ->build($this->makeSearchQuery($variants));

        $this->assertSame([
            QueryVariantSource::Original,
            QueryVariantSource::Contextual,
            QueryVariantSource::Synonym,
        ], array_map(
            static fn ($plan): QueryVariantSource => $plan->variant->source,
            $plans,
        ));
    }

    public function test_plan_execution_never_contains_semantically_duplicate_variants_after_capacity_replacement(): void
    {
        $original = new QueryVariant('original', 'en', ['original'], QueryVariantSource::Original, 1000, 'original');
        $existing = new QueryVariant('corrected', 'en', ['corrected'], QueryVariantSource::Spelling, 700, 'existing', 'original');
        $leaf = new QueryVariant('leaf', 'en', ['leaf'], QueryVariantSource::Synonym, 400, 'leaf', 'original');
        $contextual = new QueryVariant('corrected', 'en', ['corrected'], QueryVariantSource::Contextual, 500, 'contextual', 'original');
        $variants = (new QueryVariantCollection(3, [$original, $existing, $leaf]))
            ->withPriorityReplacement($contextual);
        $plans = (new SearchCandidatePlanBuilder(new SearchCandidatePolicy(10, 100, 500)))
            ->build($this->makeSearchQuery($variants));

        $this->assertSame(['original', 'corrected', 'leaf'], array_map(
            static fn ($plan): string => $plan->variant->query,
            $plans,
        ));
        $this->assertTrue($variants->contains('leaf'));
        $this->assertFalse($variants->contains('contextual'));
    }

    private function makeSearchQuery(QueryVariantCollection $variants): SearchQuery
    {
        $processed = new ProcessedSearchQuery(
            'orange',
            'orange',
            'en',
            'orange',
            'orange',
            ['orange'],
            ['orange'],
            SearchQueryStatus::Ready,
            false,
            6,
            6,
        );

        return new SearchQuery(
            'orange',
            'orange',
            ['orange'],
            ['product'],
            'en',
            'public',
            20,
            0,
            $processed,
            $variants,
        );
    }
}
