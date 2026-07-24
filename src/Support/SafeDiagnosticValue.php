<?php

namespace Zarbinco\PersianSearch\Support;

final class SafeDiagnosticValue
{
    public static function describe(string $value): string
    {
        if (CanonicalConfigurationName::isValid($value)) {
            return $value;
        }

        return 'unsafe-sha256:'.hash('sha256', $value).';bytes='.strlen($value);
    }
}
