<?php

namespace Zarbinco\PersianSearch;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Zarbinco\PersianCore\Contracts\PersianSearchNormalizerContract;
use Zarbinco\PersianSearch\Candidates\LiteralLikeCondition;
use Zarbinco\PersianSearch\Candidates\SearchCandidateMatcher;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePlanBuilder;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePolicy;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePolicyFactory;
use Zarbinco\PersianSearch\Console\FlushCommand;
use Zarbinco\PersianSearch\Console\InstallCommand;
use Zarbinco\PersianSearch\Console\ReindexCommand;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\SearchCandidateDriver;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Contracts\SearchLifecycleDispatcher;
use Zarbinco\PersianSearch\Contracts\SearchRanker;
use Zarbinco\PersianSearch\Contracts\SearchTextNormalizer;
use Zarbinco\PersianSearch\Contracts\SearchTextSanitizer;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Contracts\SynonymExpander;
use Zarbinco\PersianSearch\Drivers\DatabaseCandidateDriver;
use Zarbinco\PersianSearch\Drivers\DatabaseSearchDriver;
use Zarbinco\PersianSearch\Exceptions\InvalidQueryVariantConfigurationException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDocumentProviderException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchIndexingConfigurationException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchQueryConfigurationException;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;
use Zarbinco\PersianSearch\Indexing\SearchDocumentPersistenceVerifier;
use Zarbinco\PersianSearch\Indexing\SearchIndexingPolicy;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Lifecycle\DefaultSearchLifecycleDispatcher;
use Zarbinco\PersianSearch\Lifecycle\EloquentSearchSourceSynchronizer;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecyclePolicy;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecyclePolicyFactory;
use Zarbinco\PersianSearch\Lifecycle\SearchQueuePolicy;
use Zarbinco\PersianSearch\Lifecycle\UniqueSearchLifecycleJobDispatcher;
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
use Zarbinco\PersianSearch\Ranking\ProfessionalSearchRanker;
use Zarbinco\PersianSearch\Ranking\SearchRankingPolicy;
use Zarbinco\PersianSearch\Ranking\SearchRankingPolicyFactory;
use Zarbinco\PersianSearch\Ranking\SearchRankMatcher;
use Zarbinco\PersianSearch\Search\EffectiveSearchSuggestionEvaluator;
use Zarbinco\PersianSearch\Search\EmptySearchResultFactory;
use Zarbinco\PersianSearch\Search\SearchExecutionProcessor;
use Zarbinco\PersianSearch\Search\SearchFacetBuilder;
use Zarbinco\PersianSearch\Search\SearchLocaleBridge;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgePolicy;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgePolicyFactory;
use Zarbinco\PersianSearch\Search\SearchLocaleCounterpartLookup;
use Zarbinco\PersianSearch\Search\SearchQueryPolicy;
use Zarbinco\PersianSearch\Search\SearchQueryProcessor;
use Zarbinco\PersianSearch\Search\SearchResultHydrator;
use Zarbinco\PersianSearch\Search\SearchResultPolicy;
use Zarbinco\PersianSearch\Search\SearchResultPolicyFactory;
use Zarbinco\PersianSearch\Search\SearchSuggestionPolicy;
use Zarbinco\PersianSearch\Search\SearchSuggestionPolicyFactory;
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

        $this->app->singleton(SearchRankingPolicyFactory::class, SearchRankingPolicyFactory::class);
        $this->app->singleton(SearchRankingPolicy::class, function ($app): SearchRankingPolicy {
            return $app->make(SearchRankingPolicyFactory::class)->make();
        });
        $this->app->singleton(SearchRankMatcher::class, SearchRankMatcher::class);
        $this->app->singleton(ProfessionalSearchRanker::class, ProfessionalSearchRanker::class);
        $this->app->alias(ProfessionalSearchRanker::class, SearchRanker::class);
        $this->app->singleton(SearchResultPolicyFactory::class, SearchResultPolicyFactory::class);
        $this->app->singleton(SearchResultPolicy::class, function ($app): SearchResultPolicy {
            return $app->make(SearchResultPolicyFactory::class)->make();
        });
        $this->app->singleton(SearchFacetBuilder::class, SearchFacetBuilder::class);
        $this->app->singleton(SearchResultHydrator::class, SearchResultHydrator::class);
        $this->app->singleton(EmptySearchResultFactory::class, EmptySearchResultFactory::class);
        $this->app->singleton(SearchLocaleBridgePolicyFactory::class, SearchLocaleBridgePolicyFactory::class);
        $this->app->singleton(SearchLocaleBridgePolicy::class, function ($app): SearchLocaleBridgePolicy {
            return $app->make(SearchLocaleBridgePolicyFactory::class)->make();
        });
        $this->app->singleton(SearchSuggestionPolicyFactory::class, SearchSuggestionPolicyFactory::class);
        $this->app->singleton(SearchSuggestionPolicy::class, function ($app): SearchSuggestionPolicy {
            return $app->make(SearchSuggestionPolicyFactory::class)->make();
        });
        $this->app->singleton(SearchLocaleBridge::class, SearchLocaleBridge::class);
        $this->app->singleton(SearchLocaleCounterpartLookup::class, SearchLocaleCounterpartLookup::class);
        $this->app->singleton(EffectiveSearchSuggestionEvaluator::class, EffectiveSearchSuggestionEvaluator::class);
        $this->app->singleton(SearchExecutionProcessor::class, SearchExecutionProcessor::class);

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

        $this->app->singleton(SearchCandidatePolicyFactory::class, SearchCandidatePolicyFactory::class);
        $this->app->singleton(SearchCandidatePolicy::class, function ($app): SearchCandidatePolicy {
            return $app->make(SearchCandidatePolicyFactory::class)->make();
        });
        $this->app->singleton(SearchCandidatePlanBuilder::class, SearchCandidatePlanBuilder::class);
        $this->app->singleton(SearchCandidateMatcher::class, SearchCandidateMatcher::class);
        $this->app->singleton(LiteralLikeCondition::class, LiteralLikeCondition::class);
        $this->app->singleton(DatabaseCandidateDriver::class, DatabaseCandidateDriver::class);
        $this->app->alias(DatabaseCandidateDriver::class, SearchCandidateDriver::class);

        $this->app->singleton(DatabaseSearchDriver::class, function ($app): DatabaseSearchDriver {
            return new DatabaseSearchDriver(
                $app->make(SearchExecutionProcessor::class),
                $app->make(SearchResultPolicy::class),
                $app->make(SearchFacetBuilder::class),
                $app->make(SearchResultHydrator::class),
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

        $this->app->singleton(SearchIndexingPolicy::class, function (): SearchIndexingPolicy {
            $attempts = config('persian-search.index.transaction_attempts', 3);

            if (! is_int($attempts)) {
                throw InvalidSearchIndexingConfigurationException::transactionAttempts($attempts);
            }

            return new SearchIndexingPolicy($attempts);
        });

        $this->app->singleton(SearchIndexManager::class, function ($app): SearchIndexManager {
            return new SearchIndexManager(
                $app->make(SearchDocumentProviderRegistry::class),
                $app->make(SearchIndexingPolicy::class),
                $app->make(SearchDocumentPersistenceVerifier::class),
            );
        });

        $this->app->singleton(SearchLifecyclePolicyFactory::class, SearchLifecyclePolicyFactory::class);
        $this->app->singleton(SearchLifecyclePolicy::class, function ($app): SearchLifecyclePolicy {
            return $app->make(SearchLifecyclePolicyFactory::class)->lifecycle();
        });
        $this->app->singleton(SearchQueuePolicy::class, function ($app): SearchQueuePolicy {
            return $app->make(SearchLifecyclePolicyFactory::class)->queue();
        });
        $this->app->singleton(EloquentSearchSourceSynchronizer::class, EloquentSearchSourceSynchronizer::class);
        $this->app->singleton(UniqueLock::class, function ($app): UniqueLock {
            return new UniqueLock($app->make(CacheRepository::class));
        });
        $this->app->singleton(UniqueSearchLifecycleJobDispatcher::class, UniqueSearchLifecycleJobDispatcher::class);
        $this->app->singleton(DefaultSearchLifecycleDispatcher::class, DefaultSearchLifecycleDispatcher::class);
        $this->app->alias(DefaultSearchLifecycleDispatcher::class, SearchLifecycleDispatcher::class);

        $this->app->singleton(PersianSearchManager::class, function ($app): PersianSearchManager {
            return new PersianSearchManager(
                $app->make(SearchTextPipeline::class),
                fn (): SearchQueryProcessor => $app->make(SearchQueryProcessor::class),
                $app->make(SearchDocumentBuilder::class),
                $app->make(SearchIndexManager::class),
                $app->make(SearchDriver::class),
                $app->make(QueryExpander::class),
                $app->make(SearchDocumentProviderRegistry::class),
                $app->make(SearchResultPolicy::class),
                $app->make(EmptySearchResultFactory::class),
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
