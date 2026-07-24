<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchableRelationException;
use Zarbinco\PersianSearch\Exceptions\SearchableModelNotPersistedException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\EloquentSearchDocumentProvider;
use Zarbinco\PersianSearch\Providers\EloquentSearchSourceReferenceFactory;
use Zarbinco\PersianSearch\Tests\TestCase;

final class EloquentSearchDocumentProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
        Schema::create('provider_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('provider_brands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id');
            $table->string('name');
        });
        Schema::create('provider_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable();
            $table->string('title');
            $table->string('locale')->nullable();
            $table->timestamps();
        });
    }

    public function test_fallback_supports_only_searchable_models_and_rejects_unpersisted_sources(): void
    {
        $provider = app(EloquentSearchDocumentProvider::class);

        $this->assertTrue($provider->supports(new ProviderProduct));
        $this->assertFalse($provider->supports(new ProviderPlainModel));
        $this->assertSame([], $provider->relations(new ProviderDefaultProduct));
        $this->expectException(SearchableModelNotPersistedException::class);
        $provider->reference(new ProviderProduct(['title' => 'Unsaved']));
    }

    public function test_integer_uuid_and_padded_keys_are_preserved_by_central_reference_factory(): void
    {
        $factory = app(EloquentSearchSourceReferenceFactory::class);
        $integer = new ProviderProduct;
        $integer->setRawAttributes(['id' => 12], true);
        $uuid = new ProviderProduct;
        $uuid->setKeyType('string');
        $uuid->setRawAttributes(['id' => '550e8400-e29b-41d4-a716-446655440000'], true);
        $padded = new ProviderProduct;
        $padded->setKeyType('string');
        $padded->setRawAttributes(['id' => '00123'], true);

        $this->assertSame('12', $factory->make($integer)->sourceId);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $factory->make($uuid)->sourceId);
        $this->assertSame('00123', $factory->make($padded)->sourceId);
        $this->assertSame(ProviderProduct::class.':12', $factory->make($integer)->sourceKey);
    }

    public function test_simple_model_produces_one_document_with_pipeline_payload_locale_and_timestamp(): void
    {
        $product = ProviderProduct::create(['title' => 'كیك تازه', 'locale' => 'fa']);
        $document = PersianSearch::documentsFor($product)->all()[0];

        $this->assertSame(ProviderProduct::class, $document->sourceType);
        $this->assertSame((string) $product->getKey(), $document->sourceId);
        $this->assertSame('کیک تازه', $document->normalizedTitle);
        $this->assertSame(['kind' => 'product'], $document->payload);
        $this->assertSame('fa', $document->locale());
        $this->assertNotNull($document->sourceUpdatedAt);
    }

    public function test_declared_nested_relations_load_missing_and_duplicates_are_removed(): void
    {
        $company = ProviderCompany::create(['name' => 'Company']);
        $brand = ProviderBrand::create(['name' => 'Brand', 'company_id' => $company->getKey()]);
        $product = ProviderProduct::create(['title' => 'Product', 'brand_id' => $brand->getKey(), 'locale' => 'en']);
        $product = ProviderProduct::query()->whereKey($product->getKey())->firstOrFail();
        $provider = app(EloquentSearchDocumentProvider::class);

        $this->assertSame(['brand', 'brand.company'], $provider->relations($product));
        $document = PersianSearch::documentsFor($product)->all()[0];
        $this->assertTrue($product->relationLoaded('brand'));
        $loadedBrand = $product->getRelation('brand');
        $this->assertInstanceOf(ProviderBrand::class, $loadedBrand);
        $this->assertTrue($loadedBrand->relationLoaded('company'));
        $this->assertStringContainsString('brand', (string) $document->normalizedContent);
        $this->assertStringContainsString('company', (string) $document->normalizedContent);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        PersianSearch::documentsFor($product);
        $this->assertSame(0, $queries);
    }

    public function test_invalid_relation_declarations_are_rejected(): void
    {
        $model = new InvalidRelationProduct;
        $model->setRawAttributes(['id' => 1, 'title' => 'Invalid'], true);
        $this->expectException(InvalidSearchableRelationException::class);

        app(EloquentSearchDocumentProvider::class)->relations($model);
    }

    public function test_reindex_eager_loads_declared_relations_once_for_a_chunk(): void
    {
        $company = ProviderCompany::create(['name' => 'Company']);
        $brand = ProviderBrand::create(['name' => 'Brand', 'company_id' => $company->getKey()]);
        ProviderProduct::create(['title' => 'One', 'brand_id' => $brand->getKey()]);
        ProviderProduct::create(['title' => 'Two', 'brand_id' => $brand->getKey()]);
        Model::preventLazyLoading();

        $command = $this->operationalReindex(ProviderProduct::class);
        $this->assertInstanceOf(PendingCommand::class, $command);
        try {
            $exitCode = $command->execute();
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('persian_search_documents', 2);
    }

    public function test_reindex_keeps_model_global_scopes_active(): void
    {
        ProviderScopedProduct::create(['title' => 'Visible']);
        ProviderScopedProduct::create(['title' => 'Hidden']);

        $command = $this->operationalReindex(ProviderScopedProduct::class);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $this->assertSame(0, $command->execute());

        $this->assertDatabaseCount('persian_search_documents', 1);
        $this->assertSame('Visible', SearchDocumentRecord::query()->value('title'));
    }
}

final class ProviderCompany extends Model
{
    public $timestamps = false;

    protected $table = 'provider_companies';

    protected $guarded = [];
}

final class ProviderBrand extends Model
{
    public $timestamps = false;

    protected $table = 'provider_brands';

    protected $guarded = [];

    /** @return BelongsTo<ProviderCompany, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(ProviderCompany::class, 'company_id');
    }
}

class ProviderProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'provider_products';

    protected $guarded = [];

    /** @return BelongsTo<ProviderBrand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProviderBrand::class, 'brand_id');
    }

    public function persianSearchableFields(): array
    {
        return ['title', 'brand.name', 'brand.company.name'];
    }

    /** @return list<string> */
    public function persianSearchableRelations(): array
    {
        return ['brand', 'brand.company', 'brand'];
    }

    public function persianSearchLocale(): ?string
    {
        return $this->getAttribute('locale');
    }

    public function persianSearchMetadata(): array
    {
        return ['kind' => 'product'];
    }
}

final class InvalidRelationProduct extends ProviderProduct
{
    /** @return list<mixed> */
    public function persianSearchableRelations(): array
    {
        return ['', 12];
    }
}

final class ProviderPlainModel extends Model {}

final class ProviderDefaultProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'provider_products';
}

final class ProviderScopedProduct extends ProviderProduct
{
    protected static function booted(): void
    {
        self::addGlobalScope('visible', static function (Builder $query): void {
            $query->where('title', '!=', 'Hidden');
        });
    }
}
