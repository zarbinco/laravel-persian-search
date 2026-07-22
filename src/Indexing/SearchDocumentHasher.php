<?php

namespace Zarbinco\PersianSearch\Indexing;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Enumerable;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;
use Traversable;
use UnitEnum;

final class SearchDocumentHasher
{
    public function hash(SearchDocument $document): string
    {
        $json = json_encode(
            self::canonicalize($document->meaningfulData()),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $json);
    }

    public static function jsonSafeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return self::jsonSafeValue($value->value);
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return (new DateTimeImmutable($value->format('Y-m-d H:i:s.u'), $value->getTimezone()))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z');
        }

        if ($value instanceof JsonSerializable) {
            return self::jsonSafeValue($value->jsonSerialize());
        }

        if ($value instanceof Arrayable) {
            return self::jsonSafeValue($value->toArray());
        }

        if ($value instanceof Enumerable) {
            return self::jsonSafeValue($value->all());
        }

        if ($value instanceof Traversable) {
            return self::jsonSafeValue(iterator_to_array($value));
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_array($value)) {
            $safe = [];

            foreach ($value as $key => $item) {
                $safe[$key] = self::jsonSafeValue($item);
            }

            return $safe;
        }

        throw new InvalidArgumentException(
            'Search document payload contains unsupported value ['.get_debug_type($value).'].',
        );
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    public static function canonicalizePayload(array $payload): array
    {
        $canonical = self::canonicalize($payload);

        return is_array($canonical) ? $canonical : [];
    }

    private static function canonicalize(mixed $value): mixed
    {
        $value = self::jsonSafeValue($value);

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
