<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Zarbinco\PersianCore\Facades\Persian;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Drivers\DatabaseSearchDriver;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\SearchResult;
use Zarbinco\PersianSearch\Tests\TestCase;

final class DatabaseSearchDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
        Schema::create('driver_products', function ($table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('locale')->nullable();
            $table->timestamps();
        });
    }

    public function test_database_driver_resolves_and_eloquent_results_hydrate(): void
    {
        $this->assertInstanceOf(DatabaseSearchDriver::class, app(SearchDriver::class));
        $product = DriverProduct::create(['title' => 'كیكِ شکلاتي', 'description' => 'دسر تازه', 'locale' => 'fa']);
        PersianSearch::index($product);

        $results = DriverProduct::persianSearch('کیک شکلاتی')->results();
        $result = $results->items()[0];

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertTrue($product->is($result->model));
        $this->assertInstanceOf(SearchDocumentRecord::class, $result->record);
        $this->assertGreaterThan(0, $result->score);
        $this->assertTrue($product->is($results->models()->first()));
        $this->assertTrue($product->is(DriverProduct::persianSearch('کیک')->get()->first()));
    }

    public function test_virtual_results_are_not_discarded_and_models_excludes_them(): void
    {
        PersianSearch::indexDocument($this->virtualDocument());

        $results = PersianSearch::search('درباره')->types(['page', 'brand'])->partition('public')->locale('fa')->results();

        $this->assertCount(1, $results->items());
        $this->assertSame('page', $results->items()[0]->record->source_type);
        $this->assertNull($results->items()[0]->model);
        $this->assertTrue($results->models()->isEmpty());
    }

    public function test_missing_eloquent_models_remain_document_results_with_null_model(): void
    {
        $product = DriverProduct::create(['title' => 'زعفران', 'description' => 'ایرانی']);
        PersianSearch::index($product);
        $product->newQuery()->whereKey($product->getKey())->delete();

        $results = PersianSearch::search('زعفران')->for(DriverProduct::class)->results();

        $this->assertCount(1, $results->items());
        $this->assertNull($results->items()[0]->model);
        $this->assertTrue($results->models()->isEmpty());
    }

    public function test_inactive_documents_are_excluded(): void
    {
        PersianSearch::indexDocument($this->virtualDocument(isActive: false));

        $this->assertTrue(PersianSearch::search('درباره')->type('page')->partition('public')->results()->isEmpty());
    }

    public function test_locale_partition_and_arbitrary_source_type_filters_work(): void
    {
        PersianSearch::indexDocument($this->virtualDocument(locale: 'fa', partition: 'public'));
        PersianSearch::indexDocument($this->virtualDocument(locale: 'en', partition: 'public', sourceKey: 'page:about-en'));
        PersianSearch::indexDocument($this->virtualDocument(locale: 'fa', partition: 'admin', sourceKey: 'page:about-admin'));

        $results = PersianSearch::search('درباره')->type('page')->locale('fa')->partition('public')->results();

        $this->assertCount(1, $results->items());
        $this->assertSame('fa', $results->items()[0]->record->locale);
        $this->assertSame('public', $results->items()[0]->record->partition);
    }

    public function test_normalized_title_is_preferred_while_raw_title_is_preserved(): void
    {
        $title = DriverProduct::create(['title' => 'كیكِ شکلاتي', 'description' => 'دسر']);
        $content = DriverProduct::create(['title' => 'محصول', 'description' => 'كیكِ شکلاتي']);
        PersianSearch::index($content);
        PersianSearch::index($title);

        $items = PersianSearch::search('کیک شکلاتی')->for(DriverProduct::class)->results()->items();

        $this->assertCount(2, $items);
        $this->assertTrue($title->is($items[0]->model));
        $this->assertSame('كیكِ شکلاتي', $items[0]->record->title);
        $this->assertSame(Persian::search('كیكِ شکلاتي')->normalize(), $items[0]->record->normalized_title);
    }

    public function test_query_expansion_still_matches_keyboard_and_synonym_candidates(): void
    {
        $bag = DriverProduct::create(['title' => 'کیف چرمی']);
        $phone = DriverProduct::create(['title' => 'گوشی سامسونگ']);
        PersianSearch::index($bag);
        PersianSearch::index($phone);

        $keyboard = DriverProduct::persianSearch(';dt')->results()->items()[0];
        $this->assertTrue($bag->is($keyboard->model));
        $this->assertSame('keyboard', $keyboard->candidateSource);

        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.map', ['گوشی' => ['موبایل']]);
        $synonym = DriverProduct::persianSearch('موبایل سامسونگ')->results()->items()[0];
        $this->assertTrue($phone->is($synonym->model));
        $this->assertSame('synonym', $synonym->candidateSource);
    }

    public function test_source_type_and_partition_reject_empty_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PersianSearch::search('test')->type(' ');
    }

    private function virtualDocument(
        bool $isActive = true,
        string $locale = 'fa',
        string $partition = 'public',
        string $sourceKey = 'page:about',
    ): SearchDocument {
        return new SearchDocument(
            partition: $partition,
            sourceKey: $sourceKey,
            sourceType: 'page',
            sourceId: null,
            locale: $locale,
            title: 'درباره ما',
            excerpt: 'درباره شرکت',
            normalizedTitle: Persian::search('درباره ما')->normalize(),
            normalizedExcerpt: Persian::search('درباره شرکت')->normalize(),
            normalizedKeywords: Persian::search('شرکت سن ایچ')->normalize(),
            normalizedContent: Persian::search('تاریخچه و معرفی')->normalize(),
            payload: ['route_name' => 'about'],
            priority: 10,
            isActive: $isActive,
        );
    }
}

final class DriverProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'driver_products';

    protected $guarded = [];

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title' => 10, 'description' => 1];
    }

    public function persianSearchLocale(): ?string
    {
        $locale = $this->getAttribute('locale');

        return is_string($locale) ? $locale : null;
    }
}
