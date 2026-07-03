<?php

namespace Zarbinco\PersianSearch;

use Illuminate\Support\ServiceProvider;
use Zarbinco\PersianSearch\Console\FlushCommand;
use Zarbinco\PersianSearch\Console\InstallCommand;
use Zarbinco\PersianSearch\Console\ReindexCommand;
use Zarbinco\PersianSearch\Contracts\SearchNormalizer;
use Zarbinco\PersianSearch\Core\CoreSearchNormalizer;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;

final class PersianSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/persian-search.php', 'persian-search');

        $this->app->singleton(SearchNormalizer::class, CoreSearchNormalizer::class);

        $this->app->singleton(SearchDocumentBuilder::class, function ($app): SearchDocumentBuilder {
            return new SearchDocumentBuilder(
                $app->make(SearchNormalizer::class),
            );
        });

        $this->app->singleton(SearchIndexManager::class, function ($app): SearchIndexManager {
            return new SearchIndexManager(
                $app->make(SearchDocumentBuilder::class),
            );
        });

        $this->app->singleton(PersianSearchManager::class, function ($app): PersianSearchManager {
            return new PersianSearchManager(
                $app->make(SearchNormalizer::class),
                $app->make(SearchDocumentBuilder::class),
                $app->make(SearchIndexManager::class),
            );
        });

        $this->app->alias(PersianSearchManager::class, 'persian-search');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/persian-search.php' => config_path('persian-search.php'),
        ], 'persian-search-config');

        $migrations = [
            __DIR__.'/../database/migrations/create_persian_search_documents_table.php' => database_path('migrations/create_persian_search_documents_table.php'),
        ];

        $this->publishesMigrations($migrations, 'persian-search-migrations');

        $this->commands([
            InstallCommand::class,
            ReindexCommand::class,
            FlushCommand::class,
        ]);
    }
}
