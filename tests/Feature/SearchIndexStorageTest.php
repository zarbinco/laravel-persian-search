<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentHasher;
use Zarbinco\PersianSearch\Indexing\SearchDocumentIdentity;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchIndexStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
        Schema::create('storage_products', function ($table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });
    }

    public function test_migration_contains_exact_document_first_columns_and_no_legacy_columns(): void
    {
        $expected = [
            'id', 'partition', 'source_key', 'source_type', 'source_id', 'source_connection', 'locale',
            'title', 'excerpt', 'normalized_title', 'normalized_excerpt',
            'normalized_keywords', 'normalized_content', 'payload', 'priority',
            'is_active', 'document_hash', 'source_updated_at', 'indexed_at',
            'created_at', 'updated_at',
        ];

        foreach ($expected as $column) {
            $this->assertTrue(Schema::hasColumn('persian_search_documents', $column), "Missing [{$column}].");
        }

        foreach (['searchable_type', 'searchable_id', 'content', 'tokens', 'fields', 'metadata'] as $column) {
            $this->assertFalse(Schema::hasColumn('persian_search_documents', $column), "Legacy column [{$column}] exists.");
        }
    }

    public function test_source_key_locking_index_and_existing_indexes_are_present(): void
    {
        $indexes = collect(Schema::getIndexes('persian_search_documents'))->keyBy('name');

        $this->assertSame(['source_key'], $indexes->get('ps_docs_source_key')['columns'] ?? null);
        $this->assertSame(
            ['partition', 'source_key', 'locale'],
            $indexes->get('ps_docs_identity_unique')['columns'] ?? null,
        );
        $this->assertArrayHasKey('ps_docs_partition_locale_active', $indexes);
        $this->assertArrayHasKey('ps_docs_partition_type_locale_active', $indexes);
        $this->assertArrayHasKey('ps_docs_source_type_id', $indexes);
    }

    public function test_identity_validates_values_and_normalizes_undefined_locale(): void
    {
        $identity = new SearchDocumentIdentity(' public ', ' page:about ', null);

        $this->assertSame([
            'partition' => 'public',
            'source_key' => 'page:about',
            'locale' => 'und',
        ], $identity->toArray());

        $this->expectException(InvalidArgumentException::class);
        new SearchDocumentIdentity(' ', 'page:about', 'fa');
    }

    public function test_identity_allows_locales_and_partitions_but_reindexes_one_row(): void
    {
        PersianSearch::indexDocument($this->document(title: 'نسخه اول'));
        PersianSearch::indexDocument($this->document(title: 'نسخه دوم'));
        PersianSearch::indexDocument($this->document(locale: 'en', title: 'English'));
        PersianSearch::indexDocument($this->document(partition: 'admin', title: 'مدیریت'));

        $this->assertSame(3, SearchDocumentRecord::count());
        $this->assertSame('نسخه دوم', SearchDocumentRecord::query()
            ->where('partition', 'public')->where('locale', 'fa')->value('title'));
    }

    public function test_source_id_values_are_canonical_in_the_dto_hash_and_database(): void
    {
        $integer = $this->document(sourceId: 123, sourceKey: 'product:canonical');
        $string = $this->document(sourceId: '123', sourceKey: 'product:canonical');
        $padded = $this->document(sourceId: '00123', sourceKey: 'product:canonical');
        $ulid = '01J9ZXYZABCDEF123456789012';
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $this->assertSame('123', $integer->sourceId);
        $this->assertSame('123', $string->sourceId);
        $this->assertSame($integer->meaningfulData()['source_id'], $string->meaningfulData()['source_id']);
        $this->assertSame($integer->documentHash, $string->documentHash);
        $this->assertSame('00123', $padded->sourceId);
        $this->assertNotSame($padded->sourceId, $string->sourceId);
        $this->assertNotSame($padded->documentHash, $string->documentHash);
        $this->assertSame($ulid, $this->document(sourceId: $ulid)->sourceId);
        $this->assertSame($uuid, $this->document(sourceId: $uuid)->sourceId);

        PersianSearch::indexDocument($this->document(sourceId: null, sourceKey: 'page:about'));
        PersianSearch::indexDocument($integer);
        PersianSearch::indexDocument($this->document(sourceId: $ulid, sourceKey: 'product:ulid'));
        PersianSearch::indexDocument($this->document(sourceId: $uuid, sourceKey: 'product:uuid'));

        $this->assertNull(SearchDocumentRecord::query()->where('source_key', 'page:about')->value('source_id'));
        $this->assertSame($integer->sourceId, SearchDocumentRecord::query()->where('source_key', 'product:canonical')->value('source_id'));
        $this->assertSame($ulid, SearchDocumentRecord::query()->where('source_key', 'product:ulid')->value('source_id'));
        $this->assertSame($uuid, SearchDocumentRecord::query()->where('source_key', 'product:uuid')->value('source_id'));
    }

    public function test_document_and_source_deletion_use_document_identities(): void
    {
        PersianSearch::indexDocument($this->document(locale: 'fa'));
        PersianSearch::indexDocument($this->document(locale: 'en'));

        $this->assertSame(1, PersianSearch::deleteDocument(new SearchDocumentIdentity('public', 'page:home', 'fa')));
        $this->assertSame(1, PersianSearch::deleteSourceKey('page:home', 'public'));
        $this->assertSame(0, SearchDocumentRecord::count());
    }

    public function test_record_casts_payload_flags_numbers_and_timestamps(): void
    {
        $record = PersianSearch::indexDocument($this->document(
            payload: ['nested' => ['when' => new DateTimeImmutable('2026-01-01T00:00:00+00:00')]],
            sourceUpdatedAt: new DateTimeImmutable('2026-02-03T04:05:06+00:00'),
        ));
        $record = $record->fresh();

        $this->assertInstanceOf(SearchDocumentRecord::class, $record);
        $this->assertIsArray($record->payload);
        $this->assertSame('2026-01-01T00:00:00.000000Z', $record->payload['nested']['when']);
        $this->assertTrue($record->is_active);
        $this->assertSame(10, $record->priority);
        $this->assertNotNull($record->source_updated_at);
        $this->assertNotNull($record->indexed_at);
    }

    public function test_hash_is_stable_across_nested_payload_order_and_changes_for_meaningful_data(): void
    {
        $left = $this->document(payload: ['b' => ['y' => 2, 'x' => 1], 'a' => 3]);
        $right = $this->document(payload: ['a' => 3, 'b' => ['x' => 1, 'y' => 2]]);
        $changed = $this->document(payload: ['a' => 4, 'b' => ['x' => 1, 'y' => 2]]);

        $hasher = new SearchDocumentHasher;
        $this->assertSame($left->documentHash, $right->documentHash);
        $this->assertSame($hasher->hash($left), $left->documentHash);
        $this->assertNotSame($left->documentHash, $changed->documentHash);

        $base = $this->document();
        $variants = [
            $this->document(partition: 'admin'),
            $this->document(sourceKey: 'page:other'),
            $this->document(sourceType: 'brand'),
            $this->document(sourceId: '123'),
            $this->document(sourceConnection: 'source_a'),
            $this->document(locale: 'en'),
            $this->document(title: 'عنوان دیگر'),
            $this->document(excerpt: 'خلاصه دیگر'),
            $this->document(normalizedTitle: 'عنوان نرمال دیگر'),
            $this->document(normalizedExcerpt: 'خلاصه نرمال دیگر'),
            $this->document(normalizedKeywords: 'کلیدواژه دیگر'),
            $this->document(normalizedContent: 'متن دیگر'),
            $this->document(priority: 11),
            $this->document(isActive: false),
            $this->document(sourceUpdatedAt: new DateTimeImmutable('2026-01-01T00:00:00Z')),
        ];

        foreach ($variants as $variant) {
            $this->assertNotSame($base->documentHash, $variant->documentHash);
        }
    }

    public function test_model_deletion_removes_every_locale_document_owned_by_the_model(): void
    {
        $model = StorageProduct::create(['title' => 'محصول']);
        PersianSearch::index($model);
        $base = $model->toPersianSearchDocument();
        PersianSearch::indexDocument(new SearchDocument(
            partition: $base->partition(), sourceKey: $base->sourceKey(), sourceType: $base->sourceType,
            sourceId: $base->sourceId, locale: 'fa', title: $base->title, excerpt: null,
            normalizedTitle: $base->normalizedTitle, normalizedExcerpt: null, normalizedKeywords: null,
            normalizedContent: $base->normalizedContent,
        ));

        $this->assertSame(2, SearchDocumentRecord::count());
        $this->assertSame(2, $model->deletePersianSearchDocument());
        $this->assertSame(0, SearchDocumentRecord::count());
    }

    public function test_record_honors_configurable_table_and_connection(): void
    {
        config()->set('persian-search.index.table', 'custom_documents');
        config()->set('persian-search.index.connection', 'testing');

        $record = new SearchDocumentRecord;
        $this->assertSame('custom_documents', $record->getTable());
        $this->assertSame('testing', $record->getConnectionName());
    }

    public function test_operational_reindex_and_explicit_flush_api_use_source_type(): void
    {
        StorageProduct::create(['title' => 'اول']);
        StorageProduct::create(['title' => 'دوم']);
        $this->assertSame(2, StorageProduct::count());

        $reindex = $this->operationalReindex(StorageProduct::class);
        $this->assertInstanceOf(PendingCommand::class, $reindex);
        $this->assertSame(0, $reindex->run());
        $this->assertSame(2, SearchDocumentRecord::count());

        PersianSearch::index(StorageProduct::query()->firstOrFail());
        $this->assertSame(2, SearchDocumentRecord::count());

        $this->assertSame(2, PersianSearch::indexManager()->flush(StorageProduct::class));
        $this->assertSame(0, SearchDocumentRecord::count());
    }

    public function test_install_command_is_registered_and_succeeds(): void
    {
        $install = $this->artisan('persian-search:install');
        $this->assertInstanceOf(PendingCommand::class, $install);
        $install->assertSuccessful();
    }

    /** @param  array<string|int, mixed>  $payload */
    private function document(
        string $partition = 'public',
        string $sourceKey = 'page:home',
        string $sourceType = 'page',
        int|string|null $sourceId = null,
        ?string $locale = 'fa',
        ?string $title = 'خانه',
        ?string $excerpt = 'معرفی',
        ?string $normalizedTitle = 'خانه',
        ?string $normalizedExcerpt = 'معرفی',
        ?string $normalizedKeywords = 'شرکت',
        ?string $normalizedContent = 'محتوا',
        array $payload = [],
        int $priority = 10,
        bool $isActive = true,
        ?DateTimeImmutable $sourceUpdatedAt = null,
        ?string $sourceConnection = null,
    ): SearchDocument {
        return new SearchDocument(
            partition: $partition, sourceKey: $sourceKey, sourceType: $sourceType, sourceId: $sourceId,
            locale: $locale, title: $title, excerpt: $excerpt, normalizedTitle: $normalizedTitle,
            normalizedExcerpt: $normalizedExcerpt, normalizedKeywords: $normalizedKeywords, normalizedContent: $normalizedContent,
            payload: $payload, priority: $priority, isActive: $isActive, sourceUpdatedAt: $sourceUpdatedAt,
            sourceConnection: $sourceConnection,
        );
    }
}

final class StorageProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'storage_products';

    protected $guarded = [];

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title'];
    }
}
