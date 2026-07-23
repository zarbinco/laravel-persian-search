<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Candidates\LiteralLikePattern;

final class LiteralLikePatternTest extends TestCase
{
    #[DataProvider('patterns')]
    public function test_contains_escapes_only_like_metacharacters(string $input, string $expected): void
    {
        $this->assertSame($expected, LiteralLikePattern::contains($input)->value);
        $this->assertSame($expected, LiteralLikePattern::contains($input)->value);
    }

    /** @return array<string, array{string, string}> */
    public static function patterns(): array
    {
        return [
            'plain' => ['orange', '%orange%'],
            'percent' => ['100%', '%100!%%'],
            'underscore' => ['file_name', '%file!_name%'],
            'escape' => ['wow!', '%wow!!%'],
            'mixed' => ['%_!', '%!%!_!!%'],
            'backslash' => ['folder\\file', '%folder\\file%'],
            'single quote' => ["it's", "%it's%"],
            'double quote' => ['say "yes"', '%say "yes"%'],
            'Persian' => ['درصد٪', '%درصد٪%'],
            'emoji' => ['juice 🧃', '%juice 🧃%'],
            'SQL-like' => ["%' OR 1=1 --", "%!%' OR 1=1 --%"],
        ];
    }
}
