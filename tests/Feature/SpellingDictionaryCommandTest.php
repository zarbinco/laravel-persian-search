<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SpellingDictionaryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        (require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php')->up();
        (require __DIR__.'/../../database/migrations/create_persian_search_dictionary_tables.php')->up();
    }

    public function test_build_and_status_commands_are_safe_json_capable_and_locale_scoped(): void
    {
        PersianSearch::indexDocument($this->document('fa', 'fa', 'پرتقال'));
        PersianSearch::indexDocument($this->document('en', 'en', 'orange'));

        $this->assertSame(0, Artisan::call('persian-search:dictionary-build', [
            '--locale' => ['fa'],
            '--force' => true,
            '--json' => true,
        ]));

        $this->assertDatabaseHas('persian_search_dictionary_terms', ['locale' => 'fa', 'normalized_term' => 'پرتقال']);
        $this->assertDatabaseMissing('persian_search_dictionary_terms', ['locale' => 'en']);

        $this->assertSame(0, Artisan::call('persian-search:dictionary-status', ['--json' => true]));
    }

    public function test_non_interactive_build_requires_force(): void
    {
        $this->assertSame(5, Artisan::call('persian-search:dictionary-build'));
    }

    private function document(string $key, string $locale, string $title): SearchDocument
    {
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
            normalizedContent: $title,
        );
    }
}
