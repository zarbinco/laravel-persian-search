<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Zarbinco\PersianCore\Facades\Persian;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchableFieldException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchDocumentBuilderTest extends TestCase
{
    public function test_eloquent_adapter_builds_a_document_first_document(): void
    {
        config()->set('persian-search.index.default_partition', 'catalog');
        $model = new BuilderProduct([
            'id' => 42,
            'title' => 'كیكِ شکلاتي',
            'description' => 'آب‌میوه سن‌ایچ',
            'updated_at' => '2026-01-02 03:04:05',
        ]);

        $document = app(SearchDocumentBuilder::class)->build($model);

        $this->assertSame('catalog', $document->partition());
        $this->assertSame(BuilderProduct::class.':42', $document->sourceKey());
        $this->assertSame(BuilderProduct::class, $document->sourceType);
        $this->assertSame('42', $document->sourceId);
        $this->assertSame('كیكِ شکلاتي', $document->title);
        $this->assertSame(Persian::search('كیكِ شکلاتي')->normalize(), $document->normalizedTitle);
        $this->assertSame(
            Persian::search('كیكِ شکلاتي آب‌میوه سن‌ایچ')->normalize(),
            $document->normalizedContent,
        );
        $this->assertSame(['route' => 'products.show'], $document->payload);
        $this->assertSame(0, $document->priority);
        $this->assertTrue($document->isActive);
        $this->assertNotNull($document->sourceUpdatedAt);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $document->documentHash);
    }

    public function test_loaded_dot_notation_relations_are_aggregated_without_loading_relations(): void
    {
        $model = new BuilderProduct(['id' => 7, 'title' => 'محصول']);
        $model->fields = ['brand.name' => 5];
        $model->setRelation('brand', new BuilderBrand(['name' => 'سن‌ایچ']));

        $document = app(SearchDocumentBuilder::class)->build($model);

        $this->assertSame(Persian::search('سن‌ایچ')->normalize(), $document->normalizedContent);
    }

    public function test_unloaded_dot_notation_relation_is_rejected_instead_of_lazy_loaded(): void
    {
        $model = new BuilderProduct(['id' => 8, 'title' => 'محصول']);
        $model->fields = ['brand.name'];

        $this->expectException(InvalidSearchableFieldException::class);

        app(SearchDocumentBuilder::class)->build($model);
    }

    public function test_invalid_field_declarations_remain_rejected(): void
    {
        $model = new BuilderProduct(['id' => 9]);
        $model->fields = [123];

        $this->expectException(InvalidSearchableFieldException::class);

        app(SearchDocumentBuilder::class)->build($model);
    }

    public function test_manager_facade_and_trait_build_documents(): void
    {
        $model = new BuilderProduct(['id' => 10, 'title' => 'محصول']);

        $this->assertInstanceOf(SearchDocument::class, PersianSearch::documentFor($model));
        $this->assertInstanceOf(SearchDocument::class, $model->toPersianSearchDocument());
    }

    public function test_non_searchable_models_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PersianSearch::documentFor(new BuilderPlainModel(['id' => 1]));
    }
}

final class BuilderProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    /** @var array<int|string, string|int|float> */
    public array $fields = ['title' => 10, 'description' => 1];

    protected $guarded = [];

    protected $table = 'builder_products';

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return $this->fields;
    }

    /** @return array<string, mixed> */
    public function persianSearchMetadata(): array
    {
        return ['route' => 'products.show'];
    }
}

final class BuilderBrand extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

final class BuilderPlainModel extends Model
{
    protected $guarded = [];
}
