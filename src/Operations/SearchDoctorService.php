<?php

namespace Zarbinco\PersianSearch\Operations;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Zarbinco\PersianSearch\Candidates\SearchCandidatePolicy;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyPolicy;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyResolverRegistry;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Jobs\SynchronizeEloquentSearchSourceJob;
use Zarbinco\PersianSearch\Lifecycle\EloquentSearchSourceLocator;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecyclePolicy;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronization;
use Zarbinco\PersianSearch\Lifecycle\SearchQueuePolicy;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Ranking\SearchRankingPolicy;
use Zarbinco\PersianSearch\Search\SearchLocaleBridgePolicy;
use Zarbinco\PersianSearch\Search\SearchQueryPolicy;
use Zarbinco\PersianSearch\Search\SearchResultPolicy;
use Zarbinco\PersianSearch\Search\SearchSuggestionPolicy;

final readonly class SearchDoctorService
{
    public function __construct(private Container $container) {}

    public function run(bool $deep = false): SearchDoctorReport
    {
        $checks = [
            'cache.atomic-lock' => fn (): SearchDoctorCheckResult => $this->cacheLock(),
            'configuration.policies' => fn (): SearchDoctorCheckResult => $this->policies(),
            'database.connection' => fn (): SearchDoctorCheckResult => $this->databaseConnection(),
            'database.schema' => fn (): SearchDoctorCheckResult => $this->schema(),
            'extensions.dependencies' => fn (): SearchDoctorCheckResult => $this->dependencies(),
            'extensions.enumerators' => fn (): SearchDoctorCheckResult => $this->extension(
                'extensions.enumerators',
                fn () => $this->container->make(SearchSourceEnumeratorRegistry::class)->all(),
            ),
            'extensions.providers' => fn (): SearchDoctorCheckResult => $this->extension(
                'extensions.providers',
                fn () => $this->container->make(SearchDocumentProviderRegistry::class)->keys(),
            ),
            'operations.readiness' => fn (): SearchDoctorCheckResult => $this->readiness(),
            'queue.configuration' => fn (): SearchDoctorCheckResult => $this->queue(),
            'schema.semantic-sample' => fn (): SearchDoctorCheckResult => $deep ? $this->sample() : $this->result('schema.semantic-sample', SearchDoctorCheckStatus::Skipped, 'Deep semantic sampling was not requested.'),
        ];
        ksort($checks, SORT_STRING);
        $results = [];
        foreach ($checks as $key => $check) {
            try {
                $results[] = $check();
            } catch (Throwable) {
                $results[] = $this->result($key, SearchDoctorCheckStatus::Failed, 'The check could not complete safely.');
            }
        }

        return new SearchDoctorReport($results);
    }

    private function policies(): SearchDoctorCheckResult
    {
        foreach ([
            SearchQueryPolicy::class,
            SearchCandidatePolicy::class,
            SearchRankingPolicy::class,
            SearchResultPolicy::class,
            SearchLocaleBridgePolicy::class,
            SearchSuggestionPolicy::class,
            SearchDependencyPolicy::class,
            SearchLifecyclePolicy::class,
            SearchOperationsPolicy::class,
        ] as $class) {
            $this->container->make($class);
        }

        return $this->result('configuration.policies', SearchDoctorCheckStatus::Passed, 'Package policy configuration is valid.');
    }

    private function dependencies(): SearchDoctorCheckResult
    {
        $policy = $this->container->make(SearchDependencyPolicy::class);
        if (! $policy->enabled) {
            return $this->result(
                'extensions.dependencies',
                SearchDoctorCheckStatus::Skipped,
                'The extension registry is disabled by policy.',
            );
        }
        $this->container->make(SearchDependencyResolverRegistry::class)->registrations();

        return $this->result(
            'extensions.dependencies',
            SearchDoctorCheckStatus::Passed,
            'The extension registry initialized deterministically.',
        );
    }

    private function databaseConnection(): SearchDoctorCheckResult
    {
        $driver = (new SearchDocumentRecord)->getConnection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            return $this->result('database.connection', SearchDoctorCheckStatus::Failed, 'The search-index database driver is unsupported.');
        }

        return $this->result('database.connection', SearchDoctorCheckStatus::Passed, 'The search-index database connection and driver are supported.');
    }

    private function schema(): SearchDoctorCheckResult
    {
        $record = new SearchDocumentRecord;
        $schema = Schema::connection($record->getConnectionName());
        $table = $record->getTable();
        if (! $schema->hasTable($table)) {
            return $this->result('database.schema', SearchDoctorCheckStatus::Failed, 'The search-document table does not exist.');
        }
        $required = [
            'partition', 'source_key', 'source_type', 'source_id', 'source_connection',
            'provider_key', 'locale', 'document_hash', 'is_active',
        ];
        foreach ($required as $column) {
            if (! $schema->hasColumn($table, $column)) {
                return $this->result('database.schema', SearchDoctorCheckStatus::Failed, 'The search-document table is missing a required column.');
            }
        }
        $indexes = $schema->getIndexes($table);
        $unique = false;
        $providerIndex = false;
        foreach ($indexes as $index) {
            $columns = $index['columns'];
            $unique = $unique || ($index['unique'] && $columns === ['partition', 'source_key', 'locale']);
            $providerIndex = $providerIndex || $columns === ['provider_key', 'is_active'];
        }
        if (! $unique || ! $providerIndex) {
            return $this->result('database.schema', SearchDoctorCheckStatus::Failed, 'A required search-document constraint or operational index is missing.');
        }

        return $this->result('database.schema', SearchDoctorCheckStatus::Passed, 'The search-document schema is operationally complete.');
    }

    private function cacheLock(): SearchDoctorCheckResult
    {
        if (! $this->container->make(SearchMaintenanceLockManager::class)->testAtomicLock()) {
            return $this->result('cache.atomic-lock', SearchDoctorCheckStatus::Failed, 'The configured cache store could not acquire an atomic test lock.');
        }

        return $this->result('cache.atomic-lock', SearchDoctorCheckStatus::Passed, 'The configured cache store supports atomic locks.');
    }

    private function queue(): SearchDoctorCheckResult
    {
        $queue = $this->container->make(SearchQueuePolicy::class);
        try {
            $this->container->make(QueueManager::class)->connection($queue->connection);
        } catch (Throwable) {
            return $this->result('queue.configuration', SearchDoctorCheckStatus::Failed, 'The configured queue connection could not be resolved safely.');
        }
        $connection = (new SearchDocumentRecord)->getConnection()->getName();
        if (! is_string($connection)) {
            return $this->result('queue.configuration', SearchDoctorCheckStatus::Failed, 'The queue serialization probe could not resolve a safe connection name.');
        }
        $probe = 'doctor-'.bin2hex(random_bytes(16));
        $job = new SynchronizeEloquentSearchSourceJob(
            new SearchLifecycleSynchronization(
                new EloquentSearchSourceLocator(
                    SearchDocumentRecord::class,
                    $connection,
                    'id',
                    $probe,
                ),
                new SearchSourceReference($probe, SearchDocumentRecord::class, $probe),
            ),
            $queue,
        );
        if ($job->afterCommit !== false) {
            return $this->result('queue.configuration', SearchDoctorCheckStatus::Failed, 'The queue job is not configured for pre-commit dispatch safety.');
        }
        try {
            $serialized = serialize($job);
            $restored = unserialize($serialized, ['allowed_classes' => true]);
            if (! $restored instanceof SynchronizeEloquentSearchSourceJob
                || ! hash_equals($job->uniqueId(), $restored->uniqueId())) {
                throw new \RuntimeException;
            }
        } catch (Throwable) {
            return $this->result('queue.configuration', SearchDoctorCheckStatus::Failed, 'The queue job serialization probe failed safely.');
        }

        $unique = new UniqueLock($this->container->make(CacheRepository::class));
        $acquired = false;
        try {
            $acquired = $unique->acquire($job);
            if (! $acquired || $unique->acquire($job)) {
                return $this->result('queue.configuration', SearchDoctorCheckStatus::Failed, 'The queue unique-lock probe failed safely.');
            }
        } catch (Throwable) {
            return $this->result('queue.configuration', SearchDoctorCheckStatus::Failed, 'The queue unique-lock probe failed safely.');
        } finally {
            if ($acquired) {
                try {
                    $unique->release($job);
                } catch (Throwable) {
                    return $this->result('queue.configuration', SearchDoctorCheckStatus::Failed, 'The queue unique-lock probe could not be released safely.');
                }
            }
        }

        return $this->result('queue.configuration', SearchDoctorCheckStatus::Passed, 'Queue connection, serialization, and unique locking are ready without dispatching a job.');
    }

    private function readiness(): SearchDoctorCheckResult
    {
        $registrations = $this->container->make(SearchSourceEnumeratorRegistry::class)->all();
        if ($registrations === []) {
            return $this->result('operations.readiness', SearchDoctorCheckStatus::Warning, 'No source enumerators are configured; full reindex and prune are not ready.');
        }
        if (array_filter($registrations, static fn ($item): bool => $item->authoritative) === []) {
            return $this->result('operations.readiness', SearchDoctorCheckStatus::Warning, 'No authoritative source enumerators are configured; prune is not ready.');
        }

        return $this->result('operations.readiness', SearchDoctorCheckStatus::Passed, 'Operational source enumeration is configured.');
    }

    private function sample(): SearchDoctorCheckResult
    {
        $record = new SearchDocumentRecord;
        $schema = Schema::connection($record->getConnectionName());
        if (! $schema->hasTable($record->getTable())) {
            return $this->result('schema.semantic-sample', SearchDoctorCheckStatus::Skipped, 'The search-document table is unavailable for sampling.');
        }
        $operations = $this->container->make(SearchOperationsPolicy::class);
        $rows = SearchDocumentRecord::query()->orderBy('id')->limit($operations->doctorSampleSize)->get();
        $ownership = [];
        foreach ($rows as $row) {
            $scope = hash('sha256', json_encode([
                $row->provider_key,
                $row->partition,
                $row->source_key,
            ], JSON_THROW_ON_ERROR));
            $identity = hash('sha256', json_encode([
                $row->source_type,
                $row->source_id,
            ], JSON_THROW_ON_ERROR));
            if (isset($ownership[$scope]) && ! hash_equals($ownership[$scope], $identity)) {
                return $this->result('schema.semantic-sample', SearchDoctorCheckStatus::Failed, 'Sampled rows contain conflicting source ownership metadata.');
            }
            $ownership[$scope] = $identity;
            $document = new SearchDocument(
                partition: $row->partition,
                sourceKey: $row->source_key,
                sourceType: $row->source_type,
                sourceId: $row->source_id,
                locale: $row->locale,
                title: $row->title,
                excerpt: $row->excerpt,
                normalizedTitle: $row->normalized_title,
                normalizedExcerpt: $row->normalized_excerpt,
                normalizedKeywords: $row->normalized_keywords,
                normalizedContent: $row->normalized_content,
                payload: is_array($row->payload) ? $row->payload : [],
                priority: $row->priority,
                isActive: $row->is_active,
                sourceUpdatedAt: $row->source_updated_at,
                sourceConnection: $row->source_connection,
                providerKey: $row->provider_key,
            );
            if (! hash_equals($row->document_hash, $document->documentHash)) {
                return $this->result('schema.semantic-sample', SearchDoctorCheckStatus::Failed, 'A sampled document has inconsistent semantic metadata or hashing.');
            }
        }

        return $this->result('schema.semantic-sample', SearchDoctorCheckStatus::Passed, 'Bounded document semantic sampling passed.');
    }

    private function extension(string $key, callable $initialize): SearchDoctorCheckResult
    {
        $initialize();

        return $this->result($key, SearchDoctorCheckStatus::Passed, 'The extension registry initialized deterministically.');
    }

    private function result(string $key, SearchDoctorCheckStatus $status, string $message): SearchDoctorCheckResult
    {
        return new SearchDoctorCheckResult($key, $status, $message);
    }
}
