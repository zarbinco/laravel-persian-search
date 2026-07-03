<?php

namespace Zarbinco\PersianSearch\Indexing;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use InvalidArgumentException;
use Stringable;
use Traversable;
use UnitEnum;
use Zarbinco\PersianSearch\Contracts\PersianSearchable;
use Zarbinco\PersianSearch\Contracts\SearchNormalizer;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchableFieldException;

final readonly class SearchDocumentBuilder
{
    public function __construct(
        private SearchNormalizer $normalizer,
    ) {}

    public function build(Model $model): SearchDocument
    {
        if (! $model instanceof PersianSearchable) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] must implement [%s] to build a Persian search document.',
                $model::class,
                PersianSearchable::class,
            ));
        }

        $fields = [];

        foreach ($model->persianSearchableFields() as $key => $declaration) {
            [$fieldName, $weight] = $this->parseFieldDeclaration($key, $declaration);
            $rawValue = $this->resolveFieldValue($model, $fieldName);
            $stringValue = $this->stringValue($rawValue);

            if ($stringValue === null) {
                continue;
            }

            $normalizedValue = $this->normalizer->normalize($stringValue);

            if ($normalizedValue === '') {
                continue;
            }

            $fields[] = new SearchField(
                name: $fieldName,
                rawValue: $rawValue,
                value: $normalizedValue,
                tokens: $this->normalizer->tokens($stringValue),
                weight: $weight,
            );
        }

        $content = implode(' ', array_map(
            static fn (SearchField $field): string => $field->value,
            $fields,
        ));
        $rawTitle = $model->persianSearchTitle();
        $normalizedTitle = $this->normalizer->normalize($rawTitle);

        return new SearchDocument(
            searchableType: $model::class,
            searchableId: $this->modelKey($model),
            locale: $model->persianSearchLocale(),
            title: $normalizedTitle,
            content: $content,
            tokens: $this->normalizer->tokens($content),
            fields: $fields,
            metadata: $model->persianSearchMetadata(),
        );
    }

    /**
     * @return array{0: string, 1: int|float}
     */
    private function parseFieldDeclaration(int|string $key, mixed $declaration): array
    {
        if (is_int($key)) {
            if (! is_string($declaration)) {
                throw InvalidSearchableFieldException::invalidFieldName($declaration);
            }

            return [$declaration, 1];
        }

        if (! is_int($declaration) && ! is_float($declaration)) {
            throw InvalidSearchableFieldException::invalidWeight($key, $declaration);
        }

        return [$key, $declaration];
    }

    private function resolveFieldValue(Model $model, string $field): mixed
    {
        if ($field === '') {
            throw InvalidSearchableFieldException::invalidFieldName($field);
        }

        $segments = explode('.', $field);

        return $this->resolveSegmentPath($model, $segments, $field);
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
            $next = $this->resolveModelSegment($value, $segment, $segments !== [], $field);

            return $this->resolveSegmentPath($next, $segments, $field);
        }

        if ($value instanceof Enumerable) {
            $resolved = [];

            foreach ($value as $item) {
                $resolved[] = $this->resolveSegmentPath($item, array_merge([$segment], $segments), $field);
            }

            return $resolved;
        }

        if (is_array($value)) {
            if (! array_key_exists($segment, $value)) {
                return null;
            }

            return $this->resolveSegmentPath($value[$segment], $segments, $field);
        }

        if ($value === null) {
            return null;
        }

        throw InvalidSearchableFieldException::unresolvable(
            $field,
            sprintf('segment [%s] cannot be read from [%s].', $segment, get_debug_type($value)),
        );
    }

    private function resolveModelSegment(Model $model, string $segment, bool $hasRemaining, string $field): mixed
    {
        if (array_key_exists($segment, $model->getAttributes())) {
            return $model->getAttribute($segment);
        }

        if ($model->relationLoaded($segment)) {
            return $model->getRelation($segment);
        }

        if (! $hasRemaining) {
            return null;
        }

        throw InvalidSearchableFieldException::unresolvable(
            $field,
            sprintf('relation or attribute [%s] is not loaded on [%s].', $segment, $model::class),
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
            $string = (string) $value;

            return trim($string) === '' ? null : $string;
        }

        if ($value instanceof Enumerable) {
            return $this->stringValue($value->all());
        }

        if ($value instanceof Arrayable) {
            return $this->stringValue($value->toArray());
        }

        if (is_array($value)) {
            return $this->flattenArrayValue($value);
        }

        if ($value instanceof Traversable) {
            return $this->stringValue(iterator_to_array($value, preserve_keys: false));
        }

        return null;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function flattenArrayValue(array $value): ?string
    {
        $parts = [];

        foreach ($value as $item) {
            $string = $this->stringValue($item);

            if ($string !== null) {
                $parts[] = $string;
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    private function modelKey(Model $model): int|string|null
    {
        $key = $model->getKey();

        if (is_int($key) || is_string($key) || $key === null) {
            return $key;
        }

        if (is_float($key)) {
            return (string) $key;
        }

        return null;
    }
}
