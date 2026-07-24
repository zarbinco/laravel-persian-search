<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Zarbinco\PersianSearch\Exceptions\DuplicateSearchLocaleCounterpartException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchLocaleBridgeConfigurationException;
use Zarbinco\PersianSearch\Exceptions\SearchLocaleBridgeIdentityConflictException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgePolicyFactory;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgeStatus;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchLocaleBridgeIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        DB::statement(<<<'SQL'
            CREATE TABLE persian_search_documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                partition VARCHAR(64) COLLATE NOCASE NOT NULL DEFAULT 'default',
                source_key VARCHAR(191) COLLATE NOCASE NOT NULL,
                source_type VARCHAR(255) NOT NULL,
                source_id VARCHAR(255) NULL,
                source_connection VARCHAR(255) NULL,
                provider_key VARCHAR(255) NOT NULL DEFAULT 'eloquent',
                locale VARCHAR(32) COLLATE NOCASE NOT NULL DEFAULT 'und',
                title TEXT NULL,
                excerpt TEXT NULL,
                normalized_title TEXT NULL,
                normalized_excerpt TEXT NULL,
                normalized_keywords TEXT NULL,
                normalized_content TEXT NULL,
                payload TEXT NULL,
                priority INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1,
                document_hash VARCHAR(64) NOT NULL,
                source_updated_at DATETIME NULL,
                indexed_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )
            SQL);
    }

    public function test_exact_locale_collation_false_positive_is_ignored_as_counterpart_missing(): void
    {
        $this->insert($this->document('fa', 'page:orange', 'پرتقال'));
        $this->insert($this->document('EN', 'page:orange', 'False English'));

        $result = PersianSearch::query('\\vjrhg')->locale('en')->type('page')->results()->items()[0];

        $this->assertSame(SearchLocaleBridgeStatus::CounterpartMissing, $result->bridge->status);
        $this->assertSame('fa', $result->document->locale);
    }

    public function test_exact_source_key_collation_false_positive_is_ignored(): void
    {
        $this->insert($this->document('fa', 'Page:Orange', 'پرتقال'));
        $this->insert($this->document('en', 'page:orange', 'False English'));

        $result = PersianSearch::query('\\vjrhg')->locale('en')->type('page')->results()->items()[0];

        $this->assertSame(SearchLocaleBridgeStatus::CounterpartMissing, $result->bridge->status);
        $this->assertSame('Page:Orange', $result->document->source_key);
    }

    public function test_exact_partition_collation_false_positive_is_ignored(): void
    {
        $this->insert($this->document('fa', 'page:orange', 'پرتقال', partition: 'Public'));
        $this->insert($this->document('en', 'page:orange', 'False English', partition: 'public'));

        $result = PersianSearch::query('\\vjrhg')
            ->locale('en')
            ->partition('Public')
            ->type('page')
            ->results()
            ->items()[0];

        $this->assertSame(SearchLocaleBridgeStatus::CounterpartMissing, $result->bridge->status);
        $this->assertSame('Public', $result->document->partition);
    }

    public function test_duplicate_exact_counterpart_records_throw_predictably(): void
    {
        $this->insert($this->document('fa', 'page:orange', 'پرتقال'));
        $this->insert($this->document('en', 'page:orange', 'English one'));
        $this->insert($this->document('en', 'page:orange', 'English two'));

        $this->expectException(DuplicateSearchLocaleCounterpartException::class);
        PersianSearch::query('\\vjrhg')->locale('en')->type('page')->results();
    }

    public function test_scalar_locale_bridge_configuration_section_is_rejected(): void
    {
        config()->set('persian-search.locale_bridge', 'invalid');

        $this->expectException(InvalidSearchLocaleBridgeConfigurationException::class);
        app(SearchLocaleBridgePolicyFactory::class)->make();
    }

    public function test_list_locale_bridge_configuration_section_is_rejected(): void
    {
        config()->set('persian-search.locale_bridge', [true, 200]);

        $this->expectException(InvalidSearchLocaleBridgeConfigurationException::class);
        app(SearchLocaleBridgePolicyFactory::class)->make();
    }

    public function test_exact_identity_source_id_conflict_throws(): void
    {
        $this->insert($this->document('fa', 'page:orange', 'پرتقال', sourceId: '1'));
        $this->insert($this->document('en', 'page:orange', 'English', sourceId: '2'));

        $this->expectException(SearchLocaleBridgeIdentityConflictException::class);
        PersianSearch::query('\\vjrhg')->locale('en')->type('page')->results();
    }

    public function test_exact_identity_null_to_non_null_source_id_conflict_throws(): void
    {
        $this->insert($this->document('fa', 'page:orange', 'پرتقال'));
        $this->insert($this->document('en', 'page:orange', 'English', sourceId: '2'));

        $this->expectException(SearchLocaleBridgeIdentityConflictException::class);
        PersianSearch::query('\\vjrhg')->locale('en')->type('page')->results();
    }

    private function insert(SearchDocument $document): void
    {
        SearchDocumentRecord::query()->create(SearchDocumentRecord::forDocument($document));
    }

    private function document(
        string $locale,
        string $sourceKey,
        string $title,
        string $partition = 'default',
        ?string $sourceId = null,
    ): SearchDocument {
        return new SearchDocument(
            partition: $partition,
            sourceKey: $sourceKey,
            sourceType: 'page',
            sourceId: $sourceId,
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
