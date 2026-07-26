<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumeratorRegistry;
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
        $this->assertTrue(Schema::hasTable('persian_search_documents'));
        $this->assertTrue(Schema::hasColumns('persian_search_documents', [
            'provider_key', 'source_connection', 'document_hash',
        ]));
        $this->assertTrue(Schema::hasTable('persian_search_dictionary_terms'));
        $this->assertTrue(Schema::hasTable('persian_search_dictionary_deletes'));

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
}
