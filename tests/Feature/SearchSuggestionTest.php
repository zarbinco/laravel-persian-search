<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianSearch\Exceptions\InvalidSearchSuggestionConfigurationException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Search\SearchQueryBuilder;
use Zarbinco\PersianSearch\Search\SearchSuggestionPolicyFactory;
use Zarbinco\PersianSearch\Search\SearchSuggestionReason;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchSuggestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
    }

    public function test_effective_keyboard_family_suggestion_uses_root_query_and_evidence(): void
    {
        PersianSearch::indexDocument($this->document('orange', 'fa', 'پرتقال'));

        $suggestion = $this->builder()->results()->suggestion;

        $this->assertNotNull($suggestion);
        $this->assertSame('پرتقال', $suggestion->query);
        $this->assertSame('fa', $suggestion->locale);
        $this->assertSame('keyboard', $suggestion->source->value);
        $this->assertSame(SearchSuggestionReason::OriginalHadNoResults, $suggestion->evidence->reason);
        $this->assertSame(0, $suggestion->evidence->originalResultCount);
        $this->assertSame(1, $suggestion->evidence->suggestedResultCount);
        $this->assertSame(1, $suggestion->evidence->resultGain);
        $this->assertTrue($suggestion->evidence->candidateWindowWasExact);
        $this->assertArrayNotHasKey('items', $suggestion->evidence->toArray());
    }

    public function test_lineage_keyboard_family_synonym_contributes_but_suggested_text_remains_root(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.locales', ['fa' => ['پرتقال' => ['نارنج']]]);
        PersianSearch::indexDocument($this->document('orange-synonym', 'fa', 'نارنج'));

        $suggestion = $this->builder()->results()->suggestion;

        $this->assertNotNull($suggestion);
        $this->assertSame('پرتقال', $suggestion->query);
        $this->assertSame(1, $suggestion->evidence->suggestedResultCount);
    }

    public function test_synonym_only_result_does_not_create_a_suggestion(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.locales', ['en' => ['phone' => ['mobile']]]);
        PersianSearch::indexDocument($this->document('mobile', 'en', 'mobile'));

        $suggestion = PersianSearch::query('phone')->locale('en')->type('page')->results()->suggestion;

        $this->assertNull($suggestion);
    }

    public function test_equal_original_and_corrected_family_evidence_is_not_effective(): void
    {
        PersianSearch::indexDocument($this->document('original', 'en', '\\vjrhg'));
        PersianSearch::indexDocument($this->document('corrected', 'fa', 'پرتقال'));

        $this->assertNull($this->builder()->results()->suggestion);
    }

    public function test_effective_better_semantic_tier_produces_suggestion(): void
    {
        PersianSearch::indexDocument($this->document('original-content', 'en', 'other', content: '\\vjrhg'));
        PersianSearch::indexDocument($this->document('corrected-title', 'fa', 'پرتقال'));

        $suggestion = $this->builder()->results()->suggestion;

        $this->assertNotNull($suggestion);
        $this->assertSame(SearchSuggestionReason::BetterSemanticTier, $suggestion->evidence->reason);
    }

    public function test_material_result_gain_uses_integer_basis_points(): void
    {
        config()->set('persian-search.suggestions.minimum_result_gain', 1);
        config()->set('persian-search.suggestions.minimum_ratio_basis_points', 15000);
        PersianSearch::indexDocument($this->document('original', 'en', '\\vjrhg'));
        PersianSearch::indexDocument($this->document('corrected-1', 'fa', 'other', content: 'پرتقال'));
        PersianSearch::indexDocument($this->document('corrected-2', 'fa', 'another', content: 'پرتقال'));

        $suggestion = $this->builder()->results()->suggestion;

        $this->assertNotNull($suggestion);
        $this->assertSame(SearchSuggestionReason::MaterialResultGain, $suggestion->evidence->reason);
        $this->assertSame(20000, $suggestion->evidence->ratioBasisPoints);
    }

    public function test_suggestion_is_shared_across_results_pagination_preview_and_groups(): void
    {
        PersianSearch::indexDocument($this->document('orange', 'fa', 'پرتقال'));

        $resultSuggestion = $this->builder()->results()->suggestion?->toArray();
        $pageSuggestion = $this->builder()->paginate(perPage: 1, page: 1)->suggestion?->toArray();
        $previewSuggestion = $this->builder()->preview(limit: 1, perType: 1)->suggestion?->toArray();
        $groupSuggestion = $this->builder()->groupBySourceType(1)->suggestion?->toArray();

        $this->assertNotNull($resultSuggestion);
        $this->assertSame($resultSuggestion, $pageSuggestion);
        $this->assertSame($resultSuggestion, $previewSuggestion);
        $this->assertSame($resultSuggestion, $groupSuggestion);
    }

    public function test_disabled_and_non_searchable_queries_have_no_suggestion(): void
    {
        config()->set('persian-search.suggestions.enabled', false);
        PersianSearch::indexDocument($this->document('orange', 'fa', 'پرتقال'));

        $this->assertNull($this->builder()->results()->suggestion);
        $this->assertNull(PersianSearch::query(' ')->results()->suggestion);
    }

    public function test_suggestion_policy_rejects_invalid_thresholds(): void
    {
        config()->set('persian-search.suggestions.minimum_ratio_basis_points', 0);

        $this->expectException(InvalidSearchSuggestionConfigurationException::class);
        app(SearchSuggestionPolicyFactory::class)->make();
    }

    private function builder(): SearchQueryBuilder
    {
        return PersianSearch::query('\\vjrhg')->locale('en')->type('page');
    }

    private function document(
        string $key,
        string $locale,
        string $title,
        ?string $content = null,
    ): SearchDocument {
        return new SearchDocument(
            partition: 'default',
            sourceKey: 'page:'.$key,
            sourceType: 'page',
            sourceId: null,
            locale: $locale,
            title: $title,
            excerpt: null,
            normalizedTitle: $title,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $content ?? $title,
        );
    }
}
