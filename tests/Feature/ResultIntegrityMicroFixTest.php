<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Search\SearchFacet;
use Zarbinco\PersianSearch\Search\SearchFacetBucket;
use Zarbinco\PersianSearch\Search\SearchFacetCollection;
use Zarbinco\PersianSearch\Search\SearchFacetField;
use Zarbinco\PersianSearch\Search\SearchPageMetadata;
use Zarbinco\PersianSearch\Search\SearchPreview;
use Zarbinco\PersianSearch\Search\SearchResultGroup;
use Zarbinco\PersianSearch\Search\SearchResultGroupCollection;
use Zarbinco\PersianSearch\Search\SearchResultTruncationReason;
use Zarbinco\PersianSearch\Search\SearchResultWindow;
use Zarbinco\PersianSearch\Tests\TestCase;

final class ResultIntegrityMicroFixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
    }

    public function test_non_searchable_outputs_share_empty_metadata_and_configured_candidate_limit(): void
    {
        config()->set('persian-search.candidates.maximum_candidates', 321);
        $results = PersianSearch::query('!')->facets([SearchFacetField::SourceType])->results();
        $page = PersianSearch::query('!')->facets([SearchFacetField::SourceType])->paginate(10, 1);
        $preview = PersianSearch::query('!')->facets([SearchFacetField::SourceType])->preview(5, 2);
        $groups = PersianSearch::query('!')->facets([SearchFacetField::SourceType])->groupBySourceType(2);

        $this->assertSame(321, $results->toArray()['candidate_limit']);
        $this->assertSame(321, $page->metadata->candidateLimit);
        $this->assertSame(0, $results->knownTotal);
        $this->assertSame(0, $page->metadata->knownTotal);
        $this->assertSame(1, $page->metadata->lastPage);
        $this->assertFalse($page->metadata->hasNextPage);
        $this->assertNull($page->metadata->from);
        $this->assertNull($page->metadata->to);
        $this->assertSame(0, $preview->knownTotal);
        $this->assertTrue($results->totalIsExact);
        $this->assertTrue($page->metadata->totalIsExact);
        $this->assertTrue($preview->totalIsExact);
        $this->assertCount(0, $results->facets);
        $this->assertCount(0, $page->facets);
        $this->assertCount(0, $preview->facets);
        $this->assertSame(0, $groups->knownGroupTotal);
        $this->assertSame(0, $groups->returnedGroups);
        $this->assertTrue($groups->groupsAreComplete);
        $this->assertFalse($groups->isTruncated);
        $this->assertSame('en', PersianSearch::query('!')->locale('en')->paginate()->processedQuery->locale);
        $this->assertSame(
            $results->toArray(),
            PersianSearch::query('!')->facets([SearchFacetField::SourceType])->results()->toArray(),
        );
    }

    public function test_maximum_groups_reports_group_list_completeness_independently(): void
    {
        config()->set('persian-search.results.maximum_groups', 2);

        foreach (['alpha', 'beta', 'gamma'] as $index => $type) {
            PersianSearch::indexDocument($this->document($type, 30 - $index));
        }

        $groups = PersianSearch::query('orange')->locale('en')->withoutExpansion()->groupBySourceType(1);

        $this->assertSame(3, $groups->knownGroupTotal);
        $this->assertSame(2, $groups->returnedGroups);
        $this->assertSame(2, $groups->maximumGroups);
        $this->assertFalse($groups->groupsAreComplete);
        $this->assertTrue($groups->isTruncated);
        $this->assertTrue($groups->countsAreExact);
        $this->assertCount(2, $groups);
        $this->assertSame(['alpha', 'beta'], array_column($groups->toArray()['groups'], 'source_type'));
    }

    public function test_exactly_maximum_groups_is_complete_and_serialization_has_both_dimensions(): void
    {
        config()->set('persian-search.results.maximum_groups', 2);
        PersianSearch::indexDocument($this->document('alpha', 20));
        PersianSearch::indexDocument($this->document('beta', 10));

        $serialized = PersianSearch::query('orange')->locale('en')->withoutExpansion()->groupBySourceType(1)->toArray();

        $this->assertSame(2, $serialized['known_group_total']);
        $this->assertSame(2, $serialized['returned_groups']);
        $this->assertTrue($serialized['groups_are_complete']);
        $this->assertFalse($serialized['is_truncated']);
        $this->assertTrue($serialized['counts_are_exact']);
        $this->assertSame(2, $serialized['maximum_groups']);
    }

    public function test_candidate_truncation_and_group_completeness_are_independent(): void
    {
        config()->set('persian-search.candidates.per_variant_limit', 1);
        PersianSearch::indexDocument($this->document('alpha', 20));
        PersianSearch::indexDocument(new SearchDocument(
            partition: 'default',
            sourceKey: 'group:alpha:second',
            sourceType: 'alpha',
            sourceId: null,
            locale: 'en',
            title: 'Orange',
            excerpt: null,
            normalizedTitle: 'orange',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'orange',
            priority: 10,
        ));

        $groups = PersianSearch::query('orange')->locale('en')->withoutExpansion()->groupBySourceType(1);

        $this->assertFalse($groups->countsAreExact);
        $this->assertTrue($groups->groupsAreComplete);
        $this->assertFalse($groups->isTruncated);
    }

    public function test_omitted_groups_are_not_hydrated(): void
    {
        config()->set('persian-search.results.maximum_groups', 1);
        Schema::create('omitted_group_products', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $model = OmittedGroupProduct::query()->create(['name' => 'Orange']);
        PersianSearch::indexDocument($this->document('included-virtual', 20));
        PersianSearch::indexDocument(new SearchDocument(
            partition: 'default',
            sourceKey: 'group:omitted-model',
            sourceType: OmittedGroupProduct::class,
            sourceId: $model->getKey(),
            locale: 'en',
            title: 'Orange',
            excerpt: null,
            normalizedTitle: 'orange',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'orange',
            priority: 10,
        ));
        $queries = 0;
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'omitted_group_products')) {
                $queries++;
            }
        });

        $groups = PersianSearch::query('orange')->locale('en')->withoutExpansion()->groupBySourceType(1);

        $this->assertSame(2, $groups->knownGroupTotal);
        $this->assertSame(1, $groups->returnedGroups);
        $this->assertSame(0, $queries);
    }

    public function test_public_metadata_invariants_reject_contradictory_states(): void
    {
        $invalid = [
            static fn () => new SearchResultWindow(['not-ranked'], [], 1),
            static fn () => new SearchResultWindow([], ['not-a-reason'], 1),
            static fn () => new SearchPageMetadata(0, 0, 50, 2, true, true, 0),
            static fn () => new SearchPageMetadata(1, 10, 0, 0, true, false, 10, [
                SearchResultTruncationReason::GlobalCandidateLimit,
            ]),
            static fn () => new SearchPreview([], 0, 0, -1, true, true, new SearchFacetCollection),
            static fn () => new SearchResultGroup('', -1, true, []),
            static fn () => new SearchResultGroupCollection([], true, -1, 0, false, false, 0),
        ];

        foreach ($invalid as $construct) {
            try {
                $construct();
                $this->fail('Contradictory public result metadata was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_facet_bucket_values_are_unique(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchFacet(SearchFacetField::SourceType, [
            new SearchFacetBucket('product', 1),
            new SearchFacetBucket('product', 2),
        ], true);
    }

    public function test_document_facet_identifiers_reject_control_and_formatting_characters(): void
    {
        foreach (["type\u{202E}", "part\nition", "en\u{200F}"] as $value) {
            try {
                new SearchDocument(
                    partition: str_starts_with($value, 'part') ? $value : 'default',
                    sourceKey: 'safe-key',
                    sourceType: str_starts_with($value, 'type') ? $value : 'page',
                    sourceId: null,
                    locale: str_starts_with($value, 'en') ? $value : 'en',
                    title: 'Orange',
                    excerpt: null,
                    normalizedTitle: 'orange',
                    normalizedExcerpt: null,
                    normalizedKeywords: null,
                    normalizedContent: 'orange',
                );
                $this->fail('Unsafe facet identifier was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function document(string $type, int $priority): SearchDocument
    {
        return new SearchDocument(
            partition: 'default',
            sourceKey: 'group:'.$type,
            sourceType: $type,
            sourceId: null,
            locale: 'en',
            title: 'Orange',
            excerpt: null,
            normalizedTitle: 'orange',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'orange',
            priority: $priority,
        );
    }
}

final class OmittedGroupProduct extends Model
{
    public $timestamps = false;

    protected $table = 'omitted_group_products';

    protected $guarded = [];
}
