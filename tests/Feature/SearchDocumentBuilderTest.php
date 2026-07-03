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
use Zarbinco\PersianSearch\Indexing\SearchField;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchDocumentBuilderTest extends TestCase
{
    public function test_trait_defaults(): void
    {
        $model = new DefaultSearchableModel([
            'id' => 123,
            'title' => 'عنوان محصول',
            'name' => 'نام محصول',
        ]);

        $this->assertSame([], $model->persianSearchableFields());
        $this->assertSame('عنوان محصول', $model->persianSearchTitle());
        $this->assertSame(app()->getLocale(), $model->persianSearchLocale());
        $this->assertSame([], $model->persianSearchMetadata());

        $modelWithoutTitle = new DefaultSearchableModel([
            'id' => 124,
            'name' => 'نام محصول',
        ]);

        $this->assertSame('نام محصول', $modelWithoutTitle->persianSearchTitle());

        $modelWithoutTitleOrName = new DefaultSearchableModel([
            'id' => 125,
        ]);

        $this->assertSame('DefaultSearchableModel 125', $modelWithoutTitleOrName->persianSearchTitle());
    }

    public function test_document_builder_builds_document_with_weighted_simple_fields(): void
    {
        $model = new ConfigurableSearchableModel([
            'id' => 10,
            'name' => 'كیكِ شکلاتي',
            'description' => 'آب‌میوه سن‌ایچ',
        ]);
        $model->searchableFields = [
            'name' => 10,
            'description' => 1,
        ];

        $document = app(SearchDocumentBuilder::class)->build($model);

        $this->assertInstanceOf(SearchDocument::class, $document);
        $this->assertSame(ConfigurableSearchableModel::class, $document->searchableType);
        $this->assertSame(10, $document->searchableId);
        $this->assertCount(2, $document->fields);

        $name = $document->fields[0];
        $description = $document->fields[1];

        $this->assertInstanceOf(SearchField::class, $name);
        $this->assertInstanceOf(SearchField::class, $description);
        $this->assertSame('name', $name->name);
        $this->assertSame(10, $name->weight);
        $this->assertSame(Persian::search('كیكِ شکلاتي')->normalize(), $name->value);
        $this->assertSame(Persian::search('كیكِ شکلاتي')->tokens(), $name->tokens);

        $this->assertSame('description', $description->name);
        $this->assertSame(1, $description->weight);
        $this->assertSame(Persian::search('آب‌میوه سن‌ایچ')->normalize(), $description->value);
        $this->assertSame(Persian::search('آب‌میوه سن‌ایچ')->tokens(), $description->tokens);

        $expectedContent = implode(' ', [
            Persian::search('كیكِ شکلاتي')->normalize(),
            Persian::search('آب‌میوه سن‌ایچ')->normalize(),
        ]);

        $this->assertSame($expectedContent, $document->content);
        $this->assertSame(Persian::search($expectedContent)->tokens(), $document->tokens);
    }

    public function test_document_title_is_search_normalized(): void
    {
        $model = new ConfigurableSearchableModel([
            'id' => 11,
            'title' => 'كیكِ شکلاتي',
            'description' => 'دسر تازه',
        ]);
        $model->searchableFields = [
            'description' => 1,
        ];

        $this->assertSame('كیكِ شکلاتي', $model->persianSearchTitle());

        $document = app(SearchDocumentBuilder::class)->build($model);

        $this->assertSame(Persian::search('كیكِ شکلاتي')->normalize(), $document->title);
    }

    public function test_numeric_field_declarations_use_default_weight(): void
    {
        $model = new ConfigurableSearchableModel([
            'name' => 'كیكِ شکلاتي',
            'description' => 'آب‌میوه سن‌ایچ',
        ]);
        $model->searchableFields = [
            'name',
            'description',
        ];

        $document = app(SearchDocumentBuilder::class)->build($model);

        $this->assertCount(2, $document->fields);
        $this->assertSame(1, $document->fields[0]->weight);
        $this->assertSame(1, $document->fields[1]->weight);
    }

    public function test_dot_notation_loaded_relation_field_resolves_through_core_normalizer(): void
    {
        $model = new ConfigurableSearchableModel([
            'name' => 'كیك',
        ]);
        $model->searchableFields = [
            'brand.name' => 5,
        ];
        $model->setRelation('brand', new BrandModel([
            'name' => 'سن‌ایچ',
        ]));

        $document = app(SearchDocumentBuilder::class)->build($model);

        $this->assertCount(1, $document->fields);
        $this->assertSame('brand.name', $document->fields[0]->name);
        $this->assertSame(5, $document->fields[0]->weight);
        $this->assertSame(Persian::search('سن‌ایچ')->normalize(), $document->fields[0]->value);
        $this->assertSame(Persian::search('سن‌ایچ')->tokens(), $document->fields[0]->tokens);
    }

    public function test_empty_and_null_values_are_skipped_safely(): void
    {
        $model = new ConfigurableSearchableModel([
            'name' => null,
            'description' => '',
            'summary' => 'آب‌میوه',
        ]);
        $model->searchableFields = [
            'name' => 10,
            'description' => 5,
            'summary' => 1,
        ];

        $document = app(SearchDocumentBuilder::class)->build($model);

        $this->assertCount(1, $document->fields);
        $this->assertSame('summary', $document->fields[0]->name);
        $this->assertSame(Persian::search('آب‌میوه')->normalize(), $document->content);
    }

    public function test_invalid_field_declarations_throw_domain_exception(): void
    {
        $model = new ConfigurableSearchableModel([
            'name' => 'كیك',
        ]);
        $model->searchableFields = [
            123,
        ];

        $this->expectException(InvalidSearchableFieldException::class);

        app(SearchDocumentBuilder::class)->build($model);
    }

    public function test_invalid_weight_declarations_throw_domain_exception(): void
    {
        $model = new ConfigurableSearchableModel([
            'name' => 'كیك',
        ]);
        $model->searchableFields = [
            'name' => 'heavy',
        ];

        $this->expectException(InvalidSearchableFieldException::class);

        app(SearchDocumentBuilder::class)->build($model);
    }

    public function test_manager_and_facade_build_documents(): void
    {
        $model = new ConfigurableSearchableModel([
            'id' => 20,
            'name' => 'كیكِ شکلاتي',
        ]);
        $model->searchableFields = [
            'name' => 10,
        ];

        $this->assertInstanceOf(SearchDocument::class, PersianSearch::documentFor($model));
        $this->assertInstanceOf(SearchDocument::class, $model->toPersianSearchDocument());
    }

    public function test_manager_rejects_models_that_do_not_implement_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PersianSearch::documentFor(new PlainModel([
            'name' => 'كیك',
        ]));
    }
}

final class DefaultSearchableModel extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $guarded = [];

    protected $table = 'default_searchable_models';

    public $timestamps = false;
}

final class ConfigurableSearchableModel extends Model implements PersianSearchable
{
    use HasPersianSearch;

    /**
     * @var array<int|string, string|int|float>
     */
    public array $searchableFields = [];

    protected $guarded = [];

    protected $table = 'configurable_searchable_models';

    public $timestamps = false;

    /**
     * @return array<int|string, string|int|float>
     */
    public function persianSearchableFields(): array
    {
        return $this->searchableFields;
    }

    /**
     * @return array<string, mixed>
     */
    public function persianSearchMetadata(): array
    {
        return [
            'source' => 'test',
        ];
    }
}

final class BrandModel extends Model
{
    protected $guarded = [];

    protected $table = 'brands';

    public $timestamps = false;
}

final class PlainModel extends Model
{
    protected $guarded = [];

    protected $table = 'plain_models';

    public $timestamps = false;
}
