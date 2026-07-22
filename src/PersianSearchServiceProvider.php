<?php

namespace Zarbinco\PersianSearch;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Zarbinco\PersianCore\Contracts\PersianSearchNormalizerContract;
use Zarbinco\PersianSearch\Console\FlushCommand;
use Zarbinco\PersianSearch\Console\InstallCommand;
use Zarbinco\PersianSearch\Console\ReindexCommand;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Contracts\SearchTextNormalizer;
use Zarbinco\PersianSearch\Contracts\SearchTextSanitizer;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Drivers\DatabaseSearchDriver;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchQueryConfigurationException;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Query\DefaultQueryExpander;
use Zarbinco\PersianSearch\Query\KeyboardLayoutCorrector;
use Zarbinco\PersianSearch\Query\SynonymExpander;
use Zarbinco\PersianSearch\Ranking\BasicRanker;
use Zarbinco\PersianSearch\Search\SearchQueryPolicy;
use Zarbinco\PersianSearch\Search\SearchQueryProcessor;
use Zarbinco\PersianSearch\Text\DefaultSearchTextSanitizer;
use Zarbinco\PersianSearch\Text\LocaleAwareSearchTextNormalizer;
use Zarbinco\PersianSearch\Text\SearchLocaleResolver;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;
use Zarbinco\PersianSearch\Text\SearchTextValueConverter;
use Zarbinco\PersianSearch\Text\UnicodeSearchTokenizer;

final class PersianSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/persian-search.php', 'persian-search');

        $this->app->singleton(SearchLocaleResolver::class, fn (): SearchLocaleResolver => new SearchLocaleResolver(
            (string) config('persian-search.index.undefined_locale', 'und'),
        ));
        $this->app->singleton(SearchTextValueConverter::class, SearchTextValueConverter::class);
        $this->app->singleton(SearchTextSanitizer::class, DefaultSearchTextSanitizer::class);
        $this->app->singleton(SearchTextNormalizer::class, function ($app): SearchTextNormalizer {
            return new LocaleAwareSearchTextNormalizer(
                $app->make(PersianSearchNormalizerContract::class),
                $app->make(SearchLocaleResolver::class),
            );
        });
        $this->app->singleton(SearchTokenizer::class, UnicodeSearchTokenizer::class);
        $this->app->singleton(SearchTextPipeline::class, function ($app): SearchTextPipeline {
            return new SearchTextPipeline(
                $app->make(SearchTextValueConverter::class),
                $app->make(SearchTextSanitizer::class),
                $app->make(SearchTextNormalizer::class),
                $app->make(SearchTokenizer::class),
                $app->make(SearchLocaleResolver::class),
            );
        });
        $this->app->singleton(SearchQueryPolicy::class, function (): SearchQueryPolicy {
            $query = config('persian-search.query', []);

            if (! is_array($query)) {
                throw InvalidSearchQueryConfigurationException::forValue('configuration', $query, 'must be an array');
            }

            return SearchQueryPolicy::fromArray($query);
        });
        $this->app->singleton(SearchQueryProcessor::class, function ($app): SearchQueryProcessor {
            return new SearchQueryProcessor(
                $app->make(SearchTextPipeline::class),
                $app->make(SearchLocaleResolver::class),
                $app->make(SearchQueryPolicy::class),
            );
        });

        $this->app->singleton(BasicRanker::class, BasicRanker::class);

        $this->app->singleton(KeyboardLayoutCorrector::class, KeyboardLayoutCorrector::class);

        $this->app->singleton(SynonymExpander::class, function ($app): SynonymExpander {
            return new SynonymExpander(
                $app->make(SearchTextPipeline::class),
            );
        });

        $this->app->singleton(DefaultQueryExpander::class, function ($app): DefaultQueryExpander {
            return new DefaultQueryExpander(
                $app->make(SearchTextPipeline::class),
                $app->make(KeyboardLayoutCorrector::class),
                $app->make(SynonymExpander::class),
            );
        });

        $this->app->singleton(QueryExpander::class, DefaultQueryExpander::class);

        $this->app->singleton(DatabaseSearchDriver::class, function ($app): DatabaseSearchDriver {
            return new DatabaseSearchDriver(
                $app->make(BasicRanker::class),
            );
        });

        $this->app->singleton(SearchDriver::class, function ($app): SearchDriver {
            $driver = (string) config('persian-search.driver', 'database');

            return match ($driver) {
                'database' => $app->make(DatabaseSearchDriver::class),
                default => throw new InvalidArgumentException("Unsupported Persian search driver [{$driver}]."),
            };
        });

        $this->app->singleton(SearchDocumentBuilder::class, function ($app): SearchDocumentBuilder {
            return new SearchDocumentBuilder(
                $app->make(SearchTextPipeline::class),
            );
        });

        $this->app->singleton(SearchIndexManager::class, function ($app): SearchIndexManager {
            return new SearchIndexManager(
                $app->make(SearchDocumentBuilder::class),
            );
        });

        $this->app->singleton(PersianSearchManager::class, function ($app): PersianSearchManager {
            return new PersianSearchManager(
                $app->make(SearchTextPipeline::class),
                fn (): SearchQueryProcessor => $app->make(SearchQueryProcessor::class),
                $app->make(SearchDocumentBuilder::class),
                $app->make(SearchIndexManager::class),
                $app->make(SearchDriver::class),
                $app->make(QueryExpander::class),
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
