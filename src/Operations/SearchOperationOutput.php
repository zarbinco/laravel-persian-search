<?php

namespace Zarbinco\PersianSearch\Operations;

use JsonSerializable;

final class SearchOperationOutput
{
    /** @param array<string, mixed>|JsonSerializable $value */
    public static function json(JsonSerializable|array $value): string
    {
        $data = $value instanceof JsonSerializable ? $value->jsonSerialize() : $value;
        if ($value instanceof JsonSerializable || ! self::isErrorPayload($data)) {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable) {
            try {
                return json_encode(
                    self::sanitize($data),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            } catch (\Throwable) {
                return '{"status":"infrastructure_failure","message":"Operational JSON output failed safely."}';
            }
        }
    }

    /** @param array<string, mixed> $value */
    private static function isErrorPayload(array $value): bool
    {
        return array_keys($value) === ['status', 'message']
            && is_string($value['status'] ?? null)
            && is_string($value['message'] ?? null);
    }

    /** @return array{status: string, message: string} */
    public static function error(string $message, string $status = 'failed'): array
    {
        return ['status' => $status, 'message' => $message];
    }

    private static function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_match('//u', $value) === 1
                ? $value
                : 'unsafe-sha256:'.hash('sha256', $value).';bytes='.strlen($value);
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safeKey = is_string($key) && preg_match('//u', $key) !== 1
                    ? 'unsafe-key-'.hash('sha256', $key)
                    : $key;
                $safe[$safeKey] = self::sanitize($item);
            }

            return $safe;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return 'Operational value was unavailable safely.';
    }
}
