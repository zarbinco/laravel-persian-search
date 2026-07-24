<?php

namespace Zarbinco\PersianSearch\Ranking;

final class UnsignedDecimalStringComparator
{
    public static function compare(string $left, string $right): int
    {
        if (! ctype_digit($left) || ! ctype_digit($right)) {
            return self::sign(strcmp($left, $right));
        }

        $normalizedLeft = self::normalize($left);
        $normalizedRight = self::normalize($right);
        $length = strlen($normalizedLeft) <=> strlen($normalizedRight);

        if ($length !== 0) {
            return $length;
        }

        $numeric = self::sign(strcmp($normalizedLeft, $normalizedRight));

        return $numeric !== 0 ? $numeric : self::sign(strcmp($left, $right));
    }

    private static function normalize(string $value): string
    {
        $normalized = ltrim($value, '0');

        return $normalized === '' ? '0' : $normalized;
    }

    private static function sign(int $comparison): int
    {
        return $comparison <=> 0;
    }
}
