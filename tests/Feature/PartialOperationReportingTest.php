<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\CompositeExpectation;
use RuntimeException;
use Zarbinco\PersianSearch\Exceptions\SearchOperationExecutionException;
use Zarbinco\PersianSearch\Jobs\SynchronizeEloquentSearchSourceJob;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronizationRouter;
use Zarbinco\PersianSearch\Lifecycle\SearchQueuePolicy;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocatorFactory;
use Zarbinco\PersianSearch\Lifecycle\UniqueSearchLifecycleJobDispatcher;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Operations\SearchMaintenanceLockManager;
use Zarbinco\PersianSearch\Operations\SearchPruneOperation;
use Zarbinco\PersianSearch\Operations\SearchPruneReport;
use Zarbinco\PersianSearch\Operations\SearchPruneRequest;
use Zarbinco\PersianSearch\Operations\SearchReindexOperation;
use Zarbinco\PersianSearch\Operations\SearchReindexReport;
use Zarbinco\PersianSearch\Operations\SearchReindexRequest;
use Zarbinco\PersianSearch\Tests\TestCase;

final class PartialOperationReportingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OperationsProduct::$searchableCalls = 0;
        OperationsProduct::$failOnSearchableCall = null;
        OperationsProductEnumerator::$duplicates = false;
        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.operations.enumerators', [OperationsProductEnumerator::class]);
        (require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php')->up();
        Schema::create('operations_products', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function test_partial_reindex_sync_preserves_progress_and_releases_lock(): void
    {
        $this->products(3);
        OperationsProduct::$failOnSearchableCall = 2;

        try {
            app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'sync'));
            $this->fail('The failing source was accepted.');
        } catch (SearchOperationExecutionException $exception) {
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $report = $exception->partialReport;
            $this->assertInstanceOf(SearchReindexReport::class, $report);
            $this->assertSame('partial_failure', $report->status());
            $this->assertSame(1, $report->synchronized);
            $this->assertSame(1, $report->failed);
            $this->assertSame(1, $report->unprocessed);
        }

        $this->assertSame(1, SearchDocumentRecord::query()->count());
        $reacquired = app(SearchMaintenanceLockManager::class)->acquire();
        $reacquired->release();
    }

    public function test_partial_reindex_sync_json_is_safe_and_complete(): void
    {
        $this->products(3);
        OperationsProduct::$failOnSearchableCall = 2;

        $exit = Artisan::call('persian-search:reindex', [
            '--sync' => true,
            '--force' => true,
            '--json' => true,
        ]);
        $output = trim(Artisan::output());
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('partial_failure', $data['status']);
        $this->assertSame(1, $data['synchronized']);
        $this->assertSame(1, $data['failed']);
        $this->assertSame(1, $data['unprocessed']);
        $this->assertStringNotContainsString('secret', $output);
        $this->assertStringNotContainsString('source-key', $output);
    }

    public function test_partial_reindex_sync_human_output_matches_safe_counts(): void
    {
        $this->products(3);
        OperationsProduct::$failOnSearchableCall = 2;

        $exit = Artisan::call('persian-search:reindex', [
            '--sync' => true,
            '--force' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('partial_failure', $output);
        $this->assertStringContainsString('Synchronized', $output);
        $this->assertStringContainsString('Unprocessed', $output);
        $this->assertStringNotContainsString('secret', $output);
        $this->assertStringNotContainsString('source-key', $output);
    }

    public function test_partial_reindex_queue_preserves_queued_suppressed_and_failed_counts(): void
    {
        $products = $this->products(4);
        $locators = array_map(
            fn (OperationsProduct $product) => app(SearchSourceLocatorFactory::class)
                ->forModel($product, 'eloquent'),
            $products,
        );
        usort($locators, static fn ($left, $right): int => strcmp($left->fingerprint(), $right->fingerprint()));
        $policy = app(SearchQueuePolicy::class);
        $jobs = array_map(
            static fn ($locator): SynchronizeEloquentSearchSourceJob => new SynchronizeEloquentSearchSourceJob(
                $locator->synchronization(),
                $policy,
            ),
            $locators,
        );
        $unique = app(UniqueLock::class);
        $this->assertTrue($unique->acquire($jobs[1]));

        $dispatches = 0;
        $bus = Mockery::mock(BusDispatcher::class);
        $expectation = $bus->shouldReceive('dispatch');
        if (! $expectation instanceof CompositeExpectation) {
            throw new RuntimeException('A dispatch expectation could not be configured.');
        }
        $expectation->__call('andReturnUsing', [
            static function () use (&$dispatches): null {
                $dispatches++;
                if ($dispatches === 2) {
                    throw new RuntimeException('queue-password=secret');
                }

                return null;
            },
        ]);
        app()->instance(BusDispatcher::class, $bus);
        app()->forgetInstance(UniqueSearchLifecycleJobDispatcher::class);
        app()->forgetInstance(SearchLifecycleSynchronizationRouter::class);
        app()->forgetInstance(SearchReindexOperation::class);

        try {
            app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'queue'));
            $this->fail('The failing queue dispatch was accepted.');
        } catch (SearchOperationExecutionException $exception) {
            $report = $exception->partialReport;
            $this->assertInstanceOf(SearchReindexReport::class, $report);
            $this->assertSame(1, $report->queued);
            $this->assertSame(1, $report->suppressed);
            $this->assertSame(1, $report->failed);
            $this->assertSame(1, $report->unprocessed);
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        } finally {
            $this->assertSame(2, $dispatches);
            $this->assertTrue($unique->acquire($jobs[2]));
            foreach ($jobs as $job) {
                $unique->release($job);
            }
        }
    }

    public function test_partial_prune_preserves_committed_deletions_and_stops(): void
    {
        $this->orphanedDocuments(3);
        $this->failSecondPruneDelete();

        try {
            app(SearchPruneOperation::class)->run(new SearchPruneRequest(execute: true));
            $this->fail('The failing orphan deletion was accepted.');
        } catch (SearchOperationExecutionException $exception) {
            $report = $exception->partialReport;
            $this->assertInstanceOf(SearchPruneReport::class, $report);
            $this->assertSame('partial_failure', $report->status());
            $this->assertSame(1, $report->deletedSourceReferences);
            $this->assertSame(1, $report->deletedDocuments);
            $this->assertSame(1, $report->failedSourceReferences);
            $this->assertSame(1, $report->unprocessedSourceReferences);
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }

        $this->assertSame(2, SearchDocumentRecord::query()->count());
        $reacquired = app(SearchMaintenanceLockManager::class)->acquire();
        $reacquired->release();
    }

    public function test_partial_prune_json_is_safe_and_complete(): void
    {
        $this->orphanedDocuments(3);
        $this->failSecondPruneDelete();

        $exit = Artisan::call('persian-search:prune', [
            '--execute' => true,
            '--force' => true,
            '--json' => true,
        ]);
        $output = trim(Artisan::output());
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('partial_failure', $data['status']);
        $this->assertSame(1, $data['deleted_source_references']);
        $this->assertSame(1, $data['deleted_documents']);
        $this->assertSame(1, $data['failed_source_references']);
        $this->assertSame(1, $data['unprocessed_source_references']);
        $this->assertStringNotContainsString('secret', $output);
        $this->assertStringNotContainsString('source-key', $output);
    }

    public function test_partial_prune_human_output_matches_safe_counts(): void
    {
        $this->orphanedDocuments(3);
        $this->failSecondPruneDelete();

        $exit = Artisan::call('persian-search:prune', [
            '--execute' => true,
            '--force' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('partial_failure', $output);
        $this->assertStringContainsString('Deleted references', $output);
        $this->assertStringContainsString('Unprocessed references', $output);
        $this->assertStringNotContainsString('secret', $output);
        $this->assertStringNotContainsString('source-key', $output);
    }

    /** @return list<OperationsProduct> */
    private function products(int $count): array
    {
        $products = [];
        for ($index = 1; $index <= $count; $index++) {
            $products[] = OperationsProduct::create(['title' => 'Product '.$index]);
        }

        return $products;
    }

    private function orphanedDocuments(int $count): void
    {
        $this->products($count);
        app(SearchReindexOperation::class)->run(new SearchReindexRequest(executionMode: 'sync'));
        OperationsProduct::query()->delete();
        OperationsProduct::$searchableCalls = 0;
    }

    private function failSecondPruneDelete(): void
    {
        $deletions = 0;
        DB::listen(static function (QueryExecuted $query) use (&$deletions): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'delete from')) {
                return;
            }
            $deletions++;
            if ($deletions === 2) {
                throw new RuntimeException('database-password=secret source-key=hidden');
            }
        });
    }
}
