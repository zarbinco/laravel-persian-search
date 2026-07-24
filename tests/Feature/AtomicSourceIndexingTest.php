<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Eloquent\HasPersianSearch;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchIndexingConfigurationException;
use Zarbinco\PersianSearch\Exceptions\SearchIndexPersistenceException;
use Zarbinco\PersianSearch\Exceptions\SearchSourceIdentityConflictException;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchIndexingPolicy;
use Zarbinco\PersianSearch\Indexing\SearchIndexManager;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchDocumentSet;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Tests\TestCase;

final class AtomicSourceIndexingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('persian-search.index.sync_on_save', false);
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
        Schema::create('atomic_products', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
        Schema::create('atomic_unique_guards', function (Blueprint $table): void {
            $table->id();
            $table->string('token')->unique();
        });
        AtomicProductProvider::$mode = 'all';
        AtomicProductProvider::$documentCalls = 0;
        AtomicProductProvider::$referenceCalls = 0;
    }

    protected function tearDown(): void
    {
        SearchDocumentRecord::flushEventListeners();
        parent::tearDown();
    }

    /** @param non-empty-string $method */
    #[DataProvider('sourceIndexingApiMethods')]
    public function test_public_source_indexing_api_is_available(string $method): void
    {
        $this->assertTrue(method_exists(SearchIndexManager::class, $method));
    }

    /** @return array<string, array{non-empty-string}> */
    public static function sourceIndexingApiMethods(): array
    {
        return [
            'replaceDocumentSet' => ['replaceDocumentSet'],
            'indexSource' => ['indexSource'],
            'indexDocument' => ['indexDocument'],
        ];
    }

    public function test_replace_document_set_creates_multiple_locales_partitions_and_exact_source_ids(): void
    {
        foreach ([null, '550e8400-e29b-41d4-a716-446655440000', '01J9ZXYZABCDEF123456789012', '00123'] as $id) {
            $reference = new SearchSourceReference('source:'.($id ?? 'null'), 'virtual', $id);
            $set = $this->set($reference, [
                $this->document($reference, 'public', 'fa', 'فارسی'),
                $this->document($reference, 'public', 'en', 'English'),
                $this->document($reference, 'admin', 'fa', 'مدیریت'),
            ]);

            $result = PersianSearch::replaceDocumentSet($set);

            $this->assertSame(3, $result->incoming);
            $this->assertSame(3, $result->created);
            $this->assertSame(0, $result->updated);
            $this->assertSame(0, $result->unchanged);
            $this->assertSame(0, $result->deleted);
            $this->assertSame($id, $result->reference->sourceId);
        }

        $this->assertDatabaseCount('persian_search_documents', 12);
    }

    public function test_unchanged_replacement_is_a_true_no_op_without_update_query(): void
    {
        $reference = new SearchSourceReference('page:no-op', 'page', null);
        $set = $this->set($reference, [$this->document($reference)]);
        $first = PersianSearch::replaceDocumentSet($set);
        $record = SearchDocumentRecord::query()->firstOrFail();
        $updatedAt = $this->timestamp($record, 'updated_at');
        $indexedAt = $this->timestamp($record, 'indexed_at');
        $updates = 0;
        DB::listen(static function ($query) use (&$updates): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
                $updates++;
            }
        });

        $second = PersianSearch::replaceDocumentSet($set);
        $record->refresh();

        $this->assertSame(1, $first->created);
        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(1, $second->unchanged);
        $this->assertSame(0, $second->deleted);
        $this->assertTrue($second->isNoOp());
        $this->assertSame(0, $updates);
        $this->assertSame($updatedAt, $this->timestamp($record, 'updated_at'));
        $this->assertSame($indexedAt, $this->timestamp($record, 'indexed_at'));
    }

    public function test_payload_canonicalization_avoids_false_updates_but_preserves_lists_and_scalar_types(): void
    {
        $reference = new SearchSourceReference('page:payload', 'page', null);
        PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, payload: ['brand' => 'Example', 'nested' => ['b' => 2, 'a' => 1]]),
        ]));

        $ordered = PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, payload: ['nested' => ['a' => 1, 'b' => 2], 'brand' => 'Example']),
        ]));
        $this->assertSame(1, $ordered->unchanged);

        $listChanged = PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, payload: ['nested' => ['a' => 1, 'b' => 2], 'brand' => 'Example', 'list' => [2, 1]]),
        ]));
        $this->assertSame(1, $listChanged->updated);

        $scalarChanged = PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, payload: ['nested' => ['a' => 1, 'b' => 2], 'brand' => 'Example', 'list' => ['2', 1]]),
        ]));
        $this->assertSame(1, $scalarChanged->updated);
    }

    public function test_mutable_semantic_fields_each_produce_a_real_update_and_retain_primary_key(): void
    {
        $variants = [
            ['title' => 'Changed'],
            ['excerpt' => 'Changed excerpt'],
            ['normalizedTitle' => 'changed normalized'],
            ['normalizedExcerpt' => 'changed excerpt'],
            ['normalizedKeywords' => 'changed keywords'],
            ['normalizedContent' => 'changed content'],
            ['payload' => ['changed' => true]],
            ['priority' => 99],
            ['isActive' => false],
            ['sourceUpdatedAt' => new DateTimeImmutable('2026-02-03T04:05:06Z')],
        ];

        try {
            foreach ($variants as $index => $overrides) {
                Carbon::setTestNow(sprintf('2030-01-01 00:00:%02d', $index * 2));
                $reference = new SearchSourceReference('field:'.$index, 'page', (string) $index);
                PersianSearch::replaceDocumentSet($this->set($reference, [$this->document($reference)]));
                $before = SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->firstOrFail();
                $id = $before->id;
                $updatedAt = $this->timestamp($before, 'updated_at');

                Carbon::setTestNow(sprintf('2030-01-01 00:00:%02d', ($index * 2) + 1));
                $result = PersianSearch::replaceDocumentSet($this->set($reference, [
                    $this->documentWith($reference, $overrides),
                ]));
                $after = SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->firstOrFail();

                $this->assertSame(1, $result->updated);
                $this->assertSame($id, $after->id);
                $this->assertNotSame($updatedAt, $this->timestamp($after, 'updated_at'));
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mixed_replacement_creates_updates_keeps_and_deletes_exactly(): void
    {
        $reference = new SearchSourceReference('mixed:one', 'mixed', '1');
        PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, 'public', 'fa', 'Old'),
            $this->document($reference, 'public', 'en', 'Stale'),
            $this->document($reference, 'admin', 'fa', 'Same'),
        ]));

        $result = PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, 'public', 'fa', 'Updated'),
            $this->document($reference, 'admin', 'fa', 'Same'),
            $this->document($reference, 'public', 'de', 'Created'),
        ]));

        $this->assertSame(3, $result->incoming);
        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->unchanged);
        $this->assertSame(1, $result->deleted);
        $this->assertSame(3, $result->final);
        $this->assertSame(3, $result->changed());
        $this->assertFalse($result->isNoOp());
        $this->assertSame(['admin:fa', 'public:de', 'public:fa'], SearchDocumentRecord::query()
            ->where('source_key', $reference->sourceKey)
            ->orderBy('partition')->orderBy('locale')
            ->get()->map(static fn (SearchDocumentRecord $record): string => $record->partition.':'.$record->locale)->all());
    }

    public function test_stale_locale_partition_and_empty_snapshot_deletion_do_not_touch_another_source(): void
    {
        $first = new SearchSourceReference('source:first', 'virtual', null);
        $second = new SearchSourceReference('source:second', 'virtual', null);
        $all = [
            $this->document($first, 'public', 'fa'),
            $this->document($first, 'public', 'en'),
            $this->document($first, 'admin', 'fa'),
        ];
        PersianSearch::replaceDocumentSet($this->set($first, $all));
        PersianSearch::replaceDocumentSet($this->set($second, [$this->document($second)]));

        $reduced = PersianSearch::replaceDocumentSet($this->set($first, [$this->document($first, 'public', 'fa')]));
        $this->assertSame(2, $reduced->deleted);
        $this->assertSame(0, $reduced->created);
        $this->assertSame(0, $reduced->updated);
        $this->assertFalse($reduced->isNoOp());
        $this->assertSame(1, SearchDocumentRecord::query()->where('source_key', $second->sourceKey)->count());

        $empty = PersianSearch::replaceDocumentSet($this->set($first, []));
        $this->assertSame(1, $empty->deleted);
        $this->assertSame(0, $empty->incoming);
        $this->assertSame(0, $empty->final);

        $emptyAgain = PersianSearch::replaceDocumentSet($this->set($first, []));
        $this->assertTrue($emptyAgain->isNoOp());
        $this->assertSame(0, $emptyAgain->deleted);
    }

    public function test_source_key_lock_query_is_exact_and_deterministically_ordered(): void
    {
        $target = new SearchSourceReference('source-key:target', 'virtual', null);
        $other = new SearchSourceReference('source-key:other', 'virtual', null);
        $documents = [
            $this->document($target, 'public', 'en'),
            $this->document($target, 'admin', 'fa'),
        ];
        PersianSearch::replaceDocumentSet($this->set($target, $documents));
        PersianSearch::replaceDocumentSet($this->set($other, [$this->document($other)]));
        $selects = [];
        DB::listen(static function ($query) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $selects[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
            }
        });

        PersianSearch::replaceDocumentSet($this->set($target, $documents));

        $sourceKeyLock = collect($selects)->first(static fn (array $query): bool => $query['bindings'] === [$target->sourceKey]);
        $this->assertIsArray($sourceKeyLock);
        $this->assertStringContainsString('where "source_key" = ?', $sourceKeyLock['sql']);
        $this->assertStringContainsString('order by "partition" asc, "locale" asc', $sourceKeyLock['sql']);
        $this->assertStringContainsString('"id" asc', $sourceKeyLock['sql']);
        $this->assertFalse(collect($selects)->contains(static fn (array $query): bool => in_array(
            $other->sourceKey,
            $query['bindings'],
            true,
        )));
    }

    #[DataProvider('conflictingSourceReferences')]
    public function test_source_identity_conflict_rolls_back_without_writes(SearchSourceReference $conflicting): void
    {
        $wanted = new SearchSourceReference('conflict:key', 'product', '123');
        PersianSearch::indexDocument($this->document($conflicting));

        try {
            PersianSearch::replaceDocumentSet($this->set($wanted, [$this->document($wanted, title: 'Wanted')]));
            $this->fail('Expected persisted source identity conflict.');
        } catch (SearchSourceIdentityConflictException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('persian_search_documents', 1);
        $this->assertDatabaseHas('persian_search_documents', [
            'source_type' => $conflicting->sourceType,
            'source_id' => $conflicting->sourceId,
        ]);
        $this->assertDatabaseMissing('persian_search_documents', ['title' => 'Wanted']);
    }

    /** @return array<string, array{SearchSourceReference}> */
    public static function conflictingSourceReferences(): array
    {
        return [
            'source type' => [new SearchSourceReference('conflict:key', 'page', '123')],
            'canonical source ID' => [new SearchSourceReference('conflict:key', 'product', '00123')],
            'null source ID' => [new SearchSourceReference('conflict:key', 'product', null)],
        ];
    }

    public function test_duplicate_persisted_identity_fails_safely_when_fixture_bypasses_uniqueness(): void
    {
        Schema::table('persian_search_documents', function (Blueprint $table): void {
            $table->dropUnique('ps_docs_identity_unique');
        });
        $reference = new SearchSourceReference('duplicate:key', 'page', null);
        $attributes = SearchDocumentRecord::forDocument($this->document($reference));
        $attributes['payload'] = json_encode($attributes['payload'], JSON_THROW_ON_ERROR);
        DB::table('persian_search_documents')->insert([$attributes, $attributes]);

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, [$this->document($reference)]));
            $this->fail('Expected duplicate persisted identity conflict.');
        } catch (SearchSourceIdentityConflictException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(2, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
    }

    public function test_rollback_during_create_leaves_no_partial_source_snapshot(): void
    {
        $reference = new SearchSourceReference('rollback:create', 'page', null);
        $created = 0;
        SearchDocumentRecord::created(static function () use (&$created): void {
            $created++;

            if ($created === 2) {
                throw new RuntimeException('forced create failure');
            }
        });

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, [
                $this->document($reference, 'public', 'fa'),
                $this->document($reference, 'public', 'en'),
            ]));
            $this->fail('Expected persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced create failure', $exception->getMessage());
        }

        $this->assertSame(0, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
    }

    public function test_rollback_during_mixed_replacement_restores_original_snapshot(): void
    {
        $reference = new SearchSourceReference('rollback:mixed', 'page', null);
        PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, 'public', 'fa', 'Original'),
            $this->document($reference, 'public', 'en', 'Stale'),
        ]));
        SearchDocumentRecord::updated(static function (): void {
            throw new RuntimeException('forced update failure');
        });

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, [
                $this->document($reference, 'public', 'fa', 'Changed'),
                $this->document($reference, 'public', 'de', 'Created'),
            ]));
            $this->fail('Expected mixed persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced update failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('persian_search_documents', 2);
        $this->assertDatabaseHas('persian_search_documents', ['locale' => 'fa', 'title' => 'Original']);
        $this->assertDatabaseHas('persian_search_documents', ['locale' => 'en', 'title' => 'Stale']);
        $this->assertDatabaseMissing('persian_search_documents', ['locale' => 'de']);
    }

    public function test_rejected_create_rolls_back_the_complete_replacement(): void
    {
        $reference = new SearchSourceReference('rejected:create', 'page', null);
        $creating = 0;
        SearchDocumentRecord::creating(static function () use (&$creating): bool {
            $creating++;

            return $creating !== 2;
        });

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, [
                $this->document($reference, 'public', 'en'),
                $this->document($reference, 'public', 'fa'),
            ]));
            $this->fail('Expected rejected create persistence failure.');
        } catch (SearchIndexPersistenceException $exception) {
            $this->assertStringContainsString('create', $exception->getMessage());
        }

        $this->assertSame(0, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
    }

    public function test_rejected_update_rolls_back_create_and_preserves_stale_snapshot(): void
    {
        $reference = new SearchSourceReference('rejected:update', 'page', null);
        PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, 'public', 'fa', 'Original'),
            $this->document($reference, 'public', 'en', 'Stale'),
        ]));
        SearchDocumentRecord::updating(static fn (): bool => false);

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, [
                $this->document($reference, 'public', 'fa', 'Changed'),
                $this->document($reference, 'public', 'de', 'Created'),
            ]));
            $this->fail('Expected rejected update persistence failure.');
        } catch (SearchIndexPersistenceException $exception) {
            $this->assertStringContainsString('update', $exception->getMessage());
        }

        $this->assertOriginalMixedSnapshot($reference);
    }

    public function test_rejected_stale_delete_rolls_back_create_and_update(): void
    {
        $reference = new SearchSourceReference('rejected:delete', 'page', null);
        PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, 'public', 'fa', 'Original'),
            $this->document($reference, 'public', 'en', 'Stale'),
        ]));
        SearchDocumentRecord::deleting(static fn (): bool => false);

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, [
                $this->document($reference, 'public', 'fa', 'Changed'),
                $this->document($reference, 'public', 'de', 'Created'),
            ]));
            $this->fail('Expected rejected stale deletion persistence failure.');
        } catch (SearchIndexPersistenceException $exception) {
            $this->assertStringContainsString('delete', $exception->getMessage());
        }

        $this->assertOriginalMixedSnapshot($reference);
    }

    public function test_rejected_empty_snapshot_deletion_rolls_back_earlier_deletes(): void
    {
        $reference = new SearchSourceReference('rejected:empty', 'page', null);
        PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, 'public', 'en'),
            $this->document($reference, 'public', 'fa'),
        ]));
        SearchDocumentRecord::deleting(static fn (SearchDocumentRecord $record): bool => $record->locale !== 'fa');

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, []));
            $this->fail('Expected rejected empty-snapshot deletion persistence failure.');
        } catch (SearchIndexPersistenceException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(['en', 'fa'], SearchDocumentRecord::query()
            ->where('source_key', $reference->sourceKey)->orderBy('locale')->pluck('locale')->all());
    }

    public function test_final_snapshot_verification_rejects_observer_identity_mutation(): void
    {
        $reference = new SearchSourceReference('persistence:verification', 'page', null);
        SearchDocumentRecord::creating(static function (SearchDocumentRecord $record): void {
            $record->locale = 'mutated';
        });

        $this->expectException(SearchIndexPersistenceException::class);

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, [$this->document($reference)]));
        } finally {
            $this->assertSame(0, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
        }
    }

    #[DataProvider('replacementSemanticMutations')]
    public function test_replacement_rolls_back_persisted_semantic_mutation(string $field, mixed $value): void
    {
        $reference = new SearchSourceReference('semantic:create:'.$field, 'page', null);
        SearchDocumentRecord::creating(static function (SearchDocumentRecord $record) use ($field, $value): void {
            $record->setAttribute($field, $value);
        });

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, [$this->document($reference)]));
            $this->fail('Expected persisted semantic mismatch.');
        } catch (SearchIndexPersistenceException $exception) {
            $this->assertStringContainsString($field, $exception->getMessage());
            $this->assertStringNotContainsString(is_scalar($value) ? (string) $value : 'secret-payload', $exception->getMessage());
        }

        $this->assertSame(0, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
    }

    /** @return array<string, array{string, mixed}> */
    public static function replacementSemanticMutations(): array
    {
        return [
            'title mutation' => ['title', 'Observer secret title'],
            'payload mutation' => ['payload', ['secret-payload' => 'hidden']],
            'source connection mutation' => ['source_connection', 'observer_connection'],
        ];
    }

    #[DataProvider('replacementUpdateSemanticMutations')]
    public function test_replacement_update_semantic_mutation_rolls_back(string $field): void
    {
        $reference = new SearchSourceReference('semantic:update:'.$field, 'page', null);
        $original = $this->document($reference, title: 'Original');
        $persistedOriginal = $original->withProviderKey('atomic-tests');
        PersianSearch::replaceDocumentSet($this->set($reference, [$original]));
        SearchDocumentRecord::updating(static function (SearchDocumentRecord $record) use ($field, $persistedOriginal): void {
            if ($field === 'title') {
                $record->title = $persistedOriginal->title;
                $record->document_hash = $persistedOriginal->documentHash;

                return;
            }

            $record->normalized_content = 'observer changed content';
        });

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, [
                $this->document($reference, title: 'Incoming'),
            ]));
            $this->fail('Expected update semantic mismatch.');
        } catch (SearchIndexPersistenceException $exception) {
            $this->assertStringContainsString($field, $exception->getMessage());
        }

        $this->assertDatabaseHas('persian_search_documents', [
            'source_key' => $reference->sourceKey,
            'title' => 'Original',
            'document_hash' => $persistedOriginal->documentHash,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function replacementUpdateSemanticMutations(): array
    {
        return [
            'title and hash reset' => ['title'],
            'content changed with incoming hash' => ['normalized_content'],
        ];
    }

    public function test_unchanged_corrupted_record_fails_complete_snapshot_verification(): void
    {
        $reference = new SearchSourceReference('semantic:corrupt', 'page', null);
        $document = $this->document($reference, title: 'Expected');
        PersianSearch::replaceDocumentSet($this->set($reference, [$document]));
        DB::table('persian_search_documents')->where('source_key', $reference->sourceKey)->update([
            'title' => 'Corrupted but hash retained',
        ]);

        $this->expectException(SearchIndexPersistenceException::class);

        PersianSearch::replaceDocumentSet($this->set($reference, [$document]));
    }

    public function test_post_save_in_memory_mutation_does_not_reject_correct_database_state(): void
    {
        $reference = new SearchSourceReference('semantic:memory-only', 'page', null);
        SearchDocumentRecord::saved(static function (SearchDocumentRecord $record): void {
            $record->title = 'In-memory only mutation';
        });

        $result = PersianSearch::replaceDocumentSet($this->set($reference, [
            $this->document($reference, title: 'Persisted title'),
        ]));

        $this->assertSame(1, $result->created);
        $this->assertDatabaseHas('persian_search_documents', [
            'source_key' => $reference->sourceKey,
            'title' => 'Persisted title',
        ]);
    }

    public function test_semantic_mismatch_lists_sorted_field_names_without_values(): void
    {
        $reference = new SearchSourceReference('semantic:diagnostic-fields', 'page', null);
        SearchDocumentRecord::creating(static function (SearchDocumentRecord $record): void {
            $record->title = 'Secret observer title';
            $record->payload = ['secret' => 'Secret observer payload'];
        });

        try {
            PersianSearch::replaceDocumentSet($this->set($reference, [$this->document($reference)]));
            $this->fail('Expected persisted semantic mismatch.');
        } catch (SearchIndexPersistenceException $exception) {
            $this->assertStringContainsString('[payload, title]', $exception->getMessage());
            $this->assertStringNotContainsString('Secret observer title', $exception->getMessage());
            $this->assertStringNotContainsString('Secret observer payload', $exception->getMessage());
        }
    }

    public function test_transaction_retry_reuses_validated_set_and_does_not_double_counts(): void
    {
        config()->set('persian-search.providers', [AtomicProductProvider::class]);
        $product = AtomicProduct::create(['title' => 'Retry']);
        $attempt = 0;
        SearchDocumentRecord::created(static function () use (&$attempt): void {
            $attempt++;

            if ($attempt === 1) {
                throw new RuntimeException('database is locked');
            }
        });

        $result = PersianSearch::indexSource($product);

        $this->assertSame(2, $result->created);
        $this->assertSame(2, $result->incoming);
        $this->assertSame(1, AtomicProductProvider::$documentCalls);
        $this->assertSame(1, AtomicProductProvider::$referenceCalls);
        $this->assertGreaterThan(2, $attempt);
        $this->assertDatabaseCount('persian_search_documents', 2);
    }

    public function test_database_exception_propagates_after_transaction_retries_are_exhausted(): void
    {
        config()->set('persian-search.providers', [AtomicProductProvider::class]);
        config()->set('persian-search.index.transaction_attempts', 2);
        $product = AtomicProduct::create(['title' => 'Exhausted']);
        $attempts = 0;
        SearchDocumentRecord::created(static function () use (&$attempts): void {
            $attempts++;

            throw new RuntimeException('database is locked');
        });

        try {
            PersianSearch::indexSource($product);
            $this->fail('Expected the database exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database is locked', $exception->getMessage());
        }

        $this->assertSame(2, $attempts);
        $this->assertSame(1, AtomicProductProvider::$documentCalls);
        $this->assertSame(1, AtomicProductProvider::$referenceCalls);
        $this->assertDatabaseCount('persian_search_documents', 0);
    }

    public function test_transaction_uses_configured_index_connection_not_source_connection(): void
    {
        config()->set('database.connections.search_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('search_testing');
        config()->set('persian-search.index.connection', 'search_testing');
        $migration = require __DIR__.'/../../database/migrations/create_persian_search_documents_table.php';
        $migration->up();
        config()->set('persian-search.providers', [AtomicProductProvider::class]);
        $product = AtomicProduct::create(['title' => 'Separate connection']);

        $result = PersianSearch::indexSource($product);

        $this->assertSame('testing', $product->getConnectionName());
        $this->assertSame(2, $result->created);
        $this->assertSame(2, DB::connection('search_testing')->table('persian_search_documents')->count());
        $this->assertSame(0, DB::connection('testing')->table('persian_search_documents')->count());
    }

    public function test_model_save_replaces_stale_locales_and_empty_output_removes_snapshot(): void
    {
        config()->set('persian-search.providers', [AtomicProductProvider::class]);
        config()->set('persian-search.index.sync_on_save', true);
        $product = AtomicProduct::create(['title' => 'Lifecycle']);
        $this->assertDatabaseCount('persian_search_documents', 2);

        AtomicProductProvider::$mode = 'fa';
        $product->update(['title' => 'Changed']);
        $this->assertDatabaseCount('persian_search_documents', 1);
        $this->assertDatabaseMissing('persian_search_documents', ['locale' => 'en']);

        AtomicProductProvider::$mode = 'empty';
        $product->update(['title' => 'Empty']);
        $this->assertDatabaseCount('persian_search_documents', 0);
    }

    public function test_direct_index_document_is_a_no_op_for_identical_document_and_keeps_siblings(): void
    {
        $reference = new SearchSourceReference('direct:siblings', 'page', null);
        $fa = $this->document($reference, 'public', 'fa');
        $en = $this->document($reference, 'public', 'en');
        $admin = $this->document($reference, 'admin', 'fa');
        $first = PersianSearch::indexDocument($fa);
        PersianSearch::indexDocument($en);
        PersianSearch::indexDocument($admin);
        $updatedAt = $this->timestamp($first, 'updated_at');
        $indexedAt = $this->timestamp($first, 'indexed_at');

        $same = PersianSearch::indexDocument($fa);

        $this->assertSame($first->id, $same->id);
        $this->assertSame($updatedAt, $this->timestamp($same, 'updated_at'));
        $this->assertSame($indexedAt, $this->timestamp($same, 'indexed_at'));
        $this->assertDatabaseCount('persian_search_documents', 3);
        $this->assertDatabaseHas('persian_search_documents', ['locale' => 'en']);
        $this->assertDatabaseHas('persian_search_documents', ['partition' => 'admin']);
    }

    #[DataProvider('directFirstCreateRaceDocuments')]
    public function test_direct_index_document_recovers_from_first_create_race(SearchDocument $concurrent, bool $updated): void
    {
        $reference = new SearchSourceReference('race:direct', 'page', null);
        $incoming = $this->document($reference, title: 'Incoming');
        $attributes = SearchDocumentRecord::forDocument($concurrent);
        $attributes['payload'] = json_encode($attributes['payload'], JSON_THROW_ON_ERROR);
        $inserted = false;
        SearchDocumentRecord::creating(static function (SearchDocumentRecord $record) use (&$inserted, $attributes): void {
            if ($inserted) {
                return;
            }

            $inserted = true;
            DB::connection($record->getConnectionName())->table($record->getTable())->insert($attributes);
        });

        $result = PersianSearch::indexDocument($incoming);

        $this->assertSame(1, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
        $this->assertTrue($result->exists);
        $this->assertNotNull($result->getKey());
        $this->assertSame($incoming->documentHash, $result->document_hash);
        $this->assertSame('Incoming', $result->title);
        $this->assertSame($updated, $concurrent->documentHash !== $result->document_hash);
    }

    /** @return array<string, array{SearchDocument, bool}> */
    public static function directFirstCreateRaceDocuments(): array
    {
        $reference = new SearchSourceReference('race:direct', 'page', null);
        $document = static fn (string $title): SearchDocument => new SearchDocument(
            partition: 'public',
            sourceKey: $reference->sourceKey,
            sourceType: $reference->sourceType,
            sourceId: $reference->sourceId,
            locale: 'fa',
            title: $title,
            excerpt: 'Excerpt',
            normalizedTitle: 'title',
            normalizedExcerpt: 'excerpt',
            normalizedKeywords: 'keywords',
            normalizedContent: 'content',
        );

        return [
            'identical concurrent row' => [$document('Incoming'), false],
            'changed concurrent row' => [$document('Concurrent'), true],
        ];
    }

    public function test_direct_index_document_race_rejects_conflicting_source_identity(): void
    {
        $reference = new SearchSourceReference('race:conflict', 'page', null);
        $incoming = $this->document($reference);
        $conflicting = $this->document(new SearchSourceReference('race:conflict', 'other', '1'));
        $attributes = SearchDocumentRecord::forDocument($conflicting);
        $attributes['payload'] = json_encode($attributes['payload'], JSON_THROW_ON_ERROR);
        SearchDocumentRecord::creating(static function (SearchDocumentRecord $record) use ($attributes): void {
            DB::connection($record->getConnectionName())->table($record->getTable())->insert($attributes);
        });

        $this->expectException(SearchSourceIdentityConflictException::class);

        try {
            PersianSearch::indexDocument($incoming);
        } finally {
            $this->assertSame(0, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
        }
    }

    public function test_direct_index_document_rethrows_unique_violation_for_an_unrelated_constraint(): void
    {
        Schema::table('persian_search_documents', function (Blueprint $table): void {
            $table->unique('document_hash', 'test_document_hash_unique');
        });
        $reference = new SearchSourceReference('race:unrelated', 'page', null);
        $incoming = $this->document($reference);
        $other = SearchDocumentRecord::forDocument($this->document(
            new SearchSourceReference('race:other', 'page', null),
            locale: 'en',
        ));
        $other['document_hash'] = $incoming->documentHash;
        $other['payload'] = json_encode($other['payload'], JSON_THROW_ON_ERROR);
        DB::table('persian_search_documents')->insert($other);

        $this->expectException(UniqueConstraintViolationException::class);

        PersianSearch::indexDocument($incoming);
    }

    #[DataProvider('postInsertUniqueListenerEvents')]
    public function test_post_insert_listener_unique_violation_is_rethrown(string $event): void
    {
        DB::table('atomic_unique_guards')->insert(['token' => 'duplicate']);
        $listener = static function (): void {
            DB::table('atomic_unique_guards')->insert(['token' => 'duplicate']);
        };

        if ($event === 'created') {
            SearchDocumentRecord::created($listener);
        } else {
            SearchDocumentRecord::saved($listener);
        }

        $reference = new SearchSourceReference('unique:listener:'.$event, 'page', null);
        $this->expectException(UniqueConstraintViolationException::class);

        try {
            PersianSearch::indexDocument($this->document($reference));
        } finally {
            $this->assertSame(0, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
        }
    }

    /** @return array<string, array{string}> */
    public static function postInsertUniqueListenerEvents(): array
    {
        return [
            'created listener' => ['created'],
            'saved listener' => ['saved'],
        ];
    }

    public function test_creating_listener_unique_violation_on_another_table_is_rethrown(): void
    {
        DB::table('atomic_unique_guards')->insert(['token' => 'duplicate']);
        SearchDocumentRecord::creating(static function (): void {
            DB::table('atomic_unique_guards')->insert(['token' => 'duplicate']);
        });
        $reference = new SearchSourceReference('unique:creating-listener', 'page', null);
        $this->expectException(UniqueConstraintViolationException::class);

        try {
            PersianSearch::indexDocument($this->document($reference));
        } finally {
            $this->assertSame(0, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
        }
    }

    public function test_listener_unique_violation_on_different_connection_is_rethrown(): void
    {
        config()->set('database.connections.unique_guard', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('unique_guard');
        Schema::connection('unique_guard')->create('atomic_unique_guards', function (Blueprint $table): void {
            $table->id();
            $table->string('token')->unique();
        });
        DB::connection('unique_guard')->table('atomic_unique_guards')->insert(['token' => 'duplicate']);
        SearchDocumentRecord::creating(static function (): void {
            DB::connection('unique_guard')->table('atomic_unique_guards')->insert(['token' => 'duplicate']);
        });
        $reference = new SearchSourceReference('unique:different-connection', 'page', null);
        $this->expectException(UniqueConstraintViolationException::class);

        try {
            PersianSearch::indexDocument($this->document($reference));
        } finally {
            $this->assertSame(0, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
        }
    }

    #[DataProvider('directSemanticMutationOperations')]
    public function test_direct_index_document_detects_semantic_mutation(string $operation, string $field): void
    {
        $reference = new SearchSourceReference('semantic:direct:'.$operation.':'.$field, 'page', null);
        $original = $this->document($reference, title: 'Original');

        if ($operation === 'create') {
            SearchDocumentRecord::creating(static function (SearchDocumentRecord $record) use ($field): void {
                $value = match ($field) {
                    'payload' => ['secret' => 'payload-value'],
                    'source_connection' => 'observer_connection',
                    default => 'Observer title',
                };
                $record->setAttribute($field, $value);
            });
        } else {
            PersianSearch::indexDocument($original);
            SearchDocumentRecord::updating(static function (SearchDocumentRecord $record) use ($original): void {
                $record->title = $original->title;
                $record->document_hash = $original->documentHash;
            });
        }

        try {
            PersianSearch::indexDocument($this->document($reference, title: 'Incoming'));
            $this->fail('Expected direct semantic mismatch.');
        } catch (SearchIndexPersistenceException $exception) {
            $this->assertStringContainsString($field, $exception->getMessage());
            $this->assertStringNotContainsString('Observer title', $exception->getMessage());
            $this->assertStringNotContainsString('payload-value', $exception->getMessage());
        }

        if ($operation === 'create') {
            $this->assertSame(0, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
        } else {
            $this->assertDatabaseHas('persian_search_documents', [
                'source_key' => $reference->sourceKey,
                'title' => 'Original',
            ]);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function directSemanticMutationOperations(): array
    {
        return [
            'create title mutation' => ['create', 'title'],
            'create payload mutation' => ['create', 'payload'],
            'create source connection mutation' => ['create', 'source_connection'],
            'update reset' => ['update', 'title'],
        ];
    }

    public function test_direct_unchanged_corrupted_record_is_rejected(): void
    {
        $reference = new SearchSourceReference('semantic:direct:corrupt', 'page', null);
        $document = $this->document($reference, title: 'Expected');
        PersianSearch::indexDocument($document);
        DB::table('persian_search_documents')->where('source_key', $reference->sourceKey)->update([
            'title' => 'Corrupted',
        ]);

        $this->expectException(SearchIndexPersistenceException::class);

        PersianSearch::indexDocument($document);
    }

    public function test_direct_success_returns_freshly_verified_database_state(): void
    {
        $reference = new SearchSourceReference('semantic:direct:fresh', 'page', null);
        SearchDocumentRecord::saved(static function (SearchDocumentRecord $record): void {
            $record->title = 'In-memory listener mutation';
        });

        $created = PersianSearch::indexDocument($this->document($reference, title: 'Created'));
        $updated = PersianSearch::indexDocument($this->document($reference, title: 'Updated'));

        $this->assertSame('Created', $created->title);
        $this->assertSame('Updated', $updated->title);
        $this->assertSame($updated->document_hash, SearchDocumentRecord::query()
            ->where('source_key', $reference->sourceKey)->value('document_hash'));
    }

    #[DataProvider('directRejectedOperations')]
    public function test_direct_index_document_rejected_persistence_throws(string $operation): void
    {
        $reference = new SearchSourceReference('rejected:direct', 'page', null);

        if ($operation === 'create') {
            SearchDocumentRecord::creating(static fn (): bool => false);
        } else {
            PersianSearch::indexDocument($this->document($reference, title: 'Original'));
            SearchDocumentRecord::updating(static fn (): bool => false);
        }

        $this->expectException(SearchIndexPersistenceException::class);

        try {
            PersianSearch::indexDocument($this->document($reference, title: 'Changed'));
        } finally {
            if ($operation === 'update') {
                $this->assertDatabaseHas('persian_search_documents', [
                    'source_key' => $reference->sourceKey,
                    'title' => 'Original',
                ]);
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function directRejectedOperations(): array
    {
        return [
            'rejected create' => ['create'],
            'rejected update' => ['update'],
        ];
    }

    public function test_persistence_exception_does_not_render_unsafe_identity_characters(): void
    {
        $unsafeKey = "unsafe\u{0001}source";
        $reference = new SearchSourceReference($unsafeKey, 'page', null);
        SearchDocumentRecord::creating(static fn (): bool => false);

        try {
            PersianSearch::indexDocument($this->document($reference));
            $this->fail('Expected rejected direct persistence failure.');
        } catch (SearchIndexPersistenceException $exception) {
            $this->assertStringNotContainsString($unsafeKey, $exception->getMessage());
            $this->assertStringNotContainsString("\u{0001}", $exception->getMessage());
            $this->assertStringContainsString(hash('sha256', $unsafeKey), $exception->getMessage());
        }
    }

    public function test_indexing_policy_rejects_invalid_transaction_attempts(): void
    {
        foreach ([0, -1, 11] as $attempts) {
            try {
                new SearchIndexingPolicy($attempts);
                $this->fail('Expected invalid indexing policy.');
            } catch (InvalidSearchIndexingConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_indexing_policy_factory_rejects_non_integer_transaction_attempts(): void
    {
        config()->set('persian-search.index.transaction_attempts', '3');

        $this->expectException(InvalidSearchIndexingConfigurationException::class);

        app(SearchIndexingPolicy::class);
    }

    private function timestamp(SearchDocumentRecord $record, string $column): string
    {
        $value = $record->getAttribute($column);

        $this->assertInstanceOf(DateTimeInterface::class, $value);

        return $value->format('Y-m-d H:i:s.u');
    }

    private function assertOriginalMixedSnapshot(SearchSourceReference $reference): void
    {
        $this->assertSame(2, SearchDocumentRecord::query()->where('source_key', $reference->sourceKey)->count());
        $this->assertDatabaseHas('persian_search_documents', [
            'source_key' => $reference->sourceKey,
            'locale' => 'fa',
            'title' => 'Original',
        ]);
        $this->assertDatabaseHas('persian_search_documents', [
            'source_key' => $reference->sourceKey,
            'locale' => 'en',
            'title' => 'Stale',
        ]);
        $this->assertDatabaseMissing('persian_search_documents', [
            'source_key' => $reference->sourceKey,
            'locale' => 'de',
        ]);
    }

    /** @param list<SearchDocument> $documents */
    private function set(SearchSourceReference $reference, array $documents): SearchDocumentSet
    {
        return SearchDocumentSet::fromIterable($reference, $documents, 'atomic-tests');
    }

    /** @param array<string, mixed> $overrides */
    private function documentWith(SearchSourceReference $reference, array $overrides): SearchDocument
    {
        return $this->document(
            reference: $reference,
            title: is_string($overrides['title'] ?? null) ? $overrides['title'] : 'Title',
            excerpt: is_string($overrides['excerpt'] ?? null) ? $overrides['excerpt'] : 'Excerpt',
            normalizedTitle: is_string($overrides['normalizedTitle'] ?? null) ? $overrides['normalizedTitle'] : 'title',
            normalizedExcerpt: is_string($overrides['normalizedExcerpt'] ?? null) ? $overrides['normalizedExcerpt'] : 'excerpt',
            normalizedKeywords: is_string($overrides['normalizedKeywords'] ?? null) ? $overrides['normalizedKeywords'] : 'keywords',
            normalizedContent: is_string($overrides['normalizedContent'] ?? null) ? $overrides['normalizedContent'] : 'content',
            payload: is_array($overrides['payload'] ?? null) ? $overrides['payload'] : [],
            priority: is_int($overrides['priority'] ?? null) ? $overrides['priority'] : 0,
            isActive: is_bool($overrides['isActive'] ?? null) ? $overrides['isActive'] : true,
            sourceUpdatedAt: ($overrides['sourceUpdatedAt'] ?? null) instanceof DateTimeImmutable ? $overrides['sourceUpdatedAt'] : null,
        );
    }

    /** @param array<string|int, mixed> $payload */
    private function document(
        SearchSourceReference $reference,
        string $partition = 'public',
        string $locale = 'fa',
        string $title = 'Title',
        ?string $excerpt = 'Excerpt',
        ?string $normalizedTitle = 'title',
        ?string $normalizedExcerpt = 'excerpt',
        ?string $normalizedKeywords = 'keywords',
        ?string $normalizedContent = 'content',
        array $payload = [],
        int $priority = 0,
        bool $isActive = true,
        ?DateTimeImmutable $sourceUpdatedAt = null,
    ): SearchDocument {
        return new SearchDocument(
            partition: $partition,
            sourceKey: $reference->sourceKey,
            sourceType: $reference->sourceType,
            sourceId: $reference->sourceId,
            locale: $locale,
            title: $title,
            excerpt: $excerpt,
            normalizedTitle: $normalizedTitle,
            normalizedExcerpt: $normalizedExcerpt,
            normalizedKeywords: $normalizedKeywords,
            normalizedContent: $normalizedContent,
            payload: $payload,
            priority: $priority,
            isActive: $isActive,
            sourceUpdatedAt: $sourceUpdatedAt,
        );
    }
}

final class AtomicProduct extends Model implements PersianSearchable
{
    use HasPersianSearch;

    protected $table = 'atomic_products';

    protected $guarded = [];
}

final class AtomicProductProvider implements SearchDocumentProvider
{
    public static string $mode = 'all';

    public static int $documentCalls = 0;

    public static int $referenceCalls = 0;

    public function key(): string
    {
        return 'atomic-products';
    }

    public function supports(mixed $source): bool
    {
        return $source instanceof AtomicProduct;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        self::$referenceCalls++;

        return $this->makeReference($source);
    }

    public function documents(mixed $source): iterable
    {
        self::$documentCalls++;

        if (self::$mode === 'empty') {
            return;
        }

        $reference = $this->makeReference($source);
        yield atomicProductDocument($reference, 'fa', 'فارسی');

        if (self::$mode === 'all') {
            yield atomicProductDocument($reference, 'en', 'English');
        }
    }

    private function makeReference(mixed $source): SearchSourceReference
    {
        if (! $source instanceof AtomicProduct) {
            throw new RuntimeException('Unsupported atomic product source.');
        }

        return new SearchSourceReference('atomic-product:'.$source->getKey(), 'atomic-product', $source->getKey());
    }
}

function atomicProductDocument(SearchSourceReference $reference, string $locale, string $title): SearchDocument
{
    return new SearchDocument(
        partition: 'public',
        sourceKey: $reference->sourceKey,
        sourceType: $reference->sourceType,
        sourceId: $reference->sourceId,
        locale: $locale,
        title: $title,
        excerpt: null,
        normalizedTitle: $title,
        normalizedExcerpt: null,
        normalizedKeywords: null,
        normalizedContent: $title,
    );
}
