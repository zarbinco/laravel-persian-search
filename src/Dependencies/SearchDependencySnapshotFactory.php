<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Support\CanonicalConfigurationName;

final class SearchDependencySnapshotFactory
{
    /** @param array<string, mixed> $attributes */
    public function fromAttributes(Model $model, array $attributes): Model
    {
        $class = $model::class;
        $snapshot = new $class;
        $connection = $model->getConnection()->getName();

        if (! is_string($connection) || ! CanonicalConfigurationName::isValid($connection)) {
            throw new InvalidArgumentException('A search dependency model requires a canonical resolved connection name.');
        }

        $snapshot->setConnection($connection);
        $snapshot->setTable($model->getTable());
        $snapshot->setKeyName($model->getKeyName());
        $snapshot->setKeyType($model->getKeyType());
        $snapshot->setIncrementing($model->getIncrementing());
        $snapshot->setRawAttributes($attributes, true);
        $snapshot->exists = true;
        $snapshot->wasRecentlyCreated = false;
        $snapshot->unsetRelations();

        $modelHadPersistedKey = $model->getKey() !== null
            || $model->getRawOriginal($model->getKeyName()) !== null;

        if ($modelHadPersistedKey && $snapshot->getKey() === null) {
            throw new InvalidArgumentException('A persisted search dependency snapshot requires its raw model key.');
        }

        return $snapshot;
    }

    public function beforeUpdate(Model $model): Model
    {
        return $this->fromAttributes($model, $model->getRawOriginal());
    }

    public function current(Model $model): Model
    {
        return $this->fromAttributes($model, $model->getAttributes());
    }

    public function copy(Model $snapshot): Model
    {
        return $this->fromAttributes($snapshot, $snapshot->getAttributes());
    }
}
