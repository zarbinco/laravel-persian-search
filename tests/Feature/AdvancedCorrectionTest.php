<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Zarbinco\PersianSearch\Contracts\AdvancedQueryCorrector;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SpellingCorrector;
use Zarbinco\PersianSearch\Contracts\SynonymExpander;
use Zarbinco\PersianSearch\Correction\AdvancedCorrection;
use Zarbinco\PersianSearch\Correction\AdvancedCorrectionPolicy;
use Zarbinco\PersianSearch\Correction\AdvancedCorrectionPolicyFactory;
use Zarbinco\PersianSearch\Correction\DatabaseAdvancedQueryCorrector;
use Zarbinco\PersianSearch\Correction\LanguageCorrectionProfileRegistry;
use Zarbinco\PersianSearch\Exceptions\SpellingDictionaryUnavailableException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\PersianSearchManager;
use Zarbinco\PersianSearch\Query\DefaultQueryExpander;
use Zarbinco\PersianSearch\Query\KeyboardLayoutCorrector;
use Zarbinco\PersianSearch\Query\QueryVariantPolicy;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Spelling\DatabaseSpellingCorrector;
use Zarbinco\PersianSearch\Spelling\SpellingDictionaryBuilder;
use Zarbinco\PersianSearch\Spelling\SpellingDictionaryStatusService;
use Zarbinco\PersianSearch\Spelling\SpellingPolicy;
use Zarbinco\PersianSearch\Tests\TestCase;

final class AdvancedCorrectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.spelling.enabled', false);
        config()->set('persian-search.spelling.phonetic.enabled', true);
        config()->set('persian-search.spelling.segmentation.enabled', true);
        (require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php')->up();
        (require __DIR__.'/../../database/migrations/create_persian_search_dictionary_tables.php')->up();
    }

    public function test_persian_profile_corrects_bounded_dictionary_backed_confusions(): void
    {
        $this->index('icecream-fa', 'fa', 'بستنی');
        $this->index('food-fa', 'fa', 'غذا');
        $this->index('capacity-fa', 'fa', 'ظرفیت');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $icecream = $this->advanced('بصطنی', 'fa');
        $food = $this->advanced('قذا', 'fa');

        $this->assertSame('بستنی', $icecream?->correctedQuery);
        $this->assertSame('phonetic', $icecream->type()->value);
        $this->assertSame(['ص>س', 'ط>ت'], explode('+', $icecream->transformations[0]->rule));
        $this->assertSame('غذا', $food?->correctedQuery);
        $this->assertNull($this->advanced('ظرفیت', 'fa'));
        $this->assertNull($this->advanced('حصطنی', 'fa'));
    }

    public function test_english_profile_is_conservative_dictionary_backed_and_region_aware(): void
    {
        $this->index('phone-en', 'en', 'phone');
        $this->index('persian-phone', 'fa', 'phone');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $regional = $this->advanced('fone', 'en-GB');

        $this->assertSame('phone', $regional?->correctedQuery);
        $this->assertSame('en', $regional->locale);
        $this->assertNull($this->advanced('fone', 'fr'));
        $this->assertNull($this->advanced('fone', 'fa'));
    }

    public function test_protected_terms_exact_terms_and_alternative_limit_are_honored(): void
    {
        config()->set('persian-search.spelling.dictionary.protected_terms.fa', ['بصطنی']);
        config()->set('persian-search.spelling.phonetic.maximum_alternatives_per_token', 1);
        $this->forgetCorrectionInstances();
        $this->index('icecream-fa', 'fa', 'بستنی');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertNull($this->advanced('بصطنی', 'fa'));

        config()->set('persian-search.spelling.dictionary.protected_terms.fa', []);
        $this->forgetCorrectionInstances();
        $this->assertNull($this->advanced('بصطنی', 'fa'));
    }

    public function test_joined_words_split_for_persian_and_english_without_scanning_terms(): void
    {
        $this->index('orange-water', 'fa', 'آب پرتقال');
        $this->index('sodas', 'fa', 'نوشابه ها');
        $this->index('ice-cream', 'en', 'ice cream');
        $this->index('search-engine', 'en', 'search engine');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertDatabaseHas('persian_search_dictionary_terms', ['locale' => 'fa', 'normalized_term' => 'اب']);
        $this->assertDatabaseHas('persian_search_dictionary_terms', ['locale' => 'fa', 'normalized_term' => 'پرتقال']);
        $this->assertSame('اب پرتقال', $this->advanced('آبپرتقال', 'fa')?->correctedQuery);
        $this->assertSame('نوشابه ها', $this->advanced('نوشابهها', 'fa')?->correctedQuery);
        $this->assertSame('ice cream', $this->advanced('icecream', 'en')?->correctedQuery);
        $this->assertSame('search engine', $this->advanced('searchengine', 'en')?->correctedQuery);
    }

    public function test_split_rejects_invalid_segments_short_segments_protected_terms_and_identifiers(): void
    {
        config()->set('persian-search.spelling.dictionary.protected_terms.en', ['icecream']);
        $this->forgetCorrectionInstances();
        $this->index('ice-cream', 'en', 'ice cream');
        $this->index('search-engine', 'en', 'search engine');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertNull($this->advanced('icecream', 'en'));
        $this->assertNull($this->advanced('unknownword', 'en'));
        $this->assertNull($this->advanced('https://searchengine.test', 'en'));
        $this->assertNull($this->advanced('search_engine', 'en'));
        $this->assertNull($this->advanced('searchengine2', 'en'));
    }

    public function test_split_position_limit_is_enforced_before_lookup(): void
    {
        config()->set('persian-search.spelling.segmentation.maximum_split_positions_per_token', 1);
        $this->forgetCorrectionInstances();
        $this->index('search-engine', 'en', 'search engine');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertNull($this->advanced('searchengine', 'en'));
    }

    public function test_candidate_row_limit_is_enforced(): void
    {
        config()->set('persian-search.spelling.maximum_advanced_candidate_rows', 1);
        $this->forgetCorrectionInstances();
        $this->index('ice-cream', 'en', 'ice cream');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertNull($this->advanced('icecream', 'en'));
    }

    public function test_adjacent_words_merge_without_crossing_punctuation_or_locale_boundaries(): void
    {
        $this->index('icecream', 'en', 'icecream');
        $this->index('website', 'en', 'website');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertSame('icecream', $this->advanced('ice cream', 'en')?->correctedQuery);
        $this->assertSame('website', $this->advanced('web site', 'en')?->correctedQuery);
        $this->assertNull($this->advanced('web-site', 'en'));
        $this->assertNull($this->advanced('web fast site', 'en'));
        $this->assertNull($this->advanced('web site', 'fa'));

        config()->set('persian-search.spelling.dictionary.protected_terms.en', ['website']);
        $this->forgetCorrectionInstances();
        $this->assertNull($this->advanced('web site', 'en'));
    }

    public function test_phonetic_and_segmentation_flags_are_independent(): void
    {
        $this->index('phone-en', 'en', 'phone');
        $this->index('ice-cream', 'en', 'ice cream');
        app(SpellingDictionaryBuilder::class)->rebuild();

        config()->set('persian-search.spelling.phonetic.enabled', false);
        $this->forgetCorrectionInstances();
        $this->assertNull($this->advanced('fone', 'en'));
        $this->assertSame('ice cream', $this->advanced('icecream', 'en')?->correctedQuery);

        config()->set('persian-search.spelling.phonetic.enabled', true);
        config()->set('persian-search.spelling.segmentation.enabled', false);
        $this->forgetCorrectionInstances();
        $this->assertSame('phone', $this->advanced('fone', 'en')?->correctedQuery);
        $this->assertNull($this->advanced('icecream', 'en'));
    }

    public function test_keyboard_then_phonetic_keeps_both_provenance_layers(): void
    {
        $this->index('icecream-fa', 'fa', 'بستنی');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $combined = array_values(array_filter(
            PersianSearch::query('fwxkd')->locale('en')->variants()->all(),
            static fn ($variant): bool => $variant->source === QueryVariantSource::KeyboardPhonetic,
        ));

        $this->assertCount(1, $combined);
        $this->assertSame('بستنی', $combined[0]->query);
        $this->assertNotNull($combined[0]->keyboardCorrection);
        $this->assertNotNull($combined[0]->advancedCorrection);
        $this->assertSame('fwxkd', $combined[0]->advancedCorrection->originalQuery);
    }

    public function test_keyboard_spelling_then_phonetic_remains_bounded_with_all_provenance_layers(): void
    {
        config()->set('persian-search.spelling.enabled', true);
        config()->set('persian-search.spelling.maximum_tokens_to_correct', 1);
        $this->forgetCorrectionInstances();
        $this->index('orange-icecream-fa', 'fa', 'پرتقال بستنی');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $variants = PersianSearch::query('\\vjthg fwxkd')->locale('en')->variants();
        $combined = array_values(array_filter(
            $variants->all(),
            static fn (QueryVariant $variant): bool => $variant->query === 'پرتقال بستنی'
                && $variant->source === QueryVariantSource::KeyboardPhonetic,
        ));

        $this->assertCount(1, $combined);
        $this->assertNotNull($combined[0]->keyboardCorrection);
        $this->assertNotNull($combined[0]->spellingCorrection);
        $this->assertNotNull($combined[0]->advancedCorrection);
        $this->assertSame('\\vjthg fwxkd', $combined[0]->advancedCorrection->originalQuery);
        $this->assertLessThanOrEqual(20, $variants->count());
        $this->assertCount(count($variants), array_unique(array_map(
            static fn (QueryVariant $variant): string => $variant->semanticKey(),
            $variants->all(),
        )));
        $fingerprints = array_fill_keys(array_map(
            static fn (QueryVariant $variant): string => $variant->fingerprint,
            $variants->all(),
        ), true);
        foreach ($variants as $variant) {
            if ($variant->parentFingerprint !== null) {
                $this->assertArrayHasKey($variant->parentFingerprint, $fingerprints);
            }
        }
    }

    public function test_multiple_phonetic_tokens_compose_into_one_bounded_deterministic_correction(): void
    {
        $this->index('meal-fa', 'fa', 'بستنی غذا');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $first = PersianSearch::advancedCorrections(PersianSearch::processQuery('بصطنی قذا', 'fa'));
        $second = PersianSearch::advancedCorrections(PersianSearch::processQuery('بصطنی قذا', 'fa'));
        $combined = array_values(array_filter(
            $first->all(),
            static fn (AdvancedCorrection $correction): bool => $correction->correctedQuery === 'بستنی غذا',
        ));

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertCount(1, $combined);
        $this->assertSame($combined[0], $first->all()[0]);
        $this->assertSame([0, 1], array_map(
            static fn ($transformation): int => $transformation->tokenIndex,
            $combined[0]->transformations,
        ));
        $this->assertSame(3600, $combined[0]->weightedCost);
        $this->assertSame(3600, array_sum(array_map(
            static fn ($transformation): int => $transformation->weightedCost,
            $combined[0]->transformations,
        )));
        $this->assertSame(2, $combined[0]->transformationDepth);
        $this->assertCount(2, $combined[0]->transformations);
        $this->assertLessThanOrEqual(5, $first->count());
        $this->assertCount(2, array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'persian_search_dictionary_terms'),
        ));

        $results = PersianSearch::query('بصطنی قذا')->locale('fa')->type('page')->results();
        $this->assertSame('page:meal-fa', $results->items[0]->record->source_key);
        $this->assertSame('بستنی غذا', $results->suggestion?->query);
    }

    public function test_maximum_token_and_transformation_depth_limits_block_combined_phonetic_state(): void
    {
        $this->index('meal-fa', 'fa', 'بستنی غذا');
        app(SpellingDictionaryBuilder::class)->rebuild();

        config()->set('persian-search.spelling.phonetic.maximum_tokens_to_correct', 1);
        $this->forgetCorrectionInstances();
        $tokenLimited = PersianSearch::advancedCorrections(PersianSearch::processQuery('بصطنی قذا', 'fa'));
        $this->assertNotContains('بستنی غذا', array_column($tokenLimited->toArray(), 'corrected_query'));
        foreach ($tokenLimited as $correction) {
            $this->assertCount(1, $correction->transformations);
        }

        config()->set('persian-search.spelling.phonetic.maximum_tokens_to_correct', 2);
        config()->set('persian-search.spelling.maximum_transformation_depth', 1);
        $this->forgetCorrectionInstances();
        $depthLimited = PersianSearch::advancedCorrections(PersianSearch::processQuery('بصطنی قذا', 'fa'));
        $this->assertNotContains('بستنی غذا', array_column($depthLimited->toArray(), 'corrected_query'));
        foreach ($depthLimited as $correction) {
            $this->assertSame(1, $correction->transformationDepth);
        }

        config()->set('persian-search.spelling.maximum_transformation_depth', 2);
        config()->set('persian-search.spelling.phonetic.maximum_query_variants', 1);
        $this->forgetCorrectionInstances();
        $variantLimited = PersianSearch::advancedCorrections(PersianSearch::processQuery('بصطنی قذا', 'fa'));
        $this->assertCount(1, $variantLimited);
        $this->assertSame('بستنی غذا', $variantLimited->all()[0]->correctedQuery);
    }

    public function test_phase_one_spelling_parents_chain_into_phase_two_with_complete_lineage(): void
    {
        config()->set('persian-search.spelling.enabled', true);
        config()->set('persian-search.spelling.maximum_tokens_to_correct', 1);
        $this->forgetCorrectionInstances();
        $this->index('orange-phone', 'en', 'orange phone');
        $this->index('orange-icecream', 'fa', 'پرتقال بستنی');
        app(SpellingDictionaryBuilder::class)->rebuild();

        foreach ([
            ['query' => 'oragne fone', 'locale' => 'en', 'expected' => 'orange phone'],
            ['query' => 'پرتفال بصطنی', 'locale' => 'fa', 'expected' => 'پرتقال بستنی'],
        ] as $case) {
            $variants = PersianSearch::query($case['query'])->locale($case['locale'])->variants();
            $combined = array_values(array_filter(
                $variants->all(),
                static fn (QueryVariant $variant): bool => $variant->query === $case['expected']
                    && $variant->spellingCorrection !== null
                    && $variant->advancedCorrection !== null,
            ));

            $this->assertCount(1, $combined);
            $this->assertSame(QueryVariantSource::Phonetic, $combined[0]->source);
            $advanced = $combined[0]->advancedCorrection;
            $this->assertNotNull($advanced);
            $this->assertSame($case['query'], $advanced->originalQuery);
            $this->assertSame(1, $advanced->transformationDepth);
            $this->assertNotNull($combined[0]->parentFingerprint);
            $parents = array_column($variants->all(), null, 'fingerprint');
            $this->assertArrayHasKey($combined[0]->parentFingerprint, $parents);
            $this->assertNotNull($parents[$combined[0]->parentFingerprint]->spellingCorrection);
            $this->assertNull($parents[$combined[0]->parentFingerprint]->advancedCorrection);
            $this->assertCount(count($variants), array_unique(array_map(
                static fn (QueryVariant $variant): string => $variant->semanticKey(),
                $variants->all(),
            )));
        }

        $results = PersianSearch::query('oragne fone')->locale('en')->type('page')->results();
        $this->assertSame('page:orange-phone', $results->items[0]->record->source_key);
        $this->assertSame('orange phone', $results->suggestion?->query);
        $this->assertNull(
            PersianSearch::query('oragne fone')->locale('en')->type('product')->results()->suggestion,
        );
    }

    public function test_invalid_earlier_merge_pair_does_not_consume_accepted_merge_limit(): void
    {
        config()->set('persian-search.spelling.segmentation.maximum_merges_per_query', 1);
        $this->forgetCorrectionInstances();
        $this->index('website', 'en', 'website');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $corrections = PersianSearch::advancedCorrections(
            PersianSearch::processQuery('random words web site', 'en'),
        );
        $this->assertContains('random words website', array_column($corrections->toArray(), 'corrected_query'));

        $this->index('icecream', 'en', 'icecream');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $bounded = PersianSearch::advancedCorrections(
            PersianSearch::processQuery('ice cream web site', 'en'),
        );
        $this->assertContains('icecream web site', array_column($bounded->toArray(), 'corrected_query'));
        $this->assertContains('ice cream website', array_column($bounded->toArray(), 'corrected_query'));
        $this->assertNotContains('icecream website', array_column($bounded->toArray(), 'corrected_query'));
        foreach ($bounded as $correction) {
            $this->assertLessThanOrEqual(1, count(array_filter(
                $correction->transformations,
                static fn ($transformation): bool => $transformation->type->value === 'merge',
            )));
        }
    }

    public function test_transformation_depth_prevents_recursive_advanced_correction(): void
    {
        config()->set('persian-search.spelling.maximum_transformation_depth', 1);
        $this->forgetCorrectionInstances();
        $this->index('phone-en', 'en', 'phone');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $first = $this->advanced('fone', 'en');
        $this->assertNotNull($first);

        $parent = new QueryVariant(
            query: $first->correctedQuery,
            locale: $first->locale,
            tokens: $first->tokens,
            source: QueryVariantSource::Phonetic,
            priority: 640,
            fingerprint: 'advanced-parent',
            advancedCorrection: $first,
        );

        $this->assertTrue(app(AdvancedQueryCorrector::class)->correct($parent)->isEmpty());
    }

    public function test_disabling_phase_two_restores_phase_one_variants_exactly(): void
    {
        config()->set('persian-search.spelling.enabled', true);
        config()->set('persian-search.spelling.phonetic.enabled', false);
        config()->set('persian-search.spelling.segmentation.enabled', false);
        $this->forgetCorrectionInstances();
        $this->index('orange-en', 'en', 'orange');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $processed = PersianSearch::processQuery('oragne', 'en');
        $withoutAdvanced = new DefaultQueryExpander(
            app(QueryVariantPolicy::class),
            app(KeyboardLayoutCorrector::class),
            app(SynonymExpander::class),
            app(SpellingCorrector::class),
            null,
        );
        $expected = $withoutAdvanced->expand($processed)->toArray();
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->assertTrue(PersianSearch::advancedCorrections($processed)->isEmpty());
        $this->assertSame([], $queries);
        $actual = PersianSearch::query('oragne')->locale('en')->variants()->toArray();

        $this->assertSame($expected, $actual);
        $this->assertContains('spelling', array_column($actual, 'source'));
        $this->assertSame([], array_values(array_filter(
            array_column($actual, 'source'),
            static fn (string $source): bool => in_array($source, [
                'phonetic', 'split', 'merge', 'keyboard_phonetic', 'keyboard_split', 'keyboard_merge',
            ], true),
        )));
    }

    public function test_runtime_uses_one_bounded_parameterized_lookup_without_schema_queries(): void
    {
        foreach ([
            ['fa', 'بستنی'],
            ['fa', 'غذا'],
            ['en', 'ice cream'],
            ['en', 'search engine'],
            ['en', 'website'],
        ] as [$locale, $title]) {
            $this->index($locale.'-'.$title, $locale, $title);
        }
        app(SpellingDictionaryBuilder::class)->rebuild();
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = ['sql' => $event->sql, 'bindings' => $event->bindings];
        });

        $corrections = PersianSearch::advancedCorrections(
            PersianSearch::processQuery('icecream searchengine web site', 'en'),
        );
        $dictionaryQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['sql'], 'persian_search_dictionary_terms'),
        ));

        $this->assertFalse($corrections->isEmpty());
        $this->assertCount(1, $dictionaryQueries);
        $this->assertLessThanOrEqual(258, count($dictionaryQueries[0]['bindings']));
        $this->assertStringNotContainsString('icecream', $dictionaryQueries[0]['sql']);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('sqlite_master', strtolower($query['sql']));
            $this->assertStringNotContainsString('pragma', strtolower($query['sql']));
        }
    }

    public function test_missing_dictionary_is_fail_soft_or_fail_closed_by_existing_policy(): void
    {
        Schema::dropIfExists('persian_search_dictionary_deletes');
        Schema::dropIfExists('persian_search_dictionary_terms');

        $this->assertTrue(PersianSearch::advancedCorrections(
            PersianSearch::processQuery('fone', 'en'),
        )->isEmpty());

        config()->set('persian-search.spelling.fail_when_unavailable', true);
        $this->forgetCorrectionInstances();
        $this->expectException(SpellingDictionaryUnavailableException::class);
        PersianSearch::advancedCorrections(PersianSearch::processQuery('fone', 'en'));
    }

    public function test_suggestion_uses_effective_advanced_variant_and_respects_type_filter(): void
    {
        $this->index('icecream-fa', 'fa', 'بستنی', 'page');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $results = PersianSearch::query('بصطنی')->locale('fa')->type('page')->results();
        $excluded = PersianSearch::query('بصطنی')->locale('fa')->type('product')->results();

        $this->assertSame('بستنی', $results->suggestion?->query);
        $this->assertSame(QueryVariantSource::Phonetic, $results->suggestion->source);
        $this->assertNull($excluded->suggestion);
    }

    public function test_dictionary_status_reports_profiles_and_advanced_readiness(): void
    {
        $this->index('phone-en', 'en', 'phone');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $status = app(SpellingDictionaryStatusService::class)->snapshot();

        $this->assertSame(['en', 'fa'], $status->supportedProfiles);
        $this->assertSame(['en', 'fa'], $status->enabledProfiles);
        $this->assertTrue($status->phoneticReady);
        $this->assertTrue($status->splitReady);
        $this->assertTrue($status->mergeReady);
        $this->assertSame([], $status->warnings);
    }

    private function advanced(string $query, string $locale): ?AdvancedCorrection
    {
        return PersianSearch::advancedCorrections(
            PersianSearch::processQuery($query, $locale),
        )->all()[0] ?? null;
    }

    private function index(string $key, string $locale, string $title, string $type = 'page'): void
    {
        $normalized = PersianSearch::normalize($title, $locale);
        PersianSearch::indexDocument(new SearchDocument(
            partition: 'default',
            sourceKey: $type.':'.$key,
            sourceType: $type,
            sourceId: null,
            locale: $locale,
            title: $title,
            excerpt: null,
            normalizedTitle: $normalized,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $normalized,
        ));
    }

    private function forgetCorrectionInstances(): void
    {
        foreach ([
            SpellingPolicy::class,
            DatabaseSpellingCorrector::class,
            SpellingCorrector::class,
            AdvancedCorrectionPolicyFactory::class,
            AdvancedCorrectionPolicy::class,
            LanguageCorrectionProfileRegistry::class,
            DatabaseAdvancedQueryCorrector::class,
            AdvancedQueryCorrector::class,
            SpellingDictionaryBuilder::class,
            DefaultQueryExpander::class,
            QueryExpander::class,
            PersianSearchManager::class,
        ] as $abstract) {
            app()->forgetInstance($abstract);
        }
        PersianSearch::clearResolvedInstance(PersianSearchManager::class);
    }
}
