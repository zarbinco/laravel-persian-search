<?php

namespace Zarbinco\PersianSearch\Text;

use BackedEnum;
use Stringable;
use Zarbinco\PersianSearch\Exceptions\UnsupportedSearchTextValueException;

final class SearchTextValueConverter
{
    public function convert(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                $converted = $this->convert($item);

                if ($converted !== '') {
                    $parts[] = $converted;
                }
            }

            return implode(' ', $parts);
        }

        throw UnsupportedSearchTextValueException::forType(get_debug_type($value));
    }
}
