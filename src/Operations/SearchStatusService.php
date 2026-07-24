<?php

namespace Zarbinco\PersianSearch\Operations;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyPolicy;
use Zarbinco\PersianSearch\Dependencies\SearchDependencyResolverRegistry;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecyclePolicy;
use Zarbinco\PersianSearch\Lifecycle\SearchQueuePolicy;
use Zarbinco\PersianSearch\Models\SearchDocumentRecord;
use Zarbinco\PersianSearch\Providers\SearchDocumentProviderRegistry;

final readonly class SearchStatusService
{
    public function __construct(
        private SearchDocumentProviderRegistry $providers,
        private SearchSourceEnumeratorRegistry $enumerators,
        private Container $container,
        private SearchDependencyPolicy $dependencyPolicy,
        private SearchLifecyclePolicy $lifecycle,
        private SearchQueuePolicy $queue,
        private SearchOperationsPolicy $operations,
        private SearchMaintenanceLockManager $locks,
    ) {}

    public function snapshot(): SearchStatusSnapshot
    {
        $record = new SearchDocumentRecord;
        $connection = $record->getConnectionName();
        $table = $record->getTable();
        $exists = Schema::connection($connection)->hasTable($table);
        $registrations = $this->enumerators->all();
        $providerCounts = $exists ? $this->counts('provider_key') : [];
        $localeCounts = $exists ? $this->counts('locale') : [];
        $partitionCounts = $exists ? $this->counts('partition') : [];

        return new SearchStatusSnapshot(
            true,
            $connection,
            $table,
            $exists,
            $exists ? SearchDocumentRecord::query()->count() : 0,
            $exists ? SearchDocumentRecord::query()->where('is_active', true)->count() : 0,
            $exists ? SearchDocumentRecord::query()->where('is_active', false)->count() : 0,
            count($providerCounts),
            $exists ? SearchDocumentRecord::query()->distinct()->count('source_type') : 0,
            count($localeCounts),
            count($partitionCounts),
            $providerCounts,
            $localeCounts,
            $partitionCounts,
            $this->sorted($this->providers->keys()),
            $this->sorted(array_column($registrations, 'key')),
            $this->sorted(array_column(array_filter($registrations, static fn ($item): bool => $item->authoritative), 'key')),
            $this->dependencyPolicy->enabled
                ? $this->sorted(array_column($this->container->make(SearchDependencyResolverRegistry::class)->registrations(), 'key'))
                : [],
            $this->lifecycle->execution->value,
            $this->lifecycle->afterCommit,
            $this->queue->connection,
            $this->queue->queue,
            $this->operations->lockStore,
            $this->operations->lockKey,
            $this->locks->status(),
        );
    }

    /** @return array<string, int> */
    private function counts(string $column): array
    {
        /** @var Builder<SearchDocumentRecord> $query */
        $query = SearchDocumentRecord::query();
        $rows = $query->select($column)->selectRaw('COUNT(*) as aggregate')->groupBy($column)->orderBy($column)->get();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->getAttribute($column)] = (int) $row->getAttribute('aggregate');
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        usort($values, strcmp(...));

        return $values;
    }
}
