<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Stringable;
use Zarbinco\PersianSearch\Exceptions\UnsupportedSearchTextValueException;
use Zarbinco\PersianSearch\Tests\TestCase;
use Zarbinco\PersianSearch\Text\SearchTextValueConverter;

final class SearchTextValueConverterTest extends TestCase
{
    public function test_supported_scalar_enum_and_stringable_values_have_deterministic_strings(): void
    {
        $converter = app(SearchTextValueConverter::class);

        $this->assertSame('', $converter->convert(null));
        $this->assertSame('original', $converter->convert('original'));
        $this->assertSame('42', $converter->convert(42));
        $this->assertSame('2.5', $converter->convert(2.5));
        $this->assertSame('1', $converter->convert(true));
        $this->assertSame('0', $converter->convert(false));
        $this->assertSame('published', $converter->convert(ConverterState::Published));
        $this->assertSame('stringable', $converter->convert(new ConverterStringable));
    }

    public function test_nested_arrays_are_flattened_in_input_order_and_empty_values_are_ignored(): void
    {
        $value = ['first', null, ['second', '', false], 'named' => 3];

        $this->assertSame('first second 0 3', app(SearchTextValueConverter::class)->convert($value));
    }

    public function test_unsupported_values_fail_loudly_with_their_type(): void
    {
        $this->expectException(UnsupportedSearchTextValueException::class);
        $this->expectExceptionMessage('stdClass');

        app(SearchTextValueConverter::class)->convert(new \stdClass);
    }

    public function test_resources_are_rejected_consistently(): void
    {
        $resource = fopen('php://memory', 'r');

        try {
            $this->expectException(UnsupportedSearchTextValueException::class);
            $this->expectExceptionMessage('resource');
            app(SearchTextValueConverter::class)->convert($resource);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }
}

enum ConverterState: string
{
    case Published = 'published';
}

final class ConverterStringable implements Stringable
{
    public function __toString(): string
    {
        return 'stringable';
    }
}
