<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SoftDeleteIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('persian-search.index.sync_on_save', true);
        config()->set('persian-search.index.delete_on_model_delete', true);
        config()->set('persian-search.index.include_soft_deleted', false);

        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();

        Schema::create('soft_deleted_products', function ($table): void {
            $table->id();
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('non_soft_deleted_products', function ($table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function test_soft_deleted_document_is_removed_when_inclusion_is_disabled(): void
    {
        $product = SoftDeletedProduct::create(['title' => 'محصول حذف‌شونده']);
        $this->assertSame(1, SearchDocumentRecord::count());

        $product->delete();

        $this->assertSame(0, SearchDocumentRecord::count());
    }

    public function test_soft_deleted_document_is_kept_when_inclusion_is_enabled(): void
    {
        config()->set('persian-search.index.include_soft_deleted', true);
        $product = SoftDeletedProduct::create(['title' => 'محصول بایگانی']);

        $product->delete();

        $this->assertTrue($product->trashed());
        $this->assertSame(1, SearchDocumentRecord::count());
    }

    public function test_soft_deleted_search_hydrates_trashed_model_and_models_collection(): void
    {
        config()->set('persian-search.index.include_soft_deleted', true);
        $product = SoftDeletedProduct::create(['title' => 'زعفران بایگانی']);
        $product->delete();

        $results = SoftDeletedProduct::persianSearch('زعفران')->results();
        $result = $results->items()[0];

        $this->assertInstanceOf(SoftDeletedProduct::class, $result->model);
        $this->assertTrue($result->model->trashed());
        $this->assertCount(1, $results->models());
        $this->assertInstanceOf(SoftDeletedProduct::class, $results->models()->first());
        $this->assertTrue($results->models()->first()->trashed());
    }

    public function test_soft_deleted_reindex_includes_trashed_models_when_enabled(): void
    {
        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.index.include_soft_deleted', true);
        SoftDeletedProduct::create(['title' => 'فعال']);
        $trashed = SoftDeletedProduct::create(['title' => 'حذف شده']);
        $trashed->delete();

        $command = $this->artisan('persian-search:reindex', ['model' => SoftDeletedProduct::class]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutputToContain('Indexed 2 Persian search document(s).');

        $this->assertSame(0, $command->run());
        $this->assertSame(2, SearchDocumentRecord::count());
    }

    public function test_soft_deleted_reindex_excludes_trashed_models_when_disabled(): void
    {
        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.index.include_soft_deleted', false);
        $active = SoftDeletedProduct::create(['title' => 'فعال']);
        $trashed = SoftDeletedProduct::create(['title' => 'حذف شده']);
        $trashed->delete();

        $command = $this->artisan('persian-search:reindex', ['model' => SoftDeletedProduct::class]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutputToContain('Indexed 1 Persian search document(s).');

        $this->assertSame(0, $command->run());
        $this->assertSame(1, SearchDocumentRecord::count());
        $this->assertSame((string) $active->getKey(), SearchDocumentRecord::firstOrFail()->source_id);
    }

    public function test_soft_deleted_force_delete_removes_owned_documents(): void
    {
        config()->set('persian-search.index.include_soft_deleted', true);
        $product = SoftDeletedProduct::create(['title' => 'حذف قطعی']);
        $product->delete();
        $this->assertSame(1, SearchDocumentRecord::count());

        $product->forceDelete();

        $this->assertSame(0, SearchDocumentRecord::count());
    }

    public function test_non_soft_deleted_model_is_unaffected_by_inclusion_setting(): void
    {
        config()->set('persian-search.index.include_soft_deleted', true);
        $product = NonSoftDeletedProduct::create(['title' => 'محصول عادی']);

        $results = NonSoftDeletedProduct::persianSearch('محصول')->results();
        $this->assertTrue($product->is($results->models()->first()));

        $product->delete();

        $this->assertSame(0, SearchDocumentRecord::count());
    }
}

final class SoftDeletedProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;
    use SoftDeletes;

    protected $table = 'soft_deleted_products';

    protected $guarded = [];

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title'];
    }
}

final class NonSoftDeletedProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'non_soft_deleted_products';

    protected $guarded = [];

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title'];
    }
}
