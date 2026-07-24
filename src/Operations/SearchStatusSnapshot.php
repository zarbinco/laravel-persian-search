<?php

namespace Zarbinco\PersianSearch\Operations;

use JsonSerializable;

final readonly class SearchStatusSnapshot implements JsonSerializable
{
    /**
     * @param  array<string, int>  $providerCounts
     * @param  array<string, int>  $localeCounts
     * @param  array<string, int>  $partitionCounts
     * @param  list<string>  $providerKeys
     * @param  list<string>  $enumeratorKeys
     * @param  list<string>  $authoritativeEnumeratorKeys
     * @param  list<string>  $dependencyResolverKeys
     */
    public function __construct(
        public bool $configurationValid,
        public ?string $connection,
        public string $table,
        public bool $tableExists,
        public int $totalDocuments,
        public int $activeDocuments,
        public int $inactiveDocuments,
        public int $distinctProviders,
        public int $distinctSourceTypes,
        public int $distinctLocales,
        public int $distinctPartitions,
        public array $providerCounts,
        public array $localeCounts,
        public array $partitionCounts,
        public array $providerKeys,
        public array $enumeratorKeys,
        public array $authoritativeEnumeratorKeys,
        public array $dependencyResolverKeys,
        public string $lifecycleExecution,
        public bool $lifecycleAfterCommit,
        public ?string $queueConnection,
        public ?string $queueName,
        public ?string $lockStore,
        public string $lockKey,
        public SearchMaintenanceLockStatus $maintenanceLockStatus,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'configuration_valid' => $this->configurationValid,
            'index' => [
                'connection' => $this->connection,
                'table' => $this->table,
                'table_exists' => $this->tableExists,
            ],
            'documents' => [
                'total' => $this->totalDocuments,
                'active' => $this->activeDocuments,
                'inactive' => $this->inactiveDocuments,
                'distinct_providers' => $this->distinctProviders,
                'distinct_source_types' => $this->distinctSourceTypes,
                'distinct_locales' => $this->distinctLocales,
                'distinct_partitions' => $this->distinctPartitions,
                'by_provider' => $this->providerCounts,
                'by_locale' => $this->localeCounts,
                'by_partition' => $this->partitionCounts,
            ],
            'extensions' => [
                'providers' => $this->providerKeys,
                'enumerators' => $this->enumeratorKeys,
                'authoritative_enumerators' => $this->authoritativeEnumeratorKeys,
                'dependency_resolvers' => $this->dependencyResolverKeys,
            ],
            'lifecycle' => [
                'execution' => $this->lifecycleExecution,
                'after_commit' => $this->lifecycleAfterCommit,
                'queue_connection' => $this->queueConnection,
                'queue_name' => $this->queueName,
            ],
            'operations' => [
                'lock_store' => $this->lockStore,
                'lock_key' => $this->lockKey,
                'maintenance_lock_status' => $this->maintenanceLockStatus->value,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
