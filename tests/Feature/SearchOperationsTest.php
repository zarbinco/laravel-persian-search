<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use InvalidArgumentException;
use LogicException;
use Zarbinco\PersianSearch\Contracts\AuthoritativeSearchSourceEnumerator;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchSourceEnumeratorException;
use Zarbinco\PersianSearch\Exceptions\SearchMaintenanceLockUnavailableException;
use Zarbinco\PersianSearch\Exceptions\SearchOperationSourceLimitExceededException;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocatorFactory;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Operations\SearchDoctorCheckStatus;
use Zarbinco\PersianSearch\Operations\SearchDoctorService;
use Zarbinco\PersianSearch\Operations\SearchMaintenanceLockManager;
use Zarbinco\PersianSearch\Operations\SearchMaintenanceLockStatus;
use Zarbinco\PersianSearch\Operations\SearchOperationsPolicy;
use Zarbinco\PersianSearch\Operations\SearchPruneOperation;
use Zarbinco\PersianSearch\Operations\SearchPruneRequest;
use Zarbinco\PersianSearch\Operations\SearchReindexOperation;
use Zarbinco\PersianSearch\Operations\SearchReindexRequest;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumerationContext;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumeratorRegistry;
use Zarbinco\PersianSearch\Operations\SearchStatusService;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OperationsProductEnumerator::$duplicates = false;
        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.operations.enumerators', [OperationsProductEnumerator::class]);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
        Schema::create('operations_products', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function test_operations_policy_defaults_are_serializable_and_strict(): void
    {
        $policy = new SearchOperationsPolicy;

        $this->assertSame(500, $policy->chunkSize);
        $this->assertSame('persian-search:maintenance', $policy->lockKey);
        $this->assertSame($policy->toArray(), $policy->jsonSerialize());
        $this->expectException(InvalidArgumentException::class);
        new SearchOperationsPolicy(chunkSize: 0);
    }

    public function test_enumerator_registry_is_deterministic_and_authoritative(): void
    {
        $all = app(SearchSourceEnumeratorRegistry::class)->all();

        $this->assertCount(1, $all);
        $this->assertSame('operations-products', $all[0]->key);
        $this->assertSame('eloquent', $all[0]->providerKey);
        $this->assertSame(OperationsProduct::class, $all[0]->sourceModel);
        $this->assertTrue($all[0]->authoritative);
        $this->assertSame($all, app(SearchSourceEnumeratorRegistry::class)->all());
    }

    public function test_unknown_enumerator_and_provider_filters_fail(): void
    {
        $registry = app(SearchSourceEnumeratorRegistry::class);
        try {
            $registry->selected(['missing'], []);
            $this->fail('Unknown enumerator was accepted.');
        } catch (InvalidSearchSourceEnumeratorException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidSearchSourceEnumeratorException::class);
        $registry->selected([], ['missing']);
    }

    public function test_reindex_dry_run_deduplicates_and_routes_nothing(): void
    {
        OperationsProductEnumerator::$duplicates = true;
        OperationsProduct::create(['title' => 'One']);

        $report = app(SearchReindexOperation::class)->run(new SearchReindexRequest(dryRun: true));

        $this->assertSame(2, $report->enumerated);
        $this->assertSame(1, $report->uniqueSources);
        $this->assertSame(1, $report->duplicates);
        $this->assertSame(0, $report->synchronized);
        $this->assertSame(0, SearchDocumentRecord::query()->count());
    }

    public function test_sync_reindex_loads_current_state_and_persists_provider_ownership(): void
    {
        $product = OperationsProduct::create(['title' => 'One']);
        $report = app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'sync'));

        $this->assertSame(1, $report->synchronized);
        $this->assertDatabaseHas('persian_search_documents', [
            'source_id' => (string) $product->getKey(),
            'provider_key' => 'eloquent',
            'title' => 'One',
        ]);
    }

    public function test_source_maximum_stops_before_routing(): void
    {
        OperationsProduct::create(['title' => 'One']);
        OperationsProduct::create(['title' => 'Two']);
        $this->replacePolicy(new SearchOperationsPolicy(
            enumerators: [OperationsProductEnumerator::class],
            maximumSourcesPerRun: 1,
        ));

        $this->expectException(SearchOperationSourceLimitExceededException::class);
        try {
            app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'sync'));
        } finally {
            $this->assertSame(0, SearchDocumentRecord::query()->count());
        }
    }

    public function test_write_reindex_uses_and_releases_the_maintenance_lock(): void
    {
        OperationsProduct::create(['title' => 'One']);
        $locks = app(SearchMaintenanceLockManager::class);
        $held = $locks->acquire();
        try {
            app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'sync'));
            $this->fail('Concurrent maintenance was accepted.');
        } catch (SearchMaintenanceLockUnavailableException) {
            $this->addToAssertionCount(1);
        } finally {
            $held->release();
        }

        $this->assertSame(1, app(SearchReindexOperation::class)
            ->run(new SearchReindexRequest(executionMode: 'sync'))->synchronized);
        $reacquired = $locks->acquire();
        $reacquired->release();

        $policy = app(SearchOperationsPolicy::class);
        $repository = app(CacheManager::class)->store($policy->lockStore);
        $this->assertInstanceOf(Repository::class, $repository);
        $store = $repository->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $rawLock = $store->lock($policy->lockKey, 1);
        $expected = method_exists($rawLock, 'isLocked')
            ? SearchMaintenanceLockStatus::Available
            : SearchMaintenanceLockStatus::Unknown;

        $this->assertSame($expected, $locks->status());
        $this->assertNotSame(SearchMaintenanceLockStatus::Held, $locks->status());
    }

    public function test_prune_defaults_to_dry_run_and_execute_deletes_all_orphan_locales(): void
    {
        $product = OperationsProduct::create(['title' => 'One']);
        app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'sync'));
        SearchDocumentRecord::query()->firstOrFail()->replicate()->forceFill(['locale' => 'zz'])->save();
        $product->delete();

        $dry = app(SearchPruneOperation::class)->run(new SearchPruneRequest);
        $this->assertFalse($dry->executed);
        $this->assertSame(1, $dry->orphanedSourceReferences);
        $this->assertSame(2, $dry->orphanedDocuments);
        $this->assertSame(2, SearchDocumentRecord::query()->count());

        $executed = app(SearchPruneOperation::class)->run(new SearchPruneRequest(execute: true));
        $this->assertSame(1, $executed->deletedSourceReferences);
        $this->assertSame(2, $executed->deletedDocuments);
        $this->assertSame(0, SearchDocumentRecord::query()->count());
    }

    public function test_status_is_read_only_deterministic_and_reports_extensions(): void
    {
        OperationsProduct::create(['title' => 'One']);
        app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'sync'));

        $snapshot = app(SearchStatusService::class)->snapshot();

        $this->assertTrue($snapshot->tableExists);
        $this->assertSame(1, $snapshot->totalDocuments);
        $this->assertSame(['eloquent' => 1], $snapshot->providerCounts);
        $this->assertSame(['operations-products'], $snapshot->enumeratorKeys);
        $this->assertSame($snapshot->toArray(), $snapshot->jsonSerialize());
    }

    public function test_status_handles_a_missing_table(): void
    {
        Schema::drop('persian_search_documents');
        $snapshot = app(SearchStatusService::class)->snapshot();

        $this->assertFalse($snapshot->tableExists);
        $this->assertSame(0, $snapshot->totalDocuments);
    }

    public function test_doctor_checks_schema_locks_registries_and_bounded_sampling(): void
    {
        OperationsProduct::create(['title' => 'One']);
        app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'sync'));

        $report = app(SearchDoctorService::class)->run(true);

        $this->assertSame(0, $report->failures());
        $this->assertSame(0, $report->warnings());
        $this->assertContains(
            SearchDoctorCheckStatus::Passed,
            array_map(static fn ($result): SearchDoctorCheckStatus => $result->status, $report->results),
        );
    }

    public function test_doctor_continues_safely_when_operations_configuration_is_invalid(): void
    {
        config()->set('persian-search.operations', ['invalid-list-value']);
        app()->forgetInstance(SearchOperationsPolicy::class);
        app()->forgetInstance(SearchSourceEnumeratorRegistry::class);

        $report = app(SearchDoctorService::class)->run();

        $this->assertGreaterThan(0, $report->failures());
        $this->assertGreaterThan(1, count($report->results));
    }

    public function test_doctor_strict_mode_uses_warning_exit_code(): void
    {
        config()->set('persian-search.operations.enumerators', []);
        app()->forgetInstance(SearchOperationsPolicy::class);
        app()->forgetInstance(SearchSourceEnumeratorRegistry::class);

        $this->command('persian-search:doctor', ['--strict' => true, '--json' => true])
            ->expectsOutputToContain('"status":"warning"')
            ->assertExitCode(2);
    }

    public function test_reindex_command_validates_conflicts_limits_and_confirmation(): void
    {
        $this->command('persian-search:reindex', ['--sync' => true, '--queue' => true, '--json' => true])
            ->assertExitCode(1);
        $this->command('persian-search:reindex', ['--limit' => '0', '--json' => true])
            ->assertExitCode(1);
        $this->command('persian-search:reindex', ['--sync' => true, '--json' => true])
            ->expectsConfirmation('Proceed with Persian search reindexing?', 'no')
            ->assertExitCode(5);
    }

    public function test_commands_emit_single_json_documents_and_shared_exit_codes(): void
    {
        OperationsProduct::create(['title' => 'One']);

        $this->command('persian-search:reindex', ['--dry-run' => true, '--json' => true])
            ->expectsOutputToContain('"unique_sources":1')
            ->assertExitCode(0);
        $this->command('persian-search:prune', ['--json' => true])
            ->expectsOutputToContain('"executed":false')
            ->assertExitCode(0);
        $this->command('persian-search:status', ['--json' => true])
            ->expectsOutputToContain('"table_exists":true')
            ->assertExitCode(0);
        $this->command('persian-search:doctor', ['--deep' => true, '--json' => true])
            ->expectsOutputToContain('"status":"passed"')
            ->assertExitCode(0);
    }

    private function replacePolicy(SearchOperationsPolicy $policy): void
    {
        app()->instance(SearchOperationsPolicy::class, $policy);
        app()->forgetInstance(SearchSourceEnumeratorRegistry::class);
        app()->forgetInstance(SearchReindexOperation::class);
        app()->forgetInstance(SearchMaintenanceLockManager::class);
    }

    /** @param array<string, mixed> $parameters */
    private function command(string $name, array $parameters): PendingCommand
    {
        $command = $this->artisan($name, $parameters);
        if (! $command instanceof PendingCommand) {
            throw new LogicException('The operational command did not initialize.');
        }

        return $command;
    }
}

final class OperationsProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'operations_products';

    protected $guarded = [];

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title'];
    }
}

final class OperationsProductEnumerator implements AuthoritativeSearchSourceEnumerator
{
    public static bool $duplicates = false;

    public function __construct(private readonly SearchSourceLocatorFactory $locators) {}

    public function key(): string
    {
        return 'operations-products';
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
            yield $this->locators->forModel($product, $this->providerKey());
            if (self::$duplicates) {
                yield $this->locators->forModel($product, $this->providerKey());
            }
        }
    }
}
