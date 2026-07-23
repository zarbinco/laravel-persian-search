<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\PersianSearch\Candidates\LiteralLikeCondition;
use Zarbinco\PersianSearch\Candidates\LiteralLikePattern;
use Zarbinco\PersianSearch\Candidates\SearchDocumentField;
use Zarbinco\PersianSearch\Contracts\SearchCandidateDriver;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Search\ProcessedSearchQuery;
use Zarbinco\PersianSearch\Search\QueryVariant;
use Zarbinco\PersianSearch\Search\QueryVariantCollection;
use Zarbinco\PersianSearch\Search\QueryVariantSource;
use Zarbinco\PersianSearch\Search\SearchQuery;
use Zarbinco\PersianSearch\Search\SearchQueryStatus;
use Zarbinco\PersianSearch\Tests\TestCase;

final class DatabaseCandidateDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
    }

    #[DataProvider('literalDocuments')]
    public function test_literal_wildcard_and_sql_like_content_matches_only_literal_document(
        string $query,
        string $matching,
        string $ordinary,
    ): void {
        $this->index('matching', $matching);
        $this->index('ordinary', $ordinary);

        $results = PersianSearch::query($query)
            ->withoutExpansion()
            ->locale('en')
            ->type('page')
            ->partition('public')
            ->results();

        $this->assertSame(['matching'], array_map(
            static fn ($result): string => $result->record->source_key,
            $results->items(),
        ));
        $this->assertTrue(Schema::hasTable('persian_search_documents'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function literalDocuments(): array
    {
        return [
            'percent' => ['100%', '100% juice', 'ordinary orange'],
            'underscore' => ['file_name', 'file_name', 'ordinary orange'],
            'exclamation' => ['wow!', 'wow!', 'ordinary orange'],
            'backslash' => ['folder\\file', 'folder\\file', 'ordinary orange'],
            'single quote' => ["it's", "it's literal", 'ordinary quote'],
            'double quote' => ['say "yes"', 'say "yes"', 'ordinary orange'],
            'comment marker' => ['value -- safe', 'value -- safe', 'ordinary orange'],
            'block comment' => ['value /* safe */', 'value /* safe */', 'ordinary orange'],
        ];
    }

    #[DataProvider('injectionValues')]
    public function test_injection_input_is_bound_and_returns_only_literal_candidate(string $input): void
    {
        $this->index('injection', $input);
        $this->index('ordinary', 'ordinary searchable document');
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'persian_search_documents')) {
                $queries[] = ['sql' => $event->sql, 'bindings' => $event->bindings];
            }
        });

        $candidates = app(SearchCandidateDriver::class)->candidates(
            $this->makeSearchQuery([$this->variant($input, 'en', QueryVariantSource::Original, 1000, 'injection')]),
        );

        $this->assertCount(1, $candidates);
        $this->assertSame('injection', $candidates->all()[0]->document->source_key);
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString($input, $queries[0]['sql']);
        $this->assertStringContainsString("LIKE ? ESCAPE '!'", $queries[0]['sql']);
        $this->assertContains(LiteralLikePattern::contains($input)->value, $queries[0]['bindings']);
        $this->assertTrue(Schema::hasTable('persian_search_documents'));
    }

    /** @return array<string, array{string}> */
    public static function injectionValues(): array
    {
        return [
            'OR comment' => ["%' OR 1=1 --"],
            'underscore OR' => ["_' OR 'a'='a"],
            'union' => ["x%') UNION SELECT"],
            'drop' => ['"; DROP TABLE'],
        ];
    }

    public function test_candidate_filters_are_exact_bound_and_grouped(): void
    {
        $this->index('target', 'orange', locale: 'fa', partition: 'public', sourceType: 'product');
        $this->index('inactive', 'orange', locale: 'fa', partition: 'public', sourceType: 'product', active: false);
        $this->index('locale', 'orange', locale: 'fa_IR', partition: 'public', sourceType: 'product');
        $this->index('partition', 'orange', locale: 'fa', partition: 'admin', sourceType: 'product');
        $this->index('type', 'orange', locale: 'fa', partition: 'public', sourceType: 'page');
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'persian_search_documents')) {
                $queries[] = $event;
            }
        });

        $candidates = app(SearchCandidateDriver::class)->candidates(
            $this->makeSearchQuery(
                [$this->variant('orange', 'fa', QueryVariantSource::Original, 1000, 'original')],
                ['product'],
                'public',
            ),
        );

        $this->assertSame(['target'], array_map(
            static fn ($candidate): string => $candidate->document->source_key,
            $candidates->all(),
        ));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('"is_active" = ?', $queries[0]->sql);
        $this->assertStringContainsString('"locale" = ?', $queries[0]->sql);
        $this->assertStringContainsString('"partition" = ?', $queries[0]->sql);
        $this->assertStringContainsString('"source_type" in (?)', $queries[0]->sql);
        $this->assertContains('fa', $queries[0]->bindings);
        $this->assertContains('public', $queries[0]->bindings);
        $this->assertContains('product', $queries[0]->bindings);
    }

    public function test_each_variant_uses_its_own_exact_locale_and_same_row_is_deduplicated(): void
    {
        $this->index('english', 'orange citrus', locale: 'en');
        $this->index('persian', 'پرتقال', locale: 'fa');
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'persian_search_documents')) {
                $queries[] = $event;
            }
        });
        $variants = [
            $this->variant('orange', 'en', QueryVariantSource::Original, 1000, 'original'),
            $this->variant('citrus', 'en', QueryVariantSource::Synonym, 600, 'synonym'),
            $this->variant('پرتقال', 'fa', QueryVariantSource::Keyboard, 800, 'keyboard'),
        ];

        $candidates = app(SearchCandidateDriver::class)->candidates($this->makeSearchQuery($variants));

        $this->assertCount(2, $candidates);
        $this->assertCount(3, $queries);
        $this->assertContains('en', $queries[0]->bindings);
        $this->assertContains('en', $queries[1]->bindings);
        $this->assertContains('fa', $queries[2]->bindings);
        $english = $candidates->all()[0];
        $this->assertSame(QueryVariantSource::Original, $english->retrievalVariant->source);
        $this->assertCount(2, $english->matches);
    }

    public function test_undefined_locale_and_multiple_or_empty_type_filters_are_exact(): void
    {
        $this->index('und-page', 'orange', locale: 'und', sourceType: 'page');
        $this->index('und-product', 'orange', locale: 'und', sourceType: 'product');
        $this->index('english', 'orange', locale: 'en', sourceType: 'page');

        $several = app(SearchCandidateDriver::class)->candidates($this->makeSearchQuery(
            [$this->variant('orange', 'und', QueryVariantSource::Original, 1000, 'und')],
            ['page', 'product'],
        ));
        $unfiltered = app(SearchCandidateDriver::class)->candidates($this->makeSearchQuery(
            [$this->variant('orange', 'und', QueryVariantSource::Original, 1000, 'und-all')],
            [],
        ));

        $this->assertCount(2, $several);
        $this->assertCount(2, $unfiltered);
        $this->assertSame(['und-page', 'und-product'], array_map(
            static fn ($candidate): string => $candidate->document->source_key,
            $several->all(),
        ));
        $this->assertSame(['und', 'und'], array_map(
            static fn ($candidate): string => $candidate->document->locale,
            $unfiltered->all(),
        ));
    }

    public function test_query_count_is_one_per_variant_and_stops_when_global_capacity_is_full(): void
    {
        config()->set('persian-search.candidates.maximum_candidates', 1);
        $this->index('first', 'orange');
        $this->index('second', 'citrus');
        $queryCount = 0;
        DB::listen(static function ($event) use (&$queryCount): void {
            if (str_contains($event->sql, 'persian_search_documents')) {
                $queryCount++;
            }
        });

        $candidates = app(SearchCandidateDriver::class)->candidates($this->makeSearchQuery([
            $this->variant('orange', 'en', QueryVariantSource::Original, 1000, 'original'),
            $this->variant('citrus', 'en', QueryVariantSource::Synonym, 600, 'synonym'),
        ]));

        $this->assertCount(1, $candidates);
        $this->assertSame(1, $queryCount);
        $this->assertSame('first', $candidates->all()[0]->document->source_key);
    }

    public function test_terms_and_fields_share_one_query_and_per_variant_limit_is_applied(): void
    {
        config()->set('persian-search.candidates.per_variant_limit', 1);
        $this->index('first', 'orange fruit juice');
        $this->index('second', 'orange fruit juice');
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'persian_search_documents')) {
                $queries[] = $event;
            }
        });

        $candidates = app(SearchCandidateDriver::class)->candidates($this->makeSearchQuery([
            new QueryVariant(
                'orange fruit juice',
                'en',
                ['orange', 'fruit', 'juice'],
                QueryVariantSource::Original,
                1000,
                'original',
            ),
        ]));

        $this->assertCount(1, $candidates);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('limit 1', strtolower($queries[0]->sql));
        $this->assertSame('first', $candidates->all()[0]->document->source_key);
    }

    public function test_php_verification_rejects_sqlite_case_collation_false_positive(): void
    {
        $this->index('uppercase', 'ORANGE');

        $candidates = app(SearchCandidateDriver::class)->candidates(
            $this->makeSearchQuery([$this->variant('orange', 'en', QueryVariantSource::Original, 1000, 'original')]),
        );

        $this->assertCount(0, $candidates);
    }

    public function test_virtual_null_source_remains_a_valid_candidate(): void
    {
        $this->index('public', 'orange', partition: 'public', sourceType: 'virtual', sourceId: null);
        $query = $this->makeSearchQuery(
            [$this->variant('orange', 'en', QueryVariantSource::Original, 1000, 'original')],
            ['virtual'],
            'public',
        );

        $candidates = app(SearchCandidateDriver::class)->candidates($query);

        $this->assertCount(1, $candidates);
        $this->assertSame(['public'], array_map(
            static fn ($candidate): string => $candidate->document->source_key,
            $candidates->all(),
        ));
        $this->assertNull($candidates->all()[0]->document->source_id);
    }

    public function test_candidate_driver_queries_only_index_table_and_repeated_execution_is_deterministic(): void
    {
        $this->index('deterministic', 'orange');
        $sqlRuns = [];
        DB::listen(static function ($event) use (&$sqlRuns): void {
            if (str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
                $sqlRuns[] = [$event->sql, $event->bindings];
            }
        });
        $query = $this->makeSearchQuery([$this->variant('orange', 'en', QueryVariantSource::Original, 1000, 'original')]);
        $driver = app(SearchCandidateDriver::class);

        $first = $driver->candidates($query);
        $second = $driver->candidates($query);

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertCount(2, $sqlRuns);
        $this->assertSame($sqlRuns[0], $sqlRuns[1]);
        $this->assertStringContainsString('persian_search_documents', $sqlRuns[0][0]);
    }

    public function test_literal_like_sql_uses_database_grammar_quoting_for_supported_databases(): void
    {
        $condition = new LiteralLikeCondition;
        $sqlite = DB::connection()->getQueryGrammar();
        $mysqlConnection = new MySqlConnection(new PDO('sqlite::memory:'));
        $postgresConnection = new PostgresConnection(new PDO('sqlite::memory:'));

        $this->assertInstanceOf(SQLiteGrammar::class, $sqlite);
        $this->assertSame(
            '"normalized_title" LIKE ? ESCAPE \'!\'',
            $condition->clause($sqlite, SearchDocumentField::Title),
        );
        $this->assertSame(
            '`normalized_title` LIKE ? ESCAPE \'!\'',
            $condition->clause(new MySqlGrammar($mysqlConnection), SearchDocumentField::Title),
        );
        $this->assertSame(
            '"normalized_title" LIKE ? ESCAPE \'!\'',
            $condition->clause(new PostgresGrammar($postgresConnection), SearchDocumentField::Title),
        );
    }

    private function index(
        string $sourceKey,
        string $text,
        string $locale = 'en',
        string $partition = 'public',
        string $sourceType = 'page',
        ?string $sourceId = null,
        bool $active = true,
    ): void {
        PersianSearch::indexDocument(new SearchDocument(
            partition: $partition,
            sourceKey: $sourceKey,
            sourceType: $sourceType,
            sourceId: $sourceId,
            locale: $locale,
            title: $text,
            excerpt: null,
            normalizedTitle: $text,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $text,
            isActive: $active,
        ));
    }

    /**
     * @param  list<QueryVariant>  $variants
     * @param  list<string>  $types
     */
    private function makeSearchQuery(array $variants, array $types = ['page'], string $partition = 'public'): SearchQuery
    {
        $processed = new ProcessedSearchQuery(
            $variants[0]->query,
            $variants[0]->query,
            $variants[0]->locale,
            $variants[0]->query,
            $variants[0]->query,
            $variants[0]->tokens,
            $variants[0]->tokens,
            SearchQueryStatus::Ready,
            false,
            mb_strlen($variants[0]->query),
            mb_strlen($variants[0]->query),
        );

        return new SearchQuery(
            $variants[0]->query,
            $variants[0]->query,
            $variants[0]->tokens,
            $types,
            $variants[0]->locale,
            $partition,
            20,
            0,
            $processed,
            new QueryVariantCollection(20, $variants),
        );
    }

    private function variant(
        string $query,
        string $locale,
        QueryVariantSource $source,
        int $priority,
        string $fingerprint,
    ): QueryVariant {
        return new QueryVariant($query, $locale, [$query], $source, $priority, $fingerprint);
    }
}
