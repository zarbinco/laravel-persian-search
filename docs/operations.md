# Operations

Operational work is explicit: the package never scans Composer classes,
reflects over models, or infers all providers. Applications register source
enumerators that yield existing typed locators.

Laravel 12 requires PHP 8.2 or later and Illuminate 12.61.1 or later within
Laravel 12. Laravel 13 requires PHP 8.3 or later and Illuminate 13.12.0 or later
within Laravel 13. Laravel 11 and earlier are not supported.

## Source enumerators

```php
use App\Models\Product;
use Zarbinco\PersianSearch\Contracts\AuthoritativeSearchSourceEnumerator;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocatorFactory;
use Zarbinco\PersianSearch\Operations\SearchSourceEnumerationContext;

final class ProductSearchSourceEnumerator implements AuthoritativeSearchSourceEnumerator
{
    public function __construct(
        private readonly SearchSourceLocatorFactory $locators,
    ) {}

    public function key(): string
    {
        return 'products';
    }

    public function providerKey(): string
    {
        return 'product';
    }

    public function sourceModel(): ?string
    {
        return Product::class;
    }

    public function enumerate(SearchSourceEnumerationContext $context): iterable
    {
        foreach (Product::query()->orderBy('id')->lazyById($context->chunkSize) as $product) {
            yield $this->locators->forModel(
                source: $product,
                providerKey: $this->providerKey(),
            );
        }
    }
}
```

All enumerators may reindex. Only the marker contract
`AuthoritativeSearchSourceEnumerator` may drive pruning. Its selected union for
one provider must represent every current source reference in its documented
scope. A provider without authoritative coverage is never pruned.

```php
'operations' => [
    'enumerators' => [
        ProductSearchSourceEnumerator::class,
    ],
    'chunk_size' => 500,
    'maximum_sources_per_run' => 100000,
    'lock_store' => null,
    'lock_key' => 'persian-search:maintenance',
    'lock_seconds' => 3600,
    'doctor_sample_size' => 100,
],
```

Enumerator keys, provider keys, filters, and lock names are canonical,
Unicode-safe configuration names. Duplicate enumerator classes or keys,
unknown providers, unstable metadata, and abstract model declarations fail
before work begins.

## Reindex

```bash
php artisan persian-search:reindex --dry-run
php artisan persian-search:reindex --enumerator=products --sync --force
php artisan persian-search:reindex --provider=product --queue --force
```

`--sync` and `--queue` are exclusive. Without either, the lifecycle execution
policy is used. Dry-run enumerates, validates, deduplicates, detects fallback
conflicts, and reports without routing work or acquiring the write lock.

Sync mode invokes current-state synchronization. Queue mode uses the existing
provider-aware unique job identity, configured connection and queue,
`beforeCommit()`, and duplicate suppression. Operational commands do not add an
artificial after-commit callback.

The global unique-source maximum is enforced before scheduling the first
over-limit source; it is never silently truncated. For reindex, `--limit` is an
intentional partial-run selector. For prune, `--limit` is a fail-closed
authoritative safety ceiling: the first unique ownership reference beyond it
aborts the run, produces no orphan report, and deletes nothing. Application
enumerators remain responsible for efficient, deterministically ordered,
bounded queries.

## Maintenance lock

Mutating reindex and prune runs share the configured Laravel cache atomic lock.
Acquisition occurs before synchronization, dispatch, or deletion and release
occurs in `finally`. Lock failure performs no mutation. There is no lock bypass,
automatic lock stealing, or owner-token output.

The lock covers enumeration and queue dispatch, not the lifetime of queued
jobs. Status only uses a public non-mutating inspection API; its lock state is
`available`, `held`, or `unknown` when the installed framework cannot inspect
the state portably. Two status or doctor calls may run concurrently. Each
doctor execution uses a collision-free temporary probe key and never acquires
the real maintenance key.

## Prune

```bash
php artisan persian-search:prune
php artisan persian-search:prune --provider=product --execute --force
```

Prune without `--execute` is always read-only. It compares authoritative,
provider-aware current references with persisted documents owned by those
providers. Inactive documents participate. Unregistered providers and
providers lacking authoritative coverage remain untouched.

Prune ownership is the exact provider, partition, source key, source type, and
null-aware source ID. An authoritative enumerator must yield one locator for
every partition scope it claims to own. If a provider emits both `public` and
`archive`, both ownership locators are required. Reindex routing intentionally
deduplicates these to one source/provider synchronization; prune keeps them
separate and never builds provider documents merely to discover partitions.

Execution deletes every locale document in each orphaned provider/partition
source-reference group. Document scans are chunked. Enumeration failure,
source-limit overflow, ownership conflict, or lock failure deletes nothing.
Each source-reference deletion is transactionally atomic; the whole operation
is not a distributed transaction.

## Partial execution reports

Enumeration, validation, ownership analysis, and lock acquisition finish before
mutation begins. If routing or deletion then fails, execution stops at that
item and exits with code `1`. The immutable report preserves completed counts,
records one failed item, and records the remaining items as unprocessed.
Its status is `failed` when nothing completed or `partial_failure` when earlier
work completed. Successful and dry-run reports use `success`.

For reindex, completed work is the sum of synchronized, queued, and duplicate-
suppressed sources. For executing prune, completed work is deleted source
references, while deleted document counts remain the exact committed total.
The maintenance lock and any unique queue lock acquired for a failed dispatch
are released. Earlier committed source replacements, deletions, or accepted
queue jobs are not rolled back.

Human output shows the status and failed/unprocessed counters. JSON output
includes the same deterministic report plus a fixed safe message. The original
exception is retained only as the exception cause for programmatic diagnosis;
its message, source keys, attributes, credentials, and other unsafe values are
never rendered by the commands.

## Status and doctor

```bash
php artisan persian-search:status
php artisan persian-search:status --json
php artisan persian-search:doctor
php artisan persian-search:doctor --deep --strict --json
```

Status is read-only and reports safe configuration, table/count aggregates,
registered provider/enumerator/dependency keys, lifecycle/queue settings, and
maintenance-lock state. A missing table produces a partial snapshot.
It does not hydrate source models, build documents, resolve dependencies, or
dispatch jobs.

Doctor independently checks policies, registries, the index connection and
driver, required columns/constraints/indexes, cache atomic locks, queue
connection, job serialization, pre-commit policy, and the real unique-job
cache lock without dispatch, operational readiness, and—under `--deep`—up to
`doctor_sample_size` stored documents for semantic hash and sampled ownership
consistency. Queue readiness is checked even when sync is the lifecycle
default because reindex supports `--queue`; the doctor never pushes a job.
Disabled dependency policy keeps resolver registries and resolver classes
uninitialized.

Exit codes are:

- `0`: success; doctor warnings are allowed without `--strict`.
- `1`: failed operation or failed doctor check.
- `2`: doctor warnings under `--strict`.
- `3`: infrastructure could not initialize safely.
- `4`: maintenance lock unavailable.
- `5`: required write confirmation was not supplied.

## Output and failure semantics

`--json` writes exactly one deterministic JSON object with stable key/list/map
ordering and no progress text. Reports never contain credentials, raw source
keys, model attributes, lock-owner tokens, or Eloquent relation graphs.
Arbitrary application and infrastructure exception messages are replaced with
fixed operational messages. Unsafe extension metadata is represented by a
SHA-256 fingerprint and byte length, and error JSON has a minimal ASCII
fallback if safe encoding itself unexpectedly fails.

Source replacements are independently atomic, not globally atomic. A failure
after earlier sources completed does not roll those sources back. Already
queued jobs may suppress duplicate work. Source or dependency changes can
occur while jobs are pending; current-state reload provides convergence.
There is no outbox, distributed transaction, automatic provider enumeration,
or recursive dependency graph.
