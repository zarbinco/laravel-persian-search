<?php

namespace Zarbinco\PersianSearch\Operations;

use Illuminate\Contracts\Config\Repository;
use Zarbinco\PersianSearch\Contracts\SearchSourceEnumerator;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchOperationsConfigurationException;

final readonly class SearchOperationsPolicyFactory
{
    public function __construct(private Repository $config) {}

    public function make(): SearchOperationsPolicy
    {
        $value = $this->config->get('persian-search.operations', []);

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw InvalidSearchOperationsConfigurationException::forKey('operations', 'an associative array');
        }

        return new SearchOperationsPolicy(
            enumerators: $this->enumerators($value['enumerators'] ?? []),
            chunkSize: $this->integer($value, 'chunk_size', 500),
            maximumSourcesPerRun: $this->integer($value, 'maximum_sources_per_run', 100000),
            lockStore: $this->nullableString($value, 'lock_store'),
            lockKey: $this->string($value, 'lock_key', 'persian-search:maintenance'),
            lockSeconds: $this->integer($value, 'lock_seconds', 3600),
            doctorSampleSize: $this->integer($value, 'doctor_sample_size', 100),
        );
    }

    /** @return list<class-string<SearchSourceEnumerator>> */
    private function enumerators(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidSearchOperationsConfigurationException::forKey('operations.enumerators', 'a list of class strings');
        }
        foreach ($value as $class) {
            if (! is_string($class) || $class === '') {
                throw InvalidSearchOperationsConfigurationException::forKey('operations.enumerators', 'a list of class strings');
            }
        }

        /** @var list<class-string<SearchSourceEnumerator>> $value */
        return $value;
    }

    /** @param array<string, mixed> $values */
    private function integer(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? $default;
        if (! is_int($value)) {
            throw InvalidSearchOperationsConfigurationException::forKey("operations.{$key}", 'an integer');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key, string $default): string
    {
        $value = $values[$key] ?? $default;
        if (! is_string($value)) {
            throw InvalidSearchOperationsConfigurationException::forKey("operations.{$key}", 'a string');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function nullableString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw InvalidSearchOperationsConfigurationException::forKey("operations.{$key}", 'null or a string');
        }

        return $value;
    }
}
