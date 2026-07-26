<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Spelling\SymmetricDeleteGenerator;

final class SymmetricDeleteGeneratorTest extends TestCase
{
    public function test_generation_is_unicode_safe_deterministic_and_bounded(): void
    {
        $generator = new SymmetricDeleteGenerator;
        $first = $generator->generate('پرتقال', 2, 20);
        $second = $generator->generate('پرتقال', 2, 20);

        $this->assertSame($first, $second);
        $this->assertSame(0, $first['پرتقال']);
        $this->assertSame(1, $first['رتقال']);
        $this->assertLessThanOrEqual(20, count($first));
        $this->assertNotContains('', array_keys($first));
    }
}
