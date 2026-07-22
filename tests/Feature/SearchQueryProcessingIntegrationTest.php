<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Search\QueryCandidate;
use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Search\SearchQueryBuilder;
use Zarbinco\PersianSearch\Search\SearchQueryProcessor;
use Zarbinco\PersianSearch\Search\SearchQueryStatus;
use Zarbinco\PersianSearch\Search\SearchResult;
use Zarbinco\PersianSearch\Search\SearchResults;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchQueryProcessingIntegrationTest extends TestCase
{
    public function test_no_database_query_runs_for_every_non_searchable_status(): void
    {
        config()->set('persian-search.query.maximum_length', 3);
        config()->set('persian-search.query.maximum_length_policy', 'reject');
        $this->migrateSearchDocuments();
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $cases = [
            null => SearchQueryStatus::Empty,
            '!' => SearchQueryStatus::PunctuationOnly,
            'a' => SearchQueryStatus::TooShort,
            'long' => SearchQueryStatus::TooLong,
        ];

        foreach ($cases as $query => $status) {
            $results = PersianSearch::query($query)->results();
            $this->assertSame($status, $results->status());
            $this->assertFalse($results->isSearchableQuery());
            $this->assertSame(0, $results->total);
            $this->assertSame([], $results->items());
            $this->assertTrue($results->models()->isEmpty());
        }

        $this->assertSame(0, $queries);
    }

    public function test_no_database_non_searchable_queries_work_when_search_table_is_absent(): void
    {
        foreach ([null, '---', 'a'] as $query) {
            $this->assertTrue(PersianSearch::query($query)->results()->isEmpty());
        }
    }

    public function test_no_database_or_expansion_service_is_called_for_non_searchable_query(): void
    {
        app()->setLocale('fa');
        $recorder = new QueryProcessingRecorder;
        $builder = new SearchQueryBuilder(
            '!!!',
            app(SearchQueryProcessor::class),
            new RecordingSearchDriver($recorder),
            new RecordingQueryExpander($recorder),
        );

        $results = $builder->results();

        $this->assertSame(SearchQueryStatus::PunctuationOnly, $results->status());
        $this->assertSame('fa', $results->processedQuery->locale);
        $this->assertSame($results->processedQuery->locale, $results->query->locale);
        $this->assertSame(0, $recorder->driverCalls);
        $this->assertSame(0, $recorder->expanderCalls);
    }

    public function test_ready_query_accesses_database_normally(): void
    {
        $this->migrateSearchDocuments();
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $results = PersianSearch::query('orange')->withoutExpansion()->results();

        $this->assertSame(SearchQueryStatus::Ready, $results->status());
        $this->assertTrue($results->isSearchableQuery());
        $this->assertGreaterThan(0, $queries);
    }

    public function test_non_searchable_model_get_returns_empty_collection_without_search_table(): void
    {
        $this->assertTrue(PhaseThreeProduct::persianSearch('!')->get()->isEmpty());
    }

    public function test_results_expose_processed_query_diagnostics(): void
    {
        $results = PersianSearch::query('!')->results();
        $serialized = $results->toArray();

        $this->assertSame(SearchQueryStatus::PunctuationOnly, $results->processedQuery->status);
        $this->assertSame('punctuation_only', $serialized['processed_query']['status']);
        $this->assertSame(0, $serialized['total']);
        $this->assertSame([], $serialized['items']);
    }

    public function test_builder_processes_lazily_with_final_locale_and_is_deterministic(): void
    {
        $builder = PersianSearch::query('ك');

        $english = $builder->types(['product'])->locale('en')->results();
        $persian = $builder->locale('fa')->types(['product'])->results();
        $repeated = $builder->results();

        $this->assertSame('ك', $english->processedQuery->normalizedQuery);
        $this->assertSame('en', $english->processedQuery->locale);
        $this->assertSame('en', $english->query->locale);
        $this->assertSame('ک', $persian->processedQuery->normalizedQuery);
        $this->assertSame('fa', $persian->processedQuery->locale);
        $this->assertSame('fa', $persian->query->locale);
        $this->assertSame($persian->processedQuery->toArray(), $repeated->processedQuery->toArray());
    }

    public function test_application_locale_is_used_when_explicit_locale_is_absent(): void
    {
        app()->setLocale('fa_IR');

        $results = PersianSearch::query('ك')->results();

        $this->assertSame('fa_IR', $results->processedQuery->locale);
        $this->assertSame('ک', $results->processedQuery->normalizedQuery);
    }

    public function test_application_locale_is_the_database_filter_when_explicit_locale_is_absent(): void
    {
        app()->setLocale('fa');
        $this->migrateSearchDocuments();
        PersianSearch::indexDocument($this->orangeDocument('fa', 'page:implicit-fa'));
        PersianSearch::indexDocument($this->orangeDocument('en', 'page:implicit-en'));

        $results = PersianSearch::query('orange')->type('page')->results();

        $this->assertSame('fa', $results->processedQuery->locale);
        $this->assertSame('fa', $results->query->locale);
        $this->assertCount(1, $results->items());
        $this->assertSame('fa', $results->items()[0]->record->locale);

        app()->setLocale('en');
        $english = PersianSearch::query('orange')->type('page')->results();

        $this->assertSame('en', $english->processedQuery->locale);
        $this->assertSame('en', $english->query->locale);
        $this->assertCount(1, $english->items());
        $this->assertSame('en', $english->items()[0]->record->locale);
    }

    public function test_application_locale_region_is_an_exact_database_filter(): void
    {
        app()->setLocale('fa_IR');
        $this->migrateSearchDocuments();
        PersianSearch::indexDocument($this->orangeDocument('fa_IR', 'page:region-fa-ir'));
        PersianSearch::indexDocument($this->orangeDocument('fa', 'page:region-fa'));

        $results = PersianSearch::query('orange')->type('page')->results();

        $this->assertSame('fa_IR', $results->processedQuery->locale);
        $this->assertSame($results->processedQuery->locale, $results->query->locale);
        $this->assertCount(1, $results->items());
        $this->assertSame('fa_IR', $results->items()[0]->record->locale);
    }

    public function test_explicit_locale_reaches_processor_expansion_and_database_filter(): void
    {
        app()->setLocale('fa');
        $this->migrateSearchDocuments();
        PersianSearch::indexDocument($this->orangeDocument('en', 'page:orange-en'));
        PersianSearch::indexDocument($this->orangeDocument('fa', 'page:orange-fa'));

        $results = PersianSearch::query('ORANGE')->locale('en')->type('page')->results();

        $this->assertSame('en', $results->processedQuery->locale);
        $this->assertSame($results->processedQuery->locale, $results->query->locale);
        $this->assertSame('ORANGE', $results->query->original);
        $this->assertSame('orange', $results->query->candidates()[0]->normalized);
        $this->assertCount(1, $results->items());
        $this->assertSame('en', $results->items()[0]->record->locale);
    }

    public function test_explicit_undefined_locale_is_the_database_filter(): void
    {
        app()->setLocale('fa');
        $this->migrateSearchDocuments();
        PersianSearch::indexDocument($this->orangeDocument('und', 'page:explicit-und'));
        PersianSearch::indexDocument($this->orangeDocument('fa', 'page:explicit-fa'));

        foreach (['', '   '] as $locale) {
            $results = PersianSearch::query('orange')->locale($locale)->type('page')->results();

            $this->assertSame('und', $results->processedQuery->locale);
            $this->assertSame($results->processedQuery->locale, $results->query->locale);
            $this->assertCount(1, $results->items());
            $this->assertSame('und', $results->items()[0]->record->locale);
        }
    }

    public function test_database_filter_follows_call_order_locale_changes_and_repeated_execution(): void
    {
        $this->migrateSearchDocuments();
        PersianSearch::indexDocument($this->orangeDocument('fa', 'page:order-fa'));
        PersianSearch::indexDocument($this->orangeDocument('en', 'page:order-en'));

        $localeFirst = PersianSearch::query('orange')->locale('en')->types(['page'])->results();
        $typesFirst = PersianSearch::query('orange')->types(['page'])->locale('en')->results();
        $builder = PersianSearch::query('orange')->type('page')->locale('fa');
        $persian = $builder->results();
        $english = $builder->locale('en')->results();
        $repeated = $builder->results();

        $this->assertSame(['page:order-en'], $this->sourceKeys($localeFirst));
        $this->assertSame($this->sourceKeys($localeFirst), $this->sourceKeys($typesFirst));
        $this->assertSame(['page:order-fa'], $this->sourceKeys($persian));
        $this->assertSame('fa', $persian->query->locale);
        $this->assertSame(['page:order-en'], $this->sourceKeys($english));
        $this->assertSame('en', $english->processedQuery->locale);
        $this->assertSame($english->processedQuery->locale, $english->query->locale);
        $this->assertSame($this->sourceKeys($english), $this->sourceKeys($repeated));
        $this->assertSame($english->processedQuery->toArray(), $repeated->processedQuery->toArray());
    }

    public function test_expander_receives_the_effective_locale_for_all_resolution_paths(): void
    {
        app()->setLocale('fa');

        foreach ([[null, 'fa'], ['en', 'en'], ['', 'und'], ['   ', 'und']] as [$explicit, $expected]) {
            $recorder = new QueryProcessingRecorder;
            $builder = new SearchQueryBuilder(
                'orange',
                app(SearchQueryProcessor::class),
                new RecordingSearchDriver($recorder),
                new RecordingQueryExpander($recorder),
            );

            if ($explicit !== null) {
                $builder->locale($explicit);
            }

            $builder->results();
            $this->assertNotNull($recorder->expandedQuery);
            $this->assertSame($expected, $recorder->expandedQuery->locale);
            $this->assertSame($recorder->expandedQuery->processedQuery->locale, $recorder->expandedQuery->locale);
            $this->assertSame($recorder->expandedQuery->processedQuery->locale, $recorder->expandedQuery->textLocale);
        }
    }

    public function test_manager_expansion_uses_the_processed_locale_as_authoritative(): void
    {
        app()->setLocale('fa');
        $recorder = new QueryProcessingRecorder;
        app()->instance(QueryExpander::class, new RecordingQueryExpander($recorder));

        PersianSearch::expand('orange', '');

        $this->assertNotNull($recorder->expandedQuery);
        $this->assertSame('und', $recorder->expandedQuery->locale);
        $this->assertSame($recorder->expandedQuery->processedQuery->locale, $recorder->expandedQuery->locale);
        $this->assertSame($recorder->expandedQuery->processedQuery->locale, $recorder->expandedQuery->textLocale);
    }

    public function test_original_expansion_candidate_uses_processed_normalized_query(): void
    {
        $processed = PersianSearch::processQuery('  كیكِ شکلاتي  ', 'fa');
        $candidates = PersianSearch::expand('  كیكِ شکلاتي  ', 'fa');

        $this->assertSame($processed->normalizedQuery, $candidates[0]->normalized);
        $this->assertSame($processed->searchableTokens, $candidates[0]->tokens);
    }

    private function migrateSearchDocuments(): void
    {
        if (! Schema::hasTable('persian_search_documents')) {
            $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
            $migration->up();
        }
    }

    private function orangeDocument(string $locale, string $sourceKey): SearchDocument
    {
        return new SearchDocument(
            partition: 'default',
            sourceKey: $sourceKey,
            sourceType: 'page',
            sourceId: null,
            locale: $locale,
            title: 'Orange',
            excerpt: null,
            normalizedTitle: 'orange',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'orange juice',
        );
    }

    /** @return list<string> */
    private function sourceKeys(SearchResults $results): array
    {
        return array_map(
            static fn (SearchResult $result): string => $result->record->source_key,
            $results->items(),
        );
    }
}

final class QueryProcessingRecorder
{
    public int $driverCalls = 0;

    public int $expanderCalls = 0;

    public ?SearchQuery $expandedQuery = null;
}

final readonly class RecordingSearchDriver implements SearchDriver
{
    public function __construct(private QueryProcessingRecorder $recorder) {}

    public function search(SearchQuery $query): SearchResults
    {
        $this->recorder->driverCalls++;

        return new SearchResults($query, $query->processedQuery, [], 0);
    }
}

final readonly class RecordingQueryExpander implements QueryExpander
{
    public function __construct(private QueryProcessingRecorder $recorder) {}

    public function expand(SearchQuery $query): array
    {
        $this->recorder->expanderCalls++;
        $this->recorder->expandedQuery = $query;

        return [new QueryCandidate('original', $query->original, $query->normalized, $query->tokens, 1.0)];
    }
}

final class PhaseThreeProduct extends Model
{
    use HasPersianSearch;
}
