<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Zarbinco\PersianSearch\Contextual\ContextualCorrectionPolicy;
use Zarbinco\PersianSearch\Contextual\ContextualDecision;
use Zarbinco\PersianSearch\Contextual\ContextualNgramBuilder;
use Zarbinco\PersianSearch\Contextual\DatabaseCandidateResultCounter;
use Zarbinco\PersianSearch\Contextual\DatabaseContextualCandidateGenerator;
use Zarbinco\PersianSearch\Contextual\DatabaseCorrectionEvidenceProvider;
use Zarbinco\PersianSearch\Contextual\DefaultContextualCorrectionEvaluator;
use Zarbinco\PersianSearch\Contracts\CandidateResultCounter;
use Zarbinco\PersianSearch\Contracts\ContextualCorrectionEvaluator;
use Zarbinco\PersianSearch\Contracts\CorrectionEvidenceProvider;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Correction\AdvancedCorrectionPolicy;
use Zarbinco\PersianSearch\Drivers\DatabaseSearchDriver;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\PersianSearchManager;
use Zarbinco\PersianSearch\Query\DefaultQueryExpander;
use Zarbinco\PersianSearch\Query\QueryVariantPolicy;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Search\SearchExecutionProcessor;
use Zarbinco\PersianSearch\Spelling\SpellingDictionaryBuilder;
use Zarbinco\PersianSearch\Spelling\SpellingDictionaryStatusService;
use Zarbinco\PersianSearch\Spelling\SpellingPolicy;
use Zarbinco\PersianSearch\Tests\TestCase;

final class ContextualCorrectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.spelling.enabled', false);
        config()->set('persian-search.spelling.phonetic.enabled', true);
        config()->set('persian-search.contextual.enabled', true);
        config()->set('persian-search.contextual.ngrams_enabled', true);
        config()->set('persian-search.contextual.result_counts_enabled', true);
        config()->set('persian-search.contextual.auto_apply_recommendation_enabled', true);

        (require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php')->up();
        (require __DIR__.'/../../database/migrations/create_persian_search_dictionary_tables.php')->up();
        (require __DIR__.'/../../database/migrations/create_persian_search_contextual_ngrams_table.php')->up();
    }

    public function test_persian_real_word_correction_uses_result_and_corpus_evidence(): void
    {
        $this->index('original-valid', 'fa', 'پرتغال', 'reference');
        $this->indexMany(8, 'fa', 'پرتقال', 'page', 'orange');
        $build = app(SpellingDictionaryBuilder::class)->rebuild();

        $results = PersianSearch::query('پرتغال')->locale('fa')->type('page')->results();
        $correction = $results->suggestion?->contextualCorrection;

        $this->assertSame(0, $build->ngrams);
        $this->assertDatabaseHas('persian_search_dictionary_terms', [
            'locale' => 'fa',
            'normalized_term' => 'پرتغال',
        ]);
        $this->assertDatabaseHas('persian_search_dictionary_terms', [
            'locale' => 'fa',
            'normalized_term' => 'پرتقال',
        ]);
        $suggestion = $results->suggestion;
        $this->assertNotNull($suggestion);
        $this->assertSame('پرتقال', $suggestion->query);
        $this->assertSame(QueryVariantSource::Contextual, $suggestion->source);
        $this->assertNotNull($correction);
        $this->assertSame(0, $correction->directResults->count);
        $this->assertSame(8, $correction->candidateResults->count);
        $this->assertSame('high', $correction->confidence->value);
        $this->assertSame(ContextualDecision::AutoApplyAllowed, $correction->decision);
        $this->assertSame('پرتغال', $correction->candidate->originalQuery);
        $this->assertSame('پرتغال', $correction->candidate->parent->query);
        $this->assertContains('پرتغال', array_map(
            static fn ($variant): string => $variant->query,
            $results->variants->all(),
        ));
    }

    public function test_nonzero_direct_evidence_can_only_recommend_suggestion(): void
    {
        $this->indexMany(2, 'fa', 'پرتغال', 'page', 'original');
        $this->indexMany(8, 'fa', 'پرتقال', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $correction = PersianSearch::query('پرتغال')
            ->locale('fa')
            ->type('page')
            ->results()
            ->suggestion?->contextualCorrection;

        $this->assertNotNull($correction);
        $this->assertSame(2, $correction->directResults->count);
        $this->assertSame(ContextualDecision::SuggestOnly, $correction->decision);
    }

    public function test_strong_direct_results_and_zero_candidate_results_suppress_correction(): void
    {
        $this->indexMany(4, 'fa', 'پرتغال', 'page', 'strong-original');
        $this->indexMany(8, 'fa', 'پرتقال', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertNull(PersianSearch::query('پرتغال')->locale('fa')->type('page')->results()->suggestion);

        $this->resetTables();
        $this->index('original-valid', 'fa', 'پرتغال', 'reference');
        $this->indexMany(8, 'fa', 'پرتقال', 'reference', 'candidate-only-other-type');
        $this->index('inactive-candidate', 'fa', 'پرتقال', 'page', false);
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertNull(PersianSearch::query('پرتغال')->locale('fa')->type('page')->results()->suggestion);
    }

    public function test_bigrams_favour_the_candidate_and_stronger_original_context_suppresses_it(): void
    {
        $this->index('original-valid', 'en', 'form', 'reference');
        $this->indexMany(6, 'en', 'message from home', 'page', 'candidate-context');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $results = PersianSearch::query('message form home')->locale('en')->type('page')->results();
        $correction = $results->suggestion?->contextualCorrection;

        $this->assertSame('message from home', $results->suggestion?->query);
        $this->assertNotNull($correction);
        $this->assertGreaterThan(0, $correction->evidence->candidateContextScore);
        $this->assertSame(0, $correction->evidence->originalContextScore);

        $this->resetTables();
        $this->indexMany(7, 'en', 'message form home', 'reference', 'original-context');
        $this->indexMany(6, 'en', 'message from home', 'page', 'candidate-context');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertNull(
            PersianSearch::query('message form home')->locale('en')->type('page')->results()->suggestion,
        );
    }

    public function test_locale_isolation_regional_fallback_and_protected_terms(): void
    {
        $this->index('original-en', 'en', 'form', 'reference');
        $this->indexMany(6, 'en', 'from', 'page', 'from-en');
        $this->indexMany(8, 'fr', 'forme', 'page', 'forme-fr');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $regional = PersianSearch::query('form')->locale('en-GB')->type('page')->results();

        $regionalSuggestion = $regional->suggestion;
        $this->assertNotNull($regionalSuggestion);
        $this->assertSame('from', $regionalSuggestion->query);
        $this->assertSame('en', $regionalSuggestion->locale);
        $this->assertNull(PersianSearch::query('form')->locale('fr')->type('page')->results()->suggestion);

        config()->set('persian-search.spelling.dictionary.protected_terms.en', ['form']);
        $this->forgetContextualInstances();
        $this->assertNull(PersianSearch::query('form')->locale('en')->type('page')->results()->suggestion);
    }

    public function test_contextual_work_is_bounded_and_preview_is_off_by_default(): void
    {
        $this->index('original-valid', 'fa', 'پرتغال', 'reference');
        $this->indexMany(8, 'fa', 'پرتقال', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $preview = PersianSearch::query('پرتغال')->locale('fa')->type('page')->preview();

        $this->assertNull($preview->suggestion);
        $this->assertSame([], array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'persian_search_dictionary_ngrams')
                || str_contains($sql, 'contextual_delete'),
        )));

        $resultQueries = [];
        DB::listen(static function ($event) use (&$resultQueries): void {
            $resultQueries[] = $event->sql;
        });
        PersianSearch::query('پرتغال')->locale('fa')->type('page')->results();
        $dictionaryQueries = array_values(array_filter(
            $resultQueries,
            static fn (string $sql): bool => str_contains($sql, 'persian_search_dictionary_'),
        ));

        $this->assertLessThanOrEqual(3, count($dictionaryQueries));
        foreach ($resultQueries as $sql) {
            $this->assertStringNotContainsString('sqlite_master', strtolower($sql));
            $this->assertStringNotContainsString('pragma', strtolower($sql));
        }
    }

    public function test_ngram_rebuild_is_idempotent_locale_scoped_and_reported_ready(): void
    {
        $this->indexMany(2, 'en', 'fresh orange juice', 'page', 'en');
        $this->indexMany(2, 'fa', 'آب پرتقال طبیعی', 'page', 'fa');
        $first = app(SpellingDictionaryBuilder::class)->rebuild();
        $second = app(ContextualNgramBuilder::class)->rebuild(['en']);
        $status = app(SpellingDictionaryStatusService::class)->snapshot();

        $this->assertGreaterThan(0, $first->ngrams);
        $this->assertSame(2, $second->documents);
        $this->assertSame(2, $second->ngrams);
        $this->assertSame(4, DB::table('persian_search_dictionary_ngrams')->count());
        $this->assertTrue($status->ngramsTableExists);
        $this->assertTrue($status->contextualReady);
        $this->assertSame([], $status->warnings);
    }

    public function test_successful_zero_row_ngram_generation_is_ready_but_provides_zero_context_gain(): void
    {
        config()->set('persian-search.contextual.build.minimum_document_frequency', 100);
        $this->forgetContextualInstances();
        $this->index('form', 'en', 'form', 'reference');
        $this->indexMany(8, 'en', 'message from home', 'page', 'candidate');

        $build = app(SpellingDictionaryBuilder::class)->rebuild();
        $status = app(SpellingDictionaryStatusService::class)->snapshot();
        $variants = PersianSearch::query('message form home')->locale('en')->type('page')->variants();
        $candidates = app(DatabaseContextualCandidateGenerator::class)->generate($variants);
        $candidate = $candidates->all()[0] ?? null;
        $evidence = $candidate === null
            ? null
            : app(DatabaseCorrectionEvidenceProvider::class)->evidenceFor($candidates)->get($candidate->fingerprint);

        $this->assertSame(0, $build->ngrams);
        $this->assertTrue($status->contextualReady);
        $this->assertTrue($status->contextualReadyByLocale['en']);
        $this->assertSame(0, $status->localeNgramCounts['en']);
        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->ngramsReady);
        $this->assertTrue($evidence->contextAvailable);
        $this->assertSame(0, $evidence->contextGain());
        $this->assertNull(
            PersianSearch::query('message form home')->locale('en')->type('page')->results()->suggestion,
        );

        config()->set('persian-search.contextual.result_counts_enabled', false);
        $this->forgetContextualInstances();
        $this->assertNull(
            PersianSearch::query('message form home')->locale('en')->type('page')->results()->suggestion,
        );
    }

    public function test_mismatched_generation_and_null_completion_timestamp_are_not_ready_with_old_rows(): void
    {
        $this->index('form', 'en', 'form', 'reference');
        $this->indexMany(8, 'en', 'message from home', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $this->assertGreaterThan(0, DB::table('persian_search_dictionary_ngrams')->count());
        $generation = (string) DB::table('persian_search_contextual_builds')
            ->where('locale', 'en')
            ->value('dictionary_generation');

        DB::table('persian_search_contextual_builds')->where('locale', 'en')->update([
            'ngram_generation' => 'stale-generation',
        ]);
        $mismatched = app(SpellingDictionaryStatusService::class)->snapshot();
        $this->assertFalse($mismatched->contextualReadyByLocale['en']);

        DB::table('persian_search_contextual_builds')->where('locale', 'en')->update([
            'ngram_generation' => $generation,
            'ngram_indexed_at' => null,
        ]);
        $missingCompletion = app(SpellingDictionaryStatusService::class)->snapshot();
        $this->assertFalse($missingCompletion->contextualReadyByLocale['en']);

        $variants = PersianSearch::query('message form home')->locale('en')->type('page')->variants();
        $candidates = app(DatabaseContextualCandidateGenerator::class)->generate($variants);
        $candidate = $candidates->all()[0] ?? null;
        $this->assertNotNull($candidate);
        $evidence = app(DatabaseCorrectionEvidenceProvider::class)
            ->evidenceFor($candidates)
            ->get($candidate->fingerprint);
        $this->assertNotNull($evidence);
        $this->assertFalse($evidence->ngramsReady);
        $this->assertFalse($evidence->contextAvailable);
    }

    public function test_evidence_provider_fails_soft_only_for_the_missing_configured_metadata_table(): void
    {
        $this->index('form', 'en', 'form', 'reference');
        $this->indexMany(8, 'en', 'message from home', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $variants = PersianSearch::query('message form home')->locale('en')->type('page')->variants();
        $candidates = app(DatabaseContextualCandidateGenerator::class)->generate($variants);
        $this->assertNotEmpty($candidates->all());
        Schema::drop('persian_search_contextual_builds');

        $candidate = $candidates->all()[0];
        $evidence = app(DatabaseCorrectionEvidenceProvider::class)
            ->evidenceFor($candidates)
            ->get($candidate->fingerprint);

        $this->assertNotNull($evidence);
        $this->assertFalse($evidence->ngramsReady);
        $this->assertFalse($evidence->contextAvailable);
    }

    public function test_evidence_provider_fails_soft_for_the_missing_configured_ngram_table(): void
    {
        $this->index('form', 'en', 'form', 'reference');
        $this->indexMany(8, 'en', 'message from home', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $variants = PersianSearch::query('message form home')->locale('en')->type('page')->variants();
        $candidates = app(DatabaseContextualCandidateGenerator::class)->generate($variants);
        $candidate = $candidates->all()[0] ?? null;
        $this->assertNotNull($candidate);
        Schema::drop('persian_search_dictionary_ngrams');

        $evidence = app(DatabaseCorrectionEvidenceProvider::class)
            ->evidenceFor($candidates)
            ->get($candidate->fingerprint);

        $this->assertNotNull($evidence);
        $this->assertFalse($evidence->ngramsReady);
        $this->assertFalse($evidence->contextAvailable);
    }

    #[DataProvider('nonMissingBuildQueryFailures')]
    public function test_evidence_provider_rethrows_non_missing_table_metadata_query_failures(
        string $sqlState,
        string $message,
        bool $configuredTableSql,
    ): void {
        $this->index('form', 'en', 'form', 'reference');
        $this->indexMany(8, 'en', 'message from home', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $variants = PersianSearch::query('message form home')->locale('en')->type('page')->variants();
        $candidates = app(DatabaseContextualCandidateGenerator::class)->generate($variants);
        $this->assertNotEmpty($candidates->all());
        $observed = null;
        DB::listen(static function ($event) use (
            $sqlState,
            $message,
            $configuredTableSql,
            &$observed,
        ): void {
            if (! str_contains($event->sql, 'persian_search_contextual_builds')) {
                return;
            }
            $previous = new PDOException($message);
            $previous->errorInfo = [$sqlState, 0, $message];
            $failedSql = $configuredTableSql
                ? $event->sql
                : 'select * from unrelated_application_table';
            $observed = new QueryException('testing', $failedSql, $event->bindings, $previous);

            throw $observed;
        });

        try {
            app(DatabaseCorrectionEvidenceProvider::class)->evidenceFor($candidates);
            $this->fail('The non-missing-table query failure was not rethrown.');
        } catch (QueryException $exception) {
            $this->assertSame($observed, $exception);
        }
    }

    /** @return array<string, array{string, string, bool}> */
    public static function nonMissingBuildQueryFailures(): array
    {
        return [
            'permission denied' => ['42501', 'permission denied', true],
            'invalid column' => ['42703', 'undefined column', true],
            'connection failure' => ['08006', 'connection failure', true],
            'unrelated missing table' => ['42P01', 'undefined relation', false],
        ];
    }

    public function test_full_rebuild_removes_obsolete_locale_metadata_while_locale_rebuild_preserves_it(): void
    {
        $this->index('en', 'en', 'fresh orange juice', 'page');
        $this->index('fa', 'fa', 'آب پرتقال طبیعی', 'page');
        app(SpellingDictionaryBuilder::class)->rebuild();
        DB::table('persian_search_documents')->where('locale', 'fa')->delete();

        app(SpellingDictionaryBuilder::class)->rebuild(['en']);
        $this->assertDatabaseHas('persian_search_contextual_builds', ['locale' => 'fa']);

        app(SpellingDictionaryBuilder::class)->rebuild();
        $this->assertDatabaseMissing('persian_search_contextual_builds', ['locale' => 'fa']);
        $this->assertDatabaseHas('persian_search_contextual_builds', ['locale' => 'en']);
    }

    public function test_two_real_word_tokens_compose_deterministically_within_depth_limits(): void
    {
        $this->index('form-valid', 'en', 'form', 'reference');
        $this->index('angel-valid', 'en', 'angel', 'reference');
        $this->indexMany(6, 'en', 'from angle', 'page', 'combined');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $first = PersianSearch::query('form angel')->locale('en')->type('page')->results();
        $second = PersianSearch::query('form angel')->locale('en')->type('page')->results();
        $correction = $first->suggestion?->contextualCorrection;

        $firstSuggestion = $first->suggestion;
        $secondSuggestion = $second->suggestion;
        $this->assertNotNull($firstSuggestion);
        $this->assertNotNull($secondSuggestion);
        $this->assertSame('from angle', $firstSuggestion->query);
        $this->assertSame($firstSuggestion->toArray(), $secondSuggestion->toArray());
        $this->assertNotNull($correction);
        $this->assertCount(2, $correction->candidate->corrections);
        $this->assertSame([0, 1], array_map(
            static fn ($item): int => $item->tokenIndex,
            $correction->candidate->corrections,
        ));
        $this->assertLessThanOrEqual(5, count(array_filter(
            $first->variants->all(),
            static fn ($variant): bool => $variant->source === QueryVariantSource::Contextual,
        )));
    }

    public function test_phase_one_parent_provenance_is_retained_by_contextual_correction(): void
    {
        config()->set('persian-search.spelling.enabled', true);
        $this->forgetContextualInstances();
        $this->index('form-valid', 'en', 'form', 'reference');
        $this->indexMany(6, 'en', 'orange from', 'page', 'combined');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $results = PersianSearch::query('oragne form')->locale('en')->type('page')->results();
        $correction = $results->suggestion?->contextualCorrection;
        $variant = array_values(array_filter(
            $results->variants->all(),
            static fn ($candidate): bool => $candidate->source === QueryVariantSource::Contextual
                && $candidate->query === 'orange from',
        ))[0] ?? null;

        $this->assertSame('orange from', $results->suggestion?->query);
        $this->assertNotNull($variant);
        $this->assertNotNull($correction);
        $this->assertNotNull($variant->spellingCorrection);
        $this->assertNotNull($variant->contextualCorrection);
        $this->assertSame('oragne form', $correction->candidate->originalQuery);
        $this->assertSame($variant->parentFingerprint, $correction->candidate->parent->fingerprint);
    }

    public function test_contextual_result_gain_uses_the_retained_parent_baseline(): void
    {
        config()->set('persian-search.spelling.enabled', true);
        $this->forgetContextualInstances();
        $this->indexMany(50, 'en', 'orange form', 'page', 'parent');
        $this->indexMany(60, 'en', 'orange from', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        $results = PersianSearch::query('oragne form')->locale('en')->type('page')->results();
        $parentSearches = array_values(array_filter(
            DB::connection()->getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'persian_search_documents')
                && str_contains($query['query'], 'select *')
                && in_array('%orange form%', $query['bindings'], true),
        ));

        $this->assertNotSame('orange from', $results->suggestion?->query);
        $this->assertCount(2, $parentSearches);
        $this->assertSame([], array_values(array_filter(
            $results->variants->all(),
            static fn (QueryVariant $variant): bool => $variant->source === QueryVariantSource::Contextual,
        )));

        $this->resetTables();
        $this->index('orange-term', 'en', 'orange', 'reference');
        $this->index('form-term', 'en', 'form', 'reference');
        $this->indexMany(8, 'en', 'orange from', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $corrected = PersianSearch::query('oragne form')->locale('en')->type('page')->results();
        $correction = $corrected->suggestion?->contextualCorrection;
        $variant = array_values(array_filter(
            $corrected->variants->all(),
            static fn (QueryVariant $item): bool => $item->source === QueryVariantSource::Contextual,
        ))[0] ?? null;

        $this->assertSame('orange from', $corrected->suggestion?->query);
        $this->assertNotNull($correction);
        $this->assertNotNull($variant);
        $this->assertSame(0, $correction->directResults->count);
        $this->assertSame(0, $correction->parentResults->count);
        $this->assertSame(8, $correction->candidateResults->count);
        $this->assertSame('oragne form', $correction->candidate->originalQuery);
        $this->assertSame('orange form', $correction->candidate->parent->query);
        $this->assertNotNull($variant->spellingCorrection);
        $this->assertSame($correction, $variant->contextualCorrection);
    }

    public function test_candidates_are_globally_ranked_across_supported_parents_with_batched_lookups(): void
    {
        config()->set('persian-search.contextual.limits.maximum_candidates_per_query', 1);
        config()->set('persian-search.contextual.limits.maximum_result_count_candidates', 1);
        config()->set('persian-search.spelling.dictionary.protected_terms.en', ['oragne']);
        $this->forgetContextualInstances();
        $this->indexMany(5, 'en', 'oragne farm', 'reference', 'raw');
        $this->indexMany(6, 'en', 'harm', 'reference', 'weak');
        $this->index('form', 'en', 'form', 'reference');
        $this->indexMany(20, 'en', 'orange from', 'page', 'strong');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $policy = app(QueryVariantPolicy::class);
        $original = new QueryVariant(
            'oragne farm',
            'en',
            ['oragne', 'farm'],
            QueryVariantSource::Original,
            $policy->priority(QueryVariantSource::Original),
            'original-parent',
        );
        $spelling = new QueryVariant(
            'orange form',
            'en',
            ['orange', 'form'],
            QueryVariantSource::Spelling,
            $policy->priority(QueryVariantSource::Spelling),
            'spelling-parent',
            'original-parent',
        );
        $variants = new QueryVariantCollection(2, [$original, $spelling]);
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'persian_search_dictionary_terms')
                || str_contains($event->sql, 'persian_search_dictionary_deletes')) {
                $queries[] = $event->sql;
            }
        });

        $first = app(DatabaseContextualCandidateGenerator::class)->generate($variants);
        $second = app(DatabaseContextualCandidateGenerator::class)->generate($variants);

        $this->assertCount(1, $first);
        $this->assertSame('orange from', $first->all()[0]->correctedQuery);
        $this->assertSame('spelling-parent', $first->all()[0]->parent->fingerprint);
        $this->assertSame($first->all()[0]->toArray(), $second->all()[0]->toArray());
        $this->assertCount(4, $queries);
        $this->assertCount(2, array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'contextual_delete'),
        ));
    }

    public function test_synonyms_are_results_but_never_contextual_parents(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.locales', ['en' => ['form' => ['shape']]]);
        $this->forgetContextualInstances();
        $this->index('form', 'en', 'form', 'reference');
        $this->index('shape', 'en', 'shape', 'page');
        $this->indexMany(8, 'en', 'shame', 'reference', 'synonym-neighbour');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $builder = PersianSearch::query('form')->locale('en')->type('page');
        $variants = $builder->variants();
        $candidates = app(DatabaseContextualCandidateGenerator::class)->generate($variants);
        $results = $builder->results();

        $this->assertSame('shape', $results->items()[0]->record->title);
        $this->assertContains(QueryVariantSource::Synonym, array_map(
            static fn (QueryVariant $variant): QueryVariantSource => $variant->source,
            $variants->all(),
        ));
        foreach ($candidates as $candidate) {
            $this->assertTrue($candidate->parent->source->isContextualParent());
            $this->assertNotContains($candidate->parent->source, [
                QueryVariantSource::Synonym,
                QueryVariantSource::KeyboardSynonym,
                QueryVariantSource::Contextual,
            ]);
            $this->assertSame('form', $candidate->originalQuery);
        }
    }

    public function test_result_count_and_ngram_flags_are_independent_and_honest(): void
    {
        $this->index('form', 'en', 'form', 'reference');
        $this->indexMany(8, 'en', 'message from home', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $outcomes = [];
        DB::connection()->enableQueryLog();

        foreach ([true, false] as $resultCounts) {
            foreach ([true, false] as $ngrams) {
                config()->set('persian-search.contextual.result_counts_enabled', $resultCounts);
                config()->set('persian-search.contextual.ngrams_enabled', $ngrams);
                config()->set('persian-search.contextual.decision.minimum_confidence_basis_points', 6000);
                $this->forgetContextualInstances();
                DB::connection()->flushQueryLog();

                $correction = PersianSearch::query('message form home')
                    ->locale('en')
                    ->type('page')
                    ->results()
                    ->suggestion?->contextualCorrection;
                $queries = DB::connection()->getQueryLog();

                $this->assertNotNull($correction);
                $this->assertSame($resultCounts, $correction->candidateResults->isAvailable);
                $this->assertSame($resultCounts, $correction->parentResults->isAvailable);
                $this->assertSame($ngrams, $correction->evidence->contextAvailable);
                if (! $resultCounts) {
                    $this->assertSame(ContextualDecision::SuggestOnly, $correction->decision);
                }
                $ngramQueries = array_values(array_filter(
                    $queries,
                    static fn (array $query): bool => str_contains(
                        $query['query'],
                        'persian_search_dictionary_ngrams',
                    ),
                ));
                $candidateResultSearches = array_values(array_filter(
                    $queries,
                    static fn (array $query): bool => str_contains($query['query'], 'persian_search_documents')
                        && str_contains($query['query'], 'select *')
                        && in_array('%message from home%', $query['bindings'], true),
                ));
                $this->assertSame($ngrams, $ngramQueries !== []);
                $outcomes[(int) $resultCounts][(int) $ngrams] = count($candidateResultSearches);
            }
        }

        $this->assertSame(2, $outcomes[1][1]);
        $this->assertSame(2, $outcomes[1][0]);
        $this->assertSame(1, $outcomes[0][1]);
        $this->assertSame(1, $outcomes[0][0]);
    }

    public function test_suggestion_requires_contextual_contribution_inside_the_displayed_slice(): void
    {
        $this->index('original-visible', 'fa', 'پرتغال', 'page');
        $this->indexMany(8, 'fa', 'پرتقال', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $one = PersianSearch::query('پرتغال')->locale('fa')->type('page')->limit(1)->results();
        $all = PersianSearch::query('پرتغال')->locale('fa')->type('page')->limit(10)->results();

        $this->assertNull($one->suggestion);
        $this->assertSame('پرتغال', $one->items[0]->record->title);
        $this->assertSame('پرتقال', $all->suggestion?->query);
        $this->assertSame('پرتغال', $all->query->original);

        config()->set('persian-search.contextual.trigger.evaluate_on_preview', true);
        $this->forgetContextualInstances();
        $this->assertNull(
            PersianSearch::query('پرتغال')->locale('fa')->type('page')->preview(1, 1)->suggestion,
        );
        $this->assertSame(
            'پرتقال',
            PersianSearch::query('پرتغال')->locale('fa')->type('page')->preview(10, 10)->suggestion?->query,
        );
    }

    public function test_contextual_migration_rolls_back_only_package_owned_tables(): void
    {
        Schema::create('application_owned_context', static function ($table): void {
            $table->id();
        });
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_contextual_ngrams_table.php';

        $migration->down();

        $this->assertFalse(Schema::hasTable('persian_search_dictionary_ngrams'));
        $this->assertFalse(Schema::hasTable('persian_search_dictionary_ngram_staging'));
        $this->assertFalse(Schema::hasTable('persian_search_contextual_builds'));
        $this->assertTrue(Schema::hasTable('application_owned_context'));
    }

    public function test_result_counts_are_capped_honestly_and_public_api_is_container_resolved(): void
    {
        $this->index('original-valid', 'fa', 'پرتغال', 'reference');
        $this->indexMany(15, 'fa', 'پرتقال', 'page', 'candidate');
        app(SpellingDictionaryBuilder::class)->rebuild();

        $correction = PersianSearch::query('پرتغال')
            ->locale('fa')
            ->type('page')
            ->results()
            ->suggestion?->contextualCorrection;

        $this->assertNotNull($correction);
        $this->assertSame(11, $correction->candidateResults->count);
        $this->assertTrue($correction->candidateResults->isApproximate);
        $this->assertSame(15, $correction->candidateResults->examinedCandidates);
        $this->assertSame(ContextualDecision::SuggestOnly, $correction->decision);
        $this->assertSame(
            app(ContextualCorrectionEvaluator::class),
            PersianSearch::contextualCorrectionEvaluator(),
        );
    }

    public function test_disabled_contextual_feature_adds_no_runtime_dictionary_queries(): void
    {
        config()->set('persian-search.contextual.enabled', false);
        config()->set('persian-search.spelling.phonetic.enabled', false);
        config()->set('persian-search.spelling.segmentation.enabled', false);
        $this->forgetContextualInstances();
        $this->index('page', 'en', 'form', 'page');
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        PersianSearch::query('form')->locale('en')->type('page')->results();

        $this->assertSame([], array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'persian_search_dictionary'),
        )));
    }

    public function test_ngrams_can_be_prebuilt_before_runtime_correction_is_enabled(): void
    {
        config()->set('persian-search.contextual.enabled', false);
        $this->forgetContextualInstances();
        $this->index('page', 'en', 'fresh orange juice', 'page');

        $build = app(SpellingDictionaryBuilder::class)->rebuild();

        $this->assertSame(2, $build->ngrams);
        $this->assertNull(PersianSearch::query('fresh arrange juice')->locale('en')->results()->suggestion);
    }

    public function test_failed_ngram_staging_preserves_usable_final_rows_and_cleans_staging(): void
    {
        $this->index('first', 'en', 'fresh orange juice', 'page');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $before = DB::table('persian_search_dictionary_ngrams')
            ->orderBy('gram_hash')
            ->pluck('normalized_gram')
            ->all();
        $this->index('second', 'en', 'cold apple juice', 'page');
        $fail = true;
        DB::listen(static function ($event) use (&$fail): void {
            if ($fail && str_starts_with($event->sql, 'insert into "persian_search_dictionary_ngram_staging"')) {
                $fail = false;

                throw new RuntimeException('Simulated staging interruption.');
            }
        });

        try {
            app(ContextualNgramBuilder::class)->rebuild();
            $this->fail('The simulated staging failure was not observed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated staging interruption.', $exception->getMessage());
        }

        $this->assertSame(
            $before,
            DB::table('persian_search_dictionary_ngrams')
                ->orderBy('gram_hash')
                ->pluck('normalized_gram')
                ->all(),
        );
        $this->assertSame(0, DB::table('persian_search_dictionary_ngram_staging')->count());
        $status = app(SpellingDictionaryStatusService::class)->snapshot();
        $this->assertFalse($status->contextualReady);
        $this->assertFalse($status->contextualReadyByLocale['en']);
        $this->assertArrayHasKey('en', $status->ngramBuiltByLocale);
    }

    public function test_malformed_candidate_records_do_not_inflate_result_evidence(): void
    {
        $this->index('original-valid', 'fa', 'پرتغال', 'reference');
        $this->indexMany(8, 'fa', 'پرتقال', 'reference', 'candidate-corpus');
        app(SpellingDictionaryBuilder::class)->rebuild();
        $template = DB::table('persian_search_documents')
            ->where('source_key', 'reference:candidate-corpus-0')
            ->first();
        $this->assertNotNull($template);
        $malformed = (array) $template;
        unset($malformed['id']);
        $malformed['source_key'] = 'page:malformed-candidate';
        $malformed['source_type'] = 'page';
        $malformed['normalized_content'] = "\xC3\x28پرتقال";
        $malformed['document_hash'] = str_repeat('0', 64);
        DB::table('persian_search_documents')->insert($malformed);

        $this->assertNull(PersianSearch::query('پرتغال')->locale('fa')->type('page')->results()->suggestion);
    }

    private function indexMany(int $count, string $locale, string $title, string $type, string $prefix): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->index($prefix.'-'.$index, $locale, $title, $type);
        }
    }

    private function index(string $key, string $locale, string $title, string $type, bool $active = true): void
    {
        PersianSearch::indexDocument(new SearchDocument(
            partition: 'default',
            sourceKey: $type.':'.$key,
            sourceType: $type,
            sourceId: null,
            locale: $locale,
            title: $title,
            excerpt: null,
            normalizedTitle: $title,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $title,
            isActive: $active,
        ));
    }

    private function resetTables(): void
    {
        DB::table('persian_search_dictionary_ngram_staging')->delete();
        DB::table('persian_search_dictionary_ngrams')->delete();
        DB::table('persian_search_dictionary_deletes')->delete();
        DB::table('persian_search_dictionary_terms')->delete();
        DB::table('persian_search_documents')->delete();
    }

    private function forgetContextualInstances(): void
    {
        PersianSearch::clearResolvedInstance(PersianSearchManager::class);
        foreach ([
            ContextualCorrectionPolicy::class,
            ContextualNgramBuilder::class,
            SpellingDictionaryBuilder::class,
            SpellingPolicy::class,
            AdvancedCorrectionPolicy::class,
            DefaultQueryExpander::class,
            QueryExpander::class,
            DatabaseContextualCandidateGenerator::class,
            DatabaseCorrectionEvidenceProvider::class,
            CorrectionEvidenceProvider::class,
            DatabaseCandidateResultCounter::class,
            CandidateResultCounter::class,
            DefaultContextualCorrectionEvaluator::class,
            ContextualCorrectionEvaluator::class,
            SearchExecutionProcessor::class,
            DatabaseSearchDriver::class,
            SearchDriver::class,
            PersianSearchManager::class,
            SpellingDictionaryStatusService::class,
        ] as $class) {
            app()->forgetInstance($class);
        }
    }
}
