<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Operations\SearchOperationsPolicy;

final class SearchOperationsPolicyTest extends TestCase
{
    public function test_defaults_and_constructor_invariants(): void
    {
        $policy = new SearchOperationsPolicy;

        $this->assertSame(500, $policy->chunkSize);
        $this->assertSame(100000, $policy->maximumSourcesPerRun);
        $this->assertSame($policy->toArray(), $policy->jsonSerialize());

        $this->expectException(InvalidArgumentException::class);
        new SearchOperationsPolicy(lockSeconds: 0);
    }
}
