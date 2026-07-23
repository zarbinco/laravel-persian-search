<?php

namespace Zarbinco\PersianSearch\Lifecycle;

use Illuminate\Contracts\Config\Repository;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchLifecycleConfigurationException;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final readonly class SearchLifecyclePolicyFactory
{
    public function __construct(private Repository $config) {}

    public function lifecycle(): SearchLifecyclePolicy
    {
        $automatic = $this->boolean('persian-search.index.sync_on_save', true);
        $afterCommit = $this->boolean('persian-search.lifecycle.after_commit', true);
        $includeSoftDeleted = $this->boolean('persian-search.index.include_soft_deleted', false);
        $execution = $this->config->get('persian-search.lifecycle.execution', 'sync');

        if (! is_string($execution) || SearchLifecycleExecutionMode::tryFrom($execution) === null) {
            throw InvalidSearchLifecycleConfigurationException::forKey(
                'persian-search.lifecycle.execution',
                'one of [sync, queue]',
                $execution,
            );
        }

        return new SearchLifecyclePolicy(
            $automatic,
            $afterCommit,
            SearchLifecycleExecutionMode::from($execution),
            $includeSoftDeleted,
        );
    }

    public function queue(): SearchQueuePolicy
    {
        $backoff = $this->config->get('persian-search.queue.backoff', [10, 30, 60]);

        if (! is_array($backoff) || $backoff === [] || ! array_is_list($backoff)) {
            throw InvalidSearchLifecycleConfigurationException::forKey(
                'persian-search.queue.backoff',
                'a non-empty list of positive integers',
                $backoff,
            );
        }

        foreach ($backoff as $value) {
            if (! is_int($value) || $value < 1) {
                throw InvalidSearchLifecycleConfigurationException::forKey(
                    'persian-search.queue.backoff',
                    'a non-empty list of positive integers',
                    $value,
                );
            }
        }

        return new SearchQueuePolicy(
            $this->nullableCanonicalString('persian-search.queue.connection'),
            $this->nullableCanonicalString('persian-search.queue.queue'),
            $this->positiveInteger('persian-search.queue.tries', 3, 100),
            $backoff,
            $this->positiveInteger('persian-search.queue.timeout', 60, 86400),
            $this->positiveInteger('persian-search.queue.unique_for', 300, 86400),
        );
    }

    private function boolean(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        if (! is_bool($value)) {
            throw InvalidSearchLifecycleConfigurationException::forKey($key, 'a boolean', $value);
        }

        return $value;
    }

    private function positiveInteger(string $key, int $default, int $maximum): int
    {
        $value = $this->config->get($key, $default);

        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw InvalidSearchLifecycleConfigurationException::forKey(
                $key,
                "an integer from 1 through {$maximum}",
                $value,
            );
        }

        return $value;
    }

    private function nullableCanonicalString(string $key): ?string
    {
        $value = $this->config->get($key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! CanonicalConfigurationName::isValid($value)) {
            throw InvalidSearchLifecycleConfigurationException::forKey($key, 'null or a non-empty canonical string', $value);
        }

        return $value;
    }
}
