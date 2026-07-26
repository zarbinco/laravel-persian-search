<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SpellingCorrector;
use Zarbinco\PersianSearch\Exceptions\InvalidSpellingConfigurationException;
use Zarbinco\PersianSearch\Exceptions\SpellingDictionaryUnavailableException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\PersianSearchManager;
use Zarbinco\PersianSearch\Query\DefaultQueryExpander;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Spelling\DatabaseSpellingCorrector;
use Zarbinco\PersianSearch\Spelling\SpellingDictionaryBuilder;
use Zarbinco\PersianSearch\Spelling\SpellingPolicy;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SpellingCorrectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.spelling.enabled', true);
        config()->set('persian-search.spelling.correction.maximum_query_variants', 5);
        (require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php')->up();
        (require __DIR__.'/../../database/migrations/create_persian_search_dictionary_tables.php')->up();
    }

    public function test_dictionary_builds_from_active_multilingual_documents_and_corrects_all_basic_edit_types(): void
    {
        PersianSearch::indexDocument($this->document('orange-fa', 'fa', 'پرتقال'));
        PersianSearch::indexDocument($this->document('icecream-fa', 'fa', 'بستنی'));
        PersianSearch::indexDocument($this->document('orange-en', 'en', 'orange'));
        PersianSearch::indexDocument($this->document('chocolate-fr', 'fr', 'chocolat'));
        PersianSearch::indexDocument($this->document('inactive', 'fa', 'غیرفعال', active: false));

        $result = app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertSame(4, $result->documents);
        $this->assertSame(4, $result->terms);
        $this->assertGreaterThan(3, $result->deletes);
        $this->assertSame('پرتقال', $this->corrected('پرتفال', 'fa'));
        $this->assertSame('پرتقال', $this->corrected('پرتال', 'fa'));
        $this->assertSame('پرتقال', $this->corrected('پرتتقال', 'fa'));
        $this->assertSame('پرتقال', $this->corrected('پترقال', 'fa'));
        $this->assertSame('orange', $this->corrected('oragne', 'en'));
        $this->assertSame('orange', $this->corrected('ornge', 'en'));
        $this->assertSame('orange', $this->corrected('orannge', 'en'));
        $this->assertSame('chocolat', $this->corrected('chocloat', 'fr'));
        $this->assertSame('بستنی', $this->corrected('بستنیی', 'fa'));
    }

    public function test_two_edit_correction_is_reserved_for_long_terms(): void
    {
        PersianSearch::indexDocument($this->document('chocolate-en', 'en', 'chocolate'));
        PersianSearch::indexDocument($this->document('orange-en', 'en', 'orange'));
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertSame('chocolate', $this->corrected('choclatex', 'en'));
        $this->assertNull($this->corrected('ornagx', 'en'));
    }

    public function test_exact_dictionary_terms_short_tokens_and_other_locales_are_not_changed(): void
    {
        PersianSearch::indexDocument($this->document('orange-fa', 'fa', 'پرتقال'));
        PersianSearch::indexDocument($this->document('orange-en', 'en', 'orange'));
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertNull($this->corrected('پرتقال', 'fa'));
        $this->assertNull($this->corrected('abc', 'en'));
        $this->assertNull($this->corrected('oragne', 'fa'));
    }

    public function test_region_locale_falls_back_to_its_base_language_dictionary(): void
    {
        PersianSearch::indexDocument($this->document('orange-en', 'en', 'orange'));
        app(SpellingDictionaryBuilder::class)->rebuild();

        $correction = PersianSearch::spellingCorrections(
            PersianSearch::processQuery('oragne', 'en-GB'),
        )->all()[0] ?? null;

        $this->assertNotNull($correction);
        $this->assertSame('orange', $correction->correctedQuery);
        $this->assertSame('en', $correction->locale);
    }

    public function test_configured_protected_terms_are_never_rewritten_even_when_absent_from_the_dictionary(): void
    {
        config()->set('persian-search.spelling.dictionary.protected_terms.fa', ['پرتفال']);
        PersianSearch::indexDocument($this->document('orange-fa', 'fa', 'پرتقال'));
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertNull($this->corrected('پرتفال', 'fa'));
    }

    public function test_spelling_variant_and_did_you_mean_evidence_use_the_existing_search_pipeline(): void
    {
        PersianSearch::indexDocument($this->document('orange-fa', 'fa', 'پرتقال'));
        app(SpellingDictionaryBuilder::class)->rebuild();

        $variants = PersianSearch::query('پرتفال')->locale('fa')->variants();
        $spelling = array_values(array_filter(
            $variants->all(),
            static fn ($variant): bool => $variant->source === QueryVariantSource::Spelling,
        ));

        $this->assertCount(1, $spelling);
        $this->assertSame('پرتقال', $spelling[0]->query);
        $spellingCorrection = $spelling[0]->spellingCorrection;
        $this->assertNotNull($spellingCorrection);
        $this->assertSame('پرتفال', $spellingCorrection->corrections[0]->original);
        $this->assertSame('پرتقال', $spellingCorrection->corrections[0]->corrected);

        $results = PersianSearch::query('پرتفال')->locale('fa')->type('page')->results();
        $this->assertCount(1, $results->items);
        $this->assertNotNull($results->suggestion);
        $this->assertSame('پرتقال', $results->suggestion->query);
        $this->assertSame(QueryVariantSource::Spelling, $results->suggestion->source);
    }

    public function test_keyboard_then_spelling_correction_keeps_complete_provenance(): void
    {
        PersianSearch::indexDocument($this->document('orange-fa', 'fa', 'پرتقال'));
        app(SpellingDictionaryBuilder::class)->rebuild();

        $variants = PersianSearch::query('\\vjthg')->locale('en')->variants()->all();
        $combined = array_values(array_filter(
            $variants,
            static fn ($variant): bool => $variant->source === QueryVariantSource::KeyboardSpelling,
        ));

        $this->assertCount(1, $combined);
        $this->assertSame('پرتقال', $combined[0]->query);
        $this->assertNotNull($combined[0]->keyboardCorrection);
        $this->assertNotNull($combined[0]->spellingCorrection);
        $this->assertSame('پرتفال', $combined[0]->spellingCorrection->originalQuery);
    }

    public function test_multiple_tokens_are_corrected_with_bounded_deterministic_variants(): void
    {
        PersianSearch::indexDocument($this->document('orange-fa', 'fa', 'پرتقال'));
        PersianSearch::indexDocument($this->document('icecream-fa', 'fa', 'بستنی'));
        app(SpellingDictionaryBuilder::class)->rebuild();

        $first = PersianSearch::spellingCorrections(PersianSearch::processQuery('پرتفال بستنیی', 'fa'));
        $second = PersianSearch::spellingCorrections(PersianSearch::processQuery('پرتفال بستنیی', 'fa'));

        $this->assertLessThanOrEqual(5, $first->count());
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame('پرتقال بستنی', $first->all()[0]->correctedQuery);
        $this->assertCount(2, $first->all()[0]->corrections);
    }

    public function test_missing_dictionary_tables_fail_soft_by_default(): void
    {
        Schema::dropIfExists('persian_search_dictionary_deletes');
        Schema::dropIfExists('persian_search_dictionary_terms');

        $variants = PersianSearch::query('oragne')->locale('en')->variants();
        $spelling = array_values(array_filter(
            $variants->all(),
            static fn ($variant): bool => in_array($variant->source, [
                QueryVariantSource::Spelling,
                QueryVariantSource::KeyboardSpelling,
            ], true),
        ));

        $this->assertSame([], $spelling);
        $this->assertSame(QueryVariantSource::Original, $variants->original()?->source);
    }

    public function test_missing_dictionary_tables_can_fail_closed_explicitly(): void
    {
        Schema::dropIfExists('persian_search_dictionary_deletes');
        Schema::dropIfExists('persian_search_dictionary_terms');
        config()->set('persian-search.spelling.fail_when_unavailable', true);

        $this->expectException(SpellingDictionaryUnavailableException::class);
        PersianSearch::query('oragne')->locale('en')->variants();
    }

    public function test_disabled_spelling_executes_no_dictionary_query_and_keeps_existing_expansion_behavior(): void
    {
        config()->set('persian-search.spelling.enabled', false);
        app()->forgetInstance(SpellingPolicy::class);
        app()->forgetInstance(DatabaseSpellingCorrector::class);
        app()->forgetInstance(SpellingCorrector::class);
        app()->forgetInstance(DefaultQueryExpander::class);
        app()->forgetInstance(QueryExpander::class);
        app()->forgetInstance(PersianSearchManager::class);
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $variants = PersianSearch::query('oragne')->locale('en')->variants();
        $spelling = array_values(array_filter(
            $variants->all(),
            static fn ($variant): bool => in_array($variant->source, [
                QueryVariantSource::Spelling,
                QueryVariantSource::KeyboardSpelling,
            ], true),
        ));

        $this->assertSame([], $spelling);
        $this->assertSame([], array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'persian_search_dictionary'),
        )));
    }

    public function test_runtime_uses_bounded_parameterized_dictionary_queries(): void
    {
        PersianSearch::indexDocument($this->document('orange-en', 'en', 'orange'));
        for ($index = 0; $index < 100; $index++) {
            PersianSearch::indexDocument($this->document('term-'.$index, 'en', 'term'.$index));
        }
        app(SpellingDictionaryBuilder::class)->rebuild();
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $correction = $this->corrected('oragne%', 'en');
        $dictionaryQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'persian_search_dictionary'),
        ));

        $this->assertSame('orange', $correction);
        $this->assertLessThanOrEqual(2, count($dictionaryQueries));
        foreach ($dictionaryQueries as $sql) {
            $this->assertStringNotContainsString('oragne%', $sql);
        }
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('sqlite_master', strtolower($sql));
            $this->assertStringNotContainsString('pragma', strtolower($sql));
        }
    }

    public function test_multi_token_runtime_batches_dictionary_candidates_into_two_bounded_queries(): void
    {
        foreach ([
            'orange',
            'chocolate',
            'banana',
            'vanilla',
            'strawberry',
        ] as $term) {
            PersianSearch::indexDocument($this->document($term, 'en', $term));
        }
        app(SpellingDictionaryBuilder::class)->rebuild();

        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'persian_search_dictionary')) {
                $queries[] = [
                    'sql' => $event->sql,
                    'bindings' => $event->bindings,
                ];
            }
        });

        $corrections = PersianSearch::spellingCorrections(
            PersianSearch::processQuery('oragne choclate bananna vanila strawbery', 'en'),
        );

        $this->assertFalse($corrections->isEmpty());
        $this->assertLessThanOrEqual(2, count($queries));
        foreach ($queries as $query) {
            $this->assertLessThanOrEqual(514, count($query['bindings']));
            $this->assertStringNotContainsString('oragne', $query['sql']);
        }
    }

    public function test_dictionary_limit_fails_before_replacing_the_previous_dictionary(): void
    {
        config()->set('persian-search.spelling.dictionary.maximum_terms', 1);
        PersianSearch::indexDocument($this->document('orange-en', 'en', 'orange'));
        app(SpellingDictionaryBuilder::class)->rebuild();
        PersianSearch::indexDocument($this->document('chocolate-en', 'en', 'chocolate'));

        try {
            app(SpellingDictionaryBuilder::class)->rebuild();
            $this->fail('The configured dictionary term limit was not enforced.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('persian_search_dictionary_terms', [
            'locale' => 'en',
            'normalized_term' => 'orange',
        ]);
        $this->assertDatabaseMissing('persian_search_dictionary_terms', [
            'locale' => 'en',
            'normalized_term' => 'chocolate',
        ]);
    }

    public function test_invalid_configuration_fails_closed_before_querying(): void
    {
        config()->set('persian-search.spelling.correction.maximum_edit_distance', 9);
        app()->forgetInstance(SpellingPolicy::class);

        $this->expectException(InvalidSpellingConfigurationException::class);
        app(SpellingPolicy::class);
    }

    private function corrected(string $query, string $locale): ?string
    {
        return PersianSearch::spellingCorrections(PersianSearch::processQuery($query, $locale))->all()[0]->correctedQuery ?? null;
    }

    private function document(string $key, string $locale, string $title, bool $active = true): SearchDocument
    {
        return new SearchDocument(
            partition: 'default',
            sourceKey: 'page:'.$key,
            sourceType: 'page',
            sourceId: null,
            locale: $locale,
            title: $title,
            excerpt: null,
            normalizedTitle: $title,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $title,
            isActive: $active,
        );
    }
}
