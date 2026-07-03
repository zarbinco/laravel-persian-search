<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Zarbinco\PersianCore\Facades\Persian;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Drivers\DatabaseSearchDriver;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\SearchResult;
use Zarbinco\PersianSearch\Search\SearchResults;
use Zarbinco\PersianSearch\Tests\TestCase;

final class DatabaseSearchDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('persian-search.index.sync_on_save', false);

        $this->migrateSearchIndex();
        $this->createModelTables();
    }

    public function test_search_driver_contract_resolves_to_database_driver(): void
    {
        $this->assertInstanceOf(DatabaseSearchDriver::class, app(SearchDriver::class));
    }

    public function test_empty_query_returns_empty_collection(): void
    {
        $this->assertTrue(PersianSearch::search('')->for(SearchDriverProduct::class)->get()->isEmpty());
    }

    public function test_basic_database_search_returns_indexed_model(): void
    {
        $product = SearchDriverProduct::create([
            'title' => 'كیكِ شکلاتي',
            'description' => 'آب‌میوه سن‌ایچ',
        ]);
        SearchDriverProduct::create([
            'title' => 'نان سنگک',
            'description' => 'صبحانه',
        ]);

        PersianSearch::index($product);

        $models = PersianSearch::search('كیك شکلاتي')
            ->for(SearchDriverProduct::class)
            ->get();

        $this->assertCount(1, $models);
        $this->assertTrue($product->is($models->first()));
        $this->assertSame(
            Persian::search('كیك شکلاتي')->normalize(),
            PersianSearch::search('كیك شکلاتي')->for(SearchDriverProduct::class)->results()->query->normalized,
        );
    }

    public function test_model_convenience_search_returns_ordered_models(): void
    {
        $product = SearchDriverProduct::create([
            'title' => 'كیكِ شکلاتي',
            'description' => 'آب‌میوه سن‌ایچ',
        ]);

        PersianSearch::index($product);

        $this->assertTrue($product->is(SearchDriverProduct::persianSearch('كیك شکلاتي')->get()->first()));
    }

    public function test_result_objects_include_model_record_score_and_matched_tokens(): void
    {
        $product = SearchDriverProduct::create([
            'title' => 'كیكِ شکلاتي',
            'description' => 'آب‌میوه سن‌ایچ',
        ]);

        PersianSearch::index($product);

        $results = PersianSearch::search('كیك شکلاتي')
            ->for(SearchDriverProduct::class)
            ->results();

        $this->assertInstanceOf(SearchResults::class, $results);
        $this->assertCount(1, $results->items());

        $result = $results->items()[0];

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertTrue($product->is($result->model));
        $this->assertInstanceOf(SearchDocumentRecord::class, $result->record);
        $this->assertGreaterThan(0, $result->score);
        $this->assertNotSame([], $result->matchedTokens);
    }

    public function test_relevance_orders_exact_title_before_all_tokens_before_single_token(): void
    {
        $singleToken = SearchDriverProduct::create([
            'title' => 'محصول ساده',
            'description' => 'كیك تازه',
        ]);
        $allTokens = SearchDriverProduct::create([
            'title' => 'محصول دوم',
            'description' => 'كیك تازه با شکلاتي خوشمزه',
        ]);
        $exactTitle = SearchDriverProduct::create([
            'title' => 'كیك شکلاتي',
            'description' => 'دسر',
        ]);

        PersianSearch::index($singleToken);
        PersianSearch::index($allTokens);
        PersianSearch::index($exactTitle);

        $models = PersianSearch::search('كیك شکلاتي')
            ->for(SearchDriverProduct::class)
            ->get();

        $this->assertTrue($exactTitle->is($models[0]));
        $this->assertTrue($allTokens->is($models[1]));
        $this->assertTrue($singleToken->is($models[2]));
    }

    public function test_normalized_title_match_scores_and_ranks_before_lower_weight_content_match(): void
    {
        $titleMatch = TitleOnlySearchDriverProduct::create([
            'title' => 'كیكِ شکلاتي',
            'description' => 'دسر تازه',
        ]);
        $contentMatch = SearchDriverProduct::create([
            'title' => 'محصول ساده',
            'description' => 'كیكِ شکلاتي',
        ]);

        PersianSearch::index($contentMatch);
        PersianSearch::index($titleMatch);

        $titleRecord = SearchDocumentRecord::query()
            ->where('searchable_type', TitleOnlySearchDriverProduct::class)
            ->firstOrFail();

        $this->assertSame(Persian::search('كیكِ شکلاتي')->normalize(), $titleRecord->title);
        $this->assertSame(Persian::search('دسر تازه')->normalize(), $titleRecord->content);

        $results = PersianSearch::search('کیک شکلاتی')
            ->for([TitleOnlySearchDriverProduct::class, SearchDriverProduct::class])
            ->results();
        $items = $results->items();

        $this->assertCount(2, $items);
        $this->assertTrue($titleMatch->is($items[0]->model));
        $this->assertTrue($contentMatch->is($items[1]->model));
        $this->assertGreaterThan(0, $items[0]->score);
        $this->assertGreaterThan($items[1]->score, $items[0]->score);
    }

    public function test_field_weight_influences_relevance_when_other_factors_are_comparable(): void
    {
        $highWeight = WeightedSearchDriverProduct::create([
            'name' => 'زعفران',
            'description' => null,
        ]);
        $lowWeight = WeightedSearchDriverProduct::create([
            'name' => null,
            'description' => 'زعفران',
        ]);

        PersianSearch::index($lowWeight);
        PersianSearch::index($highWeight);

        $models = PersianSearch::search('زعفران')
            ->for(WeightedSearchDriverProduct::class)
            ->get();

        $this->assertTrue($highWeight->is($models[0]));
        $this->assertTrue($lowWeight->is($models[1]));
    }

    public function test_searchable_type_filter_limits_results(): void
    {
        $product = SearchDriverProduct::create([
            'title' => 'كیك',
            'description' => 'توضیح',
        ]);
        $article = SearchDriverArticle::create([
            'title' => 'كیك',
            'body' => 'مقاله',
        ]);

        PersianSearch::index($product);
        PersianSearch::index($article);

        $models = PersianSearch::search('كیك')
            ->for(SearchDriverProduct::class)
            ->get();

        $this->assertCount(1, $models);
        $this->assertTrue($product->is($models->first()));
    }

    public function test_locale_filtering_is_optional(): void
    {
        $fa = SearchDriverProduct::create([
            'title' => 'كیك',
            'description' => 'فارسی',
            'locale' => 'fa',
        ]);
        $en = SearchDriverProduct::create([
            'title' => 'كیك',
            'description' => 'انگلیسی',
            'locale' => 'en',
        ]);

        PersianSearch::index($fa);
        PersianSearch::index($en);

        $faOnly = PersianSearch::search('كیك')
            ->for(SearchDriverProduct::class)
            ->locale('fa')
            ->get();

        $all = PersianSearch::search('كیك')
            ->for(SearchDriverProduct::class)
            ->get();

        $this->assertCount(1, $faOnly);
        $this->assertTrue($fa->is($faOnly->first()));
        $this->assertCount(2, $all);
    }

    public function test_limit_and_first(): void
    {
        $top = SearchDriverProduct::create([
            'title' => 'كیك شکلاتي',
            'description' => 'دسر',
        ]);
        $second = SearchDriverProduct::create([
            'title' => 'محصول دوم',
            'description' => 'كیك',
        ]);

        PersianSearch::index($top);
        PersianSearch::index($second);

        $models = PersianSearch::search('كیك')
            ->for(SearchDriverProduct::class)
            ->limit(1)
            ->get();

        $this->assertCount(1, $models);
        $this->assertTrue($top->is($models->first()));
        $this->assertTrue($top->is(PersianSearch::search('كیك')->for(SearchDriverProduct::class)->first()));
        $this->assertNull(PersianSearch::search('ناموجود')->for(SearchDriverProduct::class)->first());
    }

    public function test_missing_models_are_skipped_safely(): void
    {
        $product = SearchDriverProduct::create([
            'title' => 'كیك',
            'description' => 'توضیح',
        ]);

        PersianSearch::index($product);
        $product->newQuery()->whereKey($product->getKey())->delete();

        $this->assertTrue(PersianSearch::search('كیك')->for(SearchDriverProduct::class)->get()->isEmpty());
    }

    public function test_no_wrong_keyboard_or_synonym_expansion_is_active(): void
    {
        $bag = SearchDriverProduct::create([
            'title' => 'کیف',
            'description' => 'چرمی',
        ]);
        $car = SearchDriverProduct::create([
            'title' => 'خودرو',
            'description' => 'سواری',
        ]);

        PersianSearch::index($bag);
        PersianSearch::index($car);

        $this->assertTrue(PersianSearch::search(';dt')->for(SearchDriverProduct::class)->get()->isEmpty());
        $this->assertTrue(PersianSearch::search('ماشین')->for(SearchDriverProduct::class)->get()->isEmpty());
    }

    private function migrateSearchIndex(): void
    {
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
    }

    private function createModelTables(): void
    {
        Schema::create('search_driver_products', function ($table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('locale')->nullable();
            $table->timestamps();
        });

        Schema::create('search_driver_articles', function ($table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }
}

final class TitleOnlySearchDriverProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'search_driver_products';

    protected $guarded = [];

    /**
     * @return array<int|string, string|int|float>
     */
    public function persianSearchableFields(): array
    {
        return [
            'description' => 1,
        ];
    }
}

final class SearchDriverProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'search_driver_products';

    protected $guarded = [];

    /**
     * @return array<int|string, string|int|float>
     */
    public function persianSearchableFields(): array
    {
        return [
            'title' => 10,
            'name' => 10,
            'description' => 1,
        ];
    }

    public function persianSearchLocale(): ?string
    {
        $locale = $this->getAttribute('locale');

        return is_string($locale) && $locale !== '' ? $locale : null;
    }
}

final class WeightedSearchDriverProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'search_driver_products';

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

    public function persianSearchTitle(): string
    {
        return 'محصول';
    }
}

final class SearchDriverArticle extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'search_driver_articles';

    protected $guarded = [];

    /**
     * @return array<int|string, string|int|float>
     */
    public function persianSearchableFields(): array
    {
        return [
            'title' => 10,
            'body' => 1,
        ];
    }
}
