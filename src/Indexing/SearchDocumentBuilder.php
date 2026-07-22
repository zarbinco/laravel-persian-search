<?php

namespace Zarbinco\PersianSearch\Indexing;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use InvalidArgumentException;
use Throwable;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchableFieldException;
use Zarbinco\PersianSearch\Providers\EloquentSearchSourceReferenceFactory;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;
use Zarbinco\PersianSearch\Text\PreparedSearchText;
use Zarbinco\PersianSearch\Text\SearchTextPipeline;

final readonly class SearchDocumentBuilder
{
    public function __construct(
        private SearchTextPipeline $pipeline,
        private EloquentSearchSourceReferenceFactory $references,
    ) {}

    public function build(Model $model, ?SearchSourceReference $reference = null): SearchDocument
    {
        if (! $model instanceof PersianSearchable) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] must implement [%s] to build a Persian search document.',
                $model::class,
                PersianSearchable::class,
            ));
        }

        $reference ??= $this->references->make($model);

        $locale = $model->persianSearchLocale();

        if ($locale === null || trim($locale) === '') {
            try {
                $locale = app()->getLocale();
            } catch (Throwable) {
                $locale = null;
            }
        }

        /** @var array<string, PreparedSearchText> $preparedStrings */
        $preparedStrings = [];
        $prepare = function (mixed $value) use (&$preparedStrings, $locale): PreparedSearchText {
            if (! is_string($value)) {
                return $this->pipeline->prepare($value, $locale);
            }

            return $preparedStrings[$value] ??= $this->pipeline->prepare($value, $locale);
        };
        $normalizedFields = [];

        foreach ($model->persianSearchableFields() as $declarationKey => $declaration) {
            $field = $this->fieldName($declarationKey, $declaration);
            $prepared = $prepare($this->resolveFieldValue($model, $field));

            if (! $prepared->isEmpty()) {
                $normalizedFields[] = $prepared->normalized;
            }
        }

        $title = $model->persianSearchTitle();
        $preparedTitle = $prepare($title);

        return new SearchDocument(
            partition: (string) config('persian-search.index.default_partition', 'default'),
            sourceKey: $reference->sourceKey,
            sourceType: $reference->sourceType,
            sourceId: $reference->sourceId,
            locale: $locale,
            title: $title,
            excerpt: null,
            normalizedTitle: $preparedTitle->normalized === '' ? null : $preparedTitle->normalized,
            normalizedExcerpt: null,
            normalizedKeywords: null,
            normalizedContent: $normalizedFields === [] ? null : implode(' ', $normalizedFields),
            payload: $model->persianSearchMetadata(),
            priority: 0,
            isActive: true,
            sourceUpdatedAt: $this->sourceUpdatedAt($model),
        );
    }

    private function fieldName(int|string $key, mixed $declaration): string
    {
        if (is_int($key)) {
            if (! is_string($declaration)) {
                throw InvalidSearchableFieldException::invalidFieldName($declaration);
            }

            return $declaration;
        }

        if (! is_int($declaration) && ! is_float($declaration)) {
            throw InvalidSearchableFieldException::invalidWeight($key, $declaration);
        }

        return $key;
    }

    private function resolveFieldValue(Model $model, string $field): mixed
    {
        if ($field === '') {
            throw InvalidSearchableFieldException::invalidFieldName($field);
        }

        return $this->resolveSegmentPath($model, explode('.', $field), $field);
    }

    /**
     * @param  list<string>  $segments
     */
    private function resolveSegmentPath(mixed $value, array $segments, string $field): mixed
    {
        if ($segments === []) {
            return $value;
        }

        $segment = array_shift($segments);

        if ($segment === '') {
            throw InvalidSearchableFieldException::invalidFieldName($field);
        }

        if ($value instanceof Model) {
            if (array_key_exists($segment, $value->getAttributes())) {
                return $this->resolveSegmentPath($value->getAttribute($segment), $segments, $field);
            }

            if ($value->relationLoaded($segment)) {
                return $this->resolveSegmentPath($value->getRelation($segment), $segments, $field);
            }

            if ($segments === []) {
                return null;
            }

            throw InvalidSearchableFieldException::unresolvable(
                $field,
                sprintf('relation or attribute [%s] is not loaded on [%s].', $segment, $value::class),
            );
        }

        if ($value instanceof Enumerable) {
            return $value->map(fn (mixed $item): mixed => $this->resolveSegmentPath($item, [$segment, ...$segments], $field))->all();
        }

        if (is_array($value)) {
            return array_key_exists($segment, $value)
                ? $this->resolveSegmentPath($value[$segment], $segments, $field)
                : null;
        }

        if ($value === null) {
            return null;
        }

        throw InvalidSearchableFieldException::unresolvable(
            $field,
            sprintf('segment [%s] cannot be read from [%s].', $segment, get_debug_type($value)),
        );
    }

    private function sourceUpdatedAt(Model $model): ?DateTimeInterface
    {
        $column = $model->getUpdatedAtColumn();

        if ($column === null) {
            return null;
        }

        $value = $model->getAttribute($column);

        return $value instanceof DateTimeInterface ? $value : null;
    }
}
