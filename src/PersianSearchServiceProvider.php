<?php

namespace Zarbinco\PersianSearch;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Zarbinco\PersianCore\Contracts\PersianSearchNormalizerContract;
use Zarbinco\PersianSearch\Console\FlushCommand;
use Zarbinco\PersianSearch\Console\InstallCommand;
use Zarbinco\PersianSearch\Console\ReindexCommand;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Contracts\SearchTextNormalizer;
use Zarbinco\PersianSearch\Contracts\SearchTextSanitizer;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Contracts\SynonymExpander;
use Zarbinco\PersianSearch\Drivers\DatabaseSearchDriver;
use Zarbinco\PersianSearch\Exceptions\InvalidQueryVariantConfigurationException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDocumentProviderException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchQueryConfigurationException;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Providers\EloquentSearchDocumentProvider;
use Zarbinco\PersianSearch\Providers\EloquentSearchSourceReferenceFactory;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;
use Zarbinco\PersianSearch\Query\DefaultQueryExpander;
use Zarbinco\PersianSearch\Query\KeyboardCorrectionPolicy;
use Zarbinco\PersianSearch\Query\KeyboardLayoutCorrector;
use Zarbinco\PersianSearch\Query\QueryVariantPolicy;
use Zarbinco\PersianSearch\Query\SynonymDictionary;
use Zarbinco\PersianSearch\Query\SynonymDictionaryFactory;
use Zarbinco\PersianSearch\Query\TokenAwareSynonymExpander;
use Zarbinco\PersianSearch\Query\WindowsPersianKeyboardMap;
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

        $this->app->singleton(QueryVariantPolicy::class, function (): QueryVariantPolicy {
            $variants = config('persian-search.variants', []);

            if (! is_array($variants)) {
                throw InvalidQueryVariantConfigurationException::forValue('variants', $variants, 'must be an array');
            }

            return QueryVariantPolicy::fromArray(
                $variants['maximum_variants'] ?? 20,
                is_array($variants['priorities'] ?? null) ? $variants['priorities'] : [],
            );
        });
        $this->app->singleton(KeyboardCorrectionPolicy::class, function ($app): KeyboardCorrectionPolicy {
            $keyboard = config('persian-search.keyboard', []);

            if (! is_array($keyboard)) {
                throw InvalidQueryVariantConfigurationException::forValue('keyboard', $keyboard, 'must be an array');
            }

            return KeyboardCorrectionPolicy::fromArray($keyboard, $app->make(SearchLocaleResolver::class));
        });
        $this->app->singleton(WindowsPersianKeyboardMap::class, WindowsPersianKeyboardMap::class);
        $this->app->singleton(KeyboardLayoutCorrector::class, function ($app): KeyboardLayoutCorrector {
            return new KeyboardLayoutCorrector(
                $app->make(KeyboardCorrectionPolicy::class),
                $app->make(WindowsPersianKeyboardMap::class),
                $app->make(SearchTextPipeline::class),
                $app->make(SearchLocaleResolver::class),
            );
        });
        $this->app->singleton(SynonymDictionaryFactory::class, SynonymDictionaryFactory::class);
        $this->app->singleton(SynonymDictionary::class, function ($app): SynonymDictionary {
            $synonyms = config('persian-search.synonyms', []);

            if (! is_array($synonyms)) {
                throw InvalidQueryVariantConfigurationException::forValue('synonyms', $synonyms, 'must be an array');
            }

            return $app->make(SynonymDictionaryFactory::class)->make($synonyms);
        });
        $this->app->singleton(SynonymExpander::class, function ($app): SynonymExpander {
            return new TokenAwareSynonymExpander($app->make(SynonymDictionary::class), $app->make(SearchTextPipeline::class));
        });

        $this->app->singleton(DefaultQueryExpander::class, function ($app): DefaultQueryExpander {
            return new DefaultQueryExpander(
                $app->make(QueryVariantPolicy::class),
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
                $app->make(EloquentSearchSourceReferenceFactory::class),
            );
        });

        $this->app->singleton(EloquentSearchSourceReferenceFactory::class, EloquentSearchSourceReferenceFactory::class);
        $this->app->singleton(EloquentSearchDocumentProvider::class, EloquentSearchDocumentProvider::class);
        $this->app->singleton(SearchDocumentProviderRegistry::class, function ($app): SearchDocumentProviderRegistry {
            $configured = config('persian-search.providers', []);

            if (! is_array($configured) || ! array_is_list($configured)) {
                throw InvalidSearchDocumentProviderException::invalidConfiguration($configured);
            }

            foreach ($configured as $class) {
                if (! is_string($class) || trim($class) === '') {
                    throw InvalidSearchDocumentProviderException::invalidConfiguration($class);
                }
            }

            /** @var list<class-string<SearchDocumentProvider>> $configured */
            return new SearchDocumentProviderRegistry(
                $app,
                $configured,
                $app->make(EloquentSearchDocumentProvider::class),
            );
        });

        $this->app->singleton(SearchIndexManager::class, function ($app): SearchIndexManager {
            return new SearchIndexManager(
                $app->make(SearchDocumentProviderRegistry::class),
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
                $app->make(SearchDocumentProviderRegistry::class),
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
