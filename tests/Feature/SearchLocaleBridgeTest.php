<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchLocaleBridgeConfigurationException;
use Zarbinco\PersianSearch\Exceptions\SearchLocaleBridgeIdentityConflictException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\SearchFacetField;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgePolicyFactory;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgeStatus;
use Zarbinco\PersianSearch\Search\SearchQueryBuilder;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchLocaleBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
    }

    public function test_counterpart_bridge_preserves_matched_locale_and_uses_presented_locale(): void
    {
        PersianSearch::indexDocument($this->document('fa', 'پرتقال', 'پرتقال'));
        PersianSearch::indexDocument($this->document('en', 'Orange display', 'orange display'));

        $results = $this->keyboardSearch()->facets(['locale', 'source_type', 'partition'])->results();
        $result = $results->items()[0];

        $this->assertSame('Orange display', $result->document->title);
        $this->assertSame('en', $result->document->locale);
        $this->assertSame('fa', $result->matchedLocale);
        $this->assertSame(SearchLocaleBridgeStatus::Bridged, $result->bridge->status);
        $this->assertTrue($result->bridge->wasBridged);
        $this->assertSame('keyboard', $result->candidateSource);
        $this->assertSame('پرتقال', $result->matchedQuery);
        $localeFacet = $results->facets->get(SearchFacetField::Locale);
        $partitionFacet = $results->facets->get(SearchFacetField::Partition);
        $this->assertNotNull($localeFacet);
        $this->assertNotNull($partitionFacet);
        $this->assertSame('en', $localeFacet->buckets[0]->value);
        $this->assertSame(1, $localeFacet->buckets[0]->count);
        $this->assertSame(['page' => 1], $results->facets->sourceTypeCounts());
        $this->assertSame('public', $partitionFacet->buckets[0]->value);
    }

    public function test_missing_counterpart_falls_back_to_the_matched_document(): void
    {
        PersianSearch::indexDocument($this->document('fa', 'پرتقال', 'پرتقال'));

        $result = $this->keyboardSearch()->results()->items()[0];

        $this->assertSame('fa', $result->document->locale);
        $this->assertSame(SearchLocaleBridgeStatus::CounterpartMissing, $result->bridge->status);
        $this->assertFalse($result->bridge->wasBridged);
    }

    public function test_inactive_counterpart_is_missing_and_different_partition_is_not_selected(): void
    {
        PersianSearch::indexDocument($this->document('fa', 'پرتقال', 'پرتقال'));
        PersianSearch::indexDocument($this->document('en', 'Inactive', 'inactive', active: false));
        PersianSearch::indexDocument($this->document('en', 'Other partition', 'other', partition: 'admin'));

        $result = $this->keyboardSearch()->results()->items()[0];

        $this->assertSame('fa', $result->document->locale);
        $this->assertSame(SearchLocaleBridgeStatus::CounterpartMissing, $result->bridge->status);
    }

    public function test_bridge_disabled_uses_matched_document_and_executes_no_counterpart_query(): void
    {
        config()->set('persian-search.locale_bridge.enabled', false);
        PersianSearch::indexDocument($this->document('fa', 'پرتقال', 'پرتقال'));
        PersianSearch::indexDocument($this->document('en', 'Orange display', 'orange display'));
        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $result = $this->keyboardSearch()->results()->items()[0];

        $this->assertSame(SearchLocaleBridgeStatus::Disabled, $result->bridge->status);
        $this->assertSame('fa', $result->document->locale);
        $this->assertCount(2, $sql);
    }

    public function test_same_locale_candidate_is_not_required_and_adds_no_bridge_query(): void
    {
        PersianSearch::indexDocument($this->document('en', 'orange', 'orange'));
        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $result = PersianSearch::query('orange')->locale('en')->partition('public')->type('page')->results()->items()[0];

        $this->assertSame(SearchLocaleBridgeStatus::NotRequired, $result->bridge->status);
        $this->assertSame('en', $result->bridge->presentedLocale);
        $this->assertCount(2, $sql);
    }

    public function test_counterpart_source_type_identity_conflict_throws(): void
    {
        $matched = $this->document('fa', 'پرتقال', 'پرتقال');
        PersianSearch::indexDocument($matched);
        $conflict = $this->document('en', 'Orange', 'orange', sourceType: 'article');
        SearchDocumentRecord::query()->create(SearchDocumentRecord::forDocument($conflict));

        $this->expectException(SearchLocaleBridgeIdentityConflictException::class);
        $this->keyboardSearch()->results();
    }

    public function test_null_source_id_virtual_counterparts_bridge_normally(): void
    {
        PersianSearch::indexDocument($this->document('fa', 'پرتقال', 'پرتقال'));
        PersianSearch::indexDocument($this->document('en', 'Orange', 'orange'));

        $result = $this->keyboardSearch()->results()->items()[0];

        $this->assertNull($result->document->source_id);
        $this->assertSame(SearchLocaleBridgeStatus::Bridged, $result->bridge->status);
    }

    public function test_search_presented_candidate_deduplicates_original_and_bridged_matches(): void
    {
        PersianSearch::indexDocument($this->document('fa', 'پرتقال', 'پرتقال'));
        PersianSearch::indexDocument($this->document('en', '\\vjrhg', '\\vjrhg'));

        $results = $this->keyboardSearch()->results();

        $this->assertSame(1, $results->knownTotal);
        $this->assertCount(1, $results);
        $this->assertSame('original', $results->items()[0]->candidateSource);
        $this->assertSame('en', $results->items()[0]->document->locale);
    }

    public function test_bridge_batch_size_controls_bound_counterpart_queries(): void
    {
        config()->set('persian-search.locale_bridge.batch_size', 1);

        foreach (['one', 'two'] as $key) {
            PersianSearch::indexDocument($this->document('fa', 'پرتقال '.$key, 'پرتقال '.$key, sourceKey: 'page:'.$key));
            PersianSearch::indexDocument($this->document('en', 'Orange '.$key, 'orange '.$key, sourceKey: 'page:'.$key));
        }

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $results = $this->keyboardSearch()->results();
        $bridgeQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['sql'], '"source_key" = ?'),
        ));

        $this->assertCount(2, $results);
        $this->assertCount(2, $bridgeQueries);
        $this->assertContains('en', $bridgeQueries[0]['bindings']);
    }

    public function test_locale_bridge_policy_rejects_invalid_values(): void
    {
        config()->set('persian-search.locale_bridge.batch_size', 0);

        $this->expectException(InvalidSearchLocaleBridgeConfigurationException::class);
        app(SearchLocaleBridgePolicyFactory::class)->make();
    }

    private function keyboardSearch(): SearchQueryBuilder
    {
        return PersianSearch::query('\\vjrhg')->locale('en')->partition('public')->type('page');
    }

    private function document(
        string $locale,
        string $title,
        string $normalizedTitle,
        bool $active = true,
        string $partition = 'public',
        string $sourceType = 'page',
        string $sourceKey = 'page:orange',
    ): SearchDocument {
        return new SearchDocument(
            partition: $partition,
            sourceKey: $sourceKey,
            sourceType: $sourceType,
            sourceId: null,
            locale: $locale,
            title: $title,
            excerpt: null,
            normalizedTitle: $normalizedTitle,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $normalizedTitle,
            isActive: $active,
        );
    }
}
