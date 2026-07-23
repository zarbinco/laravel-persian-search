<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SourceConnectionHydrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);

        foreach (['source_a', 'source_b'] as $connection) {
            config()->set("database.connections.{$connection}", [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
        }

        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();

        foreach (['testing', 'source_a', 'source_b'] as $connection) {
            Schema::connection($connection)->create('connection_products', static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        }
    }

    public function test_source_connection_schema_and_document_semantics(): void
    {
        $this->assertTrue(Schema::hasColumn('persian_search_documents', 'source_connection'));
        $this->assertFalse(collect(Schema::getIndexes('persian_search_documents'))->contains(
            static fn (array $index): bool => in_array('source_connection', $index['columns'], true),
        ));
        $default = $this->document('default', null);
        $connected = $this->document('connected', 'source_a');

        $this->assertNull($default->sourceConnection);
        $this->assertSame('source_a', $connected->sourceConnection);
        $this->assertSame('source_a', $connected->meaningfulData()['source_connection']);
        $this->assertNotSame($default->documentHash, $connected->documentHash);
    }

    public function test_source_connection_rejects_noncanonical_or_unsafe_names(): void
    {
        foreach (['', ' source_a', 'source_a ', "\u{00A0}source_a", "source\u{202E}a", "source\nname", "\xFF"] as $name) {
            try {
                $this->document('unsafe', $name);
                $this->fail('Unsafe source connection was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_eloquent_provider_captures_the_resolved_source_connection(): void
    {
        $model = new ConnectionProduct(['name' => 'Orange']);
        $model->setConnection('source_a');
        $model->save();

        $document = PersianSearch::documentsFor($model)->all()[0];

        $this->assertSame('source_a', $document->sourceConnection);
    }

    public function test_same_class_and_key_hydrate_from_indexed_connections_without_collision(): void
    {
        DB::connection('testing')->table('connection_products')->insert(['id' => 1, 'name' => 'Wrong default']);
        DB::connection('source_a')->table('connection_products')->insert(['id' => 1, 'name' => 'Correct A']);
        DB::connection('source_b')->table('connection_products')->insert(['id' => 1, 'name' => 'Correct B']);
        PersianSearch::indexDocument($this->document('a', 'source_a', '1', 20));
        PersianSearch::indexDocument($this->document('b', 'source_b', '1', 10));
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'connection_products')) {
                $queries[] = $event->connectionName;
            }
        });

        $results = PersianSearch::query('orange')->locale('en')->withoutExpansion()->results();

        $this->assertSame(['Correct A', 'Correct B'], array_map(
            static fn ($result): ?string => $result->model?->getAttribute('name'),
            $results->items,
        ));
        $this->assertSame(['source_a', 'source_b'], array_map(
            static fn ($result): ?string => $result->model?->getConnectionName(),
            $results->items,
        ));
        $this->assertEqualsCanonicalizing(['source_a', 'source_b'], $queries);
        $this->assertNotContains('testing', $queries);
        $this->assertSame('source_a', $results->toArray()['items'][0]['record']['source_connection']);
    }

    public function test_same_connection_batches_selected_ids_and_null_uses_model_default(): void
    {
        DB::connection('source_a')->table('connection_products')->insert([
            ['id' => 1, 'name' => 'A1'],
            ['id' => 2, 'name' => 'A2'],
        ]);
        DB::connection('testing')->table('connection_products')->insert(['id' => 3, 'name' => 'Default']);
        PersianSearch::indexDocument($this->document('a1', 'source_a', '1', 30));
        PersianSearch::indexDocument($this->document('a2', 'source_a', '2', 20));
        PersianSearch::indexDocument($this->document('default', null, '3', 10));
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'connection_products')) {
                $queries[] = $event->connectionName;
            }
        });

        $results = PersianSearch::query('orange')->locale('en')->withoutExpansion()->results();

        $this->assertSame(['A1', 'A2', 'Default'], array_map(
            static fn ($result): ?string => $result->model?->getAttribute('name'),
            $results->items,
        ));
        $this->assertSame(1, count(array_filter($queries, static fn (string $name): bool => $name === 'source_a')));
        $this->assertSame(1, count(array_filter($queries, static fn (string $name): bool => $name === 'testing')));
    }

    public function test_pagination_preview_and_grouping_hydrate_selected_connections(): void
    {
        DB::connection('source_a')->table('connection_products')->insert(['id' => 1, 'name' => 'A']);
        DB::connection('source_b')->table('connection_products')->insert(['id' => 1, 'name' => 'B']);
        PersianSearch::indexDocument($this->document('a', 'source_a', '1', 20));
        PersianSearch::indexDocument($this->document('b', 'source_b', '1', 10));

        $page = PersianSearch::query('orange')->locale('en')->withoutExpansion()->paginate(1, 1);
        $preview = PersianSearch::query('orange')->locale('en')->withoutExpansion()->preview(2, 1);
        $groups = PersianSearch::query('orange')->locale('en')->withoutExpansion()->groupBySourceType(2);

        $this->assertSame('source_a', $page->items[0]->model?->getConnectionName());
        $this->assertSame(['source_a', 'source_b'], array_map(
            static fn ($result): ?string => $result->model?->getConnectionName(),
            $preview->items,
        ));
        $this->assertSame(['source_a', 'source_b'], array_map(
            static fn ($result): ?string => $result->model?->getConnectionName(),
            $groups->groups[0]->items,
        ));
    }

    public function test_virtual_documents_issue_no_source_query_and_missing_connection_fails(): void
    {
        PersianSearch::indexDocument(new SearchDocument(
            partition: 'default',
            sourceKey: 'virtual',
            sourceType: 'virtual',
            sourceId: null,
            locale: 'en',
            title: 'Orange',
            excerpt: null,
            normalizedTitle: 'orange',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'orange',
            sourceConnection: null,
        ));
        $queries = 0;
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'connection_products')) {
                $queries++;
            }
        });

        $this->assertNull(PersianSearch::query('orange')->locale('en')->withoutExpansion()->results()->items[0]->model);
        $this->assertSame(0, $queries);

        PersianSearch::indexDocument($this->document('missing', 'missing_source', '1', 100));
        $this->expectException(InvalidArgumentException::class);
        PersianSearch::query('orange')->locale('en')->withoutExpansion()->results();
    }

    public function test_reindexing_changed_source_connection_updates_semantic_storage(): void
    {
        $first = PersianSearch::indexDocument($this->document('switch', 'source_a', '1'));
        $second = PersianSearch::indexDocument($this->document('switch', 'source_b', '1'));

        $this->assertNotSame($first->document_hash, $second->document_hash);
        $this->assertSame('source_b', $second->source_connection);
        $this->assertSame('source_b', SearchDocumentRecord::query()->firstOrFail()->source_connection);
        $this->assertSame('source_b', $second->toArray()['source_connection']);
    }

    public function test_unchanged_source_connection_remains_a_true_no_op(): void
    {
        $document = $this->document('no-op', 'source_a', '1');
        $first = PersianSearch::indexDocument($document);
        $second = PersianSearch::indexDocument($document);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($first->getRawOriginal('updated_at'), $second->getRawOriginal('updated_at'));
        $this->assertSame('source_a', $second->source_connection);
    }

    public function test_changing_model_connection_and_reindexing_updates_the_persisted_connection(): void
    {
        DB::connection('source_a')->table('connection_products')->insert(['id' => 1, 'name' => 'Orange']);
        DB::connection('source_b')->table('connection_products')->insert(['id' => 1, 'name' => 'Orange']);
        $sourceA = (new ConnectionProduct)->setConnection('source_a')->newQuery()->findOrFail(1);
        $sourceB = (new ConnectionProduct)->setConnection('source_b')->newQuery()->findOrFail(1);

        $first = PersianSearch::index($sourceA);
        $second = PersianSearch::index($sourceB);

        $this->assertSame($first->source_key, $second->source_key);
        $this->assertNotSame($first->document_hash, $second->document_hash);
        $this->assertSame('source_b', $second->source_connection);
    }

    private function document(string $key, ?string $connection, ?string $sourceId = '1', int $priority = 0): SearchDocument
    {
        return new SearchDocument(
            partition: 'default',
            sourceKey: 'connection:'.$key,
            sourceType: ConnectionProduct::class,
            sourceId: $sourceId,
            locale: 'en',
            title: 'Orange',
            excerpt: null,
            normalizedTitle: 'orange',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'orange',
            priority: $priority,
            sourceConnection: $connection,
        );
    }
}

final class ConnectionProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    public $timestamps = false;

    protected $table = 'connection_products';

    protected $guarded = [];

    /** @return list<string> */
    public function persianSearchableFields(): array
    {
        return ['name'];
    }

    public function persianSearchTitle(): string
    {
        return $this->getAttribute('name');
    }
}
