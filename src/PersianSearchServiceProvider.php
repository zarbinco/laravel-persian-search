<?php

namespace Zarbinco\PersianSearch;

use Illuminate\Support\ServiceProvider;
use Zarbinco\PersianSearch\Contracts\SearchNormalizer;
use Zarbinco\PersianSearch\Core\CoreSearchNormalizer;

final class PersianSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/persian-search.php', 'persian-search');

        $this->app->singleton(SearchNormalizer::class, CoreSearchNormalizer::class);

        $this->app->singleton(PersianSearchManager::class, function ($app): PersianSearchManager {
            return new PersianSearchManager(
                $app->make(SearchNormalizer::class),
            );
        });

        $this->app->alias(PersianSearchManager::class, 'persian-search');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/persian-search.php' => config_path('persian-search.php'),
        ], 'persian-search-config');
    }
}
