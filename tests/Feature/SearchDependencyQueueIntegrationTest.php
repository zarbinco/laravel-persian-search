<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchDependencyResolver;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyContext;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyDispatcher;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyObserver;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Jobs\SynchronizeEloquentSearchSourceJob;
use Zarbinco\PersianSearch\Lifecycle\EloquentSearchSourceSynchronizer;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronizationRouter;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocatorFactory;
use Zarbinco\PersianSearch\Lifecycle\UniqueSearchLifecycleJobDispatcher;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchDependencyQueueIntegrationTest extends TestCase
{
    private QueueFake $queue;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        foreach (['dependency_queue', 'source_queue', 'unrelated_queue'] as $connection) {
            $app['config']->set("database.connections.{$connection}", [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
        }

        $app['config']->set('persian-search.index.sync_on_save', false);
        $app['config']->set('persian-search.dependencies.enabled', true);
        $app['config']->set('persian-search.dependencies.resolvers', [QueueDependencyResolver::class]);
        $app['config']->set('persian-search.providers', [
            QueueDependencyProviderA::class,
            QueueDependencyProviderB::class,
        ]);
        $app['config']->set('persian-search.lifecycle.execution', 'queue');
        $app['config']->set('persian-search.lifecycle.after_commit', true);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.sync.after_commit', true);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['dependency_queue', 'source_queue', 'unrelated_queue'] as $connection) {
            DB::purge($connection);
        }

        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
        Schema::connection('dependency_queue')->create('queue_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::connection('source_queue')->create('queue_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('dependency_id');
            $table->string('title');
            $table->timestamps();
        });

        $this->queue = Queue::fake();
    }

    public function test_dependency_queue_waits_for_exact_dependency_commit_and_is_provider_aware(): void
    {
        [$dependency] = $this->fixture();
        $connection = DB::connection('dependency_queue');

        $connection->beginTransaction();
        $dependency->update(['name' => 'Committed']);
        Queue::assertNothingPushed();
        $connection->commit();

        $jobs = $this->queuedJobs();
        $this->assertCount(2, $jobs);
        $this->assertSame(
            ['queue-provider-a', 'queue-provider-b'],
            $this->providerKeys($jobs),
        );
        $this->assertNotSame($jobs[0]->uniqueId(), $jobs[1]->uniqueId());

        foreach ($jobs as $job) {
            $this->assertFalse($job->afterCommit);
            $serialized = serialize($job);
            $this->assertStringNotContainsString(QueueDependencyModel::class, $serialized);
            $this->assertStringNotContainsString(QueueDependencyResolver::class, $serialized);
            $this->assertStringNotContainsString(SearchDependencyContext::class, $serialized);
        }
    }

    public function test_dependency_queue_rollback_and_nested_transactions_follow_outer_boundary(): void
    {
        [$dependency] = $this->fixture();
        $connection = DB::connection('dependency_queue');

        $connection->beginTransaction();
        $dependency->update(['name' => 'Rolled back']);
        $connection->rollBack();
        Queue::assertNothingPushed();

        $dependency->refresh();
        $connection->beginTransaction();
        $connection->transaction(static function () use ($dependency): void {
            $dependency->update(['name' => 'Nested']);
        });
        Queue::assertNothingPushed();
        $connection->commit();

        $this->assertCount(2, $this->queuedJobs());
    }

    public function test_dependency_queue_is_not_delayed_by_source_or_unrelated_transactions(): void
    {
        [$dependency] = $this->fixture();
        $source = DB::connection('source_queue');
        $unrelated = DB::connection('unrelated_queue');
        $source->beginTransaction();
        $unrelated->beginTransaction();

        $dependency->update(['name' => 'Independent']);

        $this->assertCount(2, $this->queuedJobs());
        $source->rollBack();
        $unrelated->rollBack();
        $this->assertCount(2, $this->queuedJobs());
    }

    public function test_dependency_queue_real_unique_lock_suppresses_repeated_source_provider_work(): void
    {
        [$dependency] = $this->fixture();

        $dependency->update(['name' => 'First']);
        $dependency->update(['name' => 'Second']);

        $this->assertCount(2, $this->queuedJobs());
    }

    public function test_dependency_queue_worker_uses_captured_providers_and_latest_source_state(): void
    {
        [$dependency, $product] = $this->fixture();

        $dependency->update(['name' => 'Queue']);
        $jobs = $this->queuedJobs();
        $product->updateQuietly(['title' => 'Latest source title']);

        foreach ($jobs as $job) {
            $job->handle(app(EloquentSearchSourceSynchronizer::class));
        }

        $this->assertSame(
            ['queue-a:Latest source title', 'queue-b:Latest source title'],
            SearchDocumentRecord::query()->orderBy('source_type')->pluck('normalized_content')->all(),
        );
        $this->assertSame(['source_queue'], SearchDocumentRecord::query()->pluck('source_connection')->unique()->values()->all());
    }

    public function test_dependency_queue_missing_source_deletes_each_correct_fallback_reference(): void
    {
        [$dependency, $product] = $this->fixture();
        $index = app(SearchIndexManager::class);
        $index->indexSourceWithProvider('queue-provider-a', $product);
        $index->indexSourceWithProvider('queue-provider-b', $product);
        $dependency->update(['name' => 'Delete source']);
        $jobs = $this->queuedJobs();
        $product->deleteQuietly();

        $jobs[0]->handle(app(EloquentSearchSourceSynchronizer::class));
        $this->assertSame(1, SearchDocumentRecord::query()->count());
        $jobs[1]->handle(app(EloquentSearchSourceSynchronizer::class));
        $this->assertSame(0, SearchDocumentRecord::query()->count());
    }

    public function test_dependency_queue_dispatch_failure_releases_the_provider_aware_lock(): void
    {
        $bus = new DependencyQueueRecordingBus;
        app()->instance(BusDispatcher::class, $bus);
        foreach ([
            UniqueSearchLifecycleJobDispatcher::class,
            SearchLifecycleSynchronizationRouter::class,
            SearchDependencyDispatcher::class,
            SearchDependencyObserver::class,
        ] as $service) {
            app()->forgetInstance($service);
        }

        QueueDependencyModel::flushEventListeners();
        QueueDependencyModel::observe(app(SearchDependencyObserver::class));
        [$dependency] = $this->fixture();

        try {
            $dependency->update(['name' => 'First failure']);
            $this->fail('Expected dependency queue dispatch failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Dependency queue unavailable.', $exception->getMessage());
        }

        $dependency->update(['name' => 'Retry succeeds']);

        $this->assertSame(3, $bus->dispatchCalls);
    }

    /** @return array{QueueDependencyModel, QueueDependencyProduct} */
    private function fixture(): array
    {
        $dependency = QueueDependencyModel::create(['name' => 'Initial']);
        $product = QueueDependencyProduct::create([
            'dependency_id' => $dependency->getKey(),
            'title' => 'Initial source title',
        ]);
        Queue::assertNothingPushed();

        return [$dependency, $product];
    }

    /** @return list<SynchronizeEloquentSearchSourceJob> */
    private function queuedJobs(): array
    {
        $jobs = [];

        foreach ($this->queue->pushed(SynchronizeEloquentSearchSourceJob::class) as $job) {
            if (! $job instanceof SynchronizeEloquentSearchSourceJob) {
                throw new \LogicException('Unexpected dependency queue payload.');
            }

            $jobs[] = $job;
        }

        return $jobs;
    }

    /**
     * @param  list<SynchronizeEloquentSearchSourceJob>  $jobs
     * @return list<string>
     */
    private function providerKeys(array $jobs): array
    {
        $keys = array_map(
            static fn (SynchronizeEloquentSearchSourceJob $job): string => $job->synchronization->providerKey,
            $jobs,
        );
        sort($keys, SORT_STRING);

        return $keys;
    }
}

final class QueueDependencyModel extends Model
{
    protected $connection = 'dependency_queue';

    protected $table = 'queue_dependencies';

    protected $guarded = [];
}

final class QueueDependencyProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $connection = 'source_queue';

    protected $table = 'queue_products';

    protected $guarded = [];

    /** @return array<int|string, string|int|float> */
    public function persianSearchableFields(): array
    {
        return ['title'];
    }
}

final class QueueDependencyResolver implements SearchDependencyResolver
{
    public function __construct(private readonly SearchSourceLocatorFactory $locators) {}

    public function key(): string
    {
        return 'queue-dependency';
    }

    public function dependencyModel(): string
    {
        return QueueDependencyModel::class;
    }

    public function resolve(SearchDependencyContext $context): iterable
    {
        foreach (QueueDependencyProduct::query()
            ->where('dependency_id', $context->dependency->getKey())
            ->orderBy('id')
            ->cursor() as $product) {
            yield $this->locators->forModel($product, 'queue-provider-a');
            yield $this->locators->forModel($product, 'queue-provider-a');
            yield $this->locators->forModel($product, 'queue-provider-b');
        }
    }
}

abstract class QueueDependencyProvider implements SearchDocumentProvider
{
    abstract protected function prefix(): string;

    public function supports(mixed $source): bool
    {
        return $source instanceof QueueDependencyProduct;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        if (! $source instanceof QueueDependencyProduct) {
            throw new \LogicException('Unexpected dependency queue source.');
        }

        return new SearchSourceReference(
            $this->prefix().':'.$source->getKey(),
            $this->prefix(),
            $source->getKey(),
        );
    }

    public function documents(mixed $source): iterable
    {
        if (! $source instanceof QueueDependencyProduct) {
            throw new \LogicException('Unexpected dependency queue source.');
        }

        $title = $source->getAttribute('title');
        if (! is_string($title)) {
            throw new \LogicException('Dependency queue title must be a string.');
        }

        yield new SearchDocument(
            partition: 'public',
            sourceKey: $this->prefix().':'.$source->getKey(),
            sourceType: $this->prefix(),
            sourceId: $source->getKey(),
            locale: 'en',
            title: $title,
            excerpt: null,
            normalizedTitle: $title,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $this->prefix().':'.$title,
            sourceConnection: $source->getConnection()->getName(),
        );
    }
}

final class QueueDependencyProviderA extends QueueDependencyProvider
{
    public function key(): string
    {
        return 'queue-provider-a';
    }

    protected function prefix(): string
    {
        return 'queue-a';
    }
}

final class QueueDependencyProviderB extends QueueDependencyProvider
{
    public function key(): string
    {
        return 'queue-provider-b';
    }

    protected function prefix(): string
    {
        return 'queue-b';
    }
}

final class DependencyQueueRecordingBus implements BusDispatcher
{
    public int $dispatchCalls = 0;

    private bool $failNext = true;

    public function dispatch(mixed $command): mixed
    {
        $this->dispatchCalls++;

        if ($this->failNext) {
            $this->failNext = false;

            throw new \RuntimeException('Dependency queue unavailable.');
        }

        return $command;
    }

    public function dispatchSync(mixed $command, mixed $handler = null): mixed
    {
        throw new \RuntimeException('Unexpected sync dispatch.');
    }

    public function dispatchNow(mixed $command, mixed $handler = null): mixed
    {
        throw new \RuntimeException('Unexpected immediate dispatch.');
    }

    public function dispatchAfterResponse(mixed $command, mixed $handler = null): void
    {
        throw new \RuntimeException('Unexpected after-response dispatch.');
    }

    /** @param Collection<array-key, mixed>|array<mixed>|null $jobs */
    public function chain($jobs = null): mixed
    {
        throw new \RuntimeException('Unexpected chain.');
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
