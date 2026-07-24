<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianSearch\Operations\SearchSourceEnumeratorRegistry;
use Zarbinco\PersianSearch\Tests\TestCase;

final class SearchSourceEnumeratorTest extends TestCase
{
    public function test_empty_registry_is_valid_and_deterministic(): void
    {
        $registry = app(SearchSourceEnumeratorRegistry::class);

        $this->assertSame([], $registry->all());
        $this->assertSame([], $registry->selected([], []));
        $this->assertSame([], $registry->authoritativeForProviders([]));
    }
}
