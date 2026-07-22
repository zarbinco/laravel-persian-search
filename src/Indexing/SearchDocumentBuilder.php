<?php

namespace Zarbinco\PersianSearch\Indexing;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use InvalidArgumentException;
use Stringable;
use Throwable;
use Traversable;
use UnitEnum;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchNormalizer;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchableFieldException;

final readonly class SearchDocumentBuilder
{
    public function __construct(private SearchNormalizer $normalizer) {}

    public function build(Model $model): SearchDocument
    {
        if (! $model instanceof PersianSearchable) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] must implement [%s] to build a Persian search document.',
                $model::class,
                PersianSearchable::class,
            ));
        }

        $key = $this->modelKey($model);

        if ($key === null || $key === '') {
            throw new InvalidArgumentException('A stable source key requires a persisted model key.');
        }

        $normalizedFields = [];

        foreach ($model->persianSearchableFields() as $declarationKey => $declaration) {
            $field = $this->fieldName($declarationKey, $declaration);
            $value = $this->stringValue($this->resolveFieldValue($model, $field));

            if ($value === null) {
                continue;
            }

            $normalized = $this->normalizer->normalize($value);

            if ($normalized !== '') {
                $normalizedFields[] = $normalized;
            }
        }

        $title = $model->persianSearchTitle();
        $locale = $model->persianSearchLocale();

        if ($locale === null || trim($locale) === '') {
            try {
                $locale = app()->getLocale();
            } catch (Throwable) {
                $locale = null;
            }
        }

        return new SearchDocument(
            partition: (string) config('persian-search.index.default_partition', 'default'),
            sourceKey: $model::class.':'.$key,
            sourceType: $model::class,
            sourceId: $key,
            locale: $locale,
            title: $title,
            excerpt: null,
            normalizedTitle: $this->nullableNormalized($title),
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

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return trim($value) === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : null;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof Stringable) {
            return $this->stringValue((string) $value);
        }

        if ($value instanceof Arrayable) {
            return $this->stringValue($value->toArray());
        }

        if ($value instanceof Enumerable) {
            return $this->stringValue($value->all());
        }

        if ($value instanceof Traversable) {
            return $this->stringValue(iterator_to_array($value, false));
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                $part = $this->stringValue($item);

                if ($part !== null) {
                    $parts[] = $part;
                }
            }

            return $parts === [] ? null : implode(' ', $parts);
        }

        return null;
    }

    private function modelKey(Model $model): ?string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : null;
    }

    private function nullableNormalized(string $value): ?string
    {
        $normalized = $this->normalizer->normalize($value);

        return $normalized === '' ? null : $normalized;
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
