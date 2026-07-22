<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
        app()->setLocale('fa');
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
        $title = DriverProduct::create(['title' => 'كیكِ شکلاتي', 'description' => 'دسر', 'locale' => 'fa']);
        $content = DriverProduct::create(['title' => 'محصول', 'description' => 'كیكِ شکلاتي', 'locale' => 'fa']);
        PersianSearch::index($content);
        PersianSearch::index($title);

        $items = PersianSearch::search('کیک شکلاتی')->for(DriverProduct::class)->locale('fa')->results()->items();

        $this->assertCount(2, $items);
        $this->assertTrue($title->is($items[0]->model));
        $this->assertSame('كیكِ شکلاتي', $items[0]->record->title);
        $this->assertSame(Persian::search('كیكِ شکلاتي')->normalize(), $items[0]->record->normalized_title);
    }

    public function test_query_expansion_matches_keyboard_and_synonym_variants(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.locales', ['fa' => ['موبایل' => ['گوشی']]]);
        $bag = DriverProduct::create(['title' => 'کیف چرمی', 'locale' => 'fa']);
        $phone = DriverProduct::create(['title' => 'گوشی سامسونگ', 'locale' => 'fa']);
        PersianSearch::index($bag);
        PersianSearch::index($phone);

        $keyboard = DriverProduct::persianSearch(';dt')->locale('en')->results()->items()[0];
        $this->assertTrue($bag->is($keyboard->model));
        $this->assertSame('keyboard', $keyboard->candidateSource);

        $synonym = DriverProduct::persianSearch('موبایل')->locale('fa')->results()->items()[0];
        $this->assertTrue($phone->is($synonym->model));
        $this->assertSame('synonym', $synonym->candidateSource);
        $this->assertSame('fa', $synonym->matchedLocale);
        $this->assertSame($synonym->matchedVariant->query, $synonym->matchedQuery);
    }

    public function test_source_type_and_partition_reject_empty_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PersianSearch::search('test')->type(' ');
    }

    public function test_matched_variant_provenance_survives_virtual_results(): void
    {
        PersianSearch::indexDocument($this->virtualDocument());
        $result = PersianSearch::query('درباره')->locale('fa')->partition('public')->results()->items()[0];

        $this->assertSame('original', $result->candidateSource);
        $this->assertSame('درباره', $result->matchedQuery);
        $this->assertSame('fa', $result->matchedLocale);
        $this->assertSame($result->matchedVariant->toArray(), $result->toArray()['matched_variant']);
    }

    public function test_same_document_matching_original_and_synonym_is_returned_once_with_original_provenance(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.locales', ['fa' => ['کالا' => ['محصول']]]);
        PersianSearch::indexDocument(new SearchDocument(
            partition: 'default',
            sourceKey: 'page:original-and-synonym',
            sourceType: 'page',
            sourceId: null,
            locale: 'fa',
            title: 'کالا محصول',
            excerpt: null,
            normalizedTitle: 'کالا محصول',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'کالا محصول',
        ));

        $results = PersianSearch::query('کالا')->locale('fa')->type('page')->results();

        $this->assertCount(1, $results->items());
        $this->assertSame('original', $results->items()[0]->candidateSource);
        $this->assertSame('original', $results->items()[0]->matchedVariant->source->value);
    }

    public function test_keyboard_synonym_result_retains_both_provenance_operations(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.locales', ['fa' => ['پرتقال' => ['نارنج']]]);
        PersianSearch::indexDocument(new SearchDocument(
            partition: 'default',
            sourceKey: 'page:keyboard-synonym',
            sourceType: 'page',
            sourceId: null,
            locale: 'fa',
            title: 'نارنج',
            excerpt: null,
            normalizedTitle: 'نارنج',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'نارنج',
        ));

        $result = PersianSearch::query('\\vjrhg')->locale('en')->type('page')->results()->items()[0];

        $this->assertSame('keyboard_synonym', $result->candidateSource);
        $this->assertSame('fa', $result->matchedLocale);
        $this->assertNotNull($result->matchedVariant->keyboardCorrection);
        $this->assertCount(1, $result->matchedVariant->appliedSynonyms);
    }

    public function test_duplicate_synonyms_do_not_execute_identical_query_locale_variants(): void
    {
        config()->set('persian-search.variants.maximum_variants', 5);
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.locales', ['en' => [
            'a' => ['x'],
            'a b' => ['x b'],
            'a b c' => ['x b c'],
            'b' => ['y'],
        ]]);
        PersianSearch::indexDocument(new SearchDocument(
            partition: 'default',
            sourceKey: 'page:semantic-deduplication',
            sourceType: 'page',
            sourceId: null,
            locale: 'en',
            title: 'x b c',
            excerpt: null,
            normalizedTitle: 'x b c',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'a y c',
        ));
        $builder = PersianSearch::query('a b c')->locale('en')->type('page');
        $variantCount = $builder->variants()->count();
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $results = $builder->results();

        $this->assertSame(4, $variantCount);
        $this->assertSame($variantCount, $queries);
        $this->assertCount(1, $results->items());
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
