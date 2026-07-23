<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Contracts\SearchLifecycleDispatcher;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Exceptions\InvalidEloquentSearchSourceLocatorException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchLifecycleConfigurationException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Jobs\SynchronizeEloquentSearchSourceJob;
use Zarbinco\PersianSearch\Lifecycle\DefaultSearchLifecycleDispatcher;
use Zarbinco\PersianSearch\Lifecycle\EloquentSearchSourceLocator;
use Zarbinco\PersianSearch\Lifecycle\EloquentSearchSourceSynchronizer;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleExecutionMode;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecyclePolicy;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecyclePolicyFactory;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronization;
use Zarbinco\PersianSearch\Lifecycle\SearchQueuePolicy;
use Zarbinco\PersianSearch\Lifecycle\UniqueSearchLifecycleJobDispatcher;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.lifecycle_search', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('lifecycle_search');
        config()->set('persian-search.index.connection', 'lifecycle_search');
        config()->set('persian-search.index.sync_on_save', true);
        config()->set('persian-search.index.include_soft_deleted', false);
        config()->set('persian-search.lifecycle.after_commit', true);
        config()->set('persian-search.lifecycle.execution', 'sync');
        config()->set('persian-search.providers', []);

        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
        Schema::create('lifecycle_products', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('string_lifecycle_products', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function test_search_lifecycle_policy_defaults_and_modes_are_typed(): void
    {
        $factory = app(SearchLifecyclePolicyFactory::class);
        $default = $factory->lifecycle();

        $this->assertTrue($default->automaticSync);
        $this->assertTrue($default->afterCommit);
        $this->assertSame(SearchLifecycleExecutionMode::Sync, $default->execution);

        config()->set('persian-search.lifecycle.execution', 'queue');
        $this->assertSame(SearchLifecycleExecutionMode::Queue, $factory->lifecycle()->execution);

        config()->set('persian-search.index.sync_on_save', false);
        $this->assertFalse($factory->lifecycle()->automaticSync);
        $this->assertArrayNotHasKey('enabled', config('persian-search.lifecycle'));
    }

    #[DataProvider('invalidLifecycleConfiguration')]
    public function test_search_lifecycle_policy_rejects_invalid_configuration(string $key, mixed $value): void
    {
        config()->set($key, $value);

        $this->expectException(InvalidSearchLifecycleConfigurationException::class);

        $factory = app(SearchLifecyclePolicyFactory::class);

        if (str_starts_with($key, 'persian-search.queue.')) {
            $factory->queue();
        } else {
            $factory->lifecycle();
        }
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidLifecycleConfiguration(): array
    {
        return [
            'execution' => ['persian-search.lifecycle.execution', 'later'],
            'after commit type' => ['persian-search.lifecycle.after_commit', 1],
            'automatic type' => ['persian-search.index.sync_on_save', 'yes'],
            'tries zero' => ['persian-search.queue.tries', 0],
            'tries type' => ['persian-search.queue.tries', '3'],
            'timeout zero' => ['persian-search.queue.timeout', 0],
            'unique zero' => ['persian-search.queue.unique_for', 0],
            'negative backoff' => ['persian-search.queue.backoff', [10, -1]],
            'backoff type' => ['persian-search.queue.backoff', [10, '30']],
            'empty connection' => ['persian-search.queue.connection', ''],
            'empty queue' => ['persian-search.queue.queue', ' '],
            'leading nbsp connection' => ['persian-search.queue.connection', "\u{00A0}redis"],
            'trailing nbsp connection' => ['persian-search.queue.connection', "redis\u{00A0}"],
            'leading en quad queue' => ['persian-search.queue.queue', "\u{2000}search"],
            'trailing em space queue' => ['persian-search.queue.queue', "search\u{2003}"],
            'trailing narrow nbsp queue' => ['persian-search.queue.queue', "search\u{202F}"],
            'leading ideographic space queue' => ['persian-search.queue.queue', "\u{3000}search"],
            'line separator connection' => ['persian-search.queue.connection', "red\u{2028}is"],
            'paragraph separator queue' => ['persian-search.queue.queue', "search\u{2029}sync"],
            'bidi override connection' => ['persian-search.queue.connection', "red\u{202E}is"],
            'zero width queue' => ['persian-search.queue.queue', "search\u{200B}sync"],
            'word joiner queue' => ['persian-search.queue.queue', "search\u{2060}sync"],
            'bidi isolate queue' => ['persian-search.queue.queue', "search\u{2066}sync"],
            'zwnj queue' => ['persian-search.queue.queue', "search\u{200C}sync"],
            'zwj queue' => ['persian-search.queue.queue', "search\u{200D}sync"],
            'bom connection' => ['persian-search.queue.connection', "\u{FEFF}redis"],
            'unicode whitespace only' => ['persian-search.queue.queue', "\u{00A0}\u{3000}"],
        ];
    }

    public function test_search_queue_policy_accepts_null_routing_and_exact_values(): void
    {
        $factory = app(SearchLifecyclePolicyFactory::class);
        $default = $factory->queue();
        $this->assertNull($default->connection);
        $this->assertNull($default->queue);

        config()->set('persian-search.queue', [
            'connection' => 'redis',
            'queue' => 'search',
            'tries' => 5,
            'backoff' => [1, 2],
            'timeout' => 90,
            'unique_for' => 600,
        ]);
        $policy = $factory->queue();
        $this->assertSame('redis', $policy->connection);
        $this->assertSame('search', $policy->queue);
        $this->assertSame(5, $policy->tries);
        $this->assertSame([1, 2], $policy->backoff);
        $this->assertSame(90, $policy->timeout);
        $this->assertSame(600, $policy->uniqueFor);
    }

    public function test_unicode_queue_configuration_failure_does_not_render_unsafe_value(): void
    {
        config()->set('persian-search.queue.queue', "search\u{202E}\u{200B}");

        try {
            app(SearchLifecyclePolicyFactory::class)->queue();
            $this->fail('Expected unsafe queue route rejection.');
        } catch (InvalidSearchLifecycleConfigurationException $exception) {
            $this->assertStringNotContainsString("\u{202E}", $exception->getMessage());
            $this->assertStringNotContainsString("\u{200B}", $exception->getMessage());
            $this->assertStringContainsString('persian-search.queue.queue', $exception->getMessage());
        }
    }

    public function test_eloquent_search_source_locator_is_canonical_deterministic_and_attribute_free(): void
    {
        config()->set('persian-search.index.sync_on_save', false);
        $product = LifecycleProduct::create(['title' => 'Secret title']);
        $locator = EloquentSearchSourceLocator::fromModel($product);

        $this->assertSame(LifecycleProduct::class, $locator->modelClass);
        $this->assertSame('testing', $locator->connection);
        $this->assertSame('id', $locator->keyName);
        $this->assertSame((string) $product->getKey(), $locator->keyValue);
        $this->assertSame($locator->fingerprint(), EloquentSearchSourceLocator::fromModel($product)->fingerprint());
        $this->assertStringNotContainsString('Secret title', serialize($locator));

        $different = new EloquentSearchSourceLocator(LifecycleProduct::class, 'other', 'id', $locator->keyValue);
        $this->assertNotSame($locator->fingerprint(), $different->fingerprint());
        $this->assertNotSame(
            $locator->fingerprint(),
            (new EloquentSearchSourceLocator(HiddenLifecycleProduct::class, 'testing', 'id', $locator->keyValue))->fingerprint(),
        );

        $unsafe = new EloquentSearchSourceLocator(LifecycleProduct::class, 'test'.PHP_EOL.'ing', 'id', '1');
        $this->assertStringNotContainsString(PHP_EOL, $unsafe->description());
        $this->assertStringContainsString('unsafe-key-sha256:', $unsafe->description());
    }

    #[DataProvider('canonicalStringKeys')]
    public function test_locator_preserves_canonical_string_keys(string $key): void
    {
        config()->set('persian-search.index.sync_on_save', false);
        $product = new StringLifecycleProduct(['title' => 'String key']);
        $product->setAttribute('id', $key);
        $product->save();

        $this->assertSame($key, EloquentSearchSourceLocator::fromModel($product)->keyValue);
    }

    /** @return array<string, array{string}> */
    public static function canonicalStringKeys(): array
    {
        return [
            'padded' => ['00123'],
            'uuid' => ['550e8400-e29b-41d4-a716-446655440000'],
            'ulid' => ['01J9ZXYZABCDEF123456789012'],
        ];
    }

    public function test_locator_rejects_unpersisted_or_null_key_models(): void
    {
        foreach ([new LifecycleProduct, new StringLifecycleProduct(['title' => 'No key'])] as $model) {
            try {
                EloquentSearchSourceLocator::fromModel($model);
                $this->fail('Expected invalid unpersisted locator.');
            } catch (InvalidEloquentSearchSourceLocatorException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_sync_lifecycle_runs_immediately_outside_transactions_and_explicit_api_stays_immediate(): void
    {
        $product = LifecycleProduct::create(['title' => 'Created']);
        $this->assertSame('Created', SearchDocumentRecord::query()->value('title'));

        $product->update(['title' => 'Updated']);
        $this->assertSame('Updated', SearchDocumentRecord::query()->value('title'));

        config()->set('persian-search.index.sync_on_save', false);
        $product->update(['title' => 'Manual']);
        PersianSearch::indexSource($product);
        $this->assertSame('Manual', SearchDocumentRecord::query()->value('title'));
    }

    public function test_after_commit_sync_waits_for_commit_and_rollback_prevents_create_and_update(): void
    {
        DB::beginTransaction();
        $product = LifecycleProduct::create(['title' => 'Pending']);
        $this->assertSame(0, SearchDocumentRecord::count());
        DB::commit();
        $this->assertSame('Pending', SearchDocumentRecord::query()->value('title'));

        DB::beginTransaction();
        $product->update(['title' => 'Rolled back']);
        $this->assertSame('Pending', SearchDocumentRecord::query()->value('title'));
        DB::rollBack();
        $this->assertSame('Pending', SearchDocumentRecord::query()->value('title'));

        DB::beginTransaction();
        LifecycleProduct::create(['title' => 'Never indexed']);
        DB::rollBack();
        $this->assertSame(1, SearchDocumentRecord::count());
    }

    public function test_nested_transaction_sync_runs_only_after_outer_commit_and_not_after_outer_rollback(): void
    {
        DB::beginTransaction();
        DB::beginTransaction();
        LifecycleProduct::create(['title' => 'Nested commit']);
        DB::commit();
        $this->assertSame(0, SearchDocumentRecord::count());
        DB::commit();
        $this->assertSame(1, SearchDocumentRecord::count());

        DB::beginTransaction();
        DB::beginTransaction();
        LifecycleProduct::create(['title' => 'Nested rollback']);
        DB::commit();
        DB::rollBack();
        $this->assertSame(1, SearchDocumentRecord::count());
    }

    public function test_after_commit_false_documents_cross_connection_rollback_risk(): void
    {
        config()->set('persian-search.lifecycle.after_commit', false);
        DB::beginTransaction();
        LifecycleProduct::create(['title' => 'Immediate risk']);
        $this->assertSame(1, SearchDocumentRecord::count());
        DB::rollBack();

        $this->assertSame(0, LifecycleProduct::count());
        $this->assertSame(1, SearchDocumentRecord::count());
    }

    public function test_soft_delete_force_delete_and_restore_converge_after_commit(): void
    {
        $product = LifecycleProduct::create(['title' => 'Soft lifecycle']);

        DB::beginTransaction();
        $product->delete();
        $this->assertSame(1, SearchDocumentRecord::count());
        DB::rollBack();
        $product->refresh();
        $this->assertSame(1, SearchDocumentRecord::count());

        DB::transaction(static fn () => $product->delete());
        $this->assertSame(0, SearchDocumentRecord::count());

        DB::transaction(static fn () => $product->restore());
        $this->assertSame(1, SearchDocumentRecord::count());

        DB::transaction(static fn () => $product->forceDelete());
        $this->assertSame(0, SearchDocumentRecord::count());
    }

    public function test_soft_deleted_included_source_is_reloaded_and_replaced(): void
    {
        config()->set('persian-search.index.include_soft_deleted', true);
        $product = LifecycleProduct::create(['title' => 'Included']);
        DB::transaction(static fn () => $product->delete());

        $this->assertSame(1, SearchDocumentRecord::count());
        $this->assertTrue($product->trashed());
    }

    public function test_source_connection_controls_after_commit_and_unrelated_transaction_does_not_delay(): void
    {
        config()->set('database.connections.lifecycle_source', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('lifecycle_source');
        Schema::connection('lifecycle_source')->create('lifecycle_products', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });

        $source = DB::connection('lifecycle_source');
        $source->beginTransaction();
        $product = new LifecycleProduct(['title' => 'Alternate source']);
        $product->setConnection('lifecycle_source');
        $product->save();
        $this->assertSame(0, SearchDocumentRecord::count());
        $source->commit();
        $this->assertSame(1, SearchDocumentRecord::count());

        DB::beginTransaction();
        $product->update(['title' => 'Not delayed']);
        $this->assertSame('Not delayed', SearchDocumentRecord::query()->value('title'));
        DB::rollBack();
    }

    public function test_queue_before_commit_ignores_unrelated_transaction_with_connection_after_commit_enabled(): void
    {
        $this->configureLifecycleSource();
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.after_commit', true);
        config()->set('persian-search.lifecycle.execution', 'queue');

        DB::beginTransaction();
        $product = new LifecycleProduct(['title' => 'Immediate queue']);
        $product->setConnection('lifecycle_source');
        $product->save();

        $this->assertSame(1, SearchDocumentRecord::query()->count());
        DB::rollBack();
        $this->assertSame(1, SearchDocumentRecord::query()->count());
    }

    public function test_queue_before_commit_waits_only_for_source_commit_and_survives_unrelated_rollback(): void
    {
        $this->configureLifecycleSource();
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.after_commit', true);
        config()->set('persian-search.lifecycle.execution', 'queue');
        $source = DB::connection('lifecycle_source');

        DB::beginTransaction();
        $source->beginTransaction();
        $product = new LifecycleProduct(['title' => 'Source boundary']);
        $product->setConnection('lifecycle_source');
        $product->save();
        $this->assertSame(0, SearchDocumentRecord::query()->count());

        $source->commit();
        $this->assertSame(1, SearchDocumentRecord::query()->count());
        DB::rollBack();
        $this->assertSame(1, SearchDocumentRecord::query()->count());
    }

    public function test_queue_source_rollback_dispatches_nothing_with_unrelated_transaction_open(): void
    {
        $this->configureLifecycleSource();
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.after_commit', true);
        config()->set('persian-search.lifecycle.execution', 'queue');
        $source = DB::connection('lifecycle_source');

        DB::beginTransaction();
        $source->beginTransaction();
        $product = new LifecycleProduct(['title' => 'Rolled back source']);
        $product->setConnection('lifecycle_source');
        $product->save();
        $source->rollBack();
        DB::rollBack();

        $this->assertSame(0, SearchDocumentRecord::query()->count());
    }

    public function test_queue_after_commit_false_dispatches_inside_source_transaction(): void
    {
        $this->configureLifecycleSource();
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.after_commit', true);
        config()->set('persian-search.lifecycle.execution', 'queue');
        config()->set('persian-search.lifecycle.after_commit', false);
        $source = DB::connection('lifecycle_source');

        $source->beginTransaction();
        $product = new LifecycleProduct(['title' => 'Immediate source risk']);
        $product->setConnection('lifecycle_source');
        $product->save();
        $this->assertSame(1, SearchDocumentRecord::query()->count());
        $source->rollBack();

        $this->assertSame(0, LifecycleProduct::on('lifecycle_source')->count());
        $this->assertSame(1, SearchDocumentRecord::query()->count());
    }

    public function test_queue_mode_routes_job_and_waits_for_source_commit(): void
    {
        Queue::fake();
        config()->set('persian-search.lifecycle.execution', 'queue');
        config()->set('persian-search.queue.connection', 'redis');
        config()->set('persian-search.queue.queue', 'search-sync');

        DB::beginTransaction();
        LifecycleProduct::create(['title' => 'Queued']);
        Queue::assertNothingPushed();
        DB::commit();

        Queue::assertPushed(SynchronizeEloquentSearchSourceJob::class, function ($job): bool {
            return $job->connection === 'redis' && $job->queue === 'search-sync' && $job->afterCommit === false;
        });
    }

    public function test_duplicate_queue_lifecycle_events_push_one_unique_job(): void
    {
        $queue = Queue::fake();
        config()->set('persian-search.lifecycle.execution', 'queue');
        $product = LifecycleProduct::create(['title' => 'First']);
        $product->update(['title' => 'Second']);

        $this->assertCount(1, $queue->pushed(SynchronizeEloquentSearchSourceJob::class));
    }

    public function test_unique_lifecycle_dispatcher_returns_false_for_duplicate_and_true_for_distinct_models(): void
    {
        $queue = Queue::fake();
        config()->set('persian-search.index.sync_on_save', false);
        $first = LifecycleProduct::create(['title' => 'First']);
        $second = LifecycleProduct::create(['title' => 'Second']);
        $policy = app(SearchLifecyclePolicyFactory::class)->queue();
        $dispatcher = app(UniqueSearchLifecycleJobDispatcher::class);

        $this->assertTrue($dispatcher->dispatch(new SynchronizeEloquentSearchSourceJob($this->synchronization($first), $policy)));
        $this->assertFalse($dispatcher->dispatch(new SynchronizeEloquentSearchSourceJob($this->synchronization($first), $policy)));
        $this->assertTrue($dispatcher->dispatch(new SynchronizeEloquentSearchSourceJob($this->synchronization($second), $policy)));
        $differentConnection = new SearchLifecycleSynchronization(
            new EloquentSearchSourceLocator(LifecycleProduct::class, 'other', 'id', (string) $first->getKey()),
            $this->synchronization($first)->fallbackReference,
        );
        $differentClass = new SearchLifecycleSynchronization(
            new EloquentSearchSourceLocator(HardLifecycleProduct::class, 'testing', 'id', (string) $first->getKey()),
            $this->synchronization($first)->fallbackReference,
        );
        $this->assertTrue($dispatcher->dispatch(new SynchronizeEloquentSearchSourceJob($differentConnection, $policy)));
        $this->assertTrue($dispatcher->dispatch(new SynchronizeEloquentSearchSourceJob($differentClass, $policy)));
        $this->assertCount(4, $queue->pushed(SynchronizeEloquentSearchSourceJob::class));
    }

    public function test_unique_dispatch_failure_releases_lock_for_a_subsequent_dispatch(): void
    {
        config()->set('persian-search.index.sync_on_save', false);
        $product = LifecycleProduct::create(['title' => 'Retry dispatch']);
        $job = new SynchronizeEloquentSearchSourceJob(
            $this->synchronization($product),
            app(SearchLifecyclePolicyFactory::class)->queue(),
        );
        $lock = new UniqueLock(app(CacheRepository::class));
        $bus = new RecordingBusDispatcher;

        try {
            (new UniqueSearchLifecycleJobDispatcher($lock, $bus))->dispatch($job);
            $this->fail('Expected queue dispatch failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue unavailable.', $exception->getMessage());
        }

        $this->assertTrue((new UniqueSearchLifecycleJobDispatcher($lock, $bus))->dispatch($job));
        $this->assertSame(2, $bus->dispatchCalls);
    }

    public function test_unique_lock_infrastructure_failure_is_surfaced(): void
    {
        config()->set('persian-search.index.sync_on_save', false);
        $product = LifecycleProduct::create(['title' => 'Cache failure']);
        $job = new SynchronizeEloquentSearchSourceJob(
            $this->synchronization($product),
            app(SearchLifecyclePolicyFactory::class)->queue(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unique cache unavailable.');
        (new UniqueSearchLifecycleJobDispatcher(
            new ThrowingUniqueLock(app(CacheRepository::class)),
            new RecordingBusDispatcher,
        ))->dispatch($job);
    }

    public function test_multiple_callbacks_for_one_model_after_commit_push_one_unique_job(): void
    {
        $queue = Queue::fake();
        config()->set('persian-search.lifecycle.execution', 'queue');

        DB::transaction(static function (): void {
            $product = LifecycleProduct::create(['title' => 'First']);
            $product->update(['title' => 'Second']);
            $product->update(['title' => 'Third']);
        });

        $this->assertCount(1, $queue->pushed(SynchronizeEloquentSearchSourceJob::class));
    }

    public function test_unique_lock_duration_allows_follow_up_dispatch_after_expiry(): void
    {
        $queue = Queue::fake();
        config()->set('persian-search.index.sync_on_save', false);
        $product = LifecycleProduct::create(['title' => 'Expiring lock']);
        $policy = new SearchQueuePolicy(null, null, 3, [1], 60, 2);
        $dispatcher = app(UniqueSearchLifecycleJobDispatcher::class);

        $this->assertTrue($dispatcher->dispatch(new SynchronizeEloquentSearchSourceJob($this->synchronization($product), $policy)));
        $this->assertFalse($dispatcher->dispatch(new SynchronizeEloquentSearchSourceJob($this->synchronization($product), $policy)));
        $this->travel(3)->seconds();
        $this->assertTrue($dispatcher->dispatch(new SynchronizeEloquentSearchSourceJob($this->synchronization($product), $policy)));
        $this->assertCount(2, $queue->pushed(SynchronizeEloquentSearchSourceJob::class));
    }

    public function test_queue_mode_dispatches_nothing_after_rollback_or_when_disabled(): void
    {
        Queue::fake();
        config()->set('persian-search.lifecycle.execution', 'queue');
        DB::beginTransaction();
        LifecycleProduct::create(['title' => 'Rollback queue']);
        DB::rollBack();
        Queue::assertNothingPushed();

        config()->set('persian-search.index.sync_on_save', false);
        app()->forgetInstance(SearchLifecycleDispatcher::class);
        app()->forgetInstance(DefaultSearchLifecycleDispatcher::class);
        app()->forgetInstance(SearchLifecyclePolicy::class);
        LifecycleProduct::create(['title' => 'Disabled']);
        Queue::assertNothingPushed();
    }

    public function test_synchronize_eloquent_search_source_job_consumes_policy_uniqueness_and_serializes_no_model_content(): void
    {
        config()->set('persian-search.index.sync_on_save', false);
        $product = LifecycleProduct::create(['title' => 'Never serialize this title']);
        $sync = $this->synchronization($product);
        $policy = new SearchQueuePolicy(null, null, 4, [2, 4], 70, 500);
        $job = new SynchronizeEloquentSearchSourceJob($sync, $policy);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame(4, $job->tries);
        $this->assertSame([2, 4], $job->backoff());
        $this->assertSame(70, $job->timeout);
        $this->assertSame(500, $job->uniqueFor);
        $this->assertSame($job->uniqueId(), (new SynchronizeEloquentSearchSourceJob($sync, $policy))->uniqueId());
        $differentConnection = new SearchLifecycleSynchronization(
            new EloquentSearchSourceLocator(LifecycleProduct::class, 'other', 'id', (string) $product->getKey()),
            $sync->fallbackReference,
        );
        $this->assertNotSame(
            $job->uniqueId(),
            (new SynchronizeEloquentSearchSourceJob($differentConnection, $policy))->uniqueId(),
        );

        $serialized = serialize($job);
        $this->assertStringContainsString(LifecycleProduct::class, $serialized);
        $this->assertStringContainsString('testing', $serialized);
        $this->assertStringContainsString($sync->fallbackReference->sourceKey, $serialized);
        $this->assertStringNotContainsString('Never serialize this title', $serialized);
        $this->assertStringNotContainsString('SearchDocumentSet', $serialized);
        $this->assertStringNotContainsString('password', $serialized);
    }

    public function test_queued_job_reloads_latest_state_and_repeated_execution_is_idempotent(): void
    {
        $queue = Queue::fake();
        config()->set('persian-search.lifecycle.execution', 'queue');
        $product = LifecycleProduct::create(['title' => 'A']);
        $job = $queue->pushed(SynchronizeEloquentSearchSourceJob::class)->first();
        $this->assertInstanceOf(SynchronizeEloquentSearchSourceJob::class, $job);
        $product->updateQuietly(['title' => 'B']);

        $job->handle(app(EloquentSearchSourceSynchronizer::class));
        $this->assertSame('B', SearchDocumentRecord::query()->value('title'));
        $updatedAt = SearchDocumentRecord::query()->value('updated_at');
        $job->handle(app(EloquentSearchSourceSynchronizer::class));
        $this->assertEquals($updatedAt, SearchDocumentRecord::query()->value('updated_at'));
    }

    public function test_queued_save_then_delete_and_delete_then_restore_converge_to_current_state(): void
    {
        $queue = Queue::fake();
        config()->set('persian-search.lifecycle.execution', 'queue');
        $product = LifecycleProduct::create(['title' => 'Current']);
        $saveJob = $queue->pushed(SynchronizeEloquentSearchSourceJob::class)->first();
        PersianSearch::indexSource($product);
        $product->deleteQuietly();
        $saveJob->handle(app(EloquentSearchSourceSynchronizer::class));
        $this->assertSame(0, SearchDocumentRecord::count());

        $product->restoreQuietly();
        $saveJob->handle(app(EloquentSearchSourceSynchronizer::class));
        $this->assertSame(1, SearchDocumentRecord::count());
    }

    public function test_synchronizer_bypasses_global_scope_only_for_exact_locator_key(): void
    {
        config()->set('persian-search.index.sync_on_save', false);
        $visible = LifecycleProduct::create(['title' => 'Visible']);
        $hidden = HiddenLifecycleProduct::query()->withoutGlobalScopes()->create(['title' => 'Hidden']);

        app(EloquentSearchSourceSynchronizer::class)->synchronize($this->synchronization($hidden));

        $this->assertSame(1, SearchDocumentRecord::count());
        $this->assertSame((string) $hidden->getKey(), SearchDocumentRecord::query()->value('source_id'));
        $this->assertNotSame((string) $visible->getKey(), SearchDocumentRecord::query()->value('source_id'));
    }

    public function test_synchronous_after_commit_failure_propagates_after_source_commit(): void
    {
        config()->set('persian-search.providers', [FailingLifecycleProvider::class]);
        DB::beginTransaction();
        LifecycleProduct::create(['title' => 'Committed source']);

        try {
            DB::commit();
            $this->fail('Expected after-commit provider failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Lifecycle provider failed.', $exception->getMessage());
        }

        $this->assertSame(1, LifecycleProduct::query()->count());
        $this->assertSame(0, SearchDocumentRecord::query()->count());
    }

    public function test_queue_job_does_not_swallow_provider_failure(): void
    {
        $queue = Queue::fake();
        config()->set('persian-search.providers', [FailingLifecycleProvider::class]);
        config()->set('persian-search.lifecycle.execution', 'queue');
        LifecycleProduct::create(['title' => 'Queued failure']);
        $job = $queue->pushed(SynchronizeEloquentSearchSourceJob::class)->first();
        $this->assertInstanceOf(SynchronizeEloquentSearchSourceJob::class, $job);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lifecycle provider failed.');
        $job->handle(app(EloquentSearchSourceSynchronizer::class));
    }

    public function test_lifecycle_events_schedule_create_update_delete_restore_and_force_delete_once(): void
    {
        $recorder = new RecordingSearchLifecycleDispatcher;
        app()->instance(SearchLifecycleDispatcher::class, $recorder);
        $product = LifecycleProduct::create(['title' => 'Create']);
        $product->update(['title' => 'Update']);
        $product->delete();
        $product->restore();
        $product->forceDelete();

        $this->assertCount(5, $recorder->events);
    }

    public function test_force_delete_captures_custom_provider_reference_before_row_removal(): void
    {
        RowRequiredLifecycleProvider::$referenceCalls = 0;
        RowRequiredLifecycleProvider::$failReference = false;
        config()->set('persian-search.providers', [RowRequiredLifecycleProvider::class]);
        $product = LifecycleProduct::create(['title' => 'Delete me']);
        $referenceCalls = RowRequiredLifecycleProvider::$referenceCalls;
        $this->assertSame(3, SearchDocumentRecord::query()->count());

        $product->forceDelete();

        $this->assertSame($referenceCalls + 1, RowRequiredLifecycleProvider::$referenceCalls);
        $this->assertSame(0, SearchDocumentRecord::query()->count());
    }

    public function test_hard_delete_captures_provider_reference_once_before_row_removal(): void
    {
        RowRequiredLifecycleProvider::$referenceCalls = 0;
        RowRequiredLifecycleProvider::$failReference = false;
        config()->set('persian-search.providers', [RowRequiredLifecycleProvider::class]);
        $product = HardLifecycleProduct::create(['title' => 'Hard delete']);
        $referenceCalls = RowRequiredLifecycleProvider::$referenceCalls;

        $product->delete();

        $this->assertSame($referenceCalls + 1, RowRequiredLifecycleProvider::$referenceCalls);
        $this->assertSame(0, SearchDocumentRecord::query()->count());
        $this->assertSame(0, HardLifecycleProduct::query()->count());
    }

    public function test_force_delete_rollback_preserves_source_and_index(): void
    {
        RowRequiredLifecycleProvider::$referenceCalls = 0;
        RowRequiredLifecycleProvider::$failReference = false;
        config()->set('persian-search.providers', [RowRequiredLifecycleProvider::class]);
        $product = LifecycleProduct::create(['title' => 'Rollback force delete']);

        DB::beginTransaction();
        $product->forceDelete();
        $this->assertSame(3, SearchDocumentRecord::query()->count());
        DB::rollBack();

        $this->assertSame(1, LifecycleProduct::query()->count());
        $this->assertSame(3, SearchDocumentRecord::query()->count());
    }

    public function test_canceled_deleting_event_prepares_reference_but_dispatches_nothing(): void
    {
        RowRequiredLifecycleProvider::$referenceCalls = 0;
        RowRequiredLifecycleProvider::$failReference = false;
        config()->set('persian-search.providers', [RowRequiredLifecycleProvider::class]);
        $product = LifecycleProduct::create(['title' => 'Canceled delete']);
        $referenceCalls = RowRequiredLifecycleProvider::$referenceCalls;
        LifecycleProduct::deleting(static fn (): bool => false);

        $this->assertFalse($product->delete());
        $this->assertSame($referenceCalls + 1, RowRequiredLifecycleProvider::$referenceCalls);
        $this->assertSame(1, LifecycleProduct::query()->count());
        $this->assertSame(3, SearchDocumentRecord::query()->count());
    }

    public function test_second_delete_attempt_replaces_prepared_state_after_first_is_canceled(): void
    {
        RowRequiredLifecycleProvider::$referenceCalls = 0;
        RowRequiredLifecycleProvider::$failReference = false;
        config()->set('persian-search.providers', [RowRequiredLifecycleProvider::class]);
        $product = LifecycleProduct::create(['title' => 'Retry delete']);
        $referenceCalls = RowRequiredLifecycleProvider::$referenceCalls;
        $cancel = true;
        LifecycleProduct::deleting(static function () use (&$cancel): bool {
            if ($cancel) {
                $cancel = false;

                return false;
            }

            return true;
        });

        $this->assertFalse($product->delete());
        $this->assertTrue($product->delete());
        $this->assertSame($referenceCalls + 2, RowRequiredLifecycleProvider::$referenceCalls);
        $this->assertSame(0, SearchDocumentRecord::query()->count());
    }

    public function test_provider_reference_failure_before_delete_preserves_source_and_surfaces(): void
    {
        RowRequiredLifecycleProvider::$referenceCalls = 0;
        RowRequiredLifecycleProvider::$failReference = false;
        config()->set('persian-search.providers', [RowRequiredLifecycleProvider::class]);
        $product = LifecycleProduct::create(['title' => 'Reference failure']);
        RowRequiredLifecycleProvider::$failReference = true;

        try {
            $product->forceDelete();
            $this->fail('Expected pre-delete provider-reference failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider reference failed before delete.', $exception->getMessage());
        }

        $this->assertSame(1, LifecycleProduct::query()->count());
        $this->assertSame(3, SearchDocumentRecord::query()->count());
    }

    public function test_soft_delete_prepares_provider_reference_before_row_update(): void
    {
        RowRequiredLifecycleProvider::$referenceCalls = 0;
        RowRequiredLifecycleProvider::$failReference = false;
        config()->set('persian-search.providers', [RowRequiredLifecycleProvider::class]);
        $product = LifecycleProduct::create(['title' => 'Soft delete']);
        $referenceCalls = RowRequiredLifecycleProvider::$referenceCalls;

        $product->delete();

        $this->assertSame($referenceCalls + 1, RowRequiredLifecycleProvider::$referenceCalls);
        $this->assertSame(0, SearchDocumentRecord::query()->count());
        $this->assertTrue($product->trashed());
    }

    public function test_automatic_sync_disabled_skips_pre_delete_provider_resolution(): void
    {
        RowRequiredLifecycleProvider::$referenceCalls = 0;
        RowRequiredLifecycleProvider::$failReference = true;
        config()->set('persian-search.providers', [RowRequiredLifecycleProvider::class]);
        config()->set('persian-search.index.sync_on_save', false);
        $product = LifecycleProduct::create(['title' => 'Disabled']);

        $product->forceDelete();

        $this->assertSame(0, RowRequiredLifecycleProvider::$referenceCalls);
    }

    public function test_prepared_deletion_synchronization_retains_no_model_attributes(): void
    {
        RowRequiredLifecycleProvider::$referenceCalls = 0;
        RowRequiredLifecycleProvider::$failReference = false;
        config()->set('persian-search.providers', [RowRequiredLifecycleProvider::class]);
        config()->set('persian-search.index.sync_on_save', false);
        $product = LifecycleProduct::create(['title' => 'Do not retain this title']);
        config()->set('persian-search.index.sync_on_save', true);
        app()->forgetInstance(SearchLifecyclePolicy::class);
        app()->forgetInstance(DefaultSearchLifecycleDispatcher::class);
        app()->forgetInstance(SearchLifecycleDispatcher::class);
        $synchronization = app(SearchLifecycleDispatcher::class)->prepareForModel($product);
        $this->assertInstanceOf(SearchLifecycleSynchronization::class, $synchronization);

        $this->assertStringNotContainsString('Do not retain this title', serialize($synchronization));
    }

    private function synchronization(Model $model): SearchLifecycleSynchronization
    {
        return new SearchLifecycleSynchronization(
            EloquentSearchSourceLocator::fromModel($model),
            app(SearchDocumentProviderRegistry::class)->referenceFor($model),
        );
    }

    private function configureLifecycleSource(): void
    {
        config()->set('database.connections.lifecycle_source', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('lifecycle_source');
        Schema::connection('lifecycle_source')->create('lifecycle_products', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });
    }
}

final class LifecycleProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;
    use SoftDeletes;

    protected $table = 'lifecycle_products';

    protected $guarded = [];

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title'];
    }
}

final class HardLifecycleProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'lifecycle_products';

    protected $guarded = [];

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title'];
    }
}

final class HiddenLifecycleProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;
    use SoftDeletes;

    protected $table = 'lifecycle_products';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::addGlobalScope('hidden', static fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'));
    }

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title'];
    }
}

final class StringLifecycleProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    public $incrementing = false;

    protected $table = 'string_lifecycle_products';

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title'];
    }
}

final class RecordingSearchLifecycleDispatcher implements SearchLifecycleDispatcher
{
    /** @var list<string> */
    public array $events = [];

    public function prepareForModel(Model $model): SearchLifecycleSynchronization
    {
        return new SearchLifecycleSynchronization(
            EloquentSearchSourceLocator::fromModel($model),
            new SearchSourceReference($model::class.':'.$model->getKey(), $model::class, $model->getKey()),
        );
    }

    public function dispatchForModel(Model $model): void
    {
        $this->events[] = 'dispatched';
    }

    public function dispatchSynchronization(SearchLifecycleSynchronization $synchronization): void
    {
        $this->events[] = 'dispatched';
    }

    public function execute(SearchLifecycleSynchronization $synchronization): void {}
}

final class FailingLifecycleProvider implements SearchDocumentProvider
{
    public function key(): string
    {
        return 'failing-lifecycle';
    }

    public function supports(mixed $source): bool
    {
        return $source instanceof LifecycleProduct;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        if (! $source instanceof LifecycleProduct) {
            throw new RuntimeException('Unexpected lifecycle source.');
        }

        return new SearchSourceReference('failing:'.$source->getKey(), 'failing-lifecycle', $source->getKey());
    }

    public function documents(mixed $source): iterable
    {
        throw new RuntimeException('Lifecycle provider failed.');
    }
}

final class RecordingBusDispatcher implements BusDispatcher
{
    public int $dispatchCalls = 0;

    public bool $failNext = true;

    public function dispatch(mixed $command): mixed
    {
        $this->dispatchCalls++;

        if ($this->failNext) {
            $this->failNext = false;

            throw new RuntimeException('Queue unavailable.');
        }

        return $command;
    }

    public function dispatchSync(mixed $command, mixed $handler = null): mixed
    {
        throw new RuntimeException('Unexpected sync dispatch.');
    }

    public function dispatchNow(mixed $command, mixed $handler = null): mixed
    {
        throw new RuntimeException('Unexpected immediate dispatch.');
    }

    public function dispatchAfterResponse(mixed $command, mixed $handler = null): void
    {
        throw new RuntimeException('Unexpected after-response dispatch.');
    }

    /** @param Collection<array-key, mixed>|array<mixed>|null $jobs */
    public function chain($jobs = null): mixed
    {
        throw new RuntimeException('Unexpected chain.');
    }

    public function hasCommandHandler(mixed $command): bool
    {
        return false;
    }

    public function getCommandHandler(mixed $command): mixed
    {
        return null;
    }

    /** @param array<mixed> $pipes */
    public function pipeThrough(array $pipes): static
    {
        return $this;
    }

    /** @param array<mixed> $map */
    public function map(array $map): static
    {
        return $this;
    }
}

final class ThrowingUniqueLock extends UniqueLock
{
    public function acquire(mixed $job): bool
    {
        throw new RuntimeException('Unique cache unavailable.');
    }
}

final class RowRequiredLifecycleProvider implements SearchDocumentProvider
{
    public static int $referenceCalls = 0;

    public static bool $failReference = false;

    public function key(): string
    {
        return 'row-required-lifecycle';
    }

    public function supports(mixed $source): bool
    {
        return $source instanceof LifecycleProduct || $source instanceof HardLifecycleProduct;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        self::$referenceCalls++;

        if (self::$failReference) {
            throw new RuntimeException('Provider reference failed before delete.');
        }

        if ((! $source instanceof LifecycleProduct && ! $source instanceof HardLifecycleProduct) ||
            ! $source->newQueryWithoutScopes()->whereKey($source->getKey())->exists()) {
            throw new RuntimeException('Source row must exist while resolving its reference.');
        }

        return new SearchSourceReference('row-required:'.$source->getKey(), 'row-required', $source->getKey());
    }

    public function documents(mixed $source): iterable
    {
        if (! $source instanceof LifecycleProduct && ! $source instanceof HardLifecycleProduct) {
            throw new RuntimeException('Unexpected lifecycle source.');
        }

        $title = $source->getAttribute('title');

        if (! is_string($title)) {
            throw new RuntimeException('Lifecycle title must be a string.');
        }

        yield new SearchDocument(
            partition: 'public',
            sourceKey: 'row-required:'.$source->getKey(),
            sourceType: 'row-required',
            sourceId: $source->getKey(),
            locale: 'en',
            title: $title,
            excerpt: null,
            normalizedTitle: $title,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $title,
        );
        yield new SearchDocument(
            partition: 'public',
            sourceKey: 'row-required:'.$source->getKey(),
            sourceType: 'row-required',
            sourceId: $source->getKey(),
            locale: 'fa',
            title: 'فارسی',
            excerpt: null,
            normalizedTitle: 'فارسی',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: 'فارسی',
        );
        yield new SearchDocument(
            partition: 'admin',
            sourceKey: 'row-required:'.$source->getKey(),
            sourceType: 'row-required',
            sourceId: $source->getKey(),
            locale: 'en',
            title: $title.' admin',
            excerpt: null,
            normalizedTitle: $title.' admin',
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $title.' admin',
        );
    }
}
