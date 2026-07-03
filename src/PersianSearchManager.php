<?php

namespace Zarbinco\PersianSearch;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchNormalizer;
use Zarbinco\PersianSearch\Indexing\SearchDocument;
use Zarbinco\PersianSearch\Indexing\SearchDocumentBuilder;

final class PersianSearchManager
{
    public function __construct(
        private readonly SearchNormalizer $normalizer,
        private readonly SearchDocumentBuilder $builder,
    ) {}

    public function normalizer(): SearchNormalizer
    {
        return $this->normalizer;
    }

    public function builder(): SearchDocumentBuilder
    {
        return $this->builder;
    }

    public function normalize(string $value): string
    {
        return $this->normalizer->normalize($value);
    }

    /**
     * @return array<int, string>
     */
    public function tokens(string $value): array
    {
        return $this->normalizer->tokens($value);
    }

    public function documentFor(Model $model): SearchDocument
    {
        if (! $model instanceof PersianSearchable) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] must implement [%s] to build a Persian search document.',
                $model::class,
                PersianSearchable::class,
            ));
        }

        return $this->builder->build($model);
    }
}
