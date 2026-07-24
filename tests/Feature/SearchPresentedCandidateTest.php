<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgeStatus;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchPresentedCandidateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
    }

    public function test_deduplication_keeps_one_presented_identity_and_the_better_rank(): void
    {
        PersianSearch::indexDocument($this->document('fa', 'پرتقال'));
        PersianSearch::indexDocument($this->document('en', '\\vjrhg'));

        $results = PersianSearch::query('\\vjrhg')->locale('en')->type('page')->results();

        $this->assertSame(1, $results->knownTotal);
        $this->assertCount(1, $results);
        $this->assertSame('original', $results->items()[0]->candidateSource);
        $this->assertSame(SearchLocaleBridgeStatus::NotRequired, $results->items()[0]->bridge->status);
    }

    private function document(string $locale, string $title): SearchDocument
    {
        return new SearchDocument(
            partition: 'default',
            sourceKey: 'page:orange',
            sourceType: 'page',
            sourceId: null,
            locale: $locale,
            title: $title,
            excerpt: null,
            normalizedTitle: $title,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $title,
        );
    }
}
