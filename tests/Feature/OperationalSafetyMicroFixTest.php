<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Zarbinco\PersianSearch\Contracts\AuthoritativeSearchSourceEnumerator;
use Zarbinco\PersianSearch\Contracts\SearchDependencyResolver;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyContext;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyPolicy;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyResolverRegistry;
use Zarbinco\PersianSearch\Exceptions\SearchOperationSourceLimitExceededException;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocatorFactory;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Operations\SearchDoctorCheckResult;
use Zarbinco\PersianSearch\Operations\SearchDoctorCheckStatus;
use Zarbinco\PersianSearch\Operations\SearchDoctorService;
use Zarbinco\PersianSearch\Operations\SearchMaintenanceLockInspector;
use Zarbinco\PersianSearch\Operations\SearchMaintenanceLockManager;
use Zarbinco\PersianSearch\Operations\SearchMaintenanceLockStatus;
use Zarbinco\PersianSearch\Operations\SearchOperationFailureFormatter;
use Zarbinco\PersianSearch\Operations\SearchOperationOutput;
use Zarbinco\PersianSearch\Operations\SearchOperationsPolicy;
use Zarbinco\PersianSearch\Operations\SearchPruneOperation;
use Zarbinco\PersianSearch\Operations\SearchPruneRequest;
use Zarbinco\PersianSearch\Operations\SearchReindexOperation;
use Zarbinco\PersianSearch\Operations\SearchReindexRequest;
use Zarbinco\PersianSearch\Operations\SearchSourceCollection;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumerationContext;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumeratorRegistry;
use Zarbinco\PersianSearch\Operations\SearchSourceOwnershipCollection;
use Zarbinco\PersianSearch\Operations\SearchStatusService;
use Zarbinco\PersianSearch\Tests\TestCase;

final class OperationalSafetyMicroFixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.operations.enumerators', [OperationsProductEnumerator::class]);
        (require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php')->up();
        Schema::create('operations_products', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function test_prune_limit_fails_closed_without_a_partial_report(): void
    {
        OperationsProduct::create(['title' => 'One']);
        OperationsProduct::create(['title' => 'Two']);

        $this->expectException(SearchOperationSourceLimitExceededException::class);
        app(SearchPruneOperation::class)->run(new SearchPruneRequest(limit: 1));
    }

    public function test_partition_ownership_factory_preserves_an_explicit_partition(): void
    {
        $product = OperationsProduct::create(['title' => 'One']);
        $locator = app(SearchSourceLocatorFactory::class)->forModel($product, 'eloquent', 'archive');

        $this->assertSame('archive', $locator->partition);
    }

    public function test_partition_ownership_prune_keeps_current_partition_and_deletes_only_orphan(): void
    {
        $product = OperationsProduct::create(['title' => 'One']);
        app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'sync'));
        SearchDocumentRecord::query()->update(['partition' => 'archive']);
        $this->usePartitionEnumerator(['archive']);

        $current = app(SearchPruneOperation::class)->run(new SearchPruneRequest(execute: true));
        $this->assertSame(0, $current->deletedDocuments);
        $this->assertSame(1, SearchDocumentRecord::query()->count());

        PartitionOperationsProductEnumerator::$partitions = ['public'];
        $orphan = app(SearchPruneOperation::class)->run(new SearchPruneRequest(execute: true));
        $this->assertSame(1, $orphan->deletedDocuments);
        $this->assertDatabaseMissing('persian_search_documents', ['source_id' => (string) $product->getKey()]);
    }

    public function test_prune_limit_counts_unique_partition_ownership_without_silent_truncation(): void
    {
        $first = OperationsProduct::create(['title' => 'One']);
        $second = OperationsProduct::create(['title' => 'Two']);
        $factory = app(SearchSourceLocatorFactory::class);
        $one = $factory->forModel($first, 'eloquent', 'public');
        $two = $factory->forModel($second, 'eloquent', 'public');
        $ownership = new SearchSourceOwnershipCollection(1);
        $ownership->add($one, 'eloquent');
        $ownership->add($one, 'eloquent');
        $this->assertCount(1, $ownership->all());

        $this->expectException(SearchOperationSourceLimitExceededException::class);
        $ownership->add($two, 'eloquent');
    }

    public function test_partition_ownership_remains_separate_from_reindex_routing_identity(): void
    {
        $product = OperationsProduct::create(['title' => 'One']);
        $factory = app(SearchSourceLocatorFactory::class);
        $public = $factory->forModel($product, 'eloquent', 'public');
        $archive = $factory->forModel($product, 'eloquent', 'archive');
        $routing = new SearchSourceCollection(2);
        $routing->add($public, 'eloquent');
        $routing->add($archive, 'eloquent');
        $ownership = new SearchSourceOwnershipCollection(2);
        $ownership->add($public, 'eloquent');
        $ownership->add($archive, 'eloquent');

        $this->assertSame(1, $routing->count());
        $this->assertCount(2, $ownership->all());
    }

    public function test_maintenance_lock_status_has_a_truthful_unknown_state(): void
    {
        $inspector = new SearchMaintenanceLockInspector;

        $this->assertSame(SearchMaintenanceLockStatus::Unknown, $inspector->inspect(new \stdClass));
        $this->assertSame(SearchMaintenanceLockStatus::Held, $inspector->inspect(new class
        {
            public function isLocked(): bool
            {
                return true;
            }
        }));
        $this->assertSame(SearchMaintenanceLockStatus::Available, $inspector->inspect(new class
        {
            public function isLocked(): bool
            {
                return false;
            }
        }));
    }

    public function test_json_failure_is_resilient_to_invalid_utf8(): void
    {
        $output = SearchOperationOutput::json(SearchOperationOutput::error("bad\xB1"));

        $this->assertJson($output);
        $this->assertStringNotContainsString("\xB1", $output);
    }

    public function test_doctor_lock_uses_releasable_non_maintenance_probes(): void
    {
        $locks = app(SearchMaintenanceLockManager::class);

        $this->assertTrue($locks->testAtomicLock());
        $this->assertTrue($locks->testAtomicLock());
        $this->assertSame($this->expectedRuntimeLockStatus(), $locks->status());
        $this->assertNotSame(SearchMaintenanceLockStatus::Held, $locks->status());
    }

    public function test_maintenance_lock_release_is_reacquirable_and_never_held(): void
    {
        OperationsProduct::create(['title' => 'One']);
        app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'sync'));
        $locks = app(SearchMaintenanceLockManager::class);
        $reacquired = $locks->acquire();
        $reacquired->release();

        $this->assertSame($this->expectedRuntimeLockStatus(), $locks->status());
        $this->assertNotSame(SearchMaintenanceLockStatus::Held, $locks->status());
    }

    public function test_search_status_status_json_serializes_the_runtime_maintenance_lock_state(): void
    {
        $exitCode = Artisan::call('persian-search:status', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            '"maintenance_lock_status":"'.$this->expectedRuntimeLockStatus()->value.'"',
            $output,
        );
    }

    public function test_search_status_status_human_prints_the_runtime_maintenance_lock_state(): void
    {
        $exitCode = Artisan::call('persian-search:status');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($this->expectedRuntimeLockStatus()->value, $output);
    }

    public function test_queue_readiness_runs_in_sync_mode_without_dispatch(): void
    {
        Queue::fake();

        $result = $this->doctorResult('queue.configuration');

        $this->assertSame(SearchDoctorCheckStatus::Passed, $result->status);
        Queue::assertNothingPushed();
    }

    public function test_disabled_dependencies_are_not_instantiated_by_status_or_doctor(): void
    {
        ThrowingOperationalDependencyResolver::$constructions = 0;
        config()->set('persian-search.dependencies', [
            'enabled' => false,
            'maximum_sources_per_event' => 1000,
            'resolvers' => [ThrowingOperationalDependencyResolver::class],
        ]);
        app()->forgetInstance(SearchDependencyPolicy::class);
        app()->forgetInstance(SearchDependencyResolverRegistry::class);
        app()->forgetInstance(SearchStatusService::class);

        $this->assertSame([], app(SearchStatusService::class)->snapshot()->dependencyResolverKeys);
        $this->assertSame(
            SearchDoctorCheckStatus::Skipped,
            $this->doctorResult('extensions.dependencies')->status,
        );
        $this->assertSame(0, ThrowingOperationalDependencyResolver::$constructions);
    }

    public function test_search_doctor_malformed_dependency_policy_is_confined_to_its_checks(): void
    {
        $this->useMalformedDependencyPolicy();

        $report = app(SearchDoctorService::class)->run();
        $results = [];
        foreach ($report->results as $result) {
            $results[$result->key] = $result->status;
        }

        $expectedKeys = [
            'cache.atomic-lock',
            'configuration.policies',
            'database.connection',
            'database.schema',
            'extensions.dependencies',
            'extensions.enumerators',
            'extensions.providers',
            'operations.readiness',
            'queue.configuration',
            'schema.semantic-sample',
        ];
        $this->assertSame($expectedKeys, array_keys($results));
        $this->assertSame(SearchDoctorCheckStatus::Failed, $results['configuration.policies']);
        $this->assertSame(SearchDoctorCheckStatus::Failed, $results['extensions.dependencies']);
        $this->assertSame(SearchDoctorCheckStatus::Passed, $results['cache.atomic-lock']);
        $this->assertSame(SearchDoctorCheckStatus::Passed, $results['database.connection']);
        $this->assertSame(SearchDoctorCheckStatus::Passed, $results['database.schema']);
        $this->assertSame(SearchDoctorCheckStatus::Passed, $results['queue.configuration']);
    }

    public function test_search_doctor_doctor_check_isolation_returns_a_complete_safe_command_report(): void
    {
        $this->useMalformedDependencyPolicy();

        $exitCode = Artisan::call('persian-search:doctor', ['--json' => true]);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', $decoded['status']);
        $this->assertCount(10, $decoded['checks']);
        $this->assertStringNotContainsString('malformed-secret-value', $output);
        $this->assertStringNotContainsString('"status":"infrastructure_failure"', $output);
    }

    public function test_safe_diagnostic_redacts_arbitrary_exception_messages(): void
    {
        $message = (new SearchOperationFailureFormatter)->format(
            new RuntimeException("password=secret\nsource:key\xB1"),
            'prune',
        );

        $this->assertSame('Search prune execution failed safely.', $message);
        $this->assertStringNotContainsString('secret', SearchOperationOutput::json(
            SearchOperationOutput::error($message),
        ));
    }

    private function doctorResult(string $key): SearchDoctorCheckResult
    {
        foreach (app(SearchDoctorService::class)->run()->results as $result) {
            if ($result->key === $key) {
                return $result;
            }
        }

        throw new RuntimeException('Expected doctor result was not produced.');
    }

    private function useMalformedDependencyPolicy(): void
    {
        config()->set('persian-search.dependencies', 'malformed-secret-value');
        foreach ([
            SearchDependencyPolicy::class,
            SearchDependencyResolverRegistry::class,
            SearchDoctorService::class,
        ] as $class) {
            app()->forgetInstance($class);
        }
    }

    private function expectedRuntimeLockStatus(): SearchMaintenanceLockStatus
    {
        $policy = app(SearchOperationsPolicy::class);
        $repository = app(CacheManager::class)->store($policy->lockStore);
        $this->assertInstanceOf(Repository::class, $repository);
        $store = $repository->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $rawLock = $store->lock($policy->lockKey, 1);

        return method_exists($rawLock, 'isLocked')
            ? SearchMaintenanceLockStatus::Available
            : SearchMaintenanceLockStatus::Unknown;
    }

    /** @param list<string> $partitions */
    private function usePartitionEnumerator(array $partitions): void
    {
        PartitionOperationsProductEnumerator::$partitions = $partitions;
        config()->set('persian-search.operations.enumerators', [PartitionOperationsProductEnumerator::class]);
        foreach ([
            SearchOperationsPolicy::class,
            SearchSourceEnumeratorRegistry::class,
            SearchPruneOperation::class,
        ] as $class) {
            app()->forgetInstance($class);
        }
    }
}

final class ThrowingOperationalDependencyResolver implements SearchDependencyResolver
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
        throw new RuntimeException('This dependency resolver must remain lazy.');
    }

    public function key(): string
    {
        return 'throwing-operational';
    }

    public function dependencyModel(): string
    {
        return OperationsProduct::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        return [];
    }
}

final class PartitionOperationsProductEnumerator implements AuthoritativeSearchSourceEnumerator
{
    /** @var list<string> */
    public static array $partitions = ['default'];

    public function __construct(private readonly SearchSourceLocatorFactory $locators) {}

    public function key(): string
    {
        return 'partition-operations-products';
    }

    public function providerKey(): string
    {
        return 'eloquent';
    }

    public function sourceModel(): string
    {
        return OperationsProduct::class;
    }

    public function enumerate(SearchSourceEnumerationContext $context): iterable
    {
        foreach (OperationsProduct::query()->orderBy('id')->lazyById($context->chunkSize) as $product) {
            foreach (self::$partitions as $partition) {
                yield $this->locators->forModel($product, $this->providerKey(), $partition);
            }
        }
    }
}
