<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Zarbinco\PersianCore\Facades\Persian;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Exceptions\SearchableModelNotPersistedException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchField;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchIndexStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.index.delete_on_model_delete', true);
        config()->set('persian-search.index.include_soft_deleted', false);

        $this->migrateSearchIndex();
        $this->createModelTables();
    }

    public function test_package_migration_creates_search_documents_table(): void
    {
        $this->assertTrue(Schema::hasTable('persian_search_documents'));

        foreach ([
            'id',
            'searchable_type',
            'searchable_id',
            'locale',
            'title',
            'content',
            'tokens',
            'fields',
            'metadata',
            'indexed_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('persian_search_documents', $column), "Missing column [{$column}].");
        }
    }

    public function test_search_document_record_uses_configurable_table_and_casts_arrays(): void
    {
        config()->set('persian-search.index.table', 'custom_persian_search_documents');

        $this->assertSame('custom_persian_search_documents', (new SearchDocumentRecord)->getTable());

        config()->set('persian-search.index.table', 'persian_search_documents');

        $record = SearchDocumentRecord::create([
            'searchable_type' => TestIndexedProduct::class,
            'searchable_id' => '1',
            'locale' => '',
            'title' => 'كیك',
            'content' => 'کیک',
            'tokens' => ['کیک'],
            'fields' => [
                [
                    'name' => 'name',
                    'raw_value' => 'كیك',
                    'value' => 'کیک',
                    'tokens' => ['کیک'],
                    'weight' => 10,
                ],
            ],
            'metadata' => ['source' => 'test'],
            'indexed_at' => now(),
        ])->fresh();

        $this->assertInstanceOf(SearchDocumentRecord::class, $record);
        $this->assertSame(['کیک'], $record->tokens);
        $this->assertSame('name', $this->firstStoredField($record)['name']);
        $this->assertSame(['source' => 'test'], $record->metadata);
        $this->assertNotNull($record->indexed_at);
    }

    public function test_manual_indexing_persists_document_payload(): void
    {
        $model = TestIndexedProduct::create([
            'name' => 'كیكِ شکلاتي',
            'description' => 'آب‌میوه سن‌ایچ',
        ]);

        $record = PersianSearch::index($model);
        $document = app(SearchDocumentBuilder::class)->build($model);

        $this->assertInstanceOf(SearchDocumentRecord::class, $record);
        $this->assertDatabaseCount('persian_search_documents', 1);
        $this->assertSame(TestIndexedProduct::class, $record->searchable_type);
        $this->assertSame((string) $model->getKey(), $record->searchable_id);
        $this->assertSame('', $record->locale);
        $this->assertSame($document->content, $record->content);
        $this->assertSame($document->tokens, $record->tokens);
        $storedField = $this->firstStoredField($record);

        $this->assertSame($document->fields[0]->toArray(), $storedField);
        $this->assertSame(Persian::search('كیكِ شکلاتي')->normalize(), $storedField['value']);
        $this->assertSame(Persian::search('كیكِ شکلاتي')->tokens(), $storedField['tokens']);
    }

    public function test_persisted_field_payloads_are_json_safe_for_complex_raw_values(): void
    {
        $model = ComplexPayloadIndexedProduct::create([
            'name' => 'كیك',
            'description' => 'توضیح',
            'payload' => [
                'primary' => 'آب‌میوه',
                'nested' => [
                    'brand' => 'سن‌ایچ',
                ],
            ],
            'status' => TestStorageStatus::Featured,
            'label_object' => 'برچسب',
        ]);

        $record = PersianSearch::index($model)->fresh();
        $document = app(SearchDocumentBuilder::class)->build($model);

        $this->assertInstanceOf(SearchDocumentRecord::class, $record);
        $this->assertIsArray($record->fields);
        $encodedFields = json_encode($record->fields, JSON_THROW_ON_ERROR);

        $this->assertIsArray(json_decode($encodedFields, true, 512, JSON_THROW_ON_ERROR));

        $payloadField = $this->storedFieldByName($record, 'payload');
        $statusField = $this->storedFieldByName($record, 'status');
        $stringableField = $this->storedFieldByName($record, 'label_object');

        $this->assertSame([
            'primary' => 'آب‌میوه',
            'nested' => [
                'brand' => 'سن‌ایچ',
            ],
        ], $payloadField['raw_value']);
        $this->assertSame('featured', $statusField['raw_value']);
        $this->assertSame('برچسب', $stringableField['raw_value']);

        $this->assertStoredFieldMatchesDocumentField($payloadField, $this->documentFieldByName($document, 'payload'));
        $this->assertStoredFieldMatchesDocumentField($statusField, $this->documentFieldByName($document, 'status'));
        $this->assertStoredFieldMatchesDocumentField($stringableField, $this->documentFieldByName($document, 'label_object'));
    }

    public function test_indexing_same_model_updates_existing_row(): void
    {
        $model = TestIndexedProduct::create([
            'name' => 'كیك ساده',
            'description' => 'توضیح اولیه',
        ]);

        PersianSearch::index($model);

        $model->forceFill([
            'name' => 'كیكِ شکلاتي',
        ])->save();

        PersianSearch::index($model);

        $this->assertDatabaseCount('persian_search_documents', 1);

        $record = SearchDocumentRecord::firstOrFail();

        $this->assertStringContainsString(Persian::search('كیكِ شکلاتي')->normalize(), $record->content);
    }

    public function test_delete_from_index_removes_document(): void
    {
        $model = TestIndexedProduct::create([
            'name' => 'كیك',
            'description' => 'توضیح',
        ]);

        PersianSearch::index($model);

        $this->assertSame(1, PersianSearch::deleteFromIndex($model));
        $this->assertDatabaseCount('persian_search_documents', 0);
    }

    public function test_flush_index_removes_matching_model_documents_only(): void
    {
        $product = TestIndexedProduct::create([
            'name' => 'كیك',
            'description' => 'توضیح',
        ]);
        $otherProduct = OtherIndexedProduct::create([
            'name' => 'آب‌میوه',
            'description' => 'توضیح',
        ]);

        PersianSearch::index($product);
        PersianSearch::index($otherProduct);

        $this->assertSame(1, PersianSearch::flushIndex(TestIndexedProduct::class));
        $this->assertDatabaseCount('persian_search_documents', 1);
        $this->assertSame(OtherIndexedProduct::class, SearchDocumentRecord::firstOrFail()->searchable_type);

        $this->assertSame(1, PersianSearch::flushIndex());
        $this->assertDatabaseCount('persian_search_documents', 0);
    }

    public function test_trait_convenience_methods_persist_and_delete_index(): void
    {
        $model = TestIndexedProduct::create([
            'name' => 'كیك',
            'description' => 'توضیح',
        ]);

        $this->assertInstanceOf(SearchDocumentRecord::class, $model->savePersianSearchDocument());
        $this->assertDatabaseCount('persian_search_documents', 1);
        $this->assertSame(1, $model->deletePersianSearchDocument());
        $this->assertDatabaseCount('persian_search_documents', 0);
    }

    public function test_automatic_sync_on_save_and_delete(): void
    {
        config()->set('persian-search.index.sync_on_save', true);

        $model = TestIndexedProduct::create([
            'name' => 'كیك',
            'description' => 'توضیح',
        ]);

        $this->assertDatabaseCount('persian_search_documents', 1);

        $model->forceFill([
            'name' => 'آب‌میوه',
        ])->save();

        $record = SearchDocumentRecord::firstOrFail();

        $this->assertStringContainsString(Persian::search('آب‌میوه')->normalize(), $record->content);

        $model->delete();

        $this->assertDatabaseCount('persian_search_documents', 0);
    }

    public function test_soft_deleted_models_are_removed_and_restored_models_are_reindexed(): void
    {
        config()->set('persian-search.index.sync_on_save', true);

        $model = SoftDeletedIndexedProduct::create([
            'name' => 'كیك',
            'description' => 'توضیح',
        ]);

        $this->assertDatabaseCount('persian_search_documents', 1);

        $model->delete();

        $this->assertDatabaseCount('persian_search_documents', 0);

        $model->restore();

        $this->assertDatabaseCount('persian_search_documents', 1);
    }

    public function test_unsaved_model_guard(): void
    {
        $this->expectException(SearchableModelNotPersistedException::class);

        PersianSearch::index(new TestIndexedProduct([
            'name' => 'كیك',
            'description' => 'توضیح',
        ]));
    }

    public function test_invalid_model_guard(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PersianSearch::index(PlainIndexedModel::create([
            'name' => 'كیك',
        ]));
    }

    public function test_console_commands_run_expected_storage_actions(): void
    {
        $this->assertArtisanSucceeds('persian-search:install');

        TestIndexedProduct::create([
            'name' => 'كیك',
            'description' => 'توضیح',
        ]);

        $this->assertArtisanSucceeds('persian-search:reindex', [
            'model' => TestIndexedProduct::class,
            '--fresh' => true,
            '--chunk' => 1,
        ]);

        $this->assertDatabaseCount('persian_search_documents', 1);

        $this->assertArtisanSucceeds('persian-search:flush', [
            'model' => TestIndexedProduct::class,
        ]);

        $this->assertDatabaseCount('persian_search_documents', 0);

        PersianSearch::index(TestIndexedProduct::firstOrFail());

        $this->assertArtisanSucceeds('persian-search:flush', [
            '--force' => true,
        ]);

        $this->assertDatabaseCount('persian_search_documents', 0);
    }

    public function test_regression_no_search_execution_api_or_database_driver_is_added(): void
    {
        $sourceFiles = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../src'),
        );

        foreach ($sourceFiles as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            $this->assertIsString($contents);
            $this->assertStringNotContainsString('class DatabaseSearchDriver', $contents);
            $this->assertStringNotContainsString('function persianSearch(', $contents);
            $this->assertStringNotContainsString('scopePersianSearch', $contents);
        }
    }

    private function migrateSearchIndex(): void
    {
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
    }

    private function createModelTables(): void
    {
        Schema::create('persian_search_test_products', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->text('payload')->nullable();
            $table->string('status')->nullable();
            $table->string('label_object')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('plain_indexed_models', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array{name: string, raw_value: mixed, value: string, tokens: array<int, string>, weight: int|float}
     */
    private function firstStoredField(SearchDocumentRecord $record): array
    {
        $fields = $record->fields;
        $this->assertIsArray($fields);

        $field = $fields[0] ?? null;

        return $this->storedFieldShape($field);
    }

    /**
     * @return array{name: string, raw_value: mixed, value: string, tokens: array<int, string>, weight: int|float}
     */
    private function storedFieldByName(SearchDocumentRecord $record, string $name): array
    {
        $fields = $record->fields;
        $this->assertIsArray($fields);

        foreach ($fields as $field) {
            $storedField = $this->storedFieldShape($field);

            if ($storedField['name'] === $name) {
                return $storedField;
            }
        }

        throw new \RuntimeException("Stored field [{$name}] was not found.");
    }

    /**
     * @return array{name: string, raw_value: mixed, value: string, tokens: array<int, string>, weight: int|float}
     */
    private function storedFieldShape(mixed $field): array
    {
        $this->assertIsArray($field);
        $this->assertArrayHasKey('name', $field);
        $this->assertArrayHasKey('raw_value', $field);
        $this->assertArrayHasKey('value', $field);
        $this->assertArrayHasKey('tokens', $field);
        $this->assertArrayHasKey('weight', $field);

        $name = $field['name'];
        $value = $field['value'];
        $tokens = $field['tokens'];
        $weight = $field['weight'];

        $this->assertIsString($name);
        $this->assertIsString($value);
        $this->assertIsArray($tokens);
        $this->assertTrue(is_int($weight) || is_float($weight));

        $safeTokens = [];

        foreach ($tokens as $token) {
            $this->assertIsString($token);
            $safeTokens[] = $token;
        }

        return [
            'name' => $name,
            'raw_value' => $field['raw_value'],
            'value' => $value,
            'tokens' => $safeTokens,
            'weight' => $weight,
        ];
    }

    private function documentFieldByName(SearchDocument $document, string $name): SearchField
    {
        foreach ($document->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        throw new \RuntimeException("Document field [{$name}] was not found.");
    }

    /**
     * @param  array{name: string, raw_value: mixed, value: string, tokens: array<int, string>, weight: int|float}  $storedField
     */
    private function assertStoredFieldMatchesDocumentField(array $storedField, SearchField $documentField): void
    {
        $this->assertSame($documentField->name, $storedField['name']);
        $this->assertSame($documentField->value, $storedField['value']);
        $this->assertSame($documentField->tokens, $storedField['tokens']);
        $this->assertSame($documentField->weight, $storedField['weight']);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function assertArtisanSucceeds(string $command, array $parameters = []): void
    {
        $result = $this->artisan($command, $parameters);

        if ($result instanceof PendingCommand) {
            $result->assertExitCode(0);

            return;
        }

        $this->assertSame(0, $result);
    }
}

final class TestIndexedProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'persian_search_test_products';

    protected $guarded = [];

    /**
     * @return array<int|string, string|int|float>
     */
    public function persianSearchableFields(): array
    {
        return [
            'name' => 10,
            'description' => 1,
        ];
    }

    public function persianSearchLocale(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function persianSearchMetadata(): array
    {
        return [
            'source' => 'storage-test',
        ];
    }
}

final class OtherIndexedProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'persian_search_test_products';

    protected $guarded = [];

    /**
     * @return array<int|string, string|int|float>
     */
    public function persianSearchableFields(): array
    {
        return [
            'name' => 10,
            'description' => 1,
        ];
    }
}

final class SoftDeletedIndexedProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;
    use SoftDeletes;

    protected $table = 'persian_search_test_products';

    protected $guarded = [];

    /**
     * @return array<int|string, string|int|float>
     */
    public function persianSearchableFields(): array
    {
        return [
            'name' => 10,
            'description' => 1,
        ];
    }
}

final class PlainIndexedModel extends Model
{
    protected $table = 'plain_indexed_models';

    protected $guarded = [];
}

final class ComplexPayloadIndexedProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'persian_search_test_products';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'status' => TestStorageStatus::class,
    ];

    /**
     * @return array<int|string, string|int|float>
     */
    public function persianSearchableFields(): array
    {
        return [
            'payload' => 2,
            'status' => 3,
            'label_object' => 4,
        ];
    }

    public function getLabelObjectAttribute(?string $value): TestStringableFieldValue
    {
        return new TestStringableFieldValue($value ?? '');
    }
}

enum TestStorageStatus: string
{
    case Featured = 'featured';
}

final readonly class TestStringableFieldValue implements \Stringable
{
    public function __construct(
        private string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
