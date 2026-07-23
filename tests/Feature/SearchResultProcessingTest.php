<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchPaginationException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchResultConfigurationException;
use Zarbinco\PersianSearch\Exceptions\SearchResultWindowExceededException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Search\SearchFacetField;
use Zarbinco\PersianSearch\Search\SearchPageMetadata;
use Zarbinco\PersianSearch\Search\SearchPaginationRequest;
use Zarbinco\PersianSearch\Search\SearchResultPolicyFactory;
use Zarbinco\PersianSearch\Search\SearchResultTruncationReason;
use Zarbinco\PersianSearch\Search\SearchResultWindow;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchResultProcessingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
    }

    public function test_search_result_window_exposes_exact_and_truncated_truth_immutably(): void
    {
        $exact = new SearchResultWindow([], [], 500);
        $truncated = new SearchResultWindow([], [
            SearchResultTruncationReason::UnexecutedVariants,
            SearchResultTruncationReason::PerVariantLimit,
            SearchResultTruncationReason::PerVariantLimit,
        ], 10);

        $this->assertSame(0, $exact->knownTotal);
        $this->assertTrue($exact->totalIsExact);
        $this->assertFalse($exact->isTruncated);
        $this->assertSame([
            SearchResultTruncationReason::PerVariantLimit,
            SearchResultTruncationReason::UnexecutedVariants,
        ], $truncated->truncationReasons);
        $this->assertFalse($truncated->totalIsExact);
        $this->assertTrue($truncated->isTruncated);
    }

    public function test_search_result_policy_uses_defaults_and_rejects_invalid_relationships(): void
    {
        $policy = app(SearchResultPolicyFactory::class)->make();

        $this->assertSame([
            'default_per_page' => 15,
            'maximum_per_page' => 100,
            'default_preview_limit' => 8,
            'maximum_preview_limit' => 50,
            'default_preview_per_type' => 2,
            'maximum_preview_per_type' => 10,
            'maximum_groups' => 50,
        ], $policy->toArray());

        config()->set('persian-search.results.default_per_page', 101);
        config()->set('persian-search.results.maximum_per_page', 100);

        $this->expectException(InvalidSearchResultConfigurationException::class);
        app(SearchResultPolicyFactory::class)->make();
    }

    public function test_search_result_policy_rejects_non_integer_and_unreasonable_values(): void
    {
        config()->set('persian-search.results.maximum_groups', '50');

        $this->expectException(InvalidSearchResultConfigurationException::class);
        app(SearchResultPolicyFactory::class)->make();
    }

    public function test_limit_and_offset_slice_only_after_global_ranking(): void
    {
        $this->index('lowest', 'product', 10);
        $this->index('highest', 'page', 30);
        $this->index('middle', 'article', 20);

        $all = PersianSearch::query('orange')->locale('en')->withoutExpansion()->results();
        $slice = PersianSearch::query('orange')->locale('en')->withoutExpansion()->limit(1)->offset(1)->results();

        $this->assertSame(3, $all->knownTotal);
        $this->assertSame(1, $slice->returned);
        $this->assertSame(1, $slice->offset);
        $this->assertSame($all->items[1]->record->source_key, $slice->items[0]->record->source_key);
        $this->assertTrue($slice->totalIsExact);
    }

    public function test_search_page_pagination_metadata_is_exact_and_preserves_rank_order(): void
    {
        foreach ([50, 40, 30, 20, 10] as $index => $priority) {
            $this->index('item-'.$index, 'product', $priority);
        }

        $ranked = PersianSearch::query('orange')->locale('en')->withoutExpansion()->results();
        $page = PersianSearch::query('orange')->locale('en')->withoutExpansion()->paginate(perPage: 2, page: 2);

        $this->assertSame(2, $page->metadata->page);
        $this->assertSame(2, $page->metadata->perPage);
        $this->assertSame(5, $page->metadata->knownTotal);
        $this->assertSame(3, $page->metadata->lastPage);
        $this->assertTrue($page->metadata->hasPreviousPage);
        $this->assertTrue($page->metadata->hasNextPage);
        $this->assertSame(3, $page->metadata->from);
        $this->assertSame(4, $page->metadata->to);
        $this->assertSame(
            array_map(static fn ($result): string => $result->record->source_key, array_slice($ranked->items, 2, 2)),
            array_map(static fn ($result): string => $result->record->source_key, $page->items),
        );
    }

    public function test_pagination_validates_page_bounds_conflicts_and_integer_overflow(): void
    {
        try {
            PersianSearch::query('orange')->limit(10)->paginate();
            $this->fail('Expected explicit slicing and pagination to conflict.');
        } catch (InvalidSearchPaginationException) {
            $this->addToAssertionCount(1);
        }

        try {
            new SearchPaginationRequest(0, 10);
            $this->fail('Expected page zero to be rejected.');
        } catch (InvalidSearchPaginationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidSearchPaginationException::class);
        new SearchPaginationRequest(PHP_INT_MAX, 2);
    }

    public function test_search_page_metadata_uses_unknown_last_page_for_truncation(): void
    {
        $metadata = new SearchPageMetadata(
            page: 1,
            perPage: 15,
            returned: 10,
            knownTotal: 10,
            totalIsExact: false,
            isTruncated: true,
            candidateLimit: 10,
            truncationReasons: [SearchResultTruncationReason::GlobalCandidateLimit],
        );

        $this->assertNull($metadata->lastPage);
        $this->assertTrue($metadata->hasNextPage);
        $this->assertSame(1, $metadata->from);
        $this->assertSame(10, $metadata->to);
    }

    public function test_facet_counts_use_the_full_ranked_window_and_fixed_bucket_order(): void
    {
        $this->index('product-a', 'product', 40);
        $this->index('product-b', 'product', 30);
        $this->index('article-a', 'article', 20);
        $this->index('page-a', 'page', 10);

        $results = PersianSearch::query('orange')
            ->locale('en')
            ->withoutExpansion()
            ->facets([
                SearchFacetField::Locale,
                SearchFacetField::SourceType,
                SearchFacetField::SourceType,
                SearchFacetField::Partition,
            ])
            ->limit(1)
            ->results();

        $this->assertSame(1, $results->returned);
        $this->assertCount(3, $results->facets);
        $this->assertSame(['source_type', 'partition', 'locale'], array_column($results->facets->toArray(), 'field'));
        $this->assertSame(['product' => 2, 'article' => 1, 'page' => 1], $results->typeCounts());
        $this->assertTrue($results->facets->get(SearchFacetField::SourceType)?->countsAreExact);
    }

    public function test_facet_builder_rejects_unknown_fields_and_supports_conjunctive_filters(): void
    {
        $this->index('product', 'product', 20, 'public');
        $this->index('page', 'page', 10, 'private');

        $partitioned = PersianSearch::query('orange')
            ->locale('en')
            ->partition('public')
            ->withoutExpansion()
            ->facets(['source_type', 'partition'])
            ->results();

        $this->assertSame(['product' => 1], $partitioned->typeCounts());
        $this->assertSame('public', $partitioned->facets->get(SearchFacetField::Partition)?->buckets[0]->value);

        $this->expectException(InvalidArgumentException::class);
        PersianSearch::query('orange')->facets(['payload.category']);
    }

    public function test_type_count_convenience_is_empty_when_source_type_facet_was_not_requested(): void
    {
        $this->index('product', 'product', 10);

        $results = PersianSearch::query('orange')->locale('en')->withoutExpansion()->results();

        $this->assertSame([], $results->typeCounts());
        $this->assertCount(0, $results->facets);
    }

    public function test_group_by_source_type_counts_full_window_and_preserves_global_order(): void
    {
        $this->index('product-first', 'product', 50);
        $this->index('page-first', 'page', 40);
        $this->index('product-second', 'product', 30);
        $this->index('page-second', 'page', 20);
        $this->index('article-first', 'article', 10);

        $groups = PersianSearch::query('orange')
            ->locale('en')
            ->withoutExpansion()
            ->groupBySourceType(perGroupLimit: 1);

        $this->assertSame(['product', 'page', 'article'], array_column($groups->toArray()['groups'], 'source_type'));
        $this->assertSame([2, 2, 1], array_column($groups->toArray()['groups'], 'known_count'));
        $this->assertSame(['product-first'], array_map(
            static fn ($result): string => $result->record->source_key,
            $groups->groups[0]->items,
        ));
        $this->assertTrue($groups->countsAreExact);
    }

    public function test_preview_uses_diversity_then_fill_and_preserves_relative_rank_order(): void
    {
        $this->index('product-first', 'product', 60);
        $this->index('product-second', 'product', 50);
        $this->index('product-third', 'product', 40);
        $this->index('page-first', 'page', 30);
        $this->index('article-first', 'article', 20);
        $this->index('page-second', 'page', 10);

        $preview = PersianSearch::query('orange')
            ->locale('en')
            ->withoutExpansion()
            ->preview(limit: 5, perType: 1);

        $this->assertSame([
            'product-first',
            'product-second',
            'product-third',
            'page-first',
            'article-first',
        ], array_map(static fn ($result): string => $result->record->source_key, $preview->items));
        $this->assertSame(6, $preview->knownTotal);
        $this->assertTrue($preview->totalIsExact);
    }

    public function test_hydration_occurs_only_for_the_selected_result_slice(): void
    {
        Schema::create('result_products', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $models = [];

        foreach ([30, 20, 10] as $index => $priority) {
            $model = ResultProduct::query()->create(['name' => 'Product '.$index]);
            $models[] = $model;
            $this->index(
                'model-'.$index,
                ResultProduct::class,
                $priority,
                sourceId: (string) $model->getKey(),
            );
        }

        $modelQueries = [];
        $retrievedModels = 0;
        ResultProduct::retrieved(static function () use (&$retrievedModels): void {
            $retrievedModels++;
        });
        DB::listen(static function ($event) use (&$modelQueries): void {
            if (str_contains($event->sql, 'result_products')) {
                $modelQueries[] = $event;
            }
        });

        $results = PersianSearch::query('orange')->locale('en')->withoutExpansion()->limit(1)->results();

        $this->assertCount(1, $results->models());
        $this->assertSame($models[0]->getKey(), $results->models()->first()?->getKey());
        $this->assertCount(1, $modelQueries);
        $this->assertSame(1, $retrievedModels);
    }

    public function test_truncation_metadata_propagates_to_results_facets_and_pagination_guard(): void
    {
        config()->set('persian-search.candidates.per_variant_limit', 2);
        $this->index('first', 'product', 30);
        $this->index('second', 'product', 20);
        $this->index('third', 'page', 10);

        $results = PersianSearch::query('orange')
            ->locale('en')
            ->withoutExpansion()
            ->facets([SearchFacetField::SourceType])
            ->results();

        $this->assertSame(2, $results->knownTotal);
        $this->assertFalse($results->totalIsExact);
        $this->assertTrue($results->isTruncated);
        $this->assertSame([SearchResultTruncationReason::PerVariantLimit], $results->truncationReasons);
        $this->assertFalse($results->facets->get(SearchFacetField::SourceType)?->countsAreExact);

        $this->expectException(SearchResultWindowExceededException::class);
        PersianSearch::query('orange')->locale('en')->withoutExpansion()->paginate(perPage: 2, page: 2);
    }

    private function index(
        string $sourceKey,
        string $sourceType,
        int $priority,
        string $partition = 'default',
        ?string $sourceId = null,
    ): void {
        PersianSearch::indexDocument(new SearchDocument(
            partition: $partition,
            sourceKey: $sourceKey,
            sourceType: $sourceType,
            sourceId: $sourceId,
            locale: 'en',
            title: 'Orange',
            excerpt: null,
            normalizedTitle: 'orange',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'orange',
            priority: $priority,
        ));
    }
}

final class ResultProduct extends Model
{
    public $timestamps = false;

    protected $table = 'result_products';

    protected $guarded = [];
}
