<?php

namespace Zarbinco\PersianSearch\Support;

final class CanonicalConfigurationName
{
    private const FORBIDDEN_PATTERN = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    private const EDGE_WHITESPACE_PATTERN = '/\A[\p{Z}\s]|[\p{Z}\s]\z/u';

    public static function isValid(string $value): bool
    {
        return $value !== '' &&
            preg_match('//u', $value) === 1 &&
            preg_match(self::FORBIDDEN_PATTERN, $value) !== 1 &&
            preg_match(self::EDGE_WHITESPACE_PATTERN, $value) !== 1;
    }
}
