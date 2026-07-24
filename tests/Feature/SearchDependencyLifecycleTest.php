<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchDependencyResolver;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyContext;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyOperation;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyState;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocatorFactory;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchDependencyLifecycleTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        foreach (['dependency', 'source', 'unrelated'] as $connection) {
            $app['config']->set("database.connections.{$connection}", [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
        }

        $app['config']->set('persian-search.dependencies.resolvers', [BrandDependencyResolver::class]);
        $app['config']->set('persian-search.lifecycle.after_commit', true);
        $app['config']->set('persian-search.lifecycle.execution', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['dependency', 'source', 'unrelated'] as $connection) {
            DB::purge($connection);
        }

        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();

        Schema::connection('dependency')->create('dependency_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('source')->create('dependency_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->string('title');
            $table->timestamps();
        });

        BrandDependencyResolver::$contexts = [];
    }

    public function test_dependency_update_rebuilds_related_source_from_current_state(): void
    {
        $brand = DependencyBrand::create(['name' => 'Old Brand']);
        $product = DependencyProduct::create(['brand_id' => $brand->getKey(), 'title' => 'Phone']);
        $this->assertStringContainsString('old brand', $this->contentFor($product));

        $brand->update(['name' => 'New Brand']);

        $content = $this->contentFor($product);
        $this->assertStringContainsString('new brand', $content);
        $this->assertStringNotContainsString('old brand', $content);
        $this->assertSame(
            [
                [SearchDependencyOperation::Created, SearchDependencyState::After],
                [SearchDependencyOperation::Updated, SearchDependencyState::Before],
                [SearchDependencyOperation::Updated, SearchDependencyState::After],
            ],
            BrandDependencyResolver::$contexts,
        );
    }

    public function test_soft_delete_resolves_before_removal_and_restore_resolves_after_state(): void
    {
        $brand = DependencyBrand::create(['name' => 'Restorable Brand']);
        $product = DependencyProduct::create(['brand_id' => $brand->getKey(), 'title' => 'Tablet']);
        BrandDependencyResolver::$contexts = [];

        $brand->delete();

        $this->assertStringNotContainsString('restorable brand', $this->contentFor($product));
        $this->assertSame(
            [[SearchDependencyOperation::Deleted, SearchDependencyState::Before]],
            BrandDependencyResolver::$contexts,
        );

        BrandDependencyResolver::$contexts = [];
        $brand->restore();

        $this->assertStringContainsString('restorable brand', $this->contentFor($product));
        $this->assertSame(
            [[SearchDependencyOperation::Restored, SearchDependencyState::After]],
            BrandDependencyResolver::$contexts,
        );
    }

    public function test_force_delete_dispatches_one_pre_delete_dependency_batch(): void
    {
        $brand = DependencyBrand::create(['name' => 'Force Brand']);
        $product = DependencyProduct::create(['brand_id' => $brand->getKey(), 'title' => 'Watch']);
        BrandDependencyResolver::$contexts = [];

        $brand->forceDelete();

        $this->assertStringNotContainsString('force brand', $this->contentFor($product));
        $this->assertSame(
            [[SearchDependencyOperation::Deleted, SearchDependencyState::Before]],
            BrandDependencyResolver::$contexts,
        );
    }

    public function test_dependency_after_commit_and_rollback_preserve_transaction_boundary(): void
    {
        $brand = DependencyBrand::create(['name' => 'Committed Brand']);
        $product = DependencyProduct::create(['brand_id' => $brand->getKey(), 'title' => 'Camera']);

        $dependency = DB::connection('dependency');
        $dependency->beginTransaction();
        $brand->update(['name' => 'Rolled Back Brand']);
        $this->assertStringContainsString('committed brand', $this->contentFor($product));
        $dependency->rollBack();
        $this->assertStringContainsString('committed brand', $this->contentFor($product));

        $dependency->transaction(static function () use ($brand): void {
            $brand->refresh()->update(['name' => 'After Commit Brand']);
        });

        $this->assertStringContainsString('after commit brand', $this->contentFor($product));
    }

    public function test_nested_dependency_after_commit_waits_for_outer_transaction(): void
    {
        $brand = DependencyBrand::create(['name' => 'Outer Brand']);
        $product = DependencyProduct::create(['brand_id' => $brand->getKey(), 'title' => 'Lens']);
        $dependency = DB::connection('dependency');

        $dependency->beginTransaction();
        $dependency->transaction(static function () use ($brand): void {
            $brand->update(['name' => 'Nested Brand']);
        });
        $this->assertStringContainsString('outer brand', $this->contentFor($product));
        $dependency->commit();

        $this->assertStringContainsString('nested brand', $this->contentFor($product));
    }

    public function test_open_source_and_unrelated_transactions_do_not_delay_dependency_routing(): void
    {
        $brand = DependencyBrand::create(['name' => 'Independent Brand']);
        $product = DependencyProduct::create(['brand_id' => $brand->getKey(), 'title' => 'Speaker']);
        $source = DB::connection('source');
        $unrelated = DB::connection('unrelated');
        $source->beginTransaction();
        $unrelated->beginTransaction();

        $brand->update(['name' => 'Immediately Routed Brand']);

        $this->assertStringContainsString('immediately routed brand', $this->contentFor($product));
        $source->rollBack();
        $unrelated->rollBack();
        $this->assertStringContainsString('immediately routed brand', $this->contentFor($product));
    }

    private function recordFor(DependencyProduct $product): SearchDocumentRecord
    {
        $record = SearchDocumentRecord::query()->where('source_id', (string) $product->getKey())->first();
        $this->assertInstanceOf(SearchDocumentRecord::class, $record);

        return $record;
    }

    private function contentFor(DependencyProduct $product): string
    {
        $record = $this->recordFor($product)->fresh();
        $this->assertInstanceOf(SearchDocumentRecord::class, $record);
        $this->assertIsString($record->normalized_content);

        return $record->normalized_content;
    }
}

final class DependencyBrand extends Model
{
    use SoftDeletes;

    protected $table = 'dependency_brands';

    protected $connection = 'dependency';

    protected $guarded = [];
}

final class DependencyProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'dependency_products';

    protected $connection = 'source';

    protected $guarded = [];

    /** @return BelongsTo<DependencyBrand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(DependencyBrand::class, 'brand_id');
    }

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title', 'brand.name'];
    }

    /** @return list<string> */
    public function persianSearchableRelations(): array
    {
        return ['brand'];
    }
}

final class BrandDependencyResolver implements SearchDependencyResolver
{
    /** @var list<array{SearchDependencyOperation, SearchDependencyState}> */
    public static array $contexts = [];

    public function __construct(private readonly SearchSourceLocatorFactory $locators) {}

    public function key(): string
    {
        return 'brand_dependency';
    }

    public function dependencyModel(): string
    {
        return DependencyBrand::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        self::$contexts[] = [$context->operation, $context->state];

        foreach (DependencyProduct::query()
            ->where('brand_id', $context->dependency->getKey())
            ->orderBy('id')
            ->cursor() as $product) {
            yield $this->locators->forModel($product, 'eloquent');
        }
    }
}
