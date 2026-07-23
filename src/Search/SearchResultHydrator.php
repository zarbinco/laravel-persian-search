<?php

namespace Zarbinco\PersianSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use LogicException;
use Zarbinco\PersianSearch\Ranking\SearchRankedCandidate;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final class SearchResultHydrator
{
    /** @param list<SearchRankedCandidate> $candidates
     * @return list<SearchResult>
     */
    public function hydrate(array $candidates): array
    {
        $idsByGroup = [];

        foreach ($candidates as $candidate) {
            $record = $candidate->candidate->document;

            if ($record->source_id === null
                || ! class_exists($record->source_type)
                || ! is_subclass_of($record->source_type, Model::class)) {
                continue;
            }

            /** @var class-string<Model> $type */
            $type = $record->source_type;
            $instance = new $type;
            $connection = $record->source_connection;

            if ($connection !== null) {
                if (! CanonicalConfigurationName::isValid($connection)) {
                    throw new InvalidArgumentException('Persisted source connection is not a canonical safe connection name.');
                }

                $instance->setConnection($connection);
            }

            $keyName = $instance->getKeyName();
            $groupKey = $this->groupKey($type, $connection, $keyName);
            $idsByGroup[$groupKey]['type'] = $type;
            $idsByGroup[$groupKey]['connection'] = $connection;
            $idsByGroup[$groupKey]['key_name'] = $keyName;
            $idsByGroup[$groupKey]['ids'][] = $record->source_id;
        }

        $models = [];

        foreach ($idsByGroup as $group) {
            /** @var class-string<Model> $type */
            $type = $group['type'];
            /** @var string|null $connection */
            $connection = $group['connection'];
            /** @var string $keyName */
            $keyName = $group['key_name'];
            /** @var list<string> $ids */
            $ids = $group['ids'];
            $instance = new $type;

            if ($connection !== null) {
                $instance->setConnection($connection);
            }

            $builder = $instance->newQuery();

            if ((bool) config('persian-search.index.include_soft_deleted', false)
                && in_array(SoftDeletes::class, class_uses_recursive($instance), true)) {
                $withTrashed = [$builder, 'withTrashed'];

                if (! is_callable($withTrashed)) {
                    throw new LogicException('Soft-deleting model query does not support withTrashed().');
                }

                $withTrashed();
            }

            foreach ($builder->whereKey(array_values(array_unique($ids)))->get() as $model) {
                $models[$this->modelKey($type, $connection, $keyName, (string) $model->getKey())] = $model;
            }
        }

        return array_map(function (SearchRankedCandidate $candidate) use ($models): SearchResult {
            $record = $candidate->candidate->document;
            $model = null;

            if ($record->source_id !== null
                && class_exists($record->source_type)
                && is_subclass_of($record->source_type, Model::class)) {
                /** @var class-string<Model> $type */
                $type = $record->source_type;
                $instance = new $type;

                if ($record->source_connection !== null) {
                    $instance->setConnection($record->source_connection);
                }

                $model = $models[$this->modelKey(
                    $type,
                    $record->source_connection,
                    $instance->getKeyName(),
                    $record->source_id,
                )] ?? null;
            }

            return new SearchResult(
                $record,
                $model,
                $candidate->rank,
            );
        }, $candidates);
    }

    /** @param class-string<Model> $type */
    private function groupKey(string $type, ?string $connection, string $keyName): string
    {
        return hash('sha256', serialize([$type, $connection, $keyName]));
    }

    /** @param class-string<Model> $type */
    private function modelKey(string $type, ?string $connection, string $keyName, string $id): string
    {
        return hash('sha256', serialize([$type, $connection, $keyName, $id]));
    }
}
