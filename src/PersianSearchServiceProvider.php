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
use Zarbinco\PersianSearch\Console\DictionaryBuildCommand;
use Zarbinco\PersianSearch\Console\DictionaryStatusCommand;
use Zarbinco\PersianSearch\Console\DoctorCommand;
use Zarbinco\PersianSearch\Console\InstallCommand;
use Zarbinco\PersianSearch\Console\PruneCommand;
use Zarbinco\PersianSearch\Console\ReindexCommand;
use Zarbinco\PersianSearch\Console\StatusCommand;
use Zarbinco\PersianSearch\Contextual\ContextualCorrectionPolicy;
use Zarbinco\PersianSearch\Contextual\ContextualCorrectionPolicyFactory;
use Zarbinco\PersianSearch\Contextual\ContextualNgramBuilder;
use Zarbinco\PersianSearch\Contextual\DatabaseCandidateResultCounter;
use Zarbinco\PersianSearch\Contextual\DatabaseContextualCandidateGenerator;
use Zarbinco\PersianSearch\Contextual\DatabaseCorrectionEvidenceProvider;
use Zarbinco\PersianSearch\Contextual\DefaultContextualCorrectionEvaluator;
use Zarbinco\PersianSearch\Contextual\NeutralQueryClickSignalProvider;
use Zarbinco\PersianSearch\Contextual\NeutralQueryPopularityProvider;
use Zarbinco\PersianSearch\Contracts\AdvancedQueryCorrector;
use Zarbinco\PersianSearch\Contracts\CandidateResultCounter;
use Zarbinco\PersianSearch\Contracts\ContextualCorrectionEvaluator;
use Zarbinco\PersianSearch\Contracts\CorrectionEvidenceProvider;
use Zarbinco\PersianSearch\Contracts\QueryClickSignalProvider;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Contracts\QueryPopularityProvider;
use Zarbinco\PersianSearch\Contracts\QueryVariantResultCounter;
use Zarbinco\PersianSearch\Contracts\SearchCandidateDriver;
use Zarbinco\PersianSearch\Contracts\SearchDependencyPendingState;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Contracts\SearchDriver;
use Zarbinco\PersianSearch\Contracts\SearchLifecycleDispatcher;
use Zarbinco\PersianSearch\Contracts\SearchRanker;
use Zarbinco\PersianSearch\Contracts\SearchTextNormalizer;
use Zarbinco\PersianSearch\Contracts\SearchTextSanitizer;
use Zarbinco\PersianSearch\Contracts\SearchTokenizer;
use Zarbinco\PersianSearch\Contracts\SpellingCorrector;
use Zarbinco\PersianSearch\Contracts\SynonymExpander;
use Zarbinco\PersianSearch\Correction\AdvancedCorrectionPolicy;
use Zarbinco\PersianSearch\Correction\AdvancedCorrectionPolicyFactory;
use Zarbinco\PersianSearch\Correction\DatabaseAdvancedQueryCorrector;
use Zarbinco\PersianSearch\Correction\LanguageCorrectionProfileRegistry;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyDispatcher;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyObserver;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyObserverRegistrar;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyPolicy;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyPolicyFactory;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyResolverRegistry;
use Zarbinco\PersianSearch\Dependencies\SearchDependencySnapshotFactory;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyTargetResolver;
use Zarbinco\PersianSearch\Dependencies\WeakMapSearchDependencyPendingState;
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
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronizationRouter;
use Zarbinco\PersianSearch\Lifecycle\SearchQueuePolicy;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocatorFactory;
use Zarbinco\PersianSearch\Lifecycle\UniqueSearchLifecycleJobDispatcher;
use Zarbinco\PersianSearch\Operations\SearchDoctorService;
use Zarbinco\PersianSearch\Operations\SearchMaintenanceLockManager;
use Zarbinco\PersianSearch\Operations\SearchOperationsPolicy;
use Zarbinco\PersianSearch\Operations\SearchOperationsPolicyFactory;
use Zarbinco\PersianSearch\Operations\SearchPruneOperation;
use Zarbinco\PersianSearch\Operations\SearchReindexOperation;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumeratorRegistry;
use Zarbinco\PersianSearch\Operations\SearchStatusService;
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
use Zarbinco\PersianSearch\Spelling\DatabaseSpellingCorrector;
use Zarbinco\PersianSearch\Spelling\SpellingDictionaryBuilder;
use Zarbinco\PersianSearch\Spelling\SpellingDictionaryStatusService;
use Zarbinco\PersianSearch\Spelling\SpellingPolicy;
use Zarbinco\PersianSearch\Spelling\SpellingPolicyFactory;
use Zarbinco\PersianSearch\Spelling\SymmetricDeleteGenerator;
use Zarbinco\PersianSearch\Spelling\WeightedDamerauLevenshtein;
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

        $this->app->singleton(SpellingPolicyFactory::class, SpellingPolicyFactory::class);
        $this->app->singleton(SpellingPolicy::class, function ($app): SpellingPolicy {
            return $app->make(SpellingPolicyFactory::class)->make();
        });
        $this->app->singleton(SymmetricDeleteGenerator::class, SymmetricDeleteGenerator::class);
        $this->app->singleton(WeightedDamerauLevenshtein::class, WeightedDamerauLevenshtein::class);
        $this->app->singleton(DatabaseSpellingCorrector::class, DatabaseSpellingCorrector::class);
        $this->app->alias(DatabaseSpellingCorrector::class, SpellingCorrector::class);
        $this->app->singleton(SpellingDictionaryBuilder::class, SpellingDictionaryBuilder::class);
        $this->app->singleton(SpellingDictionaryStatusService::class, SpellingDictionaryStatusService::class);
        $this->app->singleton(AdvancedCorrectionPolicyFactory::class, AdvancedCorrectionPolicyFactory::class);
        $this->app->singleton(AdvancedCorrectionPolicy::class, function ($app): AdvancedCorrectionPolicy {
            return $app->make(AdvancedCorrectionPolicyFactory::class)->make();
        });
        $this->app->singleton(LanguageCorrectionProfileRegistry::class, function ($app): LanguageCorrectionProfileRegistry {
            $policy = $app->make(AdvancedCorrectionPolicy::class);
            $profiles = array_map(
                static fn (string $class) => $app->make($class),
                $policy->profileClasses,
            );

            return new LanguageCorrectionProfileRegistry($profiles);
        });
        $this->app->singleton(DatabaseAdvancedQueryCorrector::class, DatabaseAdvancedQueryCorrector::class);
        $this->app->alias(DatabaseAdvancedQueryCorrector::class, AdvancedQueryCorrector::class);
        $this->app->singleton(ContextualCorrectionPolicyFactory::class, ContextualCorrectionPolicyFactory::class);
        $this->app->singleton(ContextualCorrectionPolicy::class, function ($app): ContextualCorrectionPolicy {
            return $app->make(ContextualCorrectionPolicyFactory::class)->make();
        });
        $this->app->singleton(NeutralQueryPopularityProvider::class, NeutralQueryPopularityProvider::class);
        $this->app->alias(NeutralQueryPopularityProvider::class, QueryPopularityProvider::class);
        $this->app->singleton(NeutralQueryClickSignalProvider::class, NeutralQueryClickSignalProvider::class);
        $this->app->alias(NeutralQueryClickSignalProvider::class, QueryClickSignalProvider::class);
        $this->app->singleton(ContextualNgramBuilder::class, ContextualNgramBuilder::class);
        $this->app->singleton(DatabaseContextualCandidateGenerator::class, DatabaseContextualCandidateGenerator::class);
        $this->app->singleton(DatabaseCorrectionEvidenceProvider::class, DatabaseCorrectionEvidenceProvider::class);
        $this->app->alias(DatabaseCorrectionEvidenceProvider::class, CorrectionEvidenceProvider::class);
        $this->app->singleton(DatabaseCandidateResultCounter::class, DatabaseCandidateResultCounter::class);
        $this->app->alias(DatabaseCandidateResultCounter::class, CandidateResultCounter::class);
        $this->app->alias(DatabaseCandidateResultCounter::class, QueryVariantResultCounter::class);
        $this->app->singleton(DefaultContextualCorrectionEvaluator::class, DefaultContextualCorrectionEvaluator::class);
        $this->app->alias(DefaultContextualCorrectionEvaluator::class, ContextualCorrectionEvaluator::class);

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
                $app->make(SpellingCorrector::class),
                $app->make(AdvancedQueryCorrector::class),
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
        $this->app->bind(SearchLifecycleSynchronizationRouter::class, SearchLifecycleSynchronizationRouter::class);
        $this->app->singleton(DefaultSearchLifecycleDispatcher::class, DefaultSearchLifecycleDispatcher::class);
        $this->app->alias(DefaultSearchLifecycleDispatcher::class, SearchLifecycleDispatcher::class);
        $this->app->singleton(SearchSourceLocatorFactory::class, SearchSourceLocatorFactory::class);

        $this->app->singleton(SearchDependencyPolicyFactory::class, SearchDependencyPolicyFactory::class);
        $this->app->singleton(SearchDependencyPolicy::class, function ($app): SearchDependencyPolicy {
            return $app->make(SearchDependencyPolicyFactory::class)->make();
        });
        $this->app->singleton(SearchDependencyResolverRegistry::class, function ($app): SearchDependencyResolverRegistry {
            return new SearchDependencyResolverRegistry(
                $app,
                $app->make(SearchDependencyPolicy::class),
            );
        });
        $this->app->singleton(SearchDependencySnapshotFactory::class, SearchDependencySnapshotFactory::class);
        $this->app->singleton(SearchDependencyTargetResolver::class, SearchDependencyTargetResolver::class);
        $this->app->singleton(SearchDependencyPendingState::class, WeakMapSearchDependencyPendingState::class);
        $this->app->singleton(SearchDependencyDispatcher::class, SearchDependencyDispatcher::class);
        $this->app->singleton(SearchDependencyObserver::class, SearchDependencyObserver::class);
        $this->app->singleton(SearchDependencyObserverRegistrar::class, SearchDependencyObserverRegistrar::class);

        $this->app->singleton(SearchOperationsPolicyFactory::class, SearchOperationsPolicyFactory::class);
        $this->app->singleton(SearchOperationsPolicy::class, function ($app): SearchOperationsPolicy {
            return $app->make(SearchOperationsPolicyFactory::class)->make();
        });
        $this->app->singleton(SearchSourceEnumeratorRegistry::class, function ($app): SearchSourceEnumeratorRegistry {
            return new SearchSourceEnumeratorRegistry(
                $app,
                $app->make(SearchOperationsPolicy::class),
                $app->make(SearchDocumentProviderRegistry::class),
            );
        });
        $this->app->singleton(SearchMaintenanceLockManager::class, SearchMaintenanceLockManager::class);
        $this->app->singleton(SearchReindexOperation::class, SearchReindexOperation::class);
        $this->app->singleton(SearchPruneOperation::class, SearchPruneOperation::class);
        $this->app->singleton(SearchStatusService::class, SearchStatusService::class);
        $this->app->singleton(SearchDoctorService::class, SearchDoctorService::class);

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
                $app->make(SpellingCorrector::class),
                $app->make(AdvancedQueryCorrector::class),
                $app->make(ContextualCorrectionEvaluator::class),
            );
        });

        $this->app->alias(PersianSearchManager::class, 'persian-search');
    }

    public function boot(): void
    {
        $this->app->make(SearchDependencyObserverRegistrar::class)->register();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/persian-search.php' => config_path('persian-search.php'),
        ], 'persian-search-config');

        $migrations = [
            __DIR__.'/../database/migrations/create_persian_search_documents_table.php' => database_path('migrations/create_persian_search_documents_table.php'),
            __DIR__.'/../database/migrations/create_persian_search_dictionary_tables.php' => database_path('migrations/create_persian_search_dictionary_tables.php'),
            __DIR__.'/../database/migrations/create_persian_search_contextual_ngrams_table.php' => database_path('migrations/create_persian_search_contextual_ngrams_table.php'),
        ];

        $this->publishesMigrations($migrations, 'persian-search-migrations');

        $this->commands([
            InstallCommand::class,
            ReindexCommand::class,
            PruneCommand::class,
            StatusCommand::class,
            DoctorCommand::class,
            DictionaryBuildCommand::class,
            DictionaryStatusCommand::class,
        ]);
    }
}
