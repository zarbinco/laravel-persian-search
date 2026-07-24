<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Jobs\SynchronizeEloquentSearchSourceJob;
use Zarbinco\PersianSearch\Lifecycle\EloquentSearchSourceSynchronizer;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchDocumentProviderIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
        Schema::create('multi_provider_products', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });
        MultiProductProvider::$documentsCalls = 0;
        MultiProductProvider::$referenceCalls = 0;
        MultiProductProvider::$includeAdmin = true;
        MultiProductProvider::$invalidOutput = false;
        MultiProductProvider::$emptyOutput = false;
        MultiProviderProduct::$relationCalls = 0;
        MultiProviderProduct::$throwFromRelations = false;
    }

    public function test_indexsource_is_ordered_and_documents_for_is_read_only(): void
    {
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', false);
        $product = MultiProviderProduct::create(['title' => 'Orange']);
        $set = PersianSearch::documentsFor($product);

        $this->assertCount(3, $set);
        $this->assertSame(0, SearchDocumentRecord::count());
        $result = PersianSearch::indexSource($product);
        $this->assertSame(3, $result->created);
        $this->assertSame(3, $result->incoming);
        $this->assertSame(3, SearchDocumentRecord::count());

        $result = PersianSearch::indexSource($product);
        $this->assertSame(3, $result->unchanged);
        $this->assertTrue($result->isNoOp());
        $this->assertSame(3, SearchDocumentRecord::count());
    }

    public function test_empty_output_replaces_the_source_with_an_empty_snapshot(): void
    {
        $this->useProviders([EmptyAwareVirtualProvider::class]);
        $source = new ProviderVirtualSource('about', false);
        PersianSearch::indexSource($source);
        $this->assertSame(1, SearchDocumentRecord::count());

        $empty = new ProviderVirtualSource('about', true);
        $this->assertTrue(PersianSearch::documentsFor($empty)->isEmpty());
        $result = PersianSearch::indexSource($empty);
        $this->assertSame(1, $result->deleted);
        $this->assertSame(0, $result->final);
        $this->assertSame(0, SearchDocumentRecord::count());
    }

    public function test_deletesource_removes_all_locales_and_partitions_but_not_another_source(): void
    {
        $this->useProviders([MultiVirtualProvider::class]);
        $about = new ProviderVirtualSource('about');
        $contact = new ProviderVirtualSource('contact');
        PersianSearch::indexSource($about);
        PersianSearch::indexSource($contact);

        $this->assertSame(6, SearchDocumentRecord::count());
        $this->assertSame(3, PersianSearch::deleteSource($about));
        $this->assertSame(3, SearchDocumentRecord::count());
        $this->assertSame(['virtual:contact'], SearchDocumentRecord::query()->distinct()->pluck('source_key')->all());
    }

    public function test_virtual_documents_index_search_and_return_without_eloquent_model(): void
    {
        $this->useProviders([MultiVirtualProvider::class]);
        PersianSearch::indexSource(new ProviderVirtualSource('about'));

        $results = PersianSearch::query('virtualpage')->locale('en')->type('page')->partition('public')->results();
        $this->assertNotEmpty($results->items());
        $result = $results->items()[0];

        $this->assertNull($result->model);
        $this->assertSame('virtual:about', $result->record->source_key);
    }

    public function test_custom_model_lifecycle_indexes_multiple_documents_deletes_and_restores(): void
    {
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', true);
        config()->set('persian-search.index.include_soft_deleted', false);
        $product = MultiProviderProduct::create(['title' => 'Orange']);
        $this->assertSame(3, SearchDocumentRecord::count());

        $product->delete();
        $this->assertSame(0, SearchDocumentRecord::count());

        $product->restore();
        $this->assertSame(3, SearchDocumentRecord::count());
        $this->assertSame('catalog-item', SearchDocumentRecord::query()->value('source_type'));
    }

    public function test_custom_soft_delete_preserves_documents_when_configured_and_force_delete_removes_them(): void
    {
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', true);
        config()->set('persian-search.index.include_soft_deleted', true);
        $product = MultiProviderProduct::create(['title' => 'Orange']);
        $product->delete();
        $this->assertSame(3, SearchDocumentRecord::count());

        $product->forceDelete();
        $this->assertSame(0, SearchDocumentRecord::count());
    }

    public function test_queued_custom_provider_builds_current_multi_locale_output_only_after_commit(): void
    {
        $queue = Queue::fake();
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.lifecycle.execution', 'queue');

        DB::beginTransaction();
        $product = MultiProviderProduct::create(['title' => 'Orange']);
        $this->assertSame(0, MultiProductProvider::$documentsCalls);
        Queue::assertNothingPushed();
        DB::commit();

        $this->assertSame(0, MultiProductProvider::$documentsCalls);
        $job = $queue->pushed(SynchronizeEloquentSearchSourceJob::class)->first();
        $this->assertInstanceOf(SynchronizeEloquentSearchSourceJob::class, $job);
        $this->assertSame('catalog:'.$product->getKey(), $job->synchronization->fallbackReference->sourceKey);
        $job->handle(app(EloquentSearchSourceSynchronizer::class));

        $this->assertSame(1, MultiProductProvider::$documentsCalls);
        $this->assertSame(['fa', 'en', 'fa'], SearchDocumentRecord::query()->orderBy('id')->pluck('locale')->all());

        MultiProductProvider::$emptyOutput = true;
        $job->handle(app(EloquentSearchSourceSynchronizer::class));
        $this->assertSame(0, SearchDocumentRecord::count());
    }

    public function test_existing_direct_document_indexing_remains_functional(): void
    {
        $record = PersianSearch::indexDocument(new SearchDocument(
            partition: 'public', sourceKey: 'direct:one', sourceType: 'page', sourceId: null,
            locale: 'fa', title: 'مستقیم', excerpt: null, normalizedTitle: 'مستقیم',
            normalizedExcerpt: null, normalizedKeywords: null, normalizedContent: 'مستقیم',
        ));

        $this->assertSame('direct:one', $record->source_key);
    }

    public function test_reindexcommand_indexes_every_document_from_a_custom_provider(): void
    {
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', false);
        MultiProviderProduct::create(['title' => 'One']);
        MultiProviderProduct::create(['title' => 'Two']);

        $command = $this->operationalReindex(MultiProviderProduct::class);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $this->assertSame(0, $command->execute());
        $this->assertDatabaseCount('persian_search_documents', 6);
    }

    public function test_fresh_custom_provider_reindex_cleans_each_current_source_once(): void
    {
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', false);
        $first = MultiProviderProduct::create(['title' => 'One']);
        $second = MultiProviderProduct::create(['title' => 'Two']);
        PersianSearch::indexSource($first);
        PersianSearch::indexSource($second);
        $this->assertDatabaseCount('persian_search_documents', 6);

        MultiProductProvider::$includeAdmin = false;
        $command = $this->operationalReindex(MultiProviderProduct::class);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $this->assertSame(0, $command->execute());
        $this->assertDatabaseCount('persian_search_documents', 4);
        $this->assertSame(4, MultiProductProvider::$documentsCalls);
        $this->assertSame(6, MultiProductProvider::$referenceCalls);
        $this->assertSame([], SearchDocumentRecord::query()->where('partition', 'admin')->pluck('source_key')->all());
        $this->assertSame(2, SearchDocumentRecord::query()->distinct()->count('source_key'));
    }

    public function test_fresh_custom_provider_validates_output_before_deleting_existing_documents(): void
    {
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', false);
        $product = MultiProviderProduct::create(['title' => 'One']);
        PersianSearch::indexSource($product);
        MultiProductProvider::$invalidOutput = true;

        $command = $this->operationalReindex(MultiProviderProduct::class);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $this->assertSame(1, $command->execute());

        $this->assertDatabaseCount('persian_search_documents', 3);
        $this->assertSame(2, MultiProductProvider::$documentsCalls);
    }

    public function test_fresh_custom_provider_cleanup_does_not_delete_an_unprocessed_source(): void
    {
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.index.delete_on_model_delete', false);
        $current = MultiProviderProduct::create(['title' => 'Current']);
        $unprocessed = MultiProviderProduct::create(['title' => 'Excluded']);
        PersianSearch::indexSource($current);
        PersianSearch::indexSource($unprocessed);
        $unprocessed->delete();
        MultiProductProvider::$includeAdmin = false;

        $command = $this->operationalReindex(MultiProviderProduct::class);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $this->assertSame(0, $command->execute());

        $this->assertSame(2, SearchDocumentRecord::query()->where('source_key', 'catalog:'.$current->getKey())->count());
        $this->assertSame(3, SearchDocumentRecord::query()->where('source_key', 'catalog:'.$unprocessed->getKey())->count());
    }

    public function test_non_fresh_custom_provider_reindex_deletes_omitted_documents(): void
    {
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', false);
        $product = MultiProviderProduct::create(['title' => 'One']);
        PersianSearch::indexSource($product);
        MultiProductProvider::$includeAdmin = false;

        $command = $this->operationalReindex(MultiProviderProduct::class);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $this->assertSame(0, $command->execute());

        $this->assertDatabaseCount('persian_search_documents', 2);
        $this->assertDatabaseMissing('persian_search_documents', ['partition' => 'admin', 'locale' => 'fa']);
    }

    public function test_fresh_fallback_reindex_globally_removes_orphaned_model_documents(): void
    {
        config()->set('persian-search.index.sync_on_save', false);
        $current = MultiProviderProduct::create(['title' => 'Current']);
        PersianSearch::index($current);
        $orphan = new MultiProviderProduct;
        $orphan->setRawAttributes(['id' => 999, 'title' => 'Orphan'], true);
        PersianSearch::index($orphan);
        $this->assertDatabaseCount('persian_search_documents', 2);

        $command = $this->operationalReindex(MultiProviderProduct::class);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $this->assertSame(0, $command->execute());

        $this->assertDatabaseCount('persian_search_documents', 2);
        $this->assertDatabaseHas('persian_search_documents', ['source_id' => '999']);
    }

    public function test_custom_provider_reindex_never_invokes_fallback_relation_declarations(): void
    {
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', false);
        config()->set('persian-search.index.delete_on_model_delete', false);
        config()->set('persian-search.index.include_soft_deleted', true);
        MultiProviderProduct::$throwFromRelations = true;
        MultiProviderProduct::create(['title' => 'One']);
        MultiProviderProduct::create(['title' => 'Two']);
        $deleted = MultiProviderProduct::create(['title' => 'Deleted']);
        $deleted->delete();

        for ($iteration = 0; $iteration < 2; $iteration++) {
            $command = $this->operationalReindex(MultiProviderProduct::class);
            $this->assertInstanceOf(PendingCommand::class, $command);
            $this->assertSame(0, $command->execute());
        }

        $this->assertSame(0, MultiProviderProduct::$relationCalls);
        $this->assertDatabaseCount('persian_search_documents', 9);
    }

    public function test_deletesourcereference_uses_validated_identity_without_provider_resolution(): void
    {
        $this->useProviders([MultiProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', false);
        $first = MultiProviderProduct::create(['title' => 'One']);
        $second = MultiProviderProduct::create(['title' => 'Two']);
        $firstSet = PersianSearch::documentsFor($first);
        PersianSearch::replaceDocumentSet($firstSet);
        PersianSearch::indexSource($second);
        $referenceCalls = MultiProductProvider::$referenceCalls;

        $this->assertSame('catalog:'.$first->getKey(), $firstSet->reference->sourceKey);
        $this->assertSame(3, PersianSearch::indexManager()->deleteSourceReference($firstSet->reference));
        $this->assertSame($referenceCalls, MultiProductProvider::$referenceCalls);
        $this->assertSame(0, SearchDocumentRecord::query()->where('source_key', $firstSet->reference->sourceKey)->count());
        $this->assertSame(3, SearchDocumentRecord::query()->where('source_key', 'catalog:'.$second->getKey())->count());

        $this->assertSame(3, PersianSearch::deleteSource($second));
        $this->assertSame($referenceCalls + 1, MultiProductProvider::$referenceCalls);
    }

    /** @param list<class-string<SearchDocumentProvider>> $providers */
    private function useProviders(array $providers): void
    {
        config()->set('persian-search.providers', $providers);
    }
}

final class MultiProviderProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;
    use SoftDeletes;

    protected $table = 'multi_provider_products';

    protected $guarded = [];

    public static int $relationCalls = 0;

    public static bool $throwFromRelations = false;

    public function persianSearchableFields(): array
    {
        return ['title'];
    }

    /** @return list<string> */
    public function persianSearchableRelations(): array
    {
        self::$relationCalls++;

        if (self::$throwFromRelations) {
            throw new \LogicException('Fallback relation declaration must not run for a custom provider.');
        }

        return [];
    }
}

final class MultiProductProvider implements SearchDocumentProvider
{
    public static int $documentsCalls = 0;

    public static int $referenceCalls = 0;

    public static bool $includeAdmin = true;

    public static bool $invalidOutput = false;

    public static bool $emptyOutput = false;

    public function key(): string
    {
        return 'multi-products';
    }

    public function supports(mixed $source): bool
    {
        return $source instanceof MultiProviderProduct;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        self::$referenceCalls++;

        return $this->makeReference($source);
    }

    private function makeReference(mixed $source): SearchSourceReference
    {
        if (! $source instanceof MultiProviderProduct) {
            throw new \LogicException;
        }

        return new SearchSourceReference('catalog:'.$source->getKey(), 'catalog-item', $source->getKey());
    }

    public function documents(mixed $source): iterable
    {
        self::$documentsCalls++;
        $reference = $this->makeReference($source);

        if (self::$emptyOutput) {
            return;
        }

        yield integrationDocument($reference, 'public', 'fa', 'پرتقال');
        yield integrationDocument($reference, 'public', 'en', 'orange');

        if (self::$invalidOutput) {
            yield integrationDocument(
                new SearchSourceReference('wrong:source', $reference->sourceType, $reference->sourceId),
                'public',
                'invalid',
                'invalid provider output',
            );
        }

        if (self::$includeAdmin) {
            yield integrationDocument($reference, 'admin', 'fa', 'پرتقال مدیریت');
        }
    }
}

final readonly class ProviderVirtualSource
{
    public function __construct(public string $key, public bool $empty = false) {}
}

class MultiVirtualProvider implements SearchDocumentProvider
{
    public function key(): string
    {
        return 'multi-virtual';
    }

    public function supports(mixed $source): bool
    {
        return $source instanceof ProviderVirtualSource;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        if (! $source instanceof ProviderVirtualSource) {
            throw new \LogicException;
        }

        return new SearchSourceReference('virtual:'.$source->key, 'page', null);
    }

    public function documents(mixed $source): iterable
    {
        $reference = $this->reference($source);
        yield integrationDocument($reference, 'public', 'fa', 'درباره');
        yield integrationDocument($reference, 'public', 'en', 'virtualpage');
        yield integrationDocument($reference, 'admin', 'fa', 'مدیریت');
    }
}

final class EmptyAwareVirtualProvider extends MultiVirtualProvider
{
    public function key(): string
    {
        return 'empty-aware';
    }

    public function documents(mixed $source): iterable
    {
        if ($source instanceof ProviderVirtualSource && $source->empty) {
            return;
        }
        yield integrationDocument($this->reference($source), 'public', 'en', 'about');
    }
}

function integrationDocument(SearchSourceReference $reference, string $partition, string $locale, string $title): SearchDocument
{
    return new SearchDocument(
        partition: $partition, sourceKey: $reference->sourceKey, sourceType: $reference->sourceType,
        sourceId: $reference->sourceId, locale: $locale, title: $title, excerpt: null,
        normalizedTitle: $title, normalizedExcerpt: null, normalizedKeywords: null, normalizedContent: $title,
    );
}
