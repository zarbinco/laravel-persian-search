<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumeratorRegistry;
use Zarbinco\PersianSearch\PersianSearchServiceProvider;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;
use Zarbinco\PersianSearch\Tests\TestCase;

final class FreshInstallPackageTest extends TestCase
{
    public function test_fresh_install_loads_defaults_migration_registries_and_console_commands(): void
    {
        $this->assertSame([], config('persian-search.operations.enumerators'));
        $this->assertSame(['eloquent'], app(SearchDocumentProviderRegistry::class)->keys());
        $this->assertSame([], app(SearchSourceEnumeratorRegistry::class)->all());

        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
        $dictionaryMigration = require __DIR__.'/../../database/migrations/create_persian_search_dictionary_tables.php';
        $dictionaryMigration->up();
        $contextualMigration = require __DIR__.'/../../database/migrations/create_persian_search_contextual_ngrams_table.php';
        $contextualMigration->up();
        $this->assertTrue(Schema::hasTable('persian_search_documents'));
        $this->assertTrue(Schema::hasColumns('persian_search_documents', [
            'provider_key', 'source_connection', 'document_hash',
        ]));
        $this->assertTrue(Schema::hasTable('persian_search_dictionary_terms'));
        $this->assertTrue(Schema::hasTable('persian_search_dictionary_deletes'));
        $this->assertTrue(Schema::hasTable('persian_search_dictionary_ngrams'));
        $this->assertTrue(Schema::hasTable('persian_search_dictionary_ngram_staging'));
        $this->assertTrue(Schema::hasTable('persian_search_contextual_builds'));
        $published = ServiceProvider::pathsToPublish(
            PersianSearchServiceProvider::class,
            'persian-search-migrations',
        );
        $this->assertContains(
            realpath(__DIR__.'/../../database/migrations/create_persian_search_contextual_ngrams_table.php'),
            array_map('realpath', array_keys($published)),
        );

        $commands = Artisan::all();
        foreach ([
            'persian-search:reindex',
            'persian-search:prune',
            'persian-search:status',
            'persian-search:doctor',
            'persian-search:dictionary-build',
            'persian-search:dictionary-status',
        ] as $name) {
            $this->assertArrayHasKey($name, $commands);
        }
    }

    public function test_contextual_migration_uses_configured_connection_and_safe_indexes(): void
    {
        config()->set('database.connections.contextual_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('persian-search.contextual.connection', 'contextual_testing');
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_contextual_ngrams_table.php';

        $migration->up();
        $migration->up();
        $schema = Schema::connection('contextual_testing');
        $indexes = array_column($schema->getIndexes('persian_search_dictionary_ngrams'), 'name');

        $this->assertTrue($schema->hasColumns('persian_search_dictionary_ngrams', [
            'locale',
            'gram_size',
            'gram_hash',
            'normalized_gram',
            'first_term',
            'second_term',
            'document_frequency',
            'title_frequency',
            'keyword_frequency',
            'indexed_at',
        ]));
        $this->assertContains('ps_context_ngram_unique', $indexes);
        $this->assertContains('ps_context_ngram_frequency', $indexes);
        $this->assertTrue($schema->hasColumns('persian_search_contextual_builds', [
            'locale',
            'dictionary_generation',
            'ngram_generation',
            'term_count',
            'document_count',
            'ngram_count',
            'dictionary_indexed_at',
            'ngram_indexed_at',
        ]));

        $migration->down();
        $this->assertFalse($schema->hasTable('persian_search_dictionary_ngrams'));
        $this->assertFalse($schema->hasTable('persian_search_dictionary_ngram_staging'));
        $this->assertFalse($schema->hasTable('persian_search_contextual_builds'));
    }
}
