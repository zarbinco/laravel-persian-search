<?php

namespace Zarbinco\PersianSearch\Ranking;

final class UnsignedDecimalStringComparator
{
    public static function compare(string $left, string $right): int
    {
        if (! ctype_digit($left) || ! ctype_digit($right)) {
            return strcmp($left, $right);
        }

        $normalizedLeft = self::normalize($left);
        $normalizedRight = self::normalize($right);
        $length = strlen($normalizedLeft) <=> strlen($normalizedRight);

        if ($length !== 0) {
            return $length;
        }

        $numeric = strcmp($normalizedLeft, $normalizedRight);

        return $numeric !== 0 ? $numeric : strcmp($left, $right);
    }

    private static function normalize(string $value): string
    {
        $normalized = ltrim($value, '0');

        return $normalized === '' ? '0' : $normalized;
    }
}
