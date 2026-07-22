<?php

namespace Zarbinco\PersianSearch\Providers;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchDocumentProvider;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchableRelationException;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;

final readonly class EloquentSearchDocumentProvider implements SearchDocumentProvider
{
    public function __construct(
        private SearchDocumentBuilder $builder,
        private EloquentSearchSourceReferenceFactory $references,
    ) {}

    public function key(): string
    {
        return 'eloquent';
    }

    public function supports(mixed $source): bool
    {
        return $source instanceof Model && $source instanceof PersianSearchable;
    }

    public function reference(mixed $source): SearchSourceReference
    {
        return $this->references->make($this->model($source));
    }

    public function documents(mixed $source): iterable
    {
        $model = $this->model($source);
        $relations = $this->relations($model);

        if ($relations !== []) {
            $model->loadMissing($relations);
        }

        yield $this->builder->build($model, $this->references->make($model));
    }

    /** @return list<string> */
    public function relations(Model $model): array
    {
        if (! method_exists($model, 'persianSearchableRelations')) {
            return [];
        }

        $declared = $model->persianSearchableRelations();

        if (! is_array($declared)) {
            throw InvalidSearchableRelationException::invalid($declared);
        }

        $relations = [];

        foreach ($declared as $relation) {
            if (! is_string($relation) || trim($relation) === '') {
                throw InvalidSearchableRelationException::invalid($relation);
            }

            $relations[] = trim($relation);
        }

        return array_values(array_unique($relations));
    }

    /** @return Model&PersianSearchable */
    private function model(mixed $source): Model
    {
        if (! $source instanceof Model || ! $source instanceof PersianSearchable) {
            throw new LogicException('Eloquent search document provider requires a searchable Eloquent model.');
        }

        return $source;
    }
}
